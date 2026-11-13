<?php

namespace App\Http\Controllers;

use App\Models\Bonus;
use App\Models\Malus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BonusMalusController extends Controller
{
    public function bonuses(Request $request): JsonResponse
    {
        $query = Bonus::with(['user', 'branch', 'sensibilisateur', 'organisation'])->orderBy('id', 'desc');

        if ($request->has('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }
        if ($request->has('bonus_type')) {
            $query->where('bonus_type', $request->query('bonus_type'));
        }

        return response()->json($query->paginate($request->query('per_page', 50)));
    }

    public function maluses(Request $request): JsonResponse
    {
        $query = Malus::with(['user', 'branch', 'credit', 'deposit'])->orderBy('id', 'desc');

        if ($request->has('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }
        if ($request->has('malus_type')) {
            $query->where('malus_type', $request->query('malus_type'));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json($query->paginate($request->query('per_page', 50)));
    }

    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = $user->id;

        $bonuses = Bonus::where('user_id', $userId)->orderBy('created_at', 'desc')->get();
        $maluses = Malus::where('user_id', $userId)->orderBy('created_at', 'desc')->get();

        $mobilisationBonuses = $bonuses->whereIn('bonus_type', ['mobilisation', 'mobilisation_bonus', 'mobilisation_bonus_annual_closing'])->values();
        $sponsorshipBonuses = $bonuses->whereIn('bonus_type', ['parrainage', 'sponsorship_bonus'])->values();
        $cautionYieldBonuses = $bonuses->whereIn('bonus_type', ['epargne_interet', 'caution_yield_bonus'])->values();
        $recoveryBonuses = $bonuses->whereIn('bonus_type', ['recouvrement_penalite', 'penalty_recovery_agent_bonus', 'penalty_recovery_org_bonus'])->values();

        $lateDepositMaluses = collect([]); // Pas de malus sur caution (géré par découvert compte ordinaire)
        $lateCreditMaluses = $maluses->whereIn('malus_type', ['retard_emprunt', 'late_credit_payment'])->values();
        $sponsorshipMaluses = $maluses->whereIn('malus_type', ['parrainage_impaye', 'sponsorship_malus'])->values();

        $totalBonus = (float) $bonuses->sum('amount');
        $totalMalus = (float) ($lateCreditMaluses->sum('penalty_amount') + $sponsorshipMaluses->sum('penalty_amount'));

        return response()->json([
            'summary' => [
                'total_bonus' => $totalBonus,
                'total_malus' => $totalMalus,
                'net_balance' => max(0.0, $totalBonus - $totalMalus),
            ],
            'bonus_categories' => [
                'mobilisation' => [
                    'total' => (float) $mobilisationBonuses->sum('amount'),
                    'items' => $mobilisationBonuses,
                ],
                'sponsorship' => [
                    'total' => (float) $sponsorshipBonuses->sum('amount'),
                    'items' => $sponsorshipBonuses,
                ],
                'caution_yield' => [
                    'total' => (float) $cautionYieldBonuses->sum('amount'),
                    'items' => $cautionYieldBonuses,
                ],
                'recovery' => [
                    'total' => (float) $recoveryBonuses->sum('amount'),
                    'items' => $recoveryBonuses,
                ],
            ],
            'malus_categories' => [
                'late_deposit' => [
                    'total' => (float) $lateDepositMaluses->sum('penalty_amount'),
                    'items' => $lateDepositMaluses,
                ],
                'late_credit' => [
                    'total' => (float) $lateCreditMaluses->sum('penalty_amount'),
                    'items' => $lateCreditMaluses,
                ],
                'sponsorship' => [
                    'total' => (float) $sponsorshipMaluses->sum('penalty_amount'),
                    'items' => $sponsorshipMaluses,
                ],
            ],
        ]);
    }
}

