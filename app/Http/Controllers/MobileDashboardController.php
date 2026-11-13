<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Deposit;
use App\Models\Credit;
use App\Models\Bonus;
use App\Models\Malus;
use App\Models\CreditGuarantor;
use App\Models\Sensibilisateur;
use App\Models\Cycle;
use App\Services\DepositService;
use App\Services\CreditService;
use App\Services\FinancialRuleService;
use Carbon\Carbon;

class MobileDashboardController extends Controller
{
    public function getMemberDashboard(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Non authentifiÃ©'], 401);
        }

        $individual = $user->individual;

        // Vérification et attribution automatique de tout bonus de caution en attente
        DepositService::checkAndGrantPendingCautionBonuses($user->id);

        // 1. Cautions
        $totalCautionReal = (float)Deposit::where('user_id', $user->id)->sum('actual_amount');
        $cautionBonus = (float)Bonus::where('user_id', $user->id)->whereIn('bonus_type', ['caution_yield_bonus', 'epargne_interet'])->sum('amount');

        // 2. CapacitÃ© d'emprunt (500% de la caution solidaire)
        $borrowCapacity = CreditService::calculateEligibleAmount($user->id);

        // 3. Double systÃ¨me d'Ã©toiles
        $cautionStarsData = DepositService::calculateCautionStars($user->id);
        $creditStarsData = CreditService::calculateCreditStars($user->id);

        // 4. Point rouge Alerte Admin / PÃ©nalitÃ©
        $alertData = CreditService::checkPenaltyAlert($user->id);

        // 5. Les 4 CatÃ©gories de Bonus
        $userBonuses = Bonus::where('user_id', $user->id)->get();
        $bonusMobilisation = (float)$userBonuses->whereIn('bonus_type', ['mobilisation_bonus', 'mobilisation_bonus_annual_closing'])->sum('amount');
        $bonusSponsorship = (float)$userBonuses->where('bonus_type', 'sponsorship_bonus')->sum('amount');
        $bonusYield = (float)$userBonuses->whereIn('bonus_type', ['caution_yield_bonus', 'epargne_interet'])->sum('amount');
        $bonusRecovery = (float)$userBonuses->whereIn('bonus_type', ['penalty_recovery_agent_bonus', 'penalty_recovery_org_bonus'])->sum('amount');
        $totalBonus = (float)$userBonuses->sum('amount');

        // 6. Les 3 CatÃ©gories de Malus
        $userMaluses = Malus::where('user_id', $user->id)->get();
        $malusDeposit = 0.0; // Pas de malus sur caution car prélèvement automatique sur compte ordinaire
        $malusCredit = (float)$userMaluses->where('malus_type', 'late_credit_payment')->sum('penalty_amount');
        $malusSponsorship = (float)$userMaluses->where('malus_type', 'sponsorship_malus')->sum('penalty_amount');
        $totalMalus = $malusCredit + $malusSponsorship;

