<?php

namespace App\Services;

use App\Models\OrdinaryAccount;
use App\Models\OrdinaryAccountTransaction;
use App\Models\SystemLedgerEntry;
use App\Models\Deposit;
use App\Models\Cycle;
use App\Models\Individual;
use App\Models\Credit;
use App\Models\CreditSchedule;
use App\Models\CreditPayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrdinaryAccountService
{
    /**
     * Dépôt sur le compte ordinaire
     */
    public function deposit(
        OrdinaryAccount $account,
        float $amount,
        string $paymentMethod = 'cash',
        ?string $description = null,
        ?int $operatorId = null,
        string $transactionType = 'deposit'
    ): OrdinaryAccountTransaction {
        return DB::transaction(function () use ($account, $amount, $paymentMethod, $description, $operatorId, $transactionType) {
            $account->refresh();

            $balanceBefore = (float) $account->balance;
            $balanceAfter  = round($balanceBefore + $amount, 2);

            $account->balance = $balanceAfter;
            // Si le compte redevient positif ou nul, réinitialiser la date de négatif
            if ($balanceAfter >= 0) {
                $account->negative_since = null;
            }
            $account->save();

            $reference = 'TX-ORD-' . date('Ymd') . '-' . strtoupper(Str::random(6));

            $tx = OrdinaryAccountTransaction::create([
                'ordinary_account_id' => $account->id,
                'transaction_type'    => $transactionType,
                'direction'           => 'credit',
                'amount'              => $amount,
                'balance_before'      => $balanceBefore,
                'balance_after'       => $balanceAfter,
                'description'         => $description ?? 'Dépôt sur compte ordinaire',
                'reference'           => $reference,
                'payment_method'      => $paymentMethod,
                'operator_id'         => $operatorId,
            ]);

            // Synchronisation miroir avec le Grand Livre de Caisse (Système Ledger)
            SystemLedgerEntry::create([
                'type'           => 'produit',
                'source'         => 'auto',
                'category'       => $transactionType === 'deposit' ? 'deposit' : 'custom',
                'title'          => "Dépôt Compte Ordinaire #{$account->id}",
                'description'    => $description ?? "Dépôt de {$amount}$ sur compte ordinaire",
                'amount'         => $amount,
                'payment_method' => $paymentMethod,
                'reference_type' => OrdinaryAccountTransaction::class,
                'reference_id'   => $tx->id,
                'status'         => 'validated',
                'created_by'     => $operatorId,
            ]);

            // Clôture éventuelle de cycle de découvert
            if ($balanceBefore < 0 && $balanceAfter >= 0) {
                app(\App\Services\OverdraftTrackingService::class)->markTransactionIfOverdraft($account, $tx);
            }

            return $tx;
        });
    }

    /**
     * Retrait depuis le compte ordinaire
     */
    public function withdraw(
        OrdinaryAccount $account,
        float $amount,
        string $paymentMethod = 'cash',
        ?string $description = null,
        ?int $operatorId = null,
        string $transactionType = 'withdrawal'
    ): OrdinaryAccountTransaction {
        return DB::transaction(function () use ($account, $amount, $paymentMethod, $description, $operatorId, $transactionType) {
            $account->refresh();

            $balanceBefore = (float) $account->balance;
            $balanceAfter  = round($balanceBefore - $amount, 2);

            $account->balance = $balanceAfter;
            // Si le compte passe en négatif et n'était pas encore marqué négatif
            if ($balanceAfter < 0 && $account->negative_since === null) {
                $account->negative_since = now();
            }
            $account->save();

            $reference = 'TX-ORD-' . date('Ymd') . '-' . strtoupper(Str::random(6));

            $tx = OrdinaryAccountTransaction::create([
                'ordinary_account_id' => $account->id,
                'transaction_type'    => $transactionType,
                'direction'           => 'debit',
                'amount'              => $amount,
                'balance_before'      => $balanceBefore,
                'balance_after'       => $balanceAfter,
                'description'         => $description ?? 'Retrait du compte ordinaire',
                'reference'           => $reference,
                'payment_method'      => $paymentMethod,
                'operator_id'         => $operatorId,
            ]);

            // Synchronisation miroir avec le Grand Livre de Caisse
            SystemLedgerEntry::create([
                'type'           => 'charge',
                'source'         => 'auto',
                'category'       => $transactionType === 'withdrawal' ? 'withdrawal' : 'custom',
                'title'          => "Retrait Compte Ordinaire #{$account->id}",
                'description'    => $description ?? "Retrait de {$amount}$ du compte ordinaire",
                'amount'         => $amount,
                'payment_method' => $paymentMethod,
                'reference_type' => OrdinaryAccountTransaction::class,
                'reference_id'   => $tx->id,
                'status'         => 'validated',
                'created_by'     => $operatorId,
            ]);

            // Traçabilité du découvert & mois d'ancienneté sous négatif
            app(\App\Services\OverdraftTrackingService::class)->markTransactionIfOverdraft($account, $tx);

            return $tx;
        });
    }

    /**
     * Déduction automatique de la caution solidaire pour un cycle actif
     * Se soustrait du compte ordinaire chaque mois selon la date du cycle
     */
    public function deductCycleCaution(int $cycleId, ?int $individualId = null): array
    {
        $cycle = Cycle::findOrFail($cycleId);
        $autoDeductEnabled = FinancialRuleService::get('compte_ordinaire_prelevement_auto_caution', true);

        if (!$autoDeductEnabled) {
            return ['status' => false, 'message' => 'Le prélèvement automatique de caution est désactivé.'];
        }

        $processed = [];

        // ── 1. Cas d'un cycle dédié à une entité spécifique (individual, organisation, branch) ──
        if (!empty($cycle->entity_type) && !empty($cycle->entity_id)) {
            $entityType = $cycle->entity_type;
            $entityId   = $cycle->entity_id;
            $userId     = $cycle->user_id;
            $branchId   = $cycle->branch_id;

            $account = OrdinaryAccount::where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->first();

            if (!$account && $userId) {
                $account = OrdinaryAccount::where('user_id', $userId)->first();
            }

            if (!$account) {
                $account = OrdinaryAccount::getOrCreateForEntity($entityType, $entityId, $userId);
            }

            $cautionAmount = (float) ($cycle->montant_cotisation_mensuelle ?: 50.00);

            // Déduction du compte ordinaire (autorise solde négatif)
            $tx = $this->withdraw(
                $account,
                $cautionAmount,
                'auto_debit',
                "Prélèvement mensuel automatique caution solidaire - {$cycle->name}",
                null,
                'cotisation_solidaire'
            );

            // Enregistrement dans les dépôts de caution du cycle
            $depositRef = 'DEP-' . strtoupper(Str::random(10));
            $deposit = Deposit::create([
                'deposit_reference'  => $depositRef,
                'user_id'            => $userId,
                'branch_id'          => $branchId,
                'operator_id'        => $cycle->opened_by_user_id ?? $userId ?? 1,
                'cycle_id'           => $cycle->id,
                'entity_type'        => $entityType,
                'expected_amount'    => $cautionAmount,
                'actual_amount'      => $cautionAmount,
                'expected_date'      => now()->toDateString(),
                'actual_date'        => now()->toDateString(),
                'delay_days'         => 0,
                'is_on_time'         => true,
                'payment_method'     => 'auto_debit',
                'payment_reference'  => $tx->reference,
                'wallet_destination' => 'caution',
                'remarks'            => "Prélèvement automatique compte ordinaire - {$cycle->name}",
            ]);

            // Mettre à jour le cycle (total_deposited + auto_deposit_next_date)
            $nextDate = $cycle->auto_deposit_next_date
                ? Carbon::parse($cycle->auto_deposit_next_date)->addMonth()
                : now()->addMonth();

            $cycle->update([
                'total_deposited'        => round((float) $cycle->total_deposited + $cautionAmount, 2),
                'auto_deposit_next_date'  => $nextDate->toDateString(),
            ]);

            // Attribution immédiate du bonus sur caution solidaire
            if ($userId) {
                try {
                    \App\Services\DepositService::checkAndGrantPendingCautionBonuses($userId);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Erreur attribution bonus caution après déduction auto: " . $e->getMessage());
                }
            }

            $processed[] = [
                'entity_type'   => $entityType,
                'entity_id'     => $entityId,
                'account_id'    => $account->id,
                'amount'        => $cautionAmount,
                'new_balance'   => $account->balance,
                'is_negative'   => $account->balance < 0,
                'reference'     => $tx->reference,
            ];

            return [
                'status'    => true,
                'message'   => "Prélèvement de caution ({$cautionAmount} $) effectué avec succès pour le cycle {$cycle->name}.",
                'processed' => $processed,
            ];
        }

        // ── 2. Cas hérité : cycle global avec participants individuels ─────────
        $query = Individual::query();
        if ($individualId) {
            $query->where('id', $individualId);
        } else {
            $query->where('status', 'active');
        }

        $individuals = $query->get();

        foreach ($individuals as $ind) {
            $account = OrdinaryAccount::getOrCreateForEntity('individual', $ind->id, $ind->user_id);

            $lastDeposit = Deposit::where('user_id', $ind->user_id)
                ->where('cycle_id', $cycle->id)
                ->where('entity_type', 'individual')
                ->orderBy('created_at', 'desc')
                ->first();

            $cautionAmount = $lastDeposit
                ? (float) ($lastDeposit->actual_amount ?? $lastDeposit->expected_amount ?? 50.00)
                : 50.00;

            $tx = $this->withdraw(
                $account,
                $cautionAmount,
                'internal',
                "Prélèvement mensuel automatique caution solidaire - Cycle {$cycle->name}",
                null,
                'caution_payment'
            );

            $depositRef = 'DEP-' . strtoupper(Str::random(10));
            Deposit::create([
                'deposit_reference'  => $depositRef,
                'user_id'            => $ind->user_id,
                'branch_id'          => $ind->branch_id,
                'operator_id'        => $cycle->opened_by_user_id ?? $ind->user_id ?? 1,
                'cycle_id'           => $cycle->id,
                'entity_type'        => 'individual',
                'expected_amount'    => $cautionAmount,
                'actual_amount'      => $cautionAmount,
                'expected_date'      => now()->toDateString(),
                'actual_date'        => now()->toDateString(),
                'delay_days'         => 0,
                'is_on_time'         => true,
                'payment_method'     => 'internal',
                'payment_reference'  => $tx->reference,
                'wallet_destination' => 'caution',
                'remarks'            => "Prélèvement automatique compte ordinaire - Cycle {$cycle->name}",
            ]);

            $processed[] = [
                'individual_id'  => $ind->id,
                'account_id'     => $account->id,
                'amount'         => $cautionAmount,
                'new_balance'    => $account->balance,
                'is_negative'    => $account->balance < 0,
                'reference'      => $tx->reference,
            ];
        }

        return [
            'status'    => true,
            'message'   => count($processed) . ' prélèvement(s) de caution solidaire effectué(s).',
            'processed' => $processed,
        ];
    }

    /**
     * Calcul et application dynamique des intérêts débiteurs sur les comptes en négatif
     * S'applique après un délai de grâce configurable (par défaut: 3 jours)
     */
    public function applyOverdraftDebitInterests(): array
    {
        $graceDays = (int) FinancialRuleService::get('compte_ordinaire_decouvert_delai_grace_jours', 3);
        $ratePct   = (float) FinancialRuleService::get('compte_ordinaire_decouvert_interet_pct', 2.0);

        // Trouver tous les comptes avec solde négatif
        $negativeAccounts = OrdinaryAccount::where('balance', '<', 0)->get();
        $charged = [];

        foreach ($negativeAccounts as $account) {
            // Si negative_since est null, l'initialiser
            if (!$account->negative_since) {
                $account->negative_since = now();
                $account->save();
                continue;
            }

            $daysInNegative = Carbon::now()->diffInDays($account->negative_since);

            // Vérifier si le délai de grâce (ex: 3 jours) est dépassé
            if ($daysInNegative >= $graceDays) {
                // Vérifier qu'on n'a pas déjà facturé aujourd'hui
                if ($account->last_interest_calculated_at && Carbon::parse($account->last_interest_calculated_at)->isToday()) {
                    continue;
                }

                $overdraftAmount = abs((float) $account->balance);
                $interestAmount  = round($overdraftAmount * ($ratePct / 100), 2);

                if ($interestAmount > 0) {
                    $balanceBefore = (float) $account->balance;
                    $balanceAfter  = round($balanceBefore - $interestAmount, 2);

                    $account->balance = $balanceAfter;
                    $account->total_debit_interest_charged = round(((float) $account->total_debit_interest_charged) + $interestAmount, 2);
                    $account->last_interest_calculated_at = now();
                    $account->save();

                    $reference = 'TX-INT-' . date('Ymd') . '-' . strtoupper(Str::random(6));

                    $tx = OrdinaryAccountTransaction::create([
                        'ordinary_account_id' => $account->id,
                        'transaction_type'    => 'debit_interest',
                        'direction'           => 'debit',
                        'amount'              => $interestAmount,
                        'balance_before'      => $balanceBefore,
                        'balance_after'       => $balanceAfter,
                        'description'         => "Intérêt débiteur ({$ratePct}%) sur solde négatif ({$overdraftAmount}$) après {$daysInNegative}j de retard",
                        'reference'           => $reference,
                        'payment_method'      => 'internal',
                    ]);

                    // Produit pour l'institution dans le Grand Livre
                    SystemLedgerEntry::create([
                        'type'           => 'produit',
                        'source'         => 'auto',
                        'category'       => 'debit_interest',
                        'title'          => "Intérêt débiteur Compte #{$account->id}",
                        'description'    => "Intérêt de {$interestAmount}$ pour {$daysInNegative}j de découvert",
                        'amount'         => $interestAmount,
                        'payment_method' => 'internal',
                        'reference_type' => OrdinaryAccountTransaction::class,
                        'reference_id'   => $tx->id,
                        'status'         => 'validated',
                    ]);

                    $charged[] = [
                        'account_id'       => $account->id,
                        'entity_type'      => $account->entity_type,
                        'entity_id'        => $account->entity_id,
                        'overdraft_amount' => $overdraftAmount,
                        'interest_charged' => $interestAmount,
                        'days_in_negative' => $daysInNegative,
                        'new_balance'      => $account->balance,
                    ];
                }
            }
        }

        return [
            'status'     => true,
            'message'    => count($charged) . ' compte(s) débité(s) d\'intérêts débiteurs.',
            'grace_days' => $graceDays,
            'rate_pct'   => $ratePct,
            'charged'    => $charged,
        ];
    }

    /**
     * Traitement automatique des échéances de crédits arrivées à terme
     * Déduit automatiquement le remboursement depuis le compte ordinaire
     * et applique la pénalité après 3 jours de retard
     */
    public function processCreditRepayments(): array
    {
        $graceDays  = (int) FinancialRuleService::get('credit_echeance_delai_grace_jours', 3);
        $penaltyPct = (float) FinancialRuleService::get('credit_echeance_penalite_pct', 1.0);

        // Échéances dues ou en retard
        $schedules = CreditSchedule::where('status', 'pending')
            ->where('due_date', '<=', now()->toDateString())
            ->with(['credit'])
            ->get();

        $processed = [];

        foreach ($schedules as $sched) {
            $credit = $sched->credit;
            if (!$credit || !$credit->user_id) continue;

            // Rechercher l'individual lié à ce user (s'il en existe un)
            $individual = Individual::where('user_id', $credit->user_id)->first();
            $entityType = $individual ? 'individual' : 'user';
            $entityId   = $individual ? $individual->id : $credit->user_id;

            $account = OrdinaryAccount::getOrCreateForEntity($entityType, $entityId, $credit->user_id);
            $dueAmount = (float) $sched->total_due;

            $daysLate = Carbon::parse($sched->due_date)->diffInDays(now(), false);

            $penaltyAmount = 0.0;
            if ($daysLate >= $graceDays) {
                $penaltyAmount = round($dueAmount * ($penaltyPct / 100), 2);
            }

            $totalDeduct = round($dueAmount + $penaltyAmount, 2);

            // Prélèvement sur le compte ordinaire
            $tx = $this->withdraw(
                $account,
                $totalDeduct,
                'internal',
                "Remboursement échéance #{$sched->installment_number} Crédit #{$credit->id}" . ($penaltyAmount > 0 ? " (dont pénalité retard {$penaltyAmount}$)" : ""),
                null,
                'credit_repayment'
            );

            // Mettre à jour l'échéancier (champs réels: paid_date, penalty_due)
            $sched->status     = 'paid';
            $sched->paid_date  = now()->toDateString();
            $sched->amount_paid = $dueAmount;
            $sched->penalty_due = ($sched->penalty_due ?? 0) + $penaltyAmount;
            $sched->save();

            // Si le prélèvement a eu lieu sur un compte en découvert, appliquer le malus aux parrains
            if ($tx->is_funded_by_overdraft || $account->balance < 0) {
                MalusService::applyGuarantorOverdraftRepaymentMalus($credit, $sched, $totalDeduct);
            }

            $processed[] = [
                'credit_id'      => $credit->id,
                'installment_no' => $sched->installment_number,
                'amount_paid'    => $totalDeduct,
                'penalty'        => $penaltyAmount,
                'account_id'     => $account->id,
                'new_balance'    => $account->balance,
            ];
        }

        return [
            'status'    => true,
            'message'   => count($processed) . ' échéance(s) de crédit traitée(s).',
            'processed' => $processed,
        ];
    }
}
