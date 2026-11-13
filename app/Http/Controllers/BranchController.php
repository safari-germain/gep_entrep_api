<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(): JsonResponse
    {
        $branches = Branch::withCount(['individuals', 'organisations', 'deposits', 'credits'])
            ->withSum('deposits', 'actual_amount')
            ->where('is_active', true)
            ->get();

        $enriched = $branches->map(function ($branch) {
            return $this->enrichBranchPortfolio($branch);
        });

        return response()->json($enriched);
    }

    public function show(int $id): JsonResponse
    {
        $branch = Branch::with([
            'organisations',
            'individuals.user',
            'deposits' => fn($q) => $q->latest()->limit(20),
            'credits' => fn($q) => $q->latest()->limit(20),
        ])->withCount(['individuals', 'organisations', 'deposits', 'credits'])
          ->withSum('deposits', 'actual_amount')
          ->findOrFail($id);

        return response()->json($this->enrichBranchPortfolio($branch));
    }

    private function enrichBranchPortfolio(Branch $branch): array
    {
        $caution = (float) \App\Models\Deposit::where('branch_id', $branch->id)->sum('actual_amount');
        $bonus = (float) \App\Models\Bonus::where('branch_id', $branch->id)->sum('amount');
        $malus = (float) \App\Models\Malus::where('branch_id', $branch->id)->where('status', 'active')->sum('amount');
        $creditsEncours = (float) \App\Models\Credit::where(function ($q) use ($branch) {
            $q->where('branch_id', $branch->id)
              ->orWhere('target_branch_id', $branch->id);
        })->whereIn('status', ['active', 'in_arrears'])->sum('remaining_balance');

        $ordinaryBalance = (float) (\App\Models\OrdinaryAccount::where('entity_type', 'branch')
            ->where('entity_id', $branch->id)
            ->value('balance') ?? 0.0);

        $data = $branch->toArray();
        $data['total_depots'] = $caution;
        $data['deposits_sum_actual_amount'] = $caution;
        $data['total_bonus'] = $bonus;
        $data['bonus_balance'] = $bonus;
        $data['total_malus'] = $malus;
        $data['malus_balance'] = $malus;
        $data['total_credit_encours'] = $creditsEncours;
        $data['credit_balance'] = $creditsEncours;
        $data['ordinary_account_balance'] = $ordinaryBalance;

        return $data;
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'code'          => 'required|string|unique:branches,code',
            'province'      => 'nullable|string',
            'territory_city'=> 'nullable|string',
            'address'       => 'nullable|string',
            'phone'         => 'nullable|string',
            'manager_name'  => 'nullable|string',
        ]);

        $branch = Branch::create(array_merge($validated, ['is_active' => true]));
        return response()->json($branch, 201);
    }
}
