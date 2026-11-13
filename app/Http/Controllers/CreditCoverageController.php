<?php

namespace App\Http\Controllers;

use App\Services\CreditCoverageService;
use App\Models\CreditCoverageCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditCoverageController extends Controller
{
    public function __construct(private readonly CreditCoverageService $coverageService)
    {
    }

    /**
     * GET /api/coverage/my-account
     * Compte couverture du parrain connecté (visible uniquement si il a des appels en couverture).
     */
    public function myAccount(Request $request): JsonResponse
    {
        $userId = auth()->id();
        $summary = $this->coverageService->getGuarantorCoverageAccount($userId);

        if (!$summary['has_coverage_calls']) {
            return response()->json([
                'success'            => true,
                'has_coverage_calls' => false,
                'message'            => 'Aucun appel en couverture actif.',
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => $summary,
        ]);
    }

    /**
     * GET /api/coverage/users/{userId}
     * Vue admin du compte couverture d'un parrain.
     */
    public function userAccount(Request $request, int $userId): JsonResponse
    {
        try {
            $summary = $this->coverageService->getGuarantorCoverageAccount($userId);

            return response()->json([
                'success' => true,
                'data'    => $summary,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/coverage/calls/{callId}/settle
     * Règle un appel en couverture depuis le compte ordinaire.
     * Body: { amount }
     */
    public function settle(Request $request, int $callId): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        $call = CreditCoverageCall::findOrFail($callId);

        // Vérifier que l'utilisateur est bien le parrain concerné ou admin
        $userId = auth()->id();
        if ($call->guarantor_user_id !== $userId) {
            // Vérifier droit admin
            $user = auth()->user();
            if (!$user || !in_array($user->role ?? '', ['admin', 'financier', 'gestionnaire'])) {
                return response()->json(['success' => false, 'message' => 'Non autorisé.'], 403);
            }
        }

        try {
            $settlement = $this->coverageService->settleFromOrdinaryAccount(
                $call,
                (float) $request->input('amount'),
                $userId
            );

            return response()->json([
                'success'    => true,
                'message'    => 'Règlement effectué avec succès.',
                'settlement' => [
                    'id'             => $settlement->id,
                    'settled_amount' => $settlement->settled_amount,
                    'settled_at'     => $settlement->settled_at,
                    'call_status'    => $call->fresh()->status,
                    'remaining'      => $call->fresh()->remaining_liability,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/coverage/admin/pending
     * Vue admin: liste des appels en couverture actifs/partiels.
     */
    public function adminPending(Request $request): JsonResponse
    {
        $calls = CreditCoverageCall::with(['credit', 'credit.guarantors'])
            ->whereIn('status', ['pending', 'partial'])
            ->orderByDesc('triggered_at')
            ->get()
            ->map(function ($call) {
                return [
                    'id'                  => $call->id,
                    'credit_id'           => $call->credit_id,
                    'credit_number'       => $call->credit?->credit_number,
                    'guarantor_user_id'   => $call->guarantor_user_id,
                    'called_amount'       => $call->called_amount,
                    'settled_amount'      => $call->settled_amount ?? 0,
                    'remaining_liability' => $call->remaining_liability,
                    'status'              => $call->status,
                    'triggered_at'        => $call->triggered_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $calls->values(),
            'total'   => $calls->count(),
        ]);
    }

    /**
     * POST /api/coverage/trigger-check
     * Déclenche manuellement la vérification des crédits en défaut (admin only).
     */
    public function triggerCheck(Request $request): JsonResponse
    {
        try {
            $results = $this->coverageService->triggerCoverageForDefaultedCredits();

            return response()->json([
                'success' => true,
                'results' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
