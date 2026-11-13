<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Cycle;
use App\Models\Deposit;
use App\Models\FinancialRule;
use App\Models\Individual;
use App\Models\Organisation;
use App\Models\User;
use App\Services\BonusCalculationService;
use App\Services\FinancialRuleService;
use App\Services\MalusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DepositController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Deposit::with(['user', 'branch', 'organisation', 'targetBranch'])->latest();

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('branch_id')) {
            $query->where(function($q) use ($request) {
                $q->where('branch_id', $request->branch_id)
                  ->orWhere('target_branch_id', $request->branch_id);
            });
        }
        if ($request->filled('organisation_id')) {
            $query->where('organisation_id', $request->organisation_id);
        }
        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }

        $deposits = $query->paginate(20);
        return response()->json($deposits);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_type'       => 'nullable|string|in:individual,organisation,branch',
            'user_id'           => 'nullable|exists:users,id',
            'organisation_id'   => 'nullable|exists:organisations,id',
            'target_branch_id'  => 'nullable|exists:branches,id',
            'branch_id'         => 'nullable|exists:branches,id',
            'amount'            => 'required|numeric|min:1',
            'cycle_month'       => 'nullable|integer|min:1|max:12',
            'cycle_id'          => 'nullable|integer|exists:cycles,id',
            'is_late'           => 'nullable|boolean',
            'delay_days'        => 'nullable|integer|min:0',
            'payment_method'    => 'nullable|string',
            'payment_reference' => 'nullable|string|max:100',
            'remarks'           => 'nullable|string',
        ]);

        $entityType = $validated['entity_type'] ?? 'individual';
        $paymentMethod = $validated['payment_method'] ?? 'cash';

        // Référence obligatoire sauf pour cash
        if ($paymentMethod !== 'cash' && empty($validated['payment_reference'])) {
            return response()->json(['message' => 'La référence de paiement est obligatoire pour ce mode de paiement.'], 422);
        }

        $user = null;
        $individual = null;
        $org = null;
        $targetBranch = null;
        $branchId = 1;
        $cycleMonth = $validated['cycle_month'] ?? null;
        $remarks = $validated['remarks'] ?? null;

        if ($entityType === 'individual') {
            $userId = $validated['user_id'] ?? null;
            if (!$userId) {
                return response()->json(['message' => 'Veuillez sélectionner un entrepreneur ou membre.'], 422);
            }
            $user = User::findOrFail($userId);
            $individual = Individual::where('user_id', $user->id)->first();
            $branchId = $individual->branch_id ?? $user->branch_id ?? 1;

            if (!$cycleMonth) {
                $count = Deposit::where('user_id', $user->id)->count();
                $cycleMonth = ($count % 12) + 1;
            }
            $remarks = $remarks ?: "Versement mois #{$cycleMonth} de caution solidaire - {$user->name}";
        } elseif ($entityType === 'organisation') {
            $orgId = $validated['organisation_id'] ?? null;
            if (!$orgId) {
                return response()->json(['message' => 'Veuillez sélectionner une organisation.'], 422);
            }
            $org = Organisation::findOrFail($orgId);
            $branchId = $org->branch_id ?? 1;

            if (!$cycleMonth) {
                $count = Deposit::where('organisation_id', $org->id)->count();
                $cycleMonth = ($count % 12) + 1;
            }
            $remarks = $remarks ?: "Versement mois #{$cycleMonth} de caution solidaire - Org: {$org->name}";
        } elseif ($entityType === 'branch') {
            $bId = $validated['target_branch_id'] ?? $validated['branch_id'] ?? null;
            if (!$bId) {
                return response()->json(['message' => 'Veuillez sélectionner une succursale.'], 422);
            }
            $targetBranch = Branch::findOrFail($bId);
            $branchId = $targetBranch->id;

            if (!$cycleMonth) {
                $count = Deposit::where('entity_type', 'branch')
                    ->where(function($q) use ($branchId) {
                        $q->where('target_branch_id', $branchId)->orWhere('branch_id', $branchId);
                    })->count();
                $cycleMonth = ($count % 12) + 1;
            }
            $remarks = $remarks ?: "Versement mois #{$cycleMonth} de caution solidaire - Succursale: {$targetBranch->name}";
        }

        // Associer au cycle actif s'il n'est pas spécifié
        $cycleId = $validated['cycle_id'] ?? null;
        if (!$cycleId) {
            $activeCycle = Cycle::where('status', 'active')->latest('start_date')->first();
            $cycleId = $activeCycle?->id;
        }

        $graceDays = (int) FinancialRuleService::get('cotisation_delai_jour_max', 10);
        $delayDays = $validated['delay_days'] ?? 0;
        $isLate = $validated['is_late'] ?? ($delayDays > $graceDays);

        $deposit = Deposit::create([
            'deposit_reference'   => 'DEP-' . strtoupper(Str::random(8)),
            'entity_type'         => $entityType,
            'user_id'             => $user?->id,
            'organisation_id'     => $org?->id,
            'target_branch_id'    => $targetBranch?->id,
            'branch_id'           => $branchId,
            'cycle_id'            => $cycleId,
            'operator_id'         => $request->user()->id ?? ($user?->id ?? 1),
            'expected_amount'     => $validated['amount'],
            'actual_amount'       => $validated['amount'],
            'expected_date'       => now(),
            'actual_date'         => now(),
            'delay_days'          => $delayDays,
            'wallet_destination'  => 'caution_solidaire',
            'payment_method'      => $paymentMethod,
            'payment_reference'   => $validated['payment_reference'] ?? ('CASH-' . strtoupper(Str::random(6))),
            'is_on_time'          => !$isLate,
            'remarks'             => $remarks,
        ]);

        $appliedMalus = null;
        $cotisationBonus = null;
        $mobilisationBonus = null;
        $fidelity13thBonus = null;

        if ($entityType === 'individual') {
            // 1. Malus si retard
            if ($isLate) {
                $appliedMalus = MalusService::applyLateDepositMalus($deposit, $request->user()->id ?? null);
            } else {
                if ($individual) {
                    $individual->increment('deposit_streak_months');
                }
            }

            // 2. Bonus intérêt cotisation
            $cotisationBonus = BonusCalculationService::processDepositInterestBonus($deposit);

            // 3. Bonus mobilisation sensibilisateur
            $mobilisationBonus = BonusCalculationService::processMobilisationBonus($deposit);

            // 4. Bonus 13ème mois
            $fidelity13thBonus = BonusCalculationService::checkAndApply13thMonthBonus($user->id, $request->user()->id ?? null);

            // 5. Recalculer les étoiles
            if ($individual) {
                $individual->recalculateStarsAndRedDot();
                $individual->refresh();
            }
        } elseif ($entityType === 'organisation') {
            $org->increment('caution_balance', $validated['amount']);

            if (!$isLate) {
                $interestRate = (float) FinancialRuleService::get('cotisation_interet_taux_annuel', 8.33);
                $bonusAmount = round(($validated['amount'] * $interestRate) / 100, 2);
                $org->increment('bonus_balance', $bonusAmount);
            }
            $org->refresh();
        }

        $deposit->load(['user', 'branch', 'organisation', 'targetBranch']);

        return response()->json([
            'message'             => 'Versement de caution enregistré avec succès.',
            'deposit'             => $deposit,
            'cycle_month'         => $cycleMonth,
            'malus'               => $appliedMalus,
            'bonus_cotisation'    => $cotisationBonus,
            'bonus_mobilisation'  => $mobilisationBonus,
            'bonus_13eme_mois'    => $fidelity13thBonus,
            'individual_stars'    => $individual ? [
                'cotisation_stars' => $individual->cotisation_stars ?? 3.0,
                'credit_stars'     => $individual->credit_stars ?? 3.0,
                'has_red_dot'      => $individual->has_red_dot ?? false,
            ] : null,
        ], 201);
    }
}