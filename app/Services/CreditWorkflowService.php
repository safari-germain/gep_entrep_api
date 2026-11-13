<?php

namespace App\Services;

use App\Models\Credit;
use App\Models\CreditGuarantor;
use App\Models\CreditSchedule;
use App\Models\Deposit;
use App\Models\Individual;
use App\Models\Organisation;
use App\Models\OrdinaryAccount;
use App\Models\SystemLedgerEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreditWorkflowService
{
    /**
     * Créer une demande de crédit pour l'un des 3 types : solidaire, express, special
     */
    public function createApplication(string $creditType, array $data, int $operatorId): Credit
    {
        return DB::transaction(function () use ($creditType, $data, $operatorId) {
            $beneficiaryType = $data['beneficiary_type'] ?? 'individual';
            $userId = $data['user_id'] ?? null;
            $amount = (float) $data['requested_amount'];
            $duration = (int) ($data['duration_months'] ?? 12);

            // 1. Validation selon le type de crédit
            if ($creditType === 'solidaire') {
                if (!$userId) {
                    throw new \InvalidArgumentException('Un utilisateur entrepreneur est obligatoire pour un crédit solidaire.');
                }

                // Vérifier la caution solidaire disponible (max capacité ex: 500%)
                $totalCaution = (float) Deposit::where('user_id', $userId)->sum('actual_amount');
                $multiplier = (float) FinancialRuleService::get('pret_pourcentage_max', 500.0);
                $maxAllowed = round($totalCaution * ($multiplier / 100.0), 2);

                if ($totalCaution <= 0 || ($maxAllowed > 0 && $amount > $maxAllowed)) {
                    throw new \InvalidArgumentException(
                        "Capacité d'emprunt dépassée : vous disposez de \${$totalCaution} de caution. " .
                        "Le montant maximal autorisé ({$multiplier}%) est de \${$maxAllowed}."
                    );
                }

                $graceDays = (int) FinancialRuleService::get('credit_echeance_delai_grace_jours', 3);
                $penaltyPct = (float) FinancialRuleService::get('credit_echeance_penalite_pct', 1.0);
            } elseif ($creditType === 'express') {
                // Crédit Express : sans condition de caution obligatoire
                $graceDays = (int) ($data['overdraft_grace_days'] ?? FinancialRuleService::get('credit_express_grace_days', 3));
                $penaltyPct = (float) ($data['overdraft_penalty_pct'] ?? FinancialRuleService::get('credit_express_penalty_pct', 2.0));
            } else { // 'special'
                // Crédit Spécial : délai et taux ajustables
                $graceDays = (int) ($data['overdraft_grace_days'] ?? FinancialRuleService::get('credit_special_grace_days', 5));
                $penaltyPct = (float) ($data['overdraft_penalty_pct'] ?? FinancialRuleService::get('credit_special_penalty_pct', 1.5));
            }

            $interestRate = (float) ($data['interest_rate'] ?? FinancialRuleService::get('pret_taux_interet_pct', 10.0));
            
            // Calcul d'amortissement dégressif : intérêts calculés sur le capital restant dû
            $monthlyPrincipalConstant = round($amount / $duration, 2);
            $rateFraction = $interestRate / 100.0;
            $totalInterest = 0.0;
            $remainingCap = $amount;
            for ($i = 1; $i <= $duration; $i++) {
                $monthInterest = round($remainingCap * $rateFraction, 2);
                $totalInterest += $monthInterest;
                $remainingCap = max(0.0, round($remainingCap - $monthlyPrincipalConstant, 2));
            }
            $totalInterest = round($totalInterest, 2);
            $totalDue = round($amount + $totalInterest, 2);

            $branchId = $data['branch_id'] ?? null;
            if (!$branchId && $userId) {
                $individual = Individual::where('user_id', $userId)->first();
                $branchId = $individual->branch_id ?? 1;
            }
            $branchId = $branchId ?? 1;

            $disbursementDate = Carbon::now();
            $firstDueDate = $disbursementDate->copy()->addMonth();
            $finalDueDate = $disbursementDate->copy()->addMonths($duration);

            $credit = Credit::create([
                'credit_number'           => 'CRE-' . strtoupper($creditType[0]) . '-' . strtoupper(Str::random(7)),
                'credit_type'             => $creditType,
                'approval_status'         => 'pending_guarantors',
                'beneficiary_type'        => $beneficiaryType,
                'user_id'                 => $userId,
                'target_branch_id'        => $data['target_branch_id'] ?? null,
                'target_organisation_id'  => $data['target_organisation_id'] ?? null,
                'branch_id'               => $branchId,
                'disbursed_by_operator_id'=> $operatorId,
                'principal_amount'        => $amount,
                'interest_rate_percentage'=> $interestRate,
                'total_interest_amount'   => $totalInterest,
                'total_amount_due'        => $totalDue,
                'total_amount_paid'       => 0.00,
                'remaining_balance'       => $totalDue,
                'duration_months'         => $duration,
                'disbursement_date'       => $disbursementDate,
                'first_due_date'          => $firstDueDate,
                'final_due_date'          => $finalDueDate,
                'overdraft_grace_days'    => $graceDays,
                'overdraft_penalty_pct'   => $penaltyPct,
                'status'                  => 'active',
                'credit_stars'            => 3.00,
                'has_red_dot'             => false,
            ]);

            // Pour Crédit Solidaire : pré-positionner automatiquement l'organisation par défaut
            if ($creditType === 'solidaire') {
                $orgDefaultPct = (float) FinancialRuleService::get('credit_solidaire_org_default_pct', 50.0);
                $defaultOrg = Organisation::where('status', 'active')->first();
                if ($defaultOrg) {
                    CreditGuarantor::create([
                        'credit_id'             => $credit->id,
                        'organisation_id'       => $defaultOrg->id,
                        'guarantor_type'        => 'organisation',
                        'guarantor_role'        => 'org_default',
                        'guaranteed_percentage' => $orgDefaultPct,
                        'guaranteed_amount'     => round($amount * ($orgDefaultPct / 100.0), 2),
                        'approval_status'       => 'pending',
                    ]);
                }
            }

            return $credit->load(['guarantors.guarantorUser', 'guarantors.organisation']);
        });
    }

    /**
     * Ajouter un parrain au dossier de crédit
     */
    public function addGuarantor(Credit $credit, array $data): CreditGuarantor
    {
        return DB::transaction(function () use ($credit, $data) {
            $percentage = (float) $data['guaranteed_percentage'];
            $role = $data['guarantor_role'] ?? 'peer';
            $isOrg = !empty($data['organisation_id']);
            $type = $isOrg ? 'organisation' : 'individual';

            // Validations spécifiques
            if ($credit->credit_type === 'solidaire') {
                if ($role === 'peer') {
                    $minPeerPct = (float) FinancialRuleService::get('credit_solidaire_min_peer_pct_each', 10.0);
                    if ($percentage < $minPeerPct) {
                        throw new \InvalidArgumentException("Chaque parrain pair doit garantir au moins {$minPeerPct}% du prêt.");
                    }

                    // Limite à 3 pairs max pour le crédit solidaire
                    $existingPeers = $credit->guarantors()->where('guarantor_role', 'peer')->count();
                    if ($existingPeers >= 3) {
                        throw new \InvalidArgumentException("Le crédit solidaire requiert exactement 3 parrains pairs (déjà atteints).");
                    }
                }
            } elseif ($credit->credit_type === 'special') {
                // Spécial : une seule org ou le centre GEP peut garantir jusqu'à 100%
                if ($percentage > 100.0) {
                    throw new \InvalidArgumentException("Le pourcentage garanti ne peut pas excéder 100%.");
                }
            }

            $guaranteedAmount = round($credit->principal_amount * ($percentage / 100.0), 2);

            $guarantor = CreditGuarantor::create([
                'credit_id'             => $credit->id,
                'guarantor_user_id'     => $data['guarantor_user_id'] ?? null,
                'organisation_id'       => $data['organisation_id'] ?? null,
                'guarantor_type'        => $type,
                'guarantor_role'        => $role,
                'guaranteed_percentage' => $percentage,
                'guaranteed_amount'     => $guaranteedAmount,
                'approval_status'       => 'pending',
                'notes'                 => $data['notes'] ?? null,
            ]);

            $this->updateWorkflowStatus($credit->fresh(['guarantors']));

            return $guarantor;
        });
    }

    /**
     * Un parrain approuve sa caution
     */
    public function approveGuarantor(CreditGuarantor $guarantor, int $userId): CreditGuarantor
    {
        return DB::transaction(function () use ($guarantor, $userId) {
            $guarantor->approval_status = 'approved';
            $guarantor->approved_at = now();
            $guarantor->save();

            $credit = $guarantor->credit()->with('guarantors')->first();
            $this->updateWorkflowStatus($credit);

            return $guarantor;
        });
    }

    /**
     * Un parrain rejette sa caution
     */
    public function rejectGuarantor(CreditGuarantor $guarantor, int $userId, string $reason = ''): CreditGuarantor
    {
        return DB::transaction(function () use ($guarantor, $userId, $reason) {
            $guarantor->approval_status = 'rejected';
            $guarantor->rejection_reason = $reason;
            $guarantor->save();

            $credit = $guarantor->credit()->with('guarantors')->first();
            $this->updateWorkflowStatus($credit);

            return $guarantor;
        });
    }

    /**
     * Mise à jour automatique de l'état du workflow
     */
    public function updateWorkflowStatus(Credit $credit): void
    {
        if (in_array($credit->approval_status, ['approved', 'rejected', 'disbursed'])) {
            return;
        }

        $guarantors = $credit->guarantors()->get();

        if ($credit->credit_type === 'solidaire') {
            $approvedPeers = $guarantors->where('guarantor_role', 'peer')->where('approval_status', 'approved');
            $orgDefault = $guarantors->where('guarantor_role', 'org_default')->first();

            if ($approvedPeers->count() < 3) {
                $credit->approval_status = 'pending_guarantors';
            } elseif (!$orgDefault || $orgDefault->approval_status !== 'approved') {
                $credit->approval_status = 'pending_org';
            } else {
                $credit->approval_status = 'pending_financier';
            }
        } elseif ($credit->credit_type === 'express') {
            $approvedGuarantors = $guarantors->where('approval_status', 'approved');
            $totalApprovedPct = $approvedGuarantors->sum('guaranteed_percentage');

            if ($totalApprovedPct < 50.0) {
                $credit->approval_status = 'pending_guarantors';
            } else {
                $credit->approval_status = 'pending_financier';
            }
        } else { // 'special'
            $approvedGuarantor = $guarantors->where('approval_status', 'approved')->first();
            if ($approvedGuarantor && $approvedGuarantor->guaranteed_percentage >= 50.0) {
                $credit->approval_status = 'pending_financier';
            } else {
                $credit->approval_status = 'pending_guarantors';
            }
        }

        $credit->save();
    }

    /**
     * Soumettre manuellement au financier si les conditions préliminaires sont remplies
     */
    public function submitToFinancier(Credit $credit): Credit
    {
        $this->updateWorkflowStatus($credit);
        $credit->refresh();

        if ($credit->approval_status !== 'pending_financier') {
            throw new \InvalidArgumentException(
                "Le dossier n'est pas encore prêt pour le financier. Statut actuel : {$credit->approval_status}."
            );
        }

        return $credit;
    }

    /**
     * Approbation par le financier (délibération finale)
     * Pour Solidaire : ajoute automatiquement le Centre GEP à 20%
     */
    public function financierApprove(Credit $credit, int $financierUserId, ?string $notes = null): Credit
    {
        return DB::transaction(function () use ($credit, $financierUserId, $notes) {
            $credit->refresh();

            // Pour solidaire : ajouter automatiquement le Centre GEP (20%)
            if ($credit->credit_type === 'solidaire') {
                $centrePct = (float) FinancialRuleService::get('credit_solidaire_centre_gep_pct', 20.0);
                CreditGuarantor::updateOrCreate(
                    [
                        'credit_id'      => $credit->id,
                        'guarantor_role' => 'centre_gep',
                    ],
                    [
                        'guarantor_type'        => 'organisation',
                        'guaranteed_percentage' => $centrePct,
                        'guaranteed_amount'     => round($credit->principal_amount * ($centrePct / 100.0), 2),
                        'approval_status'       => 'approved',
                        'approved_at'           => now(),
                        'notes'                 => 'Prise en charge automatique par le Centre GEP',
                    ]
                );
            }

            // Mettre à jour les parts d'intérêts de tous les parrains
            $this->recalculateInterestShares($credit);

            $credit->approval_status = 'approved';
            $credit->financier_user_id = $financierUserId;
            $credit->financier_approved_at = now();
            $credit->financier_notes = $notes;
            $credit->save();

            return $credit->load(['guarantors.guarantorUser', 'guarantors.organisation', 'financier']);
        });
    }

    /**
     * Rejet par le financier
     */
    public function financierReject(Credit $credit, int $financierUserId, string $reason): Credit
    {
        $credit->approval_status = 'rejected';
        $credit->financier_user_id = $financierUserId;
        $credit->rejection_reason = $reason;
        $credit->save();

        return $credit;
    }

    /**
     * Décaissement effectif : génère le tableau d'amortissement et active le prêt
     */
    public function disburse(Credit $credit, int $operatorId): Credit
    {
        return DB::transaction(function () use ($credit, $operatorId) {
            if ($credit->approval_status !== 'approved' && $credit->approval_status !== 'disbursed') {
                throw new \InvalidArgumentException("Le crédit doit être préalablement approuvé par le financier avant décaissement.");
            }

            $credit->approval_status = 'disbursed';
            $credit->status = 'active';
            $credit->disbursement_date = now();
            $credit->first_due_date = now()->addMonth();
            $credit->final_due_date = now()->addMonths($credit->duration_months);
            $credit->disbursed_by_operator_id = $operatorId;
            $credit->save();

            // Création du tableau d'amortissement dégressif si non déjà existant
            if ($credit->schedules()->count() === 0) {
                $monthlyPrincipal = round($credit->principal_amount / $credit->duration_months, 2);
                $rateFraction = ((float)$credit->interest_rate_percentage) / 100.0;
                $remainingPrincipal = (float) $credit->principal_amount;
                $cumulativePrincipal = 0.0;

                for ($i = 1; $i <= $credit->duration_months; $i++) {
                    // Dernier mois ajusté pour solder exactement le principal emprunté
                    $principalForMonth = ($i === $credit->duration_months)
                        ? round($credit->principal_amount - $cumulativePrincipal, 2)
                        : $monthlyPrincipal;
                    $cumulativePrincipal += $principalForMonth;

                    // Intérêt dégressif calculé sur le capital restant dû au début de l'échéance
                    $interestForMonth = round($remainingPrincipal * $rateFraction, 2);
                    $totalMonthDue = round($principalForMonth + $interestForMonth, 2);
                    $remainingPrincipal = max(0.0, round($remainingPrincipal - $principalForMonth, 2));

                    CreditSchedule::create([
                        'credit_id'          => $credit->id,
                        'installment_number' => $i,
                        'due_date'           => now()->addMonths($i)->toDateString(),
                        'principal_due'      => $principalForMonth,
                        'interest_due'       => $interestForMonth,
                        'total_due'          => $totalMonthDue,
                        'amount_paid'        => 0.00,
                        'penalty_due'        => 0.00,
                        'status'             => 'pending',
                    ]);
                }
            }

            // Déposer l'argent sur le compte ordinaire de l'entrepreneur s'il s'agit d'un individu
            if ($credit->user_id) {
                $ordinaryAccount = OrdinaryAccount::getOrCreateForEntity('user', $credit->user_id, $credit->user_id);
                app(OrdinaryAccountService::class)->deposit(
                    $ordinaryAccount,
                    $credit->principal_amount,
                    'internal',
                    "Décaissement du Crédit #{$credit->credit_number} ({$credit->credit_type})",
                    $operatorId,
                    'loan_disbursement'
                );
            }

            return $credit->load(['schedules', 'guarantors']);
        });
    }

    /**
     * Pardonner / effacer les pénalités et amendes de retard (ex: Crédit Express)
     * "Ce sont des amendes qu'on peut pardonner et non le capital"
     */
    public function waivePenalties(Credit $credit, int $operatorId, string $reason): Credit
    {
        return DB::transaction(function () use ($credit, $operatorId, $reason) {
            $totalWaived = 0.0;
            foreach ($credit->schedules as $schedule) {
                if ($schedule->penalty_due > 0) {
                    $totalWaived += $schedule->penalty_due;
                    $schedule->penalty_due = 0.00;
                    $schedule->save();
                }
            }

            $credit->penalties_waived = round(((float) $credit->penalties_waived) + $totalWaived, 2);
            $credit->penalties_waived_reason = $reason;
            $credit->penalties_waived_by = $operatorId;
            $credit->save();

            return $credit->fresh(['schedules']);
        });
    }

    /**
     * Répartition des intérêts proportionnellement aux pourcentages des parrains
     * Appelée lors d'un remboursement d'échéance
     */
    public function distributeInterestToGuarantors(Credit $credit, float $interestAmount): array
    {
        if ($interestAmount <= 0) {
            return [];
        }

        $approvedGuarantors = $credit->guarantors()->where('approval_status', 'approved')->get();
        $totalPct = $approvedGuarantors->sum('guaranteed_percentage');

        if ($totalPct <= 0) {
            return [];
        }

        $distributed = [];

        foreach ($approvedGuarantors as $guarantor) {
            $share = ($guarantor->guaranteed_percentage / $totalPct);
            $earned = round($interestAmount * $share, 2);

            $guarantor->interest_earned = round(((float) $guarantor->interest_earned) + $earned, 2);
            $guarantor->save();

            // Si c'est un pair individu avec un compte, on crédite son compte ordinaire
            if ($guarantor->guarantor_user_id) {
                $account = OrdinaryAccount::getOrCreateForEntity('user', $guarantor->guarantor_user_id, $guarantor->guarantor_user_id);
                app(OrdinaryAccountService::class)->deposit(
                    $account,
                    $earned,
                    'internal',
                    "Quote-part intérêt de parrainage - Crédit #{$credit->credit_number} ({$guarantor->guaranteed_percentage}%)",
                    null,
                    'guarantor_interest'
                );
            }

            $distributed[] = [
                'guarantor_id' => $guarantor->id,
                'role'         => $guarantor->guarantor_role,
                'percentage'   => $guarantor->guaranteed_percentage,
                'earned'       => $earned,
            ];
        }

        return $distributed;
    }

    /**
     * Recalculer les parts d'intérêts de chaque garant (base 100%)
     */
    private function recalculateInterestShares(Credit $credit): void
    {
        $approvedGuarantors = $credit->guarantors()->where('approval_status', 'approved')->get();
        $totalPct = $approvedGuarantors->sum('guaranteed_percentage');

        if ($totalPct > 0) {
            foreach ($approvedGuarantors as $g) {
                $g->interest_share_pct = round(($g->guaranteed_percentage / $totalPct) * 100.0, 2);
                $g->save();
            }
        }
    }
}
