<?php

namespace App\Http\Controllers;

use App\Models\Credit;
use App\Models\CreditGuarantor;
use App\Models\CreditPayment;
use App\Models\CreditSchedule;
use App\Models\Deposit;
use App\Models\Individual;
use App\Models\Organisation;
use App\Models\User;
use App\Services\BonusCalculationService;
use App\Services\FinancialRuleService;
use App\Services\MalusService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CreditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Credit::with(['user', 'branch', 'guarantors.guarantorUser', 'guarantors.organisation', 'schedules'])
            ->orderBy('id', 'desc');

        if ($request->has('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }
        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->has('approval_status')) {
            $query->where('approval_status', $request->query('approval_status'));
        }

        $credits = $query->paginate($request->query('per_page', 50));
        return response()->json($credits);
    }

    /**
     * Capacité d'emprunt maximale selon la caution disponible et la règle dynamique (ex: 500%)
     */
    public function capacity(int $userId): JsonResponse
    {
        $user = User::findOrFail($userId);
        $individual = Individual::where('user_id', $userId)->first();

        // Total des cautions versées
        $totalCaution = (float) Deposit::where('user_id', $userId)->sum('actual_amount');

        // Multiplicateur dynamique (ex: 500%)
        $maxCapacityMultiplier = (float) FinancialRuleService::get('pret_pourcentage_max', 500.0);
        $maxCapacity = round($totalCaution * ($maxCapacityMultiplier / 100.0), 2);

        // Encours de crédits déjà actifs
        $activeCredits = (float) Credit::where('user_id', $userId)
            ->whereIn('status', ['active', 'in_arrears'])
            ->sum('remaining_balance');

        $availableCapacity = max(0.0, $maxCapacity - $activeCredits);

        return response()->json([
            'user_id'              => $userId,
            'user_name'            => $user->name,
            'total_caution'        => $totalCaution,
            'multiplier_pct'       => $maxCapacityMultiplier,
            'max_capacity'         => $maxCapacity,
            'active_credits'       => $activeCredits,
            'available_capacity'   => $availableCapacity,
            'trust_score'          => $individual->trust_score ?? 100,
            'cotisation_stars'     => $individual->cotisation_stars ?? 3.0,
            'credit_stars'         => $individual->credit_stars ?? 3.0,
            'has_red_dot'          => $individual->has_red_dot ?? false,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'beneficiary_type'       => 'nullable|string|in:individual,branch,organisation',
            'user_id'                => 'nullable|integer|exists:users,id',
            'target_branch_id'       => 'nullable|integer|exists:branches,id',
            'target_organisation_id' => 'nullable|integer|exists:organisations,id',
            'requested_amount'       => 'required|numeric|min:10',
            'duration_months'        => 'required|integer|between:1,36',
            'interest_rate'          => 'nullable|numeric|min:0',
            'purpose'                => 'nullable|string',
            'branch_id'              => 'nullable|integer|exists:branches,id',
            'credit_type'            => 'nullable|string|in:solidaire,express,special',
            'guarantors'             => 'nullable|array', // [{ guarantor_user_id, organisation_id, guaranteed_percentage }]
        ]);

        $beneficiaryType = $validated['beneficiary_type'] ?? 'individual';
        $userId = $validated['user_id'] ?? null;
        $targetBranchId = $validated['target_branch_id'] ?? null;
        $targetOrganisationId = $validated['target_organisation_id'] ?? null;

        if ($beneficiaryType === 'individual' && !$userId) {
            return response()->json(['error' => 'Le champ entrepreneur (user_id) est requis pour un crédit individuel.'], 422);
        }
        if ($beneficiaryType === 'branch' && !$targetBranchId) {
            return response()->json(['error' => 'Le champ succursale (target_branch_id) est requis pour un crédit succursale.'], 422);
        }
        if ($beneficiaryType === 'organisation' && !$targetOrganisationId) {
            return response()->json(['error' => 'Le champ organisation (target_organisation_id) est requis pour un crédit organisation.'], 422);
        }

        $amount = (float) $validated['requested_amount'];
        $duration = (int) $validated['duration_months'];

        // Vérifier la capacité d'emprunt pour les entrepreneurs individuels
        $totalCaution = 0.0;
        if ($userId) {
            $totalCaution = (float) Deposit::where('user_id', $userId)->sum('actual_amount');
            $multiplier = (float) FinancialRuleService::get('pret_pourcentage_max', 500.0);
            $maxAllowed = round($totalCaution * ($multiplier / 100.0), 2);

            if ($maxAllowed > 0 && $amount > $maxAllowed) {
                return response()->json([
                    'error' => "Le montant demandé (\${$amount}) dépasse le plafond de {$multiplier}% de votre caution disponible (\${$maxAllowed}).",
                ], 422);
            }
        }

        $interestRate = (float) ($validated['interest_rate'] ?? FinancialRuleService::get('pret_taux_interet_pct', 10.0));
        $totalInterest = round($amount * ($interestRate / 100.0), 2);
        $totalDue = $amount + $totalInterest;

        $branchId = $validated['branch_id'] ?? $targetBranchId;
        if (!$branchId && $userId) {
            $individual = Individual::where('user_id', $userId)->first();
            $branchId = $individual->branch_id ?? 1;
        }
        $branchId = $branchId ?? 1;

        $disbursementDate = Carbon::now();
        $firstDueDate = $disbursementDate->copy()->addMonth();
        $finalDueDate = $disbursementDate->copy()->addMonths($duration);

        $credit = Credit::create([
            'credit_number'           => 'CRE-' . strtoupper(Str::random(8)),
            'credit_type'             => $validated['credit_type'] ?? 'solidaire',
            'approval_status'         => 'approved',
            'beneficiary_type'        => $beneficiaryType,
            'user_id'                 => $userId,
            'target_branch_id'        => $targetBranchId,
            'target_organisation_id'  => $targetOrganisationId,
            'branch_id'               => $branchId,
            'disbursed_by_operator_id'=> $request->user()->id ?? ($userId ?? 1),
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
            'caution_pledged_real'    => min($amount, $totalCaution),
            'status'                  => 'active',
            'credit_stars'            => 3.00,
            'has_red_dot'             => false,
        ]);

        // Tableau d'amortissement
        $monthlyPrincipal = round($amount / $duration, 2);
        $monthlyInterest = round($totalInterest / $duration, 2);
        $monthlyTotal = $monthlyPrincipal + $monthlyInterest;

        for ($i = 1; $i <= $duration; $i++) {
            CreditSchedule::create([
                'credit_id'          => $credit->id,
                'installment_number' => $i,
                'due_date'           => $disbursementDate->copy()->addMonths($i),
                'principal_due'      => $monthlyPrincipal,
                'interest_due'       => $monthlyInterest,
                'total_due'          => $monthlyTotal,
                'amount_paid'        => 0.00,
                'status'             => 'pending',
            ]);
        }

        // Parrains / Garants
        $guarantorsInput = $validated['guarantors'] ?? null;

        // Si aucun parrain n'est explicitement fourni, ajouter l'organisation par défaut à 50%
        if ($guarantorsInput === null) {
            $defaultOrg = Organisation::where('status', 'active')->first();
            if ($defaultOrg) {
                $guarantorsInput = [
                    [
                        'organisation_id'       => $defaultOrg->id,
                        'guarantor_user_id'     => null,
                        'guaranteed_percentage' => 50.0,
                    ]
                ];
            } else {
                $guarantorsInput = [];
            }
        }

        if (!empty($guarantorsInput)) {
            foreach ($guarantorsInput as $g) {
                $pct = (float) ($g['guaranteed_percentage'] ?? 0);
                if ($pct <= 0) continue;

                $gAmount = round($amount * ($pct / 100.0), 2);
                $isOrg = !empty($g['organisation_id']);

                CreditGuarantor::create([
                    'credit_id'             => $credit->id,
                    'guarantor_user_id'     => $g['guarantor_user_id'] ?? null,
                    'organisation_id'       => $g['organisation_id'] ?? null,
                    'guarantor_type'        => $isOrg ? 'organisation' : 'individual',
                    'guaranteed_amount'     => $gAmount,
                    'guaranteed_percentage' => $pct,
                ]);
            }

            BonusCalculationService::distributeParrainageBonus($credit, $request->user()->id ?? null);
        }

        return response()->json([
            'message' => 'Crédit octroyé et décaissé avec succès.',
            'credit'  => $credit->load(['schedules', 'guarantors.guarantorUser', 'guarantors.organisation', 'targetBranch', 'targetOrganisation']),
        ], 201);
    }


    /**
     * Remboursement d'une échéance de crédit
     */
    public function repay(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'amount'             => 'required|numeric|min:1',
            'credit_schedule_id' => 'nullable|integer|exists:credit_schedules,id',
            'is_late'            => 'nullable|boolean',
            'delay_days'         => 'nullable|integer|min:0',
            'payment_method'     => 'nullable|string',
        ]);

        $credit = Credit::with('schedules')->findOrFail($id);
        $amount = (float) $validated['amount'];
        $isLate = (bool) ($validated['is_late'] ?? false);

        // Trouver l'échéance à régler
        $schedule = null;
        if (!empty($validated['credit_schedule_id'])) {
            $schedule = CreditSchedule::find($validated['credit_schedule_id']);
        } else {
            $schedule = $credit->schedules()->whereIn('status', ['pending', 'partially_paid', 'overdue'])->first();
        }

        $appliedMaluses = [];
        if ($isLate && $schedule) {
            // Appliquer le malus de retard et le malus dynamique aux parrains
            $appliedMaluses = MalusService::applyLateCreditScheduleMalus($schedule, $request->user()->id ?? null);
        }

        // Mettre à jour le solde
        $credit->total_amount_paid += $amount;
        $credit->remaining_balance = max(0.0, $credit->total_amount_due - $credit->total_amount_paid);

        if ($schedule) {
            $schedule->amount_paid += $amount;
            if ($schedule->amount_paid >= $schedule->total_due) {
                $schedule->status = 'paid';
                $schedule->paid_date = now();
                // Résoudre les malus si payé
                MalusService::resolveCreditScheduleMalus($schedule);
            } else {
                $schedule->status = 'partially_paid';
            }
            $schedule->save();
        }

        if ($credit->remaining_balance <= 0) {
            $credit->status = 'paid_off';
            $credit->has_red_dot = false;
        }
        $credit->save();

        $interestPortion = round($amount * 0.1, 2);

        // Enregistrer le reçu de paiement
        CreditPayment::create([
            'payment_reference' => 'PAY-' . strtoupper(Str::random(8)),
            'credit_id'         => $credit->id,
            'credit_schedule_id'=> $schedule->id ?? null,
            'user_id'           => $credit->user_id,
            'branch_id'         => $credit->branch_id,
            'operator_id'       => $request->user()->id ?? $credit->user_id,
            'amount_paid'       => $amount,
            'principal_portion' => round($amount * 0.9, 2),
            'interest_portion'  => $interestPortion,
            'payment_method'    => $validated['payment_method'] ?? 'cash',
        ]);

        // Distribution de la quote-part d'intérêts aux parrains au prorata de leur garantie
        $distributedInterests = app(\App\Services\CreditWorkflowService::class)->distributeInterestToGuarantors($credit, $interestPortion);

        $individual = Individual::where('user_id', $credit->user_id)->first();
        if ($individual) {
            $individual->recalculateStarsAndRedDot();
            $individual->refresh();
        }

        return response()->json([
            'message'        => 'Remboursement enregistré avec succès.',
            'credit'         => $credit->fresh(['schedules', 'guarantors']),
            'applied_maluses'=> $appliedMaluses,
            'stars'          => [
                'credit_stars' => $individual->credit_stars ?? 3.0,
                'has_red_dot'  => $individual->has_red_dot ?? false,
            ],
        ]);
    }

    /**
     * Assignation d'un agent de recouvrement pour un dossier en souffrance
     */
    public function assignCollector(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'collector_id'   => 'required|integer|exists:users,id',
            'collector_type' => 'nullable|string|in:user,organisation,branch',
        ]);

        $credit = Credit::findOrFail($id);
        $credit->assigned_collector_id = $validated['collector_id'];
        $credit->assigned_collector_type = $validated['collector_type'] ?? 'user';
        $credit->has_red_dot = true;
        $credit->save();

        return response()->json([
            'message' => 'Agent de recouvrement assigné avec succès.',
            'credit'  => $credit,
        ]);
    }

    /**
     * Recouvrement d'une pénalité avec distribution : 20% agent, 30% structure, 50% centre
     */
    public function recoverPenalty(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'penalty_amount'    => 'required|numeric|min:1',
            'collector_user_id' => 'required|integer|exists:users,id',
        ]);

        $credit = Credit::findOrFail($id);
        $penalty = (float) $validated['penalty_amount'];
        $collectorId = (int) $validated['collector_user_id'];

        $bonuses = BonusCalculationService::distributePenaltyRecovery($credit, $penalty, $collectorId, $request->user()->id ?? null);

        return response()->json([
            'message' => 'Pénalité recouvrée et commissions distribuées (20% agent, 30% structure, 50% centre GEP) !',
            'bonuses' => $bonuses,
        ]);
    }

    /**
     * Vue admin complète : crédits classifiés par statut et regroupés par mois de retard
     * GET /api/credits/admin-overview
     */
    public function adminOverview(Request $request): JsonResponse
    {
        $branchId = $request->query('branch_id');

        $query = Credit::with([
            'user:id,name,phone,email',
            'branch:id,name',
            'targetBranch:id,name',
            'targetOrganisation:id,name,type',
            'guarantors.guarantorUser:id,name,phone',
            'guarantors.organisation:id,name',
            'assignedCollector:id,name,phone',
            'disbursedByOperator:id,name',
            'schedules',
        ])->whereIn('status', ['active', 'in_arrears']);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $credits = $query->get();

        $now = Carbon::now();
        $soonDays = 14; // Échéance dans les 14 prochains jours = "proche"

        $enCours      = [];
        $echeancesProches = [];
        $enRetard     = [];
        $critique     = []; // clé = nombre de mois de retard (1..12, "12_plus")

        foreach ($credits as $credit) {
            $schedules = $credit->schedules ?? collect();

            // Compter les échéances en retard (overdue)
            $overdueSchedules = $schedules->where('status', 'overdue');
            $overdueCount = $overdueSchedules->count();

            // Calculer le retard en mois (basé sur la première échéance overdue)
            $firstOverdue = $overdueSchedules->sortBy('due_date')->first();
            $retardMois = 0;
            if ($firstOverdue) {
                $dueDate = Carbon::parse($firstOverdue->due_date);
                $retardMois = max(1, (int) $dueDate->diffInMonths($now, true));
            }

            // Prochaine échéance pending
            $nextPending = $schedules->where('status', 'pending')->sortBy('due_date')->first();
            $nextDueDate = $nextPending ? Carbon::parse($nextPending->due_date) : null;
            $daysUntilDue = $nextDueDate ? (int) round($now->diffInDays($nextDueDate, false)) : null;

            // Déterminer le nom et le libellé du bénéficiaire
            $bType = $credit->beneficiary_type ?? 'individual';
            $bName = '-';
            $bPhone = '-';
            if ($bType === 'branch') {
                $bName = 'Succursale: ' . ($credit->targetBranch->name ?? $credit->branch->name ?? '-');
            } elseif ($bType === 'organisation') {
                $bName = 'Organisation: ' . ($credit->targetOrganisation->name ?? '-');
            } else {
                $bName = $credit->user->name ?? '-';
                $bPhone = $credit->user->phone ?? '-';
            }

            // Liste des parrains avec leurs pourcentages
            $guarantorsList = $credit->guarantors->map(function ($g) {
                return [
                    'id'                    => $g->id,
                    'guarantor_type'        => $g->guarantor_type,
                    'name'                  => $g->guarantorUser->name ?? $g->organisation->name ?? 'Parrain',
                    'guaranteed_percentage' => $g->guaranteed_percentage,
                    'guaranteed_amount'     => $g->guaranteed_amount,
                ];
            })->values();

            $creditData = [
                'id'                     => $credit->id,
                'credit_number'          => $credit->credit_number,
                'beneficiary_type'       => $bType,
                'user_id'                => $credit->user_id,
                'user_name'              => $bName,
                'user_phone'             => $bPhone,
                'target_branch_id'       => $credit->target_branch_id,
                'target_organisation_id' => $credit->target_organisation_id,
                'branch_id'              => $credit->branch_id,
                'branch_name'            => $credit->branch->name ?? '-',
                'principal_amount'       => $credit->principal_amount,
                'remaining_balance'      => $credit->remaining_balance,
                'total_amount_due'       => $credit->total_amount_due,
                'total_amount_paid'      => $credit->total_amount_paid,
                'duration_months'        => $credit->duration_months,
                'disbursement_date'      => $credit->disbursement_date,
                'final_due_date'         => $credit->final_due_date,
                'next_due_date'          => $nextDueDate?->toDateString(),
                'days_until_due'         => $daysUntilDue,
                'overdue_count'          => $overdueCount,
                'retard_mois'            => $retardMois,
                'status'                 => $credit->status,
                'credit_stars'           => $credit->credit_stars,
                'has_red_dot'            => $credit->has_red_dot,
                'guarantors'             => $guarantorsList,
                'structure_percentage'   => (float) \App\Services\FinancialRuleService::get('recouvrement_part_structure_pct', 30.0),
                'agent_suivi_percentage' => (float) \App\Services\FinancialRuleService::get('recouvrement_part_agent_pct', 20.0),
                'centre_gep_percentage'  => (float) \App\Services\FinancialRuleService::get('recouvrement_part_centre_pct', 50.0),
                'agent_suivi_name'       => $credit->assignedCollector->name ?? $credit->disbursedByOperator->name ?? 'Agent GEP',
                'agent_suivi_phone'      => $credit->assignedCollector->phone ?? null,
            ];


            if ($overdueCount === 0) {
                // Pas de retard
                if ($daysUntilDue !== null && $daysUntilDue >= 0 && $daysUntilDue <= $soonDays) {
                    $echeancesProches[] = $creditData;
                } else {
                    $enCours[] = $creditData;
                }
            } elseif ($retardMois < 1) {
                // Moins d'1 mois de retard = En retard simple
                $enRetard[] = $creditData;
            } elseif ($retardMois >= 1 && $retardMois <= 12) {
                // 1 à 12 mois de retard = Critique groupé
                $key = "{$retardMois}_mois";
                $critique[$key][] = $creditData;
            } else {
                // Plus de 12 mois = Critique max
                $critique['12_plus'][] = $creditData;
            }
        }

        // Trier les clés critique dans l'ordre numérique
        ksort($critique);

        return response()->json([
            'summary' => [
                'total_credits'           => $credits->count(),
                'en_cours_count'          => count($enCours),
                'echeances_proches_count' => count($echeancesProches),
                'en_retard_count'         => count($enRetard),
                'critique_count'          => collect($critique)->flatten(1)->count(),
            ],
            'en_cours'          => $enCours,
            'echeances_proches' => $echeancesProches,
            'en_retard'         => $enRetard,
            'critique'          => empty($critique) ? (object) [] : $critique,
        ]);
    }

    /**
     * Dépôt d'une demande de crédit pour l'un des 3 types : solidaire, express, special
     * POST /api/credits/apply
     */
    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credit_type'            => 'required|string|in:solidaire,express,special',
            'beneficiary_type'       => 'nullable|string|in:individual,branch,organisation',
            'user_id'                => 'nullable|integer|exists:users,id',
            'target_branch_id'       => 'nullable|integer|exists:branches,id',
            'target_organisation_id' => 'nullable|integer|exists:organisations,id',
            'requested_amount'       => 'required|numeric|min:10',
            'duration_months'        => 'required|integer|between:1,36',
            'interest_rate'          => 'nullable|numeric|min:0',
            'purpose'                => 'nullable|string',
            'branch_id'              => 'nullable|integer|exists:branches,id',
            'overdraft_grace_days'   => 'nullable|integer|min:0',
            'overdraft_penalty_pct'  => 'nullable|numeric|min:0',
        ]);

        $userId = $validated['user_id'] ?? $request->user()->id;
        $validated['user_id'] = $userId;
        $operatorId = $request->user()->id ?? $userId;

        try {
            $service = app(\App\Services\CreditWorkflowService::class);
            $credit = $service->createApplication($validated['credit_type'], $validated, $operatorId);

            return response()->json([
                'status'  => true,
                'message' => "Demande de crédit ({$validated['credit_type']}) enregistrée avec succès.",
                'credit'  => $credit->load(['guarantors.guarantorUser', 'guarantors.organisation']),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['status' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['status' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Ajouter un parrain au crédit
     * POST /api/credits/{id}/guarantors
     */
    public function addGuarantor(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'guarantor_user_id'     => 'nullable|integer|exists:users,id',
            'organisation_id'       => 'nullable|integer|exists:organisations,id',
            'guarantor_role'        => 'nullable|string|in:peer,org_default,centre_gep',
            'guaranteed_percentage' => 'required|numeric|min:1|max:100',
            'notes'                 => 'nullable|string',
        ]);

        $credit = Credit::findOrFail($id);

        try {
            $service = app(\App\Services\CreditWorkflowService::class);
            $guarantor = $service->addGuarantor($credit, $validated);

            return response()->json([
                'status'    => true,
                'message'   => 'Parrain enregistré avec succès.',
                'guarantor' => $guarantor->load(['guarantorUser', 'organisation']),
                'credit'    => $credit->fresh(['guarantors.guarantorUser', 'guarantors.organisation']),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['status' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['status' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Un parrain approuve sa garantie
     * POST /api/credits/{id}/guarantors/{gid}/approve
     */
    public function approveGuarantor(Request $request, int $id, int $gid): JsonResponse
    {
        $guarantor = CreditGuarantor::where('credit_id', $id)->findOrFail($gid);
        $userId = $request->user()->id ?? 1;

        $service = app(\App\Services\CreditWorkflowService::class);
        $guarantor = $service->approveGuarantor($guarantor, $userId);

        return response()->json([
            'status'    => true,
            'message'   => 'Garantie approuvée avec succès.',
            'guarantor' => $guarantor,
            'credit'    => Credit::with(['guarantors.guarantorUser', 'guarantors.organisation'])->find($id),
        ]);
    }

    /**
     * Un parrain refuse sa garantie
     * POST /api/credits/{id}/guarantors/{gid}/reject
     */
    public function rejectGuarantor(Request $request, int $id, int $gid): JsonResponse
    {
        $guarantor = CreditGuarantor::where('credit_id', $id)->findOrFail($gid);
        $userId = $request->user()->id ?? 1;
        $reason = $request->input('reason', 'Refus de parrainage');

        $service = app(\App\Services\CreditWorkflowService::class);
        $guarantor = $service->rejectGuarantor($guarantor, $userId, $reason);

        return response()->json([
            'status'    => true,
            'message'   => 'Garantie refusée.',
            'guarantor' => $guarantor,
            'credit'    => Credit::with(['guarantors.guarantorUser', 'guarantors.organisation'])->find($id),
        ]);
    }

    /**
     * Soumettre au financier
     * POST /api/credits/{id}/submit-to-financier
     */
    public function submitToFinancier(Request $request, int $id): JsonResponse
    {
        $credit = Credit::with('guarantors')->findOrFail($id);

        try {
            $service = app(\App\Services\CreditWorkflowService::class);
            $credit = $service->submitToFinancier($credit);

            return response()->json([
                'status'  => true,
                'message' => 'Dossier transmis au financier pour délibération.',
                'credit'  => $credit->load(['guarantors.guarantorUser', 'guarantors.organisation']),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['status' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Approbation par le financier
     * POST /api/credits/{id}/financier-approve
     */
    public function financierApprove(Request $request, int $id): JsonResponse
    {
        $credit = Credit::with('guarantors')->findOrFail($id);
        $financierUserId = $request->user()->id ?? 1;
        $notes = $request->input('notes', 'Approuvé par délibération financière');

        try {
            $service = app(\App\Services\CreditWorkflowService::class);
            $credit = $service->financierApprove($credit, $financierUserId, $notes);

            return response()->json([
                'status'  => true,
                'message' => 'Crédit approuvé par le financier avec succès !',
                'credit'  => $credit,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Rejet par le financier
     * POST /api/credits/{id}/financier-reject
     */
    public function financierReject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:3',
        ]);

        $credit = Credit::findOrFail($id);
        $financierUserId = $request->user()->id ?? 1;

        $service = app(\App\Services\CreditWorkflowService::class);
        $credit = $service->financierReject($credit, $financierUserId, $validated['reason']);

        return response()->json([
            'status'  => true,
            'message' => 'Crédit rejeté par le financier.',
            'credit'  => $credit,
        ]);
    }

    /**
     * Décaissement effectif
     * POST /api/credits/{id}/disburse
     */
    public function disburse(Request $request, int $id): JsonResponse
    {
        $credit = Credit::with('guarantors')->findOrFail($id);
        $operatorId = $request->user()->id ?? 1;

        try {
            $service = app(\App\Services\CreditWorkflowService::class);
            $credit = $service->disburse($credit, $operatorId);

            return response()->json([
                'status'  => true,
                'message' => 'Crédit décaissé avec succès et versé sur le compte ordinaire de l\'entrepreneur.',
                'credit'  => $credit,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['status' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['status' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Pardonner / annuler les pénalités et amendes de retard (ex: Crédit Express)
     * POST /api/credits/{id}/waive-penalties
     */
    public function waivePenalties(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:3',
        ]);

        $credit = Credit::with('schedules')->findOrFail($id);
        $operatorId = $request->user()->id ?? 1;

        $service = app(\App\Services\CreditWorkflowService::class);
        $credit = $service->waivePenalties($credit, $operatorId, $validated['reason']);

        return response()->json([
            'status'  => true,
            'message' => 'Pénalités et amendes de retard effacées avec succès.',
            'credit'  => $credit,
        ]);
    }

    /**
     * Résumé des parrains d'un crédit
     * GET /api/credits/{id}/guarantor-summary
     */
    public function guarantorSummary(Request $request, int $id): JsonResponse
    {
        $credit = Credit::with(['guarantors.guarantorUser', 'guarantors.organisation'])->findOrFail($id);

        $guarantors = $credit->guarantors->map(function ($g) {
            return [
                'id'                    => $g->id,
                'role'                  => $g->guarantor_role,
                'type'                  => $g->guarantor_type,
                'name'                  => $g->guarantorUser->name ?? $g->organisation->name ?? 'Centre GEP',
                'guaranteed_percentage' => $g->guaranteed_percentage,
                'guaranteed_amount'     => $g->guaranteed_amount,
                'approval_status'       => $g->approval_status,
                'approved_at'           => $g->approved_at,
                'interest_share_pct'    => $g->interest_share_pct,
                'interest_earned'       => $g->interest_earned,
                'rejection_reason'      => $g->rejection_reason,
            ];
        });

        $totalApprovedPct = $credit->guarantors->where('approval_status', 'approved')->sum('guaranteed_percentage');

        return response()->json([
            'credit_id'          => $credit->id,
            'credit_number'      => $credit->credit_number,
            'credit_type'        => $credit->credit_type,
            'approval_status'    => $credit->approval_status,
            'total_approved_pct' => $totalApprovedPct,
            'guarantors'         => $guarantors,
        ]);
    }

    /**
     * Liste des crédits en attente d'approbation pour le financier
     * GET /api/credits/pending-approval
     */
    public function pendingApproval(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending_financier');

        $query = Credit::with([
            'user:id,name,phone,email',
            'branch:id,name',
            'targetOrganisation:id,name',
            'guarantors.guarantorUser:id,name,phone',
            'guarantors.organisation:id,name',
        ])->orderBy('id', 'desc');

        if ($status === 'all_pending') {
            $query->whereIn('approval_status', ['pending_guarantors', 'pending_org', 'pending_financier']);
        } else {
            $query->where('approval_status', $status);
        }

        $credits = $query->paginate($request->query('per_page', 50));
        return response()->json($credits);
    }

    /**
     * Liste des crédits publiés ou en cours de campagne de parrainage
     * GET /api/credits/published
     */
    public function published(Request $request): JsonResponse
    {
        $query = Credit::with([
            'user:id,name,phone,email',
            'branch:id,name',
            'targetOrganisation:id,name',
            'guarantors.guarantorUser:id,name,phone',
            'guarantors.organisation:id,name',
        ])->orderBy('id', 'desc');

        if ($request->has('credit_type') && !empty($request->query('credit_type'))) {
            $query->where('credit_type', $request->query('credit_type'));
        }

        // On inclut les crédits en cours d'approbation / parrainage ou actifs
        $query->whereIn('approval_status', [
            'draft',
            'pending_guarantors',
            'pending_org',
            'pending_financier',
            'approved',
            'disbursed'
        ]);

        $credits = $query->paginate($request->query('per_page', 50));
        return response()->json($credits);
    }
}

