<?php

namespace App\Services;

use App\Models\User;
use App\Models\Credit;
use App\Models\Collection;
use App\Models\Bonus;
use App\Models\MicrofinanceLedgerTransaction;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class DebtRecoveryService
{
    /**
     * Assigne un agent ou une organisation au recouvrement d'une dette en souffrance
     */
    public static function assignRecovery(
        Credit $credit,
        int $assignedToUserId,
        ?int $organisationId = null,
        ?int $branchId = null,
        int $operatorId = 1,
        string $strategy = 'amicable_reminder'
    ): Collection {
        $branchId = $branchId ?? $credit->branch_id;

        return Collection::create([
            'credit_id' => $credit->id,
            'assigned_agent_id' => $assignedToUserId,
            'organisation_id' => $organisationId,
            'branch_id' => $branchId,
            'recovery_strategy' => $strategy,
            'status' => 'assigned',
            'assigned_by_operator_id' => $operatorId,
            'assigned_at' => Carbon::now(),
        ]);
    }

    /**
     * Enregistre l'encaissement de la pÃ©nalitÃ© et distribue la commission de recouvrement
     * 20% Ã  l'agent | 30% Ã  l'organisation/succursale | 50% au centre GEP
     */
    public static function recordPenaltyRecovery(
        Collection $collection,
        float $penaltyCollected,
        int $operatorId = 1
    ): array {
        return DB::transaction(function () use ($collection, $penaltyCollected, $operatorId) {
            $agentSharePct = FinancialRuleService::get('debt_recovery_agent_share_percentage', 20.0);
            $orgSharePct = FinancialRuleService::get('debt_recovery_org_or_branch_share_percentage', 30.0);
            $centerSharePct = FinancialRuleService::get('debt_recovery_center_share_percentage', 50.0);

            $agentBonus = round(($penaltyCollected * $agentSharePct) / 100, 2);
            $orgBonus = round(($penaltyCollected * $orgSharePct) / 100, 2);
            $centerAmount = round(($penaltyCollected * $centerSharePct) / 100, 2);

            $collection->amount_recovered = ($collection->amount_recovered ?? 0) + $penaltyCollected;
            $collection->status = 'recovered';
            $collection->resolved_at = Carbon::now();
            $collection->save();

            // Bonus pour l'agent de recouvrement
            if ($agentBonus > 0 && $collection->assigned_agent_id) {
                Bonus::create([
                    'user_id' => $collection->assigned_agent_id,
                    'branch_id' => $collection->branch_id,
                    'bonus_type' => 'penalty_recovery_agent_bonus',
                    'amount' => $agentBonus,
                    'description' => "Commission de recouvrement ({$agentSharePct}%) sur pÃ©nalitÃ© de \${$penaltyCollected}",
                    'granted_by_operator_id' => $operatorId,
                ]);
            }

            // Bonus pour l'organisation ou la succursale
            if ($orgBonus > 0) {
                // Trouver le compte de l'organisation ou de la branche
                $targetUserId = $collection->organisation_id ? User::where('id', $collection->organisation_id)->value('id') : null;
                if ($targetUserId) {
                    Bonus::create([
                        'user_id' => $targetUserId,
                        'branch_id' => $collection->branch_id,
                        'bonus_type' => 'penalty_recovery_org_bonus',
                        'amount' => $orgBonus,
                        'description' => "Quote-part organisation ({$orgSharePct}%) sur pÃ©nalitÃ© de recouvrement de \${$penaltyCollected}",
                        'granted_by_operator_id' => $operatorId,
                    ]);
                }
            }

            return [
                'penalty_collected' => $penaltyCollected,
                'agent_bonus' => $agentBonus,
                'org_bonus' => $orgBonus,
                'center_share' => $centerAmount,
            ];
        });
    }
}
