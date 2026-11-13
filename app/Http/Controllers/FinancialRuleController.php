<?php

namespace App\Http\Controllers;

use App\Models\FinancialRule;
use App\Services\FinancialRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialRuleController extends Controller
{
    public function index(): JsonResponse
    {
        // Assurer que les règles clés existent
        $keys = [
            'cycle_solidarite_duree_mois'           => 12,
            'cotisation_delai_jour_max'             => 10,
            'bonus_fidelite_cotisation_pct'         => 8.33,
            'cotisation_penalite_retard_pct'        => 5.0,
            'pret_pourcentage_max'                  => 500.0,
            'pret_taux_interet_pct'                 => 10.0,
            'pret_penalite_retard_jour_pct'         => 1.0,
            'sensibilisateur_bonus_versement_2_pct' => 50.0,
            'sensibilisateur_bonus_cloture_pct'     => 50.0,
            'sensibilisateur_base_bonus_pct'        => 50.0,
            'parrainage_part_centre_pct'            => 20.0,
            'parrainage_part_parrains_pct'          => 80.0,
            'epargne_bonus_13eme_mois_actif'        => true,
            'epargne_bonus_mois_nb'                 => 1.0,
            'recouvrement_part_agent_pct'           => 20.0,
            'recouvrement_part_structure_pct'       => 30.0,
            'recouvrement_part_centre_pct'          => 50.0,
            'cotisation_etoiles_base'               => 3.0,
            'admin_point_rouge_actif'               => true,
        ];

        foreach ($keys as $k => $v) {
            FinancialRuleService::get($k, $v);
        }

        $rules = FinancialRule::where('is_active', true)->get();
        return response()->json($rules);
    }

    public function batchUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'configs' => 'required|array',
        ]);

        FinancialRuleService::batchUpdate($validated['configs']);

        $rules = FinancialRule::where('is_active', true)->get();
        return response()->json([
            'message' => 'Configurations mises à jour avec succès.',
            'configs' => $rules,
        ]);
    }
}
