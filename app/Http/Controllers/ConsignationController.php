<?php

namespace App\Http\Controllers;

use App\Models\Consignation;
use App\Models\Cycle;
use App\Models\Deposit;
use App\Models\Individual;
use App\Models\OrdinaryAccount;
use App\Models\User;
use App\Services\OrdinaryAccountService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsignationController extends Controller
{
    /**
     * GET /api/consignations
     * Liste des cautions consignées avec filtres et statistiques
     */
    public function index(Request $request): JsonResponse
    {
        $query = Consignation::with([
            'user.individual',
            'cycle:id,name,start_date,end_date,status',
            'branch:id,name',
            'creator:id,name',
        ])->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('cycle_id')) {
            $query->where('cycle_id', $request->cycle_id);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('phone', 'like', "%{$search}%");
                  })
                  ->orWhereHas('user.individual', function ($iq) use ($search) {
                      $iq->where('member_code', 'like', "%{$search}%");
                  })
                  ->orWhereHas('cycle', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $consignations = $query->get();

        $stats = [
            'total_count'          => $consignations->count(),
            'active_count'         => $consignations->where('status', 'active')->count(),
            'reconduit_count'      => $consignations->where('status', 'reconduit')->count(),
            'libere_count'         => $consignations->where('status', 'libere')->count(),
            'total_amount'         => round($consignations->sum('amount'), 2),
            'active_amount'        => round($consignations->where('status', 'active')->sum('amount'), 2),
        ];

        return response()->json([
            'consignations' => $consignations,
            'summary'       => $stats,
        ]);
    }

    /**
     * POST /api/consignations
     * Création / enregistrement d'une consignation de caution (ex. en fin de cycle)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'                => 'required|integer|exists:users,id',
            'cycle_id'               => 'required|integer|exists:cycles,id',
            'branch_id'              => 'nullable|integer|exists:branches,id',
            'amount'                 => 'required|numeric|min:1',
            'initial_caution_amount' => 'nullable|numeric|min:0',
            'bonus_applied'          => 'nullable|numeric|min:0',
            'malus_deducted'         => 'nullable|numeric|min:0',
            'notes'                  => 'nullable|string|max:500',
        ]);

        $admin = Auth::user();
        $cycle = Cycle::findOrFail($validated['cycle_id']);

        $ref = 'CSG-' . date('Ymd') . '-' . strtoupper(Str::random(5));

        $consignation = Consignation::create([
            'reference'              => $ref,
            'user_id'                => $validated['user_id'],
            'cycle_id'               => $validated['cycle_id'],
            'branch_id'              => $validated['branch_id'] ?? $cycle->branch_id,
            'amount'                 => (float) $validated['amount'],
            'initial_caution_amount' => (float) ($validated['initial_caution_amount'] ?? $validated['amount']),
            'bonus_applied'          => (float) ($validated['bonus_applied'] ?? 0.0),
            'malus_deducted'         => (float) ($validated['malus_deducted'] ?? 0.0),
            'status'                 => 'active',
            'consignation_date'      => now(),
            'notes'                  => $validated['notes'] ?? 'Consignation de caution de cycle',
            'created_by_user_id'     => $admin ? $admin->id : null,
        ]);

        return response()->json([
            'status'        => true,
            'message'       => 'La caution a été consignée avec succès.',
            'consignation'  => $consignation->load(['user.individual', 'cycle', 'branch']),
        ], 201);
    }

    /**
     * POST /api/consignations/{id}/release
     * Libération de la caution consignée : versement direct sur le compte ordinaire
     */
    public function release(Request $request, int $id): JsonResponse
    {
        $consignation = Consignation::findOrFail($id);

        if ($consignation->status !== 'active') {
            return response()->json([
                'message' => 'Cette consignation ne peut pas être libérée (statut actuel: ' . $consignation->status . ').'
            ], 422);
        }

        $validated = $request->validate([
            'destination' => 'nullable|string|in:ordinary_account,cash',
            'notes'       => 'nullable|string|max:500',
        ]);

        $destination = $validated['destination'] ?? 'ordinary_account';
        $user = $consignation->user;
        $individual = $user?->individual;

        DB::beginTransaction();
        try {
            $accountService = app(OrdinaryAccountService::class);

            if ($individual) {
                $account = OrdinaryAccount::getOrCreateForEntity('individual', $individual->id, $user->id);
            } else {
                $account = OrdinaryAccount::getOrCreateForEntity('user', $user->id, $user->id);
            }

            if ($destination === 'ordinary_account') {
                $accountService->deposit(
                    $account,
                    $consignation->amount,
                    'consignation_release',
                    "Restitution de la caution consignée [Réf: {$consignation->reference}]",
                    Auth::id(),
                    'deposit'
                );
            }

            $consignation->update([
                'status'                 => 'libere',
                'release_date'           => now(),
                'released_to_account_id' => $account->id,
                'notes'                  => ($consignation->notes ? $consignation->notes . ' | ' : '') . ($validated['notes'] ?? 'Caution libérée vers compte ordinaire'),
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Erreur lors de la libération : ' . $e->getMessage()], 500);
        }

        return response()->json([
            'status'        => true,
            'message'       => 'La caution consignée a été libérée avec succès.',
            'consignation'  => $consignation->fresh(['user.individual', 'cycle', 'branch']),
        ]);
    }

    /**
     * POST /api/consignations/{id}/rollover
     * Reconduction de la caution consignée vers un nouveau cycle
     */
    public function rollover(Request $request, int $id): JsonResponse
    {
        $consignation = Consignation::findOrFail($id);

        if ($consignation->status !== 'active') {
            return response()->json([
                'message' => 'Cette consignation ne peut pas être reconduite (statut actuel: ' . $consignation->status . ').'
            ], 422);
        }

        $validated = $request->validate([
            'target_cycle_id' => 'required|integer|exists:cycles,id',
            'notes'           => 'nullable|string|max:500',
        ]);

        $targetCycle = Cycle::findOrFail($validated['target_cycle_id']);

        DB::beginTransaction();
        try {
            // Créer le dépôt dans le nouveau cycle
            Deposit::create([
                'user_id'           => $consignation->user_id,
                'cycle_id'          => $targetCycle->id,
                'deposit_reference' => 'CSG-ROLL-' . strtoupper(Str::random(6)),
                'actual_amount'     => $consignation->amount,
                'payment_method'    => 'consignation_rollover',
                'is_on_time'        => true,
                'remarks'           => "Reconduction caution consignée [Réf: {$consignation->reference}]",
                'status'            => 'approved',
                'branch_id'         => $consignation->branch_id ?? $targetCycle->branch_id,
            ]);

            $consignation->update([
                'status'            => 'reconduit',
                'rollover_cycle_id' => $targetCycle->id,
                'notes'             => ($consignation->notes ? $consignation->notes . ' | ' : '') . ($validated['notes'] ?? "Reconduit vers cycle {$targetCycle->name}"),
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Erreur lors de la reconduction : ' . $e->getMessage()], 500);
        }

        return response()->json([
            'status'        => true,
            'message'       => "La caution a été reconduite avec succès vers le cycle \"{$targetCycle->name}\".",
            'consignation'  => $consignation->fresh(['user.individual', 'cycle', 'branch']),
        ]);
    }
}
