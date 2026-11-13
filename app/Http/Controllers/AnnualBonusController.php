<?php

namespace App\Http\Controllers;

use App\Services\AnnualBonusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnualBonusController extends Controller
{
    public function __construct(private readonly AnnualBonusService $bonusService)
    {
    }

    /**
     * GET /api/annual-bonus/my-account?year=2026
     * Compte bonus annuel de l'utilisateur connecté.
     */
    public function myAccount(Request $request): JsonResponse
    {
        $year   = (int) $request->input('year', date('Y'));
        $userId = auth()->id();

        $data = $this->bonusService->getUserBonusAccount($userId, $year);

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * GET /api/annual-bonus/users/{userId}?year=2026
     * Vue admin du compte bonus annuel d'un utilisateur.
     */
    public function userAccount(Request $request, int $userId): JsonResponse
    {
        $year = (int) $request->input('year', date('Y'));
        $data = $this->bonusService->getUserBonusAccount($userId, $year);

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * GET /api/annual-bonus/admin/all?year=2026
     * Liste de tous les comptes bonus (vue admin).
     */
    public function adminAll(Request $request): JsonResponse
    {
        $year = (int) $request->input('year', date('Y'));
        $data = $this->bonusService->getAllBonusAccounts($year);

        return response()->json([
            'success' => true,
            'data'    => $data,
            'year'    => $year,
        ]);
    }

    /**
     * POST /api/annual-bonus/disburse
     * Déclenche le versement fin d'année (admin only).
     * Body: { year }
     */
    public function disburse(Request $request): JsonResponse
    {
        $request->validate([
            'year' => 'nullable|integer|min:2020|max:2100',
        ]);

        $year = (int) $request->input('year', date('Y'));

        // Vérifier qu'on est bien en fin d'année ou qu'on force manuellement
        $force = $request->boolean('force', false);
        if (!$force && date('m') !== '12') {
            return response()->json([
                'success' => false,
                'message' => 'Le versement annuel n\'est possible qu\'en décembre. Utilisez force=true pour forcer.',
            ], 422);
        }

        try {
            $results = $this->bonusService->processYearEndDisbursement($year, auth()->id());

            return response()->json([
                'success' => true,
                'year'    => $year,
                'results' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