        // 7. PrÃªt actif
        $activeCredit = Credit::with(['schedules', 'guarantors.guarantorUser'])
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'in_arrears'])
            ->latest()
            ->first();

        // 8. Parrainages accordÃ©s Ã  des tiers
        $sponsoredLoans = CreditGuarantor::with('credit.user')
            ->where('guarantor_user_id', $user->id)
            ->latest()
            ->get();

        // 9. Sensibilisateur rattachÃ©
        $sensibilisateur = null;
        if ($individual?->sensibilisateur_id) {
            $sensib = Sensibilisateur::with('user')->find($individual->sensibilisateur_id);
            if ($sensib) {
                $sensibilisateur = [
                    'id' => $sensib->id,
                    'name' => $sensib->user?->name ?? 'GEP Centre',
                    'code' => $sensib->code_promo,
                ];
            }
        }

        $streak = $individual?->deposit_streak_months ?? 0;
        $monthsToBonus = 12 - ($streak % 12);

        // 10. Cycle actif & Progression réelle dynamique
        $userCycle = Cycle::where(function ($q) use ($user, $individual) {
            if ($individual) {
                $q->where('entity_type', 'individual')->where('entity_id', $individual->id);
            }
            $q->orWhere('user_id', $user->id);
        })->latest()->first();

        if (!$userCycle && $individual?->branch_id) {
            $userCycle = Cycle::where('branch_id', $individual->branch_id)->latest()->first();
        }
        if (!$userCycle) {
            $userCycle = Cycle::where('status', 'active')->latest()->first() ?? Cycle::latest()->first();
        }

        $cycleData = null;
        if ($userCycle) {
            $start = $userCycle->start_date ? Carbon::parse($userCycle->start_date) : null;
            $end = $userCycle->end_date ? Carbon::parse($userCycle->end_date) : null;
            $now = Carbon::now();

            $totalDays = ($start && $end) ? max(1, $start->diffInDays($end)) : 365;
            $elapsedDays = $start ? max(0, $start->diffInDays($now, false)) : 0;

            $progressRatio = 0.0;
            if ($userCycle->status === 'closed') {
                $progressRatio = 1.0;
            } elseif ($start && $end) {
                if ($now->lessThan($start)) {
                    $progressRatio = 0.0;
                } elseif ($now->greaterThanOrEqualTo($end)) {
                    $progressRatio = 1.0;
                } else {
                    $progressRatio = min(1.0, max(0.0, $start->diffInDays($now) / $totalDays));
                }
            }

            $daysRemaining = ($end && $now->lessThan($end)) ? $now->diffInDays($end) : 0;
            $isCycleEnded = ($userCycle->status === 'closed') || ($end && $now->greaterThanOrEqualTo($end));

            $cycleData = [
                'id'                  => $userCycle->id,
                'name'                => $userCycle->name,
                'status'              => $userCycle->status,
                'is_blocked'          => (bool)$userCycle->is_blocked,
                'start_date'          => $userCycle->start_date?->format('Y-m-d'),
                'end_date'            => $userCycle->end_date?->format('Y-m-d'),
                'total_days'          => $totalDays,
                'elapsed_days'        => $elapsedDays,
                'days_remaining'      => $daysRemaining,
                'progress_ratio'      => round($progressRatio, 4),
                'progress_percentage' => round($progressRatio * 100, 1),
                'is_ended'            => $isCycleEnded,
                'can_consign'         => $isCycleEnded || ($userCycle->status === 'closed'),
            ];
        }

        return response()->json([
            'status' => 'success',
            'member_dashboard' => [
                'cycle' => $cycleData,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role ?? 'individual',
                    'member_code' => $individual?->member_code ?? ('MEM-' . $user->id),
                    'branch_id' => $individual?->branch_id ?? 1,
                    'organisation_id' => $individual?->organisation_id ?? $user->organisation_id,
                    'organisation' => $individual?->organisation ? [
                        'id' => $individual->organisation->id,
                        'name' => $individual->organisation->name,
                        'code' => $individual->organisation->code,
                    ] : null,
                    'branch' => $individual?->branch ? [
                        'id' => $individual->branch->id,
                        'name' => $individual->branch->name,
                        'code' => $individual->branch->code,
                    ] : null,
                ],
                'entity_type' => $user->role ?? 'individual', // individual, organisation, branch
                'sensibilisateur' => $sensibilisateur,
                'trust_score' => (float)($individual?->trust_score ?? 100.0),
                'stars' => [
                    'caution_stars' => $cautionStarsData['stars'],
                    'caution_max' => 3,
                    'caution_ratio' => $cautionStarsData['ratio'],
                    'credit_stars' => $creditStarsData['stars'],
                    'credit_max' => 3,
                    'credit_ratio' => $creditStarsData['ratio'],
                ],
                'alerts' => [
                    'has_penalty_alert' => $alertData['has_penalty_alert'],
                    'color' => $alertData['alert_color'],
                ],
                'wallets' => [
                    'real_caution' => $totalCautionReal,
                    'bonus_caution' => $cautionBonus,
                    'total_caution' => $totalCautionReal + $cautionBonus,
                    'bonus_earned' => $totalBonus,
                    'malus_total' => $totalMalus,
                    'net_bonus' => max(0.0, $totalBonus - $totalMalus),
                ],
                'borrowing_capacity' => [
                    'max_multiplier_percentage' => $borrowCapacity['max_borrow_percentage'],
                    'max_allowed_credit' => $borrowCapacity['max_allowed_total'],
                    'active_debt' => $borrowCapacity['active_debt_balance'],
                    'max_loan_amount' => $borrowCapacity['available_borrow_capacity'],
                ],
                'deposit_streak_months' => $streak,
                'months_to_annual_bonus' => $monthsToBonus,
                'bonus_summary' => [
                    'mobilisation' => $bonusMobilisation,
                    'sponsorship' => $bonusSponsorship,
                    'caution_yield' => $bonusYield,
                    'recovery' => $bonusRecovery,
                    'total' => $totalBonus,
                ],
                'malus_summary' => [
                    'late_deposit' => $malusDeposit,
                    'late_credit' => $malusCredit,
                    'sponsorship' => $malusSponsorship,
                    'total' => $totalMalus,
                ],
                'active_credit' => $activeCredit,
                'sponsored_loans_count' => $sponsoredLoans->count(),
                'sponsored_loans' => $sponsoredLoans,
            ]
        ], 200);
    }
}
