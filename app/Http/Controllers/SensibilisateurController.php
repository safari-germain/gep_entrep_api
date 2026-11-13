<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Individual;
use App\Models\Organisation;
use App\Models\Sensibilisateur;
use App\Models\User;
use App\Services\BonusCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SensibilisateurController extends Controller
{
    public function index(): JsonResponse
    {
        $sensibilisateurs = Sensibilisateur::with(['user', 'organisation', 'branch'])
            ->where('is_active', true)
            ->get()
            ->map(function ($s) {
                return [
                    'id'                     => $s->id,
                    'entity_type'            => $s->entity_type,
                    'name'                   => $s->display_name,
                    'code_promo'             => $s->code_promo,
                    'total_recruited'        => $s->total_recruited,
                    'active_recruited'       => $s->active_recruited,
                    'total_bonus_earned'     => $s->total_bonus_earned,
                    'bonus_pending_year_end' => $s->bonus_pending_year_end,
                    'caution_balance'        => $s->caution_balance,
                    'general_balance'        => $s->general_balance,
                    'bonus_balance'          => $s->bonus_balance,
                    'is_active'              => $s->is_active,
                ];
            });

        return response()->json($sensibilisateurs);
    }

    /**
     * Options pour sélection mobile : retourne les 3 catégories de sensibilisateurs
     * (Individus, Organisations, Succursales GEP) avec leurs portefeuilles.
     */
    public function options(): JsonResponse
    {
        $list = [];

        // 1. Sensibilisateurs existants enregistrés
        $registered = Sensibilisateur::with(['user', 'organisation', 'branch'])
            ->where('is_active', true)
            ->get();

        foreach ($registered as $s) {
            $list[] = [
                'id'          => $s->id,
                'entity_type' => $s->entity_type,
                'name'        => $s->display_name,
                'code_promo'  => $s->code_promo,
                'description' => match ($s->entity_type) {
                    'organisation' => 'Organisation Partenaire GEP',
                    'branch'       => 'Succursale Officielle GEP',
                    default        => 'Sensibilisateur Indépendant / Membre',
                },
                'balances'    => [
                    'caution' => $s->caution_balance,
                    'general' => $s->general_balance,
                    'bonus'   => $s->bonus_balance,
                ],
            ];
        }

        // 2. Si la liste est vide, proposer automatiquement les Succursales et Organisations
        if ($registered->isEmpty()) {
            foreach (Branch::where('is_active', true)->get() as $branch) {
                $s = Sensibilisateur::firstOrCreate(
                    ['entity_type' => 'branch', 'branch_id' => $branch->id],
                    ['code_promo' => 'SUCC-' . $branch->code, 'name' => 'Succursale: ' . $branch->name]
                );
                $list[] = [
                    'id'          => $s->id,
                    'entity_type' => 'branch',
                    'name'        => $s->display_name,
                    'code_promo'  => $s->code_promo,
                    'description' => 'Succursale Officielle GEP',
                    'balances'    => ['caution' => 0.0, 'general' => 0.0, 'bonus' => 0.0],
                ];
            }

            foreach (Organisation::where('is_active', true)->get() as $org) {
                $s = Sensibilisateur::firstOrCreate(
                    ['entity_type' => 'organisation', 'organisation_id' => $org->id],
                    ['code_promo' => 'ORG-' . ($org->code ?? $org->id), 'name' => $org->name]
                );
                $list[] = [
                    'id'          => $s->id,
                    'entity_type' => 'organisation',
                    'name'        => $s->display_name,
                    'code_promo'  => $s->code_promo,
                    'description' => 'Organisation Partenaire GEP',
                    'balances'    => ['caution' => $org->caution_balance, 'general' => $org->general_balance, 'bonus' => $org->bonus_balance],
                ];
            }
        }

        return response()->json($list);
    }

    /**
     * Choix du sensibilisateur par l'utilisateur connecté sur Mobile
     */
    public function selectSensibilisateur(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'            => 'required|integer|exists:users,id',
            'sensibilisateur_id' => 'required|integer|exists:sensibilisateurs,id',
        ]);

        $individual = Individual::where('user_id', $validated['user_id'])->first();
        if (!$individual) {
            return response()->json(['error' => 'Profil membre introuvable.'], 404);
        }

        $sensibilisateur = Sensibilisateur::findOrFail($validated['sensibilisateur_id']);

        $individual->sensibilisateur_id = $sensibilisateur->id;
        $individual->save();

        $sensibilisateur->increment('total_recruited');
        $sensibilisateur->increment('active_recruited');

        return response()->json([
            'message'         => 'Sensibilisateur associé avec succès.',
            'sensibilisateur' => [
                'id'          => $sensibilisateur->id,
                'name'        => $sensibilisateur->display_name,
                'entity_type' => $sensibilisateur->entity_type,
                'code_promo'  => $sensibilisateur->code_promo,
            ],
        ]);
    }

    /**
     * Rechercher un sensibilisateur par son code_promo (pour inscription mobile)
     */
    public function findByCode(Request $request): JsonResponse
    {
        $code = trim($request->query('code', ''));

        if (empty($code)) {
            return response()->json(['error' => 'Le code sensibilisateur est requis.'], 422);
        }

        $s = Sensibilisateur::with(['user', 'organisation', 'branch'])
            ->where('code_promo', $code)
            ->where('is_active', true)
            ->first();

        if (!$s) {
            return response()->json(['error' => 'Aucun sensibilisateur trouvé avec ce code.'], 404);
        }

        return response()->json([
            'id'          => $s->id,
            'entity_type' => $s->entity_type,
            'name'        => $s->display_name,
            'code_promo'  => $s->code_promo,
            'description' => match ($s->entity_type) {
                'organisation' => 'Organisation Partenaire GEP',
                'branch'       => 'Succursale Officielle GEP',
                default        => 'Sensibilisateur Indépendant / Membre',
            },
        ]);
    }

    /**
     * Clôture annuelle : libération de la 2ème tranche de 50% de bonus
     */
    public function releaseYearEnd(Request $request, int $id): JsonResponse
    {
        $sensibilisateur = Sensibilisateur::findOrFail($id);
        $bonus = BonusCalculationService::releaseYearEndMobilisationBonus($sensibilisateur, $request->user()->id ?? null);

        if (!$bonus) {
            return response()->json(['message' => 'Aucun montant en attente de clôture pour ce sensibilisateur.'], 200);
        }

        return response()->json([
            'message' => 'Deuxième tranche de bonus de clôture libérée avec succès !',
            'bonus'   => $bonus,
        ]);
    }
}
