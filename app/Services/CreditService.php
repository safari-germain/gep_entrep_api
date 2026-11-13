<?php

namespace App\Services;

use App\Models\User;
use App\Models\Credit;
use App\Models\CreditSchedule;
use App\Models\CreditPayment;
use App\Models\CreditGuarantor;
use App\Models\Deposit;
use App\Models\Bonus;
use App\Models\Malus;
use App\Models\MicrofinanceLedgerTransaction;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class CreditService
{
    /**
     * CapacitÃ© d'emprunt basÃ©e sur la caution totale (ex: 500%)
     */
    public static function calculateEligibleAmount(int $userId): array
    {
        $maxBorrowPct = FinancialRuleService::get('max_borrowing_percentage_of_caution', 500.0);

        $totalCaution = Deposit::where('user_id', $userId)->sum('actual_amount');
        $activeCreditsPrincipal = Credit::where('user_id', $userId)
            ->whereIn('status', ['active', 'in_arrears'])
            ->sum('remaining_balance');

        $maxAllowed = round(($totalCaution * $maxBorrowPct) / 100, 2);
        $availableBorrowCapacity = max(0.0, round($maxAllowed - $activeCreditsPrincipal, 2));

        return [
            'total_caution' => (float)$totalCaution,
            'max_borrow_percentage' => (float)$maxBorrowPct,
            'max_allowed_total' => (float)$maxAllowed,
            'active_debt_balance' => (float)$activeCreditsPrincipal,
            'available_borrow_capacity' => (float)$availableBorrowCapacity,
        ];
    }

    /**
     * SystÃ¨me d'Ã©toiles de remboursement de crÃ©dit (couleur distincte)
     */
    public static function calculateCreditStars(int $userId): array
    {
        $initialStars = FinancialRuleService::get('star_rating_initial_credit_stars', 3.0);

        $schedules = CreditSchedule::whereHas('credit', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->get();

        if ($schedules->isEmpty()) {
            return [
                'stars' => $initialStars,
                'max_stars' => 3,
                'total_schedules' => 0,
                'overdue_schedules' => 0,
                'ratio' => 1.0,
            ];
        }

        $total = $schedules->count();
        $overdue = $schedules->where('status', 'overdue')->count();

        $lateRatio = min(1.0, $overdue / max(1, $total));
        $currentStars = round(max(0.0, $initialStars * (1.0 - $lateRatio)), 1);

        return [
            'stars' => $currentStars,
            'max_stars' => 3,
            'total_schedules' => $total,
            'overdue_schedules' => $overdue,
            'ratio' => 1.0 - $lateRatio,
        ];
    }

    /**
     * Indicateur point rouge (Alerte Admin) : true si une pÃ©nalitÃ© ou un retard est actif
     */
    public static function checkPenaltyAlert(int $userId): array
    {
        $hasActivePenalty = Malus::where('user_id', $userId)
            ->where('created_at', '>=', Carbon::now()->subMonths(3))
            ->where('penalty_amount', '>', 0)
            ->exists();

        $hasOverdueCredit = CreditSchedule::whereHas('credit', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->where('status', 'overdue')->exists();

        $isRedAlert = $hasActivePenalty || $hasOverdueCredit;

        return [
            'has_penalty_alert' => $isRedAlert,
            'has_active_penalty' => $hasActivePenalty,
            'has_overdue_credit' => $hasOverdueCredit,
            'alert_color' => $isRedAlert ? 'red' : 'green',
        ];
    }

    /**
     * CrÃ©ation d'un emprunt avec parrainage dynamique
     */
    public static function createCredit(
        User $borrower,
        float $principalAmount,
        int $durationMonths,
        int $operatorId,
        ?int $branchId = null,
        array $guarantors = [], // [['user_id' => ..., 'organisation_id' => ..., 'guaranteed_percentage' => 20]]
        ?float $customInterestRate = null
    ): array {
        return DB::transaction(function () use ($borrower, $principalAmount, $durationMonths, $operatorId, $branchId, $guarantors, $customInterestRate) {
            $branchId = $branchId ?? $borrower->individual?->branch_id ?? 1;
            $interestRatePct = $customInterestRate ?? FinancialRuleService::get('credit_interest_rate_percentage', 10.0);

            $totalInterest = round(($principalAmount * $interestRatePct) / 100, 2);
            $totalAmountDue = round($principalAmount + $totalInterest, 2);

            $disbDate = Carbon::now();
            $firstDueDate = Carbon::now()->addMonth();
            $finalDueDate = Carbon::now()->addMonths($durationMonths);

            $credit = Credit::create([
                'credit_number' => 'CRD-' . strtoupper(Str::random(8)),
                'user_id' => $borrower->id,
                'branch_id' => $branchId,
                'disbursed_by_operator_id' => $operatorId,
                'principal_amount' => $principalAmount,
                'interest_rate_percentage' => $interestRatePct,
                'total_interest_amount' => $totalInterest,
                'total_amount_due' => $totalAmountDue,
                'total_amount_paid' => 0.00,
                'remaining_balance' => $totalAmountDue,
                'duration_months' => $durationMonths,
                'disbursement_date' => $disbDate->toDateString(),
                'first_due_date' => $firstDueDate->toDateString(),
                'final_due_date' => $finalDueDate->toDateString(),
                'status' => 'active',
            ]);

            // Enregistrement des parrains avec leurs pourcentages dynamiques
            foreach ($guarantors as $g) {
                $pct = (float)($g['guaranteed_percentage'] ?? 0);
                $guaranteedAmt = round(($principalAmount * $pct) / 100, 2);

                CreditGuarantor::create([
                    'credit_id' => $credit->id,
                    'guarantor_user_id' => $g['user_id'] ?? null,
                    'organisation_id' => $g['organisation_id'] ?? null,
                    'guarantor_type' => !empty($g['organisation_id']) ? 'organisation' : 'individual',
                    'guaranteed_amount' => $guaranteedAmt,
                    'notes' => "Parrainage solidaire Ã  {$pct}%",
                ]);
            }

            // GÃ©nÃ©ration de l'Ã©chÃ©ancier mensuel
            $monthlyPrincipal = round($principalAmount / $durationMonths, 2);
            $monthlyInterest = round($totalInterest / $durationMonths, 2);

            for ($i = 1; $i <= $durationMonths; $i++) {
                $dueDate = Carbon::now()->addMonths($i);
                CreditSchedule::create([
                    'credit_id' => $credit->id,
                    'installment_number' => $i,
                    'due_date' => $dueDate->toDateString(),
                    'principal_due' => $monthlyPrincipal,
                    'interest_due' => $monthlyInterest,
                    'total_due' => round($monthlyPrincipal + $monthlyInterest, 2),
                    'amount_paid' => 0.00,
                    'penalty_due' => 0.00,
                    'status' => 'pending',
                ]);
            }

            return [
                'credit' => $credit->load('guarantors', 'schedules'),
                'monthly_installment' => round($monthlyPrincipal + $monthlyInterest, 2),
            ];
        });
    }

    /**
     * Remboursement d'Ã©chÃ©ance et distribution des intÃ©rÃªts aux parrains
     * Centre GEP: 20% | Parrains (Guarantors): 80% au prorata de leur garantie
     */
    public static function recordPayment(
        Credit $credit,
        float $amountPaid,
        int $operatorId,
        ?int $scheduleId = null,
        string $paymentMethod = 'cash'
    ): array {
        return DB::transaction(function () use ($credit, $amountPaid, $operatorId, $scheduleId, $paymentMethod) {
            $schedule = $scheduleId ? CreditSchedule::find($scheduleId) : $credit->schedules()->where('status', '!=', 'paid')->orderBy('due_date')->first();

            $interestPortion = 0.0;
            $principalPortion = $amountPaid;

            if ($schedule) {
                $interestDue = max(0.0, (float)$schedule->interest_due - (float)$schedule->amount_paid);
                $interestPortion = min($interestDue, $amountPaid);
                $principalPortion = max(0.0, $amountPaid - $interestPortion);

                $schedule->amount_paid += $amountPaid;
                if ($schedule->amount_paid >= $schedule->total_due) {
                    $schedule->status = 'paid';
                    $schedule->paid_date = Carbon::now()->toDateString();
                } else {
                    $schedule->status = 'partially_paid';
                }
                $schedule->save();
            }

            $credit->total_amount_paid += $amountPaid;
            $credit->remaining_balance = max(0.0, $credit->remaining_balance - $amountPaid);
            if ($credit->remaining_balance <= 0) {
                $credit->status = 'paid_off';
            }
            $credit->save();

            $payment = CreditPayment::create([
                'payment_reference' => 'PAY-CRD-' . strtoupper(Str::random(8)),
                'credit_id' => $credit->id,
                'credit_schedule_id' => $schedule?->id,
                'user_id' => $credit->user_id,
                'branch_id' => $credit->branch_id,
                'operator_id' => $operatorId,
                'amount_paid' => $amountPaid,
                'principal_portion' => $principalPortion,
                'interest_portion' => $interestPortion,
                'penalty_portion' => 0.00,
                'payment_method' => $paymentMethod,
            ]);

            // Distribution du Bonus Parrainage sur la part d'intÃ©rÃªt
            $distributedBonuses = [];
            if ($interestPortion > 0) {
                $centerSharePct = FinancialRuleService::get('sponsorship_center_share_percentage', 20.0);
                $guarantorsSharePct = FinancialRuleService::get('sponsorship_guarantors_share_percentage', 80.0);

                $distributableInterest = ($interestPortion * $guarantorsSharePct) / 100;

                $guarantors = $credit->guarantors;
                $totalGuaranteed = $guarantors->sum('guaranteed_amount');

                if ($totalGuaranteed > 0) {
                    foreach ($guarantors as $guarantor) {
                        $shareRatio = $guarantor->guaranteed_amount / $totalGuaranteed;
                        $guarantorBonus = round($distributableInterest * $shareRatio, 2);

                        if ($guarantorBonus > 0 && $guarantor->guarantor_user_id) {
                            $b = Bonus::create([
                                'user_id' => $guarantor->guarantor_user_id,
                                'branch_id' => $credit->branch_id,
                                'bonus_type' => 'sponsorship_bonus',
                                'amount' => $guarantorBonus,
                                'description' => "Bonus de Parrainage sur l'emprunt {$credit->credit_number} (part garantie de \${$guarantor->guaranteed_amount})",
                                'granted_by_operator_id' => $operatorId,
                            ]);
                            $distributedBonuses[] = [
                                'guarantor_id' => $guarantor->guarantor_user_id,
                                'bonus_amount' => $guarantorBonus,
                            ];
                        }
                    }
                }
            }

            return [
                'payment' => $payment,
                'remaining_balance' => $credit->remaining_balance,
                'credit_status' => $credit->status,
                'distributed_sponsorship_bonuses' => $distributedBonuses,
            ];
        });
    }

    /**
     * DÃ©tection des retards et application des pÃ©nalitÃ©s & Malus de Parrainage
     */
    public static function checkAndApplyOverduePenalties(int $operatorId = 1): array
    {
        $today = Carbon::now()->toDateString();
        $overdueSchedules = CreditSchedule::where('due_date', '<', $today)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->get();

        $processed = 0;
        $penaltyPct = FinancialRuleService::get('credit_late_penalty_percentage', 5.0);

        foreach ($overdueSchedules as $schedule) {
            $schedule->status = 'overdue';
            $unpaidAmount = max(0.0, $schedule->total_due - $schedule->amount_paid);
            $penaltyAmount = round(($unpaidAmount * $penaltyPct) / 100, 2);
            $schedule->penalty_due = $penaltyAmount;
            $schedule->save();

            $credit = $schedule->credit;
            $credit->status = 'in_arrears';
            $credit->save();

            // Malus sur l'emprunteur
            Malus::create([
                'user_id' => $credit->user_id,
                'branch_id' => $credit->branch_id,
                'malus_type' => 'late_credit_payment',
                'penalty_amount' => $penaltyAmount,
                'trust_score_deduction' => 10.0,
                'reason' => "Retard sur Ã©chÃ©ance #{$schedule->installment_number} de l'emprunt {$credit->credit_number}",
                'applied_by_operator_id' => $operatorId,
            ]);

            // Malus sur les parrains proportionnel Ã  leur part garantie
            $guarantors = $credit->guarantors;
            $totalGuaranteed = $guarantors->sum('guaranteed_amount');
            if ($totalGuaranteed > 0) {
                foreach ($guarantors as $g) {
                    if ($g->guarantor_user_id) {
                        $ratio = $g->guaranteed_amount / $credit->principal_amount;
                        $guarantorMalus = round($unpaidAmount * $ratio, 2);

                        Malus::create([
                            'user_id' => $g->guarantor_user_id,
                            'branch_id' => $credit->branch_id,
                            'malus_type' => 'sponsorship_malus',
                            'penalty_amount' => $guarantorMalus,
                            'trust_score_deduction' => 5.0,
                            'reason' => "Malus Parrainage : Emprunteur {$credit->user?->name} en retard sur dette de \${$unpaidAmount} (quote-part garantie de \${$guarantorMalus})",
                            'applied_by_operator_id' => $operatorId,
                        ]);
                    }
                }
            }

            $processed++;
        }

        return ['processed_schedules' => $processed];
    }
}
