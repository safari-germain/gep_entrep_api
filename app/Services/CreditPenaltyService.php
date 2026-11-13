<?php

namespace App\Services;

use App\Models\Credit;
use App\Models\CreditSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CreditPenaltyService
{
    /**
     * Calculer le montant de pénalité pour une échéance en retard
     */
    public static function calculatePenalty(CreditSchedule $schedule): float
    {
        $credit = $schedule->credit;
        if (!$credit) {
            return 0.0;
        }

        $graceDays = (int) ($credit->overdraft_grace_days ?? FinancialRuleService::get('credit_echeance_delai_grace_jours', 3));
        $penaltyPct = (float) ($credit->overdraft_penalty_pct ?? FinancialRuleService::get('credit_echeance_penalite_pct', 1.0));

        $dueDate = Carbon::parse($schedule->due_date);
        $today = Carbon::today();

        if ($today->lte($dueDate)) {
            return 0.0;
        }

        $daysLate = $dueDate->diffInDays($today);

        // Si le délai de grâce est dépassé
        if ($daysLate >= $graceDays) {
            $baseAmount = (float) ($schedule->total_due - $schedule->amount_paid);
            if ($baseAmount <= 0) {
                return 0.0;
            }
            return round($baseAmount * ($penaltyPct / 100.0), 2);
        }

        return 0.0;
    }

    /**
     * Appliquer la pénalité sur une échéance et mettre à jour le statut en 'overdue'
     */
    public static function applyPenalty(CreditSchedule $schedule): ?float
    {
        $penalty = self::calculatePenalty($schedule);
        $dueDate = Carbon::parse($schedule->due_date);

        if (Carbon::today()->gt($dueDate) && $schedule->status === 'pending') {
            $schedule->status = 'overdue';
        }

        if ($penalty > 0) {
            $schedule->penalty_due = round(((float) $schedule->penalty_due) + $penalty, 2);
            $schedule->save();

            // Mettre le crédit en statut 'in_arrears' et activer point rouge
            $credit = $schedule->credit;
            if ($credit && $credit->status === 'active') {
                $credit->status = 'in_arrears';
                $credit->has_red_dot = true;
                $credit->save();
            }

            return $penalty;
        }

        $schedule->save();
        return null;
    }

    /**
     * Traiter toutes les échéances en retard de tous les crédits actifs
     */
    public static function processAllOverduePenalties(): array
    {
        $overdueSchedules = CreditSchedule::whereIn('status', ['pending', 'overdue'])
            ->where('due_date', '<', Carbon::today()->toDateString())
            ->with('credit')
            ->get();

        $processed = [];

        foreach ($overdueSchedules as $schedule) {
            $penalty = self::applyPenalty($schedule);
            if ($penalty !== null && $penalty > 0) {
                $processed[] = [
                    'schedule_id'    => $schedule->id,
                    'credit_id'      => $schedule->credit_id,
                    'installment_no' => $schedule->installment_number,
                    'due_date'       => $schedule->due_date,
                    'penalty_added'  => $penalty,
                    'total_penalty'  => $schedule->penalty_due,
                ];
            }
        }

        Log::info('[CreditPenaltyService] Pénalités appliquées', ['count' => count($processed)]);
        return $processed;
    }
}
