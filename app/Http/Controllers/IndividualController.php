<?php

namespace App\Http\Controllers;

use App\Models\Bonus;
use App\Models\Deposit;
use App\Models\Heir;
use App\Models\Individual;
use App\Models\Malus;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IndividualController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Individual::with(['user', 'branch', 'organisation', 'sensibilisateur', 'heirs'])
            ->orderBy('id', 'desc');

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }

        $individuals = $query->paginate($request->query('per_page', 50));

        $individuals->getCollection()->transform(function ($item) {
            $userId = $item->user_id;

            $realCaution = (float) Deposit::where('user_id', $userId)->sum('actual_amount');
            $cautionBonus = (float) Bonus::where('user_id', $userId)
                ->whereIn('bonus_type', ['caution_yield_bonus', 'epargne_interet'])
                ->sum('amount');
            $ordinaryAccount = \App\Models\OrdinaryAccount::where('user_id', $userId)->first();
            $ordinaryBalance = (float) ($ordinaryAccount?->balance ?? 0.0);

            $bonusEarned = (float) Bonus::where('user_id', $userId)->sum('amount');
            $totalMalus = (float) Malus::where('user_id', $userId)->where('is_active', true)->sum('penalty_amount');
            $guaranteedDebtMalus = (float) Malus::where('user_id', $userId)->where('is_active', true)->sum('guaranteed_debt_share');

            // 4 catégories de bonus détaillées
            $bonusMobilisation = (float) Bonus::where('user_id', $userId)->where('bonus_type', 'mobilisation')->sum('amount');
            $bonusParrainage = (float) Bonus::where('user_id', $userId)->where('bonus_type', 'parrainage')->sum('amount');
            $bonusEpargne = (float) Bonus::where('user_id', $userId)->whereIn('bonus_type', ['epargne_interet', 'caution_yield_bonus'])->sum('amount');
            $bonusRecouvrement = (float) Bonus::where('user_id', $userId)->where('bonus_type', 'recouvrement_penalite')->sum('amount');

            // Catégories de malus détaillées (cotisation supprimé car géré par découvert compte ordinaire)
            $malusCotisation = 0.0;
            $malusRetardEmprunt = (float) Malus::where('user_id', $userId)->where('malus_type', 'retard_emprunt')->sum('penalty_amount');
            $malusPenaliteEmprunt = (float) Malus::where('user_id', $userId)->where('malus_type', 'penalite_emprunt')->sum('penalty_amount');
            $malusParrainageDefaut = (float) Malus::where('user_id', $userId)->where('malus_type', 'parrainage_impaye')->sum('guaranteed_debt_share');

            return array_merge($item->toArray(), [
                'real_caution'           => $realCaution,
                'bonus_caution'          => $cautionBonus,
                'general_balance'        => $ordinaryBalance,
                'bonus_earned'           => $bonusEarned,
                'total_malus'            => $totalMalus + $guaranteedDebtMalus,
                'bonus_details'          => [
                    'mobilisation'        => $bonusMobilisation,
                    'parrainage'          => $bonusParrainage,
                    'epargne_interet'     => $bonusEpargne,
                    'recouvrement'        => $bonusRecouvrement,
                ],
                'malus_details'          => [
                    'retard_cotisation'   => $malusCotisation,
                    'retard_emprunt'      => $malusRetardEmprunt,
                    'penalite_emprunt'    => $malusPenaliteEmprunt,
                    'parrainage_defaut'   => $malusParrainageDefaut,
                ],
                'balances'               => [
                    'caution_reelle'     => $realCaution,
                    'bonus_caution'      => $cautionBonus,
                    'compte_general'     => $ordinaryBalance,
                    'compte_ordinaire'   => $ordinaryBalance,
                    'bonus_gagne'        => $bonusEarned,
                    'total_malus'        => $totalMalus + $guaranteedDebtMalus,
                ],
            ]);
        });

        return response()->json($individuals);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id'                  => 'required|exists:branches,id',
            'name'                       => 'required|string|max:255',
            'prenom'                     => 'nullable|string|max:255',
            'email'                      => 'nullable|string|email|max:255|unique:users,email',
            'phone'                      => 'required|string|max:50|unique:users,phone',
            'password'                   => 'nullable|string|min:6',
            'organisation_id'            => 'nullable|exists:organisations,id',
            'sponsor_user_id'            => 'nullable|exists:users,id',
            'sensibilisateur_code_promo' => 'nullable|string|max:50',
            'national_id_number'         => 'nullable|string|max:100',
            'profession'                 => 'nullable|string|max:255',
            'activity_sector'            => 'nullable|string|max:255',
            'emergency_contact_name'     => 'nullable|string|max:255',
            'emergency_contact_phone'    => 'nullable|string|max:50',
            'emergency_contact_relation' => 'nullable|string|max:100',
        ]);

        $sensibilisateurId = null;
        if (!empty($validated['sensibilisateur_code_promo'])) {
            $sensi = \App\Models\Sensibilisateur::where('code_promo', $validated['sensibilisateur_code_promo'])->first();
            $sensibilisateurId = $sensi?->id;
        }

        $fullName = trim(($validated['name'] ?? '') . ' ' . ($validated['prenom'] ?? ''));

        $user = User::create([
            'name'            => $fullName,
            'prenom'          => $validated['prenom'] ?? null,
            'email'           => $validated['email'] ?? null,
            'phone'           => $validated['phone'],
            'password'        => \Illuminate\Support\Facades\Hash::make($validated['password'] ?? 'gep_entreprendre@2026'),
            'role'            => 'individual',
            'branch_id'       => $validated['branch_id'],
            'organisation_id' => $validated['organisation_id'] ?? null,
            'status'          => 'actif',
        ]);

        $individual = Individual::create([
            'user_id'                    => $user->id,
            'branch_id'                  => $validated['branch_id'],
            'organisation_id'            => $validated['organisation_id'] ?? null,
            'sponsor_user_id'            => $validated['sponsor_user_id'] ?? null,
            'sensibilisateur_id'         => $sensibilisateurId,
            'member_code'                => 'MEM-' . str_pad($user->id, 5, '0', STR_PAD_LEFT),
            'national_id_number'         => $validated['national_id_number'] ?? null,
            'profession'                 => $validated['profession'] ?? null,
            'activity_sector'            => $validated['activity_sector'] ?? null,
            'emergency_contact_name'     => $validated['emergency_contact_name'] ?? null,
            'emergency_contact_phone'    => $validated['emergency_contact_phone'] ?? null,
            'emergency_contact_relation' => $validated['emergency_contact_relation'] ?? null,
            'trust_score'                => 100.0,
            'cotisation_stars'           => 3.0,
            'credit_stars'               => 3.0,
            'deposit_streak_months'      => 0,
            'status'                     => 'active',
        ]);

        $individual->load(['user', 'branch', 'organisation', 'sensibilisateur']);

        return response()->json([
            'message'    => "Entrepreneur {$user->name} inscrit avec succès.",
            'individual' => $individual,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $individual = Individual::with(['user', 'branch', 'organisation', 'sensibilisateur', 'heirs'])->findOrFail($id);
        $userId = $individual->user_id;

        $individual->recalculateStarsAndRedDot();
        $individual->refresh();

        $bonuses = Bonus::where('user_id', $userId)->orderBy('id', 'desc')->get();
        $maluses = Malus::where('user_id', $userId)->orderBy('id', 'desc')->get();

        return response()->json([
            'individual' => $individual,
            'bonuses'    => $bonuses,
            'maluses'    => $maluses,
        ]);
    }

    public function addHeir(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'full_name'          => 'required|string|max:255',
            'relationship'       => 'required|string|max:100',
            'phone'              => 'nullable|string|max:50',
            'national_id_number' => 'nullable|string|max:100',
            'percentage'         => 'required|numeric|between:1,100',
        ]);

        $heir = Heir::create([
            'user_id'            => $userId,
            'full_name'          => $validated['full_name'],
            'relationship'       => $validated['relationship'],
            'phone'              => $validated['phone'] ?? null,
            'national_id_number' => $validated['national_id_number'] ?? null,
            'percentage'         => $validated['percentage'],
            'status'             => 'active',
        ]);

        return response()->json([
            'message' => 'Héritier enregistré avec succès.',
            'heir'    => $heir,
        ], 201);
    }
}
