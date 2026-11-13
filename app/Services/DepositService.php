<?php

namespace App\Services;

use App\Models\User;
use App\Models\Deposit;
use App\Models\Bonus;
use App\Models\Malus;
use App\Models\Sensibilisateur;
use App\Models\MicrofinanceLedgerTransaction;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class DepositService
{
    /**
     * Calcule la note d'Ã©toiles de cotisation (sur 3 Ã©toiles par dÃ©faut)
     * Si 0 retard sur 12 mois = 3 Ã©toiles.
     * Si des retards existent, les Ã©toiles diminuent au prorata des retards sur 12 mois.
     */
    public static function calculateCautionStars(int $userId): array
    {
        $initialStars = FinancialRuleService::get('star_rating_initial_caution_stars', 3.0);
        $oneYearAgo = Carbon::now()->subYear();

        $totalDeposits = Deposit::where('user_id', $userId)
            ->where('created_at', '>=', $oneYearAgo)
            ->count();

        $lateDeposits = Deposit::where('user_id', $userId)
            ->where('created_at', '>=', $oneYearAgo)
            ->where('is_on_time', false)
            ->count();

        if ($totalDeposits === 0) {
            return [
                'stars' => $initialStars,
                'max_stars' => 3,
                'total_deposits' => 0,
                'late_deposits' => 0,
                'ratio' => 1.0,
            ];
        }

        $effectiveDeposits = min(12, max(1, $totalDeposits));
        $lateRatio = min(1.0, $lateDeposits / $effectiveDeposits);
        $currentStars = round(max(0.0, $initialStars * (1.0 - $lateRatio)), 1);

        return [
            'stars' => $currentStars,
            'max_stars' => 3,
            'total_deposits' => $totalDeposits,
            'late_deposits' => $lateDeposits,
            'ratio' => 1.0 - $lateRatio,
        ];
    }

    /**
     * Enregistre un dÃ©pÃ´t de caution solidaire
     */
    public static function recordDeposit(
        User $user,
        float $amount,
        int $operatorId,
        ?int $branchId = null,
        ?string $expectedDate = null,
        ?string $actualDate = null,
        string $paymentMethod = 'cash',
        ?string $remarks = null
    ): array {
        return DB::transaction(function () use ($user, $amount, $operatorId, $branchId, $expectedDate, $actualDate, $paymentMethod, $remarks) {
            $branchId = $branchId ?? $user->individual?->branch_id ?? 1;
            $expDate = $expectedDate ? Carbon::parse($expectedDate) : Carbon::now()->startOfMonth();
            $actDate = $actualDate ? Carbon::parse($actualDate) : Carbon::now();

            $delayDays = max(0, $actDate->diffInDays($expDate, false) < 0 ? $actDate->diffInDays($expDate) : 0);
            $graceDays = (int) FinancialRuleService::get('cotisation_jours_retard_grace', 0);
            $isOnTime = $delayDays <= $graceDays;

            $deposit = Deposit::create([
                'deposit_reference' => 'DEP-' . strtoupper(Str::random(8)),
                'user_id' => $user->id,
                'branch_id' => $branchId,
                'operator_id' => $operatorId,
                'expected_amount' => $amount,
                'actual_amount' => $amount,
                'expected_date' => $expDate->toDateString(),
                'actual_date' => $actDate->toDateString(),
                'delay_days' => $delayDays,
                'wallet_destination' => 'caution_reelle',
                'payment_method' => $paymentMethod,
                'payment_reference' => 'PAY-' . strtoupper(Str::random(10)),
                'is_on_time' => $isOnTime,
                'remarks' => $remarks,
            ]);

            // Enregistrement dans le Ledger
            MicrofinanceLedgerTransaction::create([
                'reference' => 'TXN-' . strtoupper(Str::random(10)),
                'user_id' => $user->id,
                'operator_id' => $operatorId,
                'branch_id' => $branchId,
                'source_wallet_type' => 'external_' . $paymentMethod,
                'destination_wallet_type' => 'caution_reelle',
                'amount' => $amount,
                'currency' => 'USD',
                'operation_type' => 'caution_deposit',
                'status' => 'completed',
                'description' => "DÃ©pÃ´t de caution solidaire ({$deposit->deposit_reference})",
                'metadata' => ['deposit_id' => $deposit->id, 'is_on_time' => $isOnTime, 'delay_days' => $delayDays],
            ]);

            $penaltyAmount = 0.0;
            // Si en retard : application pÃ©nalitÃ© & malus
            if (!$isOnTime) {
                $penaltyPct = FinancialRuleService::get('deposit_late_penalty_percentage', 5.0);
                $penaltyAmount = round(($amount * $penaltyPct) / 100, 2);

                Malus::create([
                    'user_id' => $user->id,
                    'branch_id' => $branchId,
                    'malus_type' => 'late_deposit',
                    'penalty_amount' => $penaltyAmount,
                    'trust_score_deduction' => 5.0,
                    'reason' => "Retard de {$delayDays} jour(s) sur le versement mensuel de caution",
                    'applied_by_operator_id' => $operatorId,
                ]);
            }

            // Mise Ã  jour du streak de dÃ©pÃ´ts
            $individual = $user->individual;
            $newStreak = 1;
            if ($individual) {
                $newStreak = $individual->deposit_streak_months + 1;
                $individual->deposit_streak_months = $newStreak;
                $individual->save();
            }

            // DÃ©clencheur 1 : Bonus Sensibilisateur au 2Ã¨me versement
            $sensibilisateurBonusTranche1 = 0.0;
            $targetMonth1 = (int)FinancialRuleService::get('sensibilisateur_first_bonus_month', 2);
            if ($newStreak === $targetMonth1) {
                $sensibilisateurBonusTranche1 = self::grantSensibilisateurFirstBonus($user, $amount, $branchId, $operatorId);
            }

            // DÃ©clencheur 2 : ClÃ´ture annuelle (12Ã¨me versement)
            $annualYieldBonus = 0.0;
            $sensibilisateurBonusTranche2 = 0.0;
            if ($newStreak % 12 === 0) {
                // 1) Bonus rendement caution (1 mois offert)
                $annualYieldBonus = self::grantAnnualCautionYieldBonus($user, $amount, $branchId, $operatorId);
                // 2) 2Ã¨me tranche bonus sensibilisateur (50% restants)
                $sensibilisateurBonusTranche2 = self::grantSensibilisateurAnnualBonus($user, $amount, $branchId, $operatorId);
            }

            $starsData = self::calculateCautionStars($user->id);

            return [
                'deposit' => $deposit,
                'penalty_applied' => $penaltyAmount,
                'new_streak' => $newStreak,
                'sensibilisateur_bonus_tranche_1' => $sensibilisateurBonusTranche1,
                'annual_yield_bonus' => $annualYieldBonus,
                'sensibilisateur_bonus_tranche_2' => $sensibilisateurBonusTranche2,
                'caution_stars' => $starsData,
            ];
        });
    }

    /**
     * 1Ã¨re tranche (50%) du bonus de mobilisation au 2Ã¨me versement
     */
    private static function grantSensibilisateurFirstBonus(User $user, float $baseCautionAmount, int $branchId, int $operatorId): float
    {
        $sensibilisateurId = $user->individual?->sensibilisateur_id;
        if (!$sensibilisateurId) return 0.0;

        $sensibilisateur = Sensibilisateur::find($sensibilisateurId);
        if (!$sensibilisateur || !$sensibilisateur->user) return 0.0;

        $baseRate = FinancialRuleService::get('sensibilisateur_base_caution_rate', 25.0); // 50% de 50% = 25%
        $firstPct = FinancialRuleService::get('sensibilisateur_first_bonus_percentage', 50.0); // 50% de la quote-part

        $bonusAmount = round(($baseCautionAmount * $baseRate / 100) * ($firstPct / 100), 2);
        if ($bonusAmount <= 0) return 0.0;

        Bonus::create([
            'user_id' => $sensibilisateur->user_id,
            'branch_id' => $branchId,
            'bonus_type' => 'mobilisation_bonus',
            'amount' => $bonusAmount,
            'description' => "Bonus Mobilisation (Tranche 1/2 au 2Ã¨me versement) pour le recrutement de {$user->name}",
            'granted_by_operator_id' => $operatorId,
        ]);

        $sensibilisateur->increment('total_bonus_earned', $bonusAmount);

        return $bonusAmount;
    }

    /**
     * 2Ã¨me tranche (50% restants) du bonus de mobilisation Ã  la clÃ´ture de l'exercice
     */
    private static function grantSensibilisateurAnnualBonus(User $user, float $baseCautionAmount, int $branchId, int $operatorId): float
    {
        $sensibilisateurId = $user->individual?->sensibilisateur_id;
        if (!$sensibilisateurId) return 0.0;

        $sensibilisateur = Sensibilisateur::find($sensibilisateurId);
        if (!$sensibilisateur || !$sensibilisateur->user) return 0.0;

        $baseRate = FinancialRuleService::get('sensibilisateur_base_caution_rate', 25.0);
        $annualPct = FinancialRuleService::get('sensibilisateur_annual_bonus_percentage', 50.0);

        $bonusAmount = round(($baseCautionAmount * $baseRate / 100) * ($annualPct / 100), 2);
        if ($bonusAmount <= 0) return 0.0;

        Bonus::create([
            'user_id' => $sensibilisateur->user_id,
            'branch_id' => $branchId,
            'bonus_type' => 'mobilisation_bonus_annual_closing',
            'amount' => $bonusAmount,
            'description' => "Bonus Mobilisation (Tranche 2/2 ClÃ´ture Annuelle) pour le suivi de {$user->name}",
            'granted_by_operator_id' => $operatorId,
        ]);

        $sensibilisateur->increment('total_bonus_earned', $bonusAmount);

        return $bonusAmount;
    }

    /**
     * Bonus sur Intérêt / Rendement de caution (1 mois offert pour 12 mois de cotisations)
     */
    public static function grantAnnualCautionYieldBonus(User $user, float $monthlyCautionAmount, int $branchId, int $operatorId): float
    {
        $bonusMonths = (int)FinancialRuleService::get('caution_annual_bonus_months', 1);
        $bonusAmount = round($monthlyCautionAmount * $bonusMonths, 2);

        if ($bonusAmount <= 0) return 0.0;

        Bonus::create([
            'user_id' => $user->id,
            'branch_id' => $branchId,
            'bonus_type' => 'caution_yield_bonus',
            'amount' => $bonusAmount,
            'description' => "Bonus de Rendement Annuel de Caution ({$bonusMonths} mois de caution offert après 12 versements réguliers)",
            'granted_by_operator_id' => $operatorId,
        ]);

        return $bonusAmount;
    }

    /**
     * Vérifie et attribue rétroactivement / automatiquement le bonus de caution pour chaque versement
     * Garantit que le membre voit toujours ses bonus sur caution cumulés à chaque dépôt automatique
     */
    public static function checkAndGrantPendingCautionBonuses(int $userId): float
    {
        $user = User::find($userId);
        if (!$user) return 0.0;

        $individual = $user->individual;
        $branchId = $individual->branch_id ?? $user->branch_id ?? 1;

        $deposits = Deposit::where('user_id', $userId)
            ->orderBy('created_at', 'asc')
            ->get();

        $totalGranted = 0.0;
        $monthlyInterestRate = (float) FinancialRuleService::get('bonus_fidelite_cotisation_pct', 8.33);

        foreach ($deposits as $dep) {
            // Vérifier si un bonus intérêt de cotisation a déjà été créé pour ce dépôt
            $hasInterestBonus = Bonus::where('deposit_id', $dep->id)
                ->whereIn('bonus_type', ['epargne_interet', 'caution_yield_bonus'])
                ->exists();

            if (!$hasInterestBonus && $dep->actual_amount > 0 && $monthlyInterestRate > 0) {
                $bonusAmt = round($dep->actual_amount * ($monthlyInterestRate / 100.0), 2);
                if ($bonusAmt > 0) {
                    Bonus::create([
                        'user_id'                => $userId,
                        'branch_id'              => $dep->branch_id ?: $branchId,
                        'deposit_id'             => $dep->id,
                        'bonus_type'             => 'epargne_interet',
                        'amount'                 => $bonusAmt,
                        'description'            => "Bonus Intérêt/Rendement Caution ({$monthlyInterestRate}%) sur dépôt #{$dep->deposit_reference}",
                        'granted_by_operator_id' => $dep->operator_id ?? 1,
                    ]);

                    if ($individual) {
                        $individual->bonus_caution = round(((float)$individual->bonus_caution) + $bonusAmt, 2);
                        $individual->bonus_earned = round(((float)$individual->bonus_earned) + $bonusAmt, 2);
                    }
                    $totalGranted += $bonusAmt;
                }
            }
        }

        // Vérifier bonus 12 mois (1 mois offert)
        $depositCount = $deposits->count();
        if ($depositCount >= 12) {
            $annualBonusCycles = intdiv($depositCount, 12);
            $existingAnnualBonuses = Bonus::where('user_id', $userId)
                ->where('bonus_type', 'caution_yield_bonus')
                ->count();

            if ($existingAnnualBonuses < $annualBonusCycles) {
                $avgMonthly = round($deposits->avg('actual_amount') ?: 50.0, 2);
                $missingCycles = $annualBonusCycles - $existingAnnualBonuses;
                for ($i = 0; $i < $missingCycles; $i++) {
                    $annualBonus = self::grantAnnualCautionYieldBonus($user, $avgMonthly, $branchId, 1);
                    $totalGranted += $annualBonus;
                }
            }
        }

        if ($individual && $totalGranted > 0) {
            $individual->save();
        }

        return $totalGranted;
    }
}
