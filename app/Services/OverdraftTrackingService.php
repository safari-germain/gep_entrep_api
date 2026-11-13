<?php

namespace App\Services;

use App\Models\OrdinaryAccount;
use App\Models\OrdinaryAccountTransaction;
use App\Models\OrdinaryAccountOverdraftCycle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OverdraftTrackingService
{
    /**
     * Marque une transaction comme prélevée sur compte négatif
     * et met à jour les compteurs de découvert.
     * À appeler après chaque débit sur compte ordinaire.
     */
    public function markTransactionIfOverdraft(
        OrdinaryAccount $account,
        OrdinaryAccountTransaction $tx
    ): void {
        // Si le solde avant était déjà négatif → prélèvement sur découvert
        if ($tx->balance_before <= 0) {
            $tx->is_funded_by_overdraft  = true;
            $tx->overdraft_months_count  = $account->consecutive_negative_months ?? 0;
            $tx->operation_nature        = $tx->operation_nature ?? 'overdraft_debit';
            $tx->save();
        }

        // Si le compte passe en négatif avec cette transaction
        if ($tx->balance_after < 0) {
            // Ouvrir un cycle de découvert si ce n'est pas déjà fait
            $this->openOverdraftCycleIfNeeded($account, $tx);
        }

        // Si le compte repasse positif → fermer le cycle de découvert
        if ($tx->balance_after >= 0 && $account->negative_since !== null) {
            $this->closeOverdraftCycle($account);
        }
    }

    /**
     * Ouvre un nouveau cycle de découvert si aucun n'est en cours.
     */
    private function openOverdraftCycleIfNeeded(OrdinaryAccount $account, OrdinaryAccountTransaction $tx): void
    {
        $existingOpen = OrdinaryAccountOverdraftCycle::where('ordinary_account_id', $account->id)
            ->whereNull('ended_at')
            ->exists();

        if (!$existingOpen) {
            OrdinaryAccountOverdraftCycle::create([
                'ordinary_account_id'   => $account->id,
                'started_at'            => Carbon::now(),
                'opening_balance'       => $tx->balance_before,
                'lowest_balance'        => $tx->balance_after,
                'status'                => 'open',
                'months_negative'       => 0,
            ]);
        } else {
            // Mettre à jour le solde le plus bas
            $cycle = OrdinaryAccountOverdraftCycle::where('ordinary_account_id', $account->id)
                ->whereNull('ended_at')
                ->first();
            if ($cycle && $tx->balance_after < (float)$cycle->lowest_balance) {
                $cycle->lowest_balance = $tx->balance_after;
                $cycle->save();
            }
        }
    }

    /**
     * Ferme le cycle de découvert actif et met à jour les statistiques.
     */
    private function closeOverdraftCycle(OrdinaryAccount $account): void
    {
        $cycle = OrdinaryAccountOverdraftCycle::where('ordinary_account_id', $account->id)
            ->whereNull('ended_at')
            ->first();

        if (!$cycle) return;

        $monthsNegative = (int) Carbon::parse($cycle->started_at)->diffInMonths(Carbon::now());

        $cycle->ended_at       = Carbon::now();
        $cycle->months_negative = max(1, $monthsNegative);
        $cycle->status         = 'closed';
        $cycle->save();

        // Réinitialiser les compteurs sur le compte
        $account->consecutive_negative_months = 0;
        $account->negative_since              = null;
        $account->save();
    }

    /**
     * Calcule et met à jour les mois consécutifs en découvert.
     * À appeler le 1er de chaque mois par le scheduler.
     */
    public function updateMonthlyNegativeCounters(): array
    {
        $results = ['updated' => 0, 'errors' => []];

        $negativeAccounts = OrdinaryAccount::where('balance', '<', 0)
            ->whereNotNull('negative_since')
            ->get();

        foreach ($negativeAccounts as $account) {
            try {
                $monthsNegative = (int) Carbon::parse($account->negative_since)->diffInMonths(Carbon::now());

                $account->consecutive_negative_months = max(1, $monthsNegative);
                $account->total_overdraft_operations_count = OrdinaryAccountTransaction::where('ordinary_account_id', $account->id)
                    ->where('is_funded_by_overdraft', true)
                    ->count();
                $account->save();

                // Mettre à jour le cycle de découvert en cours
                $cycle = OrdinaryAccountOverdraftCycle::where('ordinary_account_id', $account->id)
                    ->whereNull('ended_at')
                    ->first();

                if ($cycle) {
                    $cycle->months_negative = max(1, $monthsNegative);
                    $cycle->save();
                }

                $results['updated']++;
            } catch (\Exception $e) {
                $results['errors'][] = "Account #{$account->id}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Résumé du découvert pour un compte ordinaire.
     */
    public function getOverdraftSummary(int $accountId): array
    {
        $account = OrdinaryAccount::with('overdraftCycles')->findOrFail($accountId);

        $overdraftTransactions = OrdinaryAccountTransaction::where('ordinary_account_id', $accountId)
            ->where('is_funded_by_overdraft', true)
            ->orderByDesc('created_at')
            ->get();

        return [
            'account_id'                       => $accountId,
            'current_balance'                  => $account->balance,
            'is_negative'                      => $account->balance < 0,
            'negative_since'                   => $account->negative_since,
            'consecutive_negative_months'      => $account->consecutive_negative_months ?? 0,
            'total_overdraft_operations_count' => $account->total_overdraft_operations_count ?? 0,
            'coverage_debt_amount'             => $account->coverage_debt_amount ?? 0,
            'has_coverage_restriction'         => $account->has_coverage_restriction ?? false,
            'overdraft_cycles'                 => $account->overdraftCycles->map(fn($c) => [
                'started_at'      => $c->started_at,
                'ended_at'        => $c->ended_at,
                'months_negative' => $c->months_negative,
                'lowest_balance'  => $c->lowest_balance,
                'status'          => $c->status,
            ])->values()->toArray(),
            'overdraft_transactions'           => $overdraftTransactions->map(fn($tx) => [
                'id'                   => $tx->id,
                'type'                 => $tx->transaction_type,
                'amount'               => $tx->amount,
                'balance_before'       => $tx->balance_before,
                'balance_after'        => $tx->balance_after,
                'description'          => $tx->description,
                'overdraft_months'     => $tx->overdraft_months_count ?? 0,
                'operation_nature'     => $tx->operation_nature,
                'created_at'           => $tx->created_at,
            ])->values()->toArray(),
        ];
    }
}
