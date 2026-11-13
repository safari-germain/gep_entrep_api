<?php

namespace App\Http\Controllers;

use App\Services\OhadaAccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Carbon\Carbon;

class OhadaReportController extends Controller
{
    public function __construct(private readonly OhadaAccountingService $ohadaService)
    {
    }

    /**
     * GET /api/reports/ohada/bilan
     * Retourne le Bilan OHADA actif/passif pour une période.
     */
    public function bilan(Request $request): JsonResponse
    {
        $request->validate([
            'period_start' => 'nullable|date',
            'period_end'   => 'nullable|date',
        ]);

        try {
            $bilan = $this->ohadaService->getBilan(
                $request->input('period_start'),
                $request->input('period_end')
            );

            return response()->json([
                'success' => true,
                'data'    => $bilan,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/reports/ohada/compte-exploitation
     * Retourne le Compte de Résultat (Produits - Charges).
     */
    public function compteExploitation(Request $request): JsonResponse
    {
        $request->validate([
            'period_start' => 'nullable|date',
            'period_end'   => 'nullable|date',
        ]);

        try {
            $exploitation = $this->ohadaService->getCompteExploitation(
                $request->input('period_start'),
                $request->input('period_end')
            );

            return response()->json([
                'success' => true,
                'data'    => $exploitation,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/reports/ohada/export-html
     * Retourne le rapport HTML imprimable (Bilan + Compte d'Exploitation).
     */
    public function exportHtml(Request $request): Response
    {
        $request->validate([
            'period_start' => 'nullable|date',
            'period_end'   => 'nullable|date',
            'institution'  => 'nullable|string|max:200',
        ]);

        try {
            $periodStart  = $request->input('period_start');
            $periodEnd    = $request->input('period_end');
            $institution  = $request->input('institution', 'GEP Solidarité');

            $bilan        = $this->ohadaService->getBilan($periodStart, $periodEnd);
            $exploitation = $this->ohadaService->getCompteExploitation($periodStart, $periodEnd);
            $html         = $this->ohadaService->generateHtmlReport($bilan, $exploitation, $institution);

            $dateStamp = Carbon::now()->format('Ymd_His');
            $filename  = "bilan_ohada_{$dateStamp}.html";

            return response($html, 200, [
                'Content-Type'        => 'text/html; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);
        } catch (\Exception $e) {
            return response('Erreur: ' . $e->getMessage(), 500, ['Content-Type' => 'text/plain']);
        }
    }

    /**
     * GET /api/reports/ohada/dashboard
     * Vue synthétique pour le tableau de bord admin (KPIs).
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $exploitation = $this->ohadaService->getCompteExploitation();
            $bilan        = $this->ohadaService->getBilan();

            return response()->json([
                'success' => true,
                'data'    => [
                    'total_actif'     => $bilan['actif']['total'],
                    'total_passif'    => $bilan['passif']['total'],
                    'total_produits'  => $exploitation['produits']['total'],
                    'total_charges'   => $exploitation['charges']['total'],
                    'resultat_net'    => $exploitation['resultat_net'],
                    'resultat_nature' => $exploitation['resultat_nature'],
                    'periode'         => $bilan['periode'],
                    'equilibre'       => $bilan['equilibre'],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
