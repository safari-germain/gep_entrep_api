<?php

namespace App\Http\Controllers;

use App\Models\Organisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganisationController extends Controller
{
    public function index(): JsonResponse
    {
        $orgs = Organisation::with(['branch'])
            ->withCount(['individuals', 'deposits', 'credits'])
            ->where('is_active', true)
            ->get();

        $enriched = $orgs->map(function ($org) {
            return $this->enrichPortfolio($org);
        });

        return response()->json($enriched);
    }

    public function show(int $id): JsonResponse
    {
        $org = Organisation::with([
            'branch',
            'individuals.user',
            'deposits' => fn($q) => $q->latest()->limit(20),
            'credits' => fn($q) => $q->latest()->limit(20),
            'bonuses' => fn($q) => $q->latest()->limit(20),
            'maluses' => fn($q) => $q->latest()->limit(20),
        ])->withCount(['individuals', 'deposits', 'credits'])->findOrFail($id);

        return response()->json($this->enrichPortfolio($org));
    }

    private function enrichPortfolio(Organisation $org): array
    {
        $memberUserIds = $org->individuals()->pluck('user_id')->filter()->toArray();

        $caution = (float) \App\Models\Deposit::where(function ($q) use ($org, $memberUserIds) {
            $q->where('organisation_id', $org->id);
            if (!empty($memberUserIds)) {
                $q->orWhereIn('user_id', $memberUserIds);
            }
        })->sum('actual_amount');

        $bonus = (float) \App\Models\Bonus::where('organisation_id', $org->id)->sum('amount');
        if (!empty($memberUserIds)) {
            $bonus += (float) \App\Models\Bonus::whereIn('user_id', $memberUserIds)->sum('amount');
        }

        $malus = (float) \App\Models\Malus::where(function ($q) use ($org, $memberUserIds) {
            $q->where('organisation_id', $org->id);
            if (!empty($memberUserIds)) {
                $q->orWhereIn('user_id', $memberUserIds);
            }
        })->where('is_active', true)->sum('penalty_amount');

        $creditsEncours = (float) \App\Models\Credit::where(function ($q) use ($org, $memberUserIds) {
            $q->where('target_organisation_id', $org->id);
            if (!empty($memberUserIds)) {
                $q->orWhereIn('user_id', $memberUserIds);
            }
        })->whereIn('status', ['active', 'in_arrears'])->sum('remaining_balance');

        $ordinaryBalance = (float) (\App\Models\OrdinaryAccount::where('entity_type', 'organisation')
            ->where('entity_id', $org->id)
            ->value('balance') ?? 0.0);

        $data = $org->toArray();
        $data['caution_balance'] = $caution;
        $data['bonus_balance'] = $bonus;
        $data['malus_balance'] = $malus;
        $data['credit_balance'] = $creditsEncours;
        $data['ordinary_account_balance'] = $ordinaryBalance;
        $data['members_count'] = count($memberUserIds);

        return $data;
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                 => 'required|string|max:255',
            'type'                 => 'nullable|string',
            'code'                 => 'nullable|string|unique:organisations,code',
            'phone'                => 'nullable|string',
            'email'                => 'nullable|email',
            'address'              => 'nullable|string',
            'representative_name'  => 'nullable|string',
            'representative_phone' => 'nullable|string',
            'branch_id'            => 'nullable|integer|exists:branches,id',
            'notes'                => 'nullable|string',
        ]);

        $org = Organisation::create(array_merge($validated, ['is_active' => true, 'status' => 'active']));
        $org->load('branch');
        return response()->json($this->enrichPortfolio($org), 201);
    }

    public function addMember(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'nullable|string',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        $org = Organisation::findOrFail($id);

        $user = null;
        if (!empty($validated['user_id'])) {
            $user = \App\Models\User::find($validated['user_id']);
        } elseif (!empty($validated['phone'])) {
            $user = \App\Models\User::where('phone', $validated['phone'])->first();
        }

        if (!$user) {
            return response()->json(['message' => 'Utilisateur introuvable.'], 404);
        }

        $user->organisation_id = $org->id;
        $user->save();

        $individual = \App\Models\Individual::where('user_id', $user->id)->first();
        if ($individual) {
            $individual->organisation_id = $org->id;
            $individual->save();
        }

        return response()->json([
            'message' => "Membre {$user->name} rattaché avec succès à l'organisation {$org->name}.",
            'user' => $user,
        ]);
    }

    public function removeMember(Request $request, int $id, int $userId): JsonResponse
    {
        $org = Organisation::findOrFail($id);
        $user = \App\Models\User::findOrFail($userId);

        if ($user->organisation_id == $org->id) {
            $user->organisation_id = null;
            $user->save();

            $individual = \App\Models\Individual::where('user_id', $user->id)->first();
            if ($individual) {
                $individual->organisation_id = null;
                $individual->save();
            }
        }

        return response()->json([
            'message' => "Membre {$user->name} retiré de l'organisation {$org->name}.",
        ]);
    }
}
