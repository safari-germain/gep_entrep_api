<?php

namespace App\Services;

use App\Models\Credit;
use App\Models\CreditGuarantor;
use App\Models\CreditCoverageCall;
use App\Models\CreditCoverageSettlement;
use App\Models\OrdinaryAccount;
use App\Models\OrdinaryAccountTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreditCoverageService
{
    /**
     * Déclenche la couverture pour tous les crédits en défaut depuis ≥ 6 mois.
     * Crée les CreditCoverageCall pour chaque parrain concerné.
     * Appelée quotidiennement par le scheduler.
     */
    public function triggerCoverageForDefaultedCredits(): array
    {
        $results = ['triggered' => [], 'skipped' => [], 'errors' => []];

        $sixMonthsAgo = Carbon::now()->subMonths(6);

        // Crédits actifs ou en souffrance sans couverture déclenchée,
        // dont le dernier paiement remonte à plus de 6 mois
        $defaultedCredits = Credit::whereIn('status', ['active', 'in_arrears'])
            ->where(function ($q) use ($sixMonthsAgo) {
                $q->whereNull('coverage_triggered_at')
                  ->where(function ($inner) use ($sixMonthsAgo) {
                      $inner->whereNull('defaulted_at')
                            ->orWhere('defaulted_at', '<=', $sixMonthsAgo);
                  });
            })
            ->where('remaining_balance', '>', 0)
            ->get();

        foreach ($defaultedCredits as $credit) {
            try {
                // Vérifier la date du dernier paiement
                $lastPayment = DB::table('credit_schedules')
                    ->where('credit_id', $credit->id)
                    ->where('status', 'paid')
                    ->orderByDesc('paid_date')
                    ->value('paid_date');

                $lastActivity = $lastPayment
                    ? Carbon::parse($lastPayment)
                    : ($credit->defaulted_at ? Carbon::parse($credit->defaulted_at) : Carbon::parse($credit->created_at));

                if ($lastActivity->gt($sixMonthsAgo)) {
                    $results['skipped'][] = "Crédit #{$credit->id}: dernier paiement récent ({$lastActivity->toDateString()})";
                    continue;
                }

                DB::transaction(function () use ($credit, &$results) {
                    // Marquer le crédit comme en défaut couvert
                    $credit->coverage_triggered_at = Carbon::now();
                    if (!$credit->defaulted_at) {
                        $credit->defaulted_at = Carbon::now();
                    }
                    $credit->coverage_status = 'triggered';
                    $credit->save();

                    // Récupérer les parrains approuvés (hors centre_gep)
                    $guarantors = $credit->guarantors()
                        ->where('approval_status', 'approved')
                        ->where('guarantor_role', '!=', 'centre_gep')
                        ->get();

                    foreach ($guarantors as $guarantor) {
                        // Montant de couverture au prorata
                        $coverageAmount = round(
                            $credit->remaining_balance * ($guarantor->guaranteed_percentage / 100),
                            2
                        );

                        if ($coverageAmount <= 0) continue;

                        // Créer l'appel en couverture
                        $call = CreditCoverageCall::create([
                            'credit_id'           => $credit->id,
                            'credit_guarantor_id' => $guarantor->id,
                            'guarantor_user_id'   => $guarantor->guarantor_user_id,
                            'organisation_id'     => $guarantor->organisation_id,
                            'called_amount'       => $coverageAmount,
                            'remaining_liability' => $coverageAmount,
                            'status'              => 'pending',
                            'triggered_at'        => Carbon::now(),
                        ]);

                        // Mettre à jour le garant
                        $guarantor->coverage_called_amount = $coverageAmount;
                        $guarantor->coverage_status = 'called';
                        $guarantor->save();

                        // Bloquer le compte ordinaire du parrain (restriction)
                        if ($guarantor->guarantor_user_id) {
                            $oa = OrdinaryAccount::where('user_id', $guarantor->guarantor_user_id)->first();
                            if ($oa) {
                                $oa->has_coverage_restriction = true;
                                $oa->coverage_debt_amount = ($oa->coverage_debt_amount ?? 0) + $coverageAmount;
                                $oa->save();
                            }
                        }
                    }

                    $results['triggered'][] = "Crédit #{$credit->id}: couverture déclenchée pour " . count($guarantors) . " parrains";
                });
            } catch (\Exception $e) {
                $results['errors'][] = "Crédit #{$credit->id}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Règle (partiellement ou totalement) un appel en couverture
     * en déduisant du compte ordinaire du parrain.
     * Le compte peut passer en négatif.
     */
    public function settleFromOrdinaryAccount(
        CreditCoverageCall $call,
        float $amount,
        int $operatorId
    ): CreditCoverageSettlement {
        return DB::transaction(function () use ($call, $amount, $operatorId) {
            if ($call->status === 'settled') {
                throw new \Exception("Cet appel en couverture est déjà soldé.");
            }

            $amount = min($amount, $call->remaining_liability);

            // Trouver le compte ordinaire du parrain
            $oa = null;
            if ($call->guarantor_user_id) {
                $oa = OrdinaryAccount::where('user_id', $call->guarantor_user_id)->first();
            }

            $tx = null;
            if ($oa) {
                $balanceBefore = (float) $oa->balance;
                $balanceAfter = round($balanceBefore - $amount, 2);

                $oa->balance = $balanceAfter;
                if ($balanceAfter < 0 && $oa->negative_since === null) {
                    $oa->negative_since = now();
                }
                $oa->save();

                $reference = 'CVG-' . date('Ymd') . '-' . strtoupper(Str::random(6));
                $tx = OrdinaryAccountTransaction::create([
                    'ordinary_account_id'   => $oa->id,
                    'transaction_type'      => 'coverage_deduction',
                    'direction'             => 'debit',
                    'amount'                => $amount,
                    'balance_before'        => $balanceBefore,
                    'balance_after'         => $balanceAfter,
                    'description'           => "Déduction couverture crédit #{$call->credit_id} – Appel #{$call->id}",
                    'reference'             => $reference,
                    'payment_method'        => 'account_deduction',
                    'operator_id'           => $operatorId,
                    'is_funded_by_overdraft' => $balanceBefore <= 0,
                    'operation_nature'      => 'coverage_deduction',
                ]);
            }

            // Créer le règlement
            $settlement = CreditCoverageSettlement::create([
                'credit_coverage_call_id'        => $call->id,
                'ordinary_account_id'            => $oa?->id,
                'ordinary_account_transaction_id' => $tx?->id,
                'settled_amount'                 => $amount,
                'settlement_method'              => $oa ? 'ordinary_account_deduction' : 'manual',
                'settled_by'                     => $operatorId,
                'settled_at'                     => Carbon::now(),
            ]);

            // Mettre à jour l'appel
            $call->remaining_liability = round($call->remaining_liability - $amount, 2);
            $call->settled_amount = ($call->settled_amount ?? 0) + $amount;
            if ($call->remaining_liability <= 0) {
                $call->status = 'settled';
                $call->settled_at = Carbon::now();
            } else {
                $call->status = 'partial';
            }
            $call->save();

            // Mettre à jour le compte ordinaire (coverage_debt_amount)
            if ($oa) {
                $oa->coverage_debt_amount = max(0, ($oa->coverage_debt_amount ?? 0) - $amount);
                if ($oa->coverage_debt_amount <= 0) {
                    $oa->has_coverage_restriction = false;
                }
                $oa->save();
            }

            return $settlement;
        });
    }

    /**
     * Quand l'emprunteur rembourse, réduire proportionnellement
     * les appels en couverture des parrains.
     */
    public function processBorrowerRepayment(Credit $credit, float $repaidAmount): void
    {
        $pendingCalls = CreditCoverageCall::where('credit_id', $credit->id)
            ->whereIn('status', ['pending', 'partial'])
            ->get();

        if ($pendingCalls->isEmpty()) return;

        $totalLiability = $pendingCalls->sum('remaining_liability');
        if ($totalLiability <= 0) return;

        foreach ($pendingCalls as $call) {
            // Réduction proportionnelle
            $reduction = round($repaidAmount * ($call->remaining_liability / $totalLiability), 2);
            $call->remaining_liability = max(0, $call->remaining_liability - $reduction);

            if ($call->remaining_liability <= 0) {
                $call->status = 'settled';
                $call->settled_at = Carbon::now();
            }
            $call->save();

            // Réduire la dette du compte ordinaire du parrain
            if ($call->guarantor_user_id) {
                $oa = OrdinaryAccount::where('user_id', $call->guarantor_user_id)->first();
                if ($oa) {
                    $oa->coverage_debt_amount = max(0, ($oa->coverage_debt_amount ?? 0) - $reduction);
                    if ($oa->coverage_debt_amount <= 0) {
                        $oa->has_coverage_restriction = false;
                    }
                    $oa->save();
                }
            }
        }

        // Si toutes les couvertures sont soldées, retirer le statut triggered
        $allSettled = CreditCoverageCall::where('credit_id', $credit->id)
            ->whereNotIn('status', ['settled'])
            ->doesntExist();

        if ($allSettled) {
            $credit->coverage_status = 'cleared';
            $credit->save();
        }
    }

    /**
     * Retourne le résumé de couverture d'un parrain (compte couverture visible).
     */
    public function getGuarantorCoverageAccount(int $userId): array
    {
        $calls = CreditCoverageCall::where('guarantor_user_id', $userId)
            ->with(['credit', 'settlements'])
            ->get();

        $totalCalled = $calls->sum('called_amount');
        $totalSettled = $calls->sum('settled_amount');
        $totalRemaining = $calls->sum('remaining_liability');

        $oa = OrdinaryAccount::where('user_id', $userId)->first();
        $ordinaryBalance = $oa ? (float) $oa->balance : 0;
        $netBalance = $ordinaryBalance - $totalRemaining; // si négatif → ne peut pas retirer

        return [
            'user_id'                 => $userId,
            'has_coverage_calls'      => $calls->isNotEmpty(),
            'total_called'            => round($totalCalled, 2),
            'total_settled'           => round($totalSettled, 2),
            'total_remaining_liability' => round($totalRemaining, 2),
            'ordinary_account_balance' => round($ordinaryBalance, 2),
            'net_available_balance'   => round($netBalance, 2),
            'can_withdraw'            => $netBalance >= 0,
            'calls'                   => $calls->map(function ($call) {
                return [
                    'id'                  => $call->id,
                    'credit_id'           => $call->credit_id,
                    'called_amount'       => $call->called_amount,
                    'settled_amount'      => $call->settled_amount ?? 0,
                    'remaining_liability' => $call->remaining_liability,
                    'status'              => $call->status,
                    'triggered_at'        => $call->triggered_at,
                    'credit_number'       => $call->credit?->credit_number,
                    'credit_status'       => $call->credit?->status,
                ];
            })->values()->toArray(),
        ];
    }
}
