<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Individual;
use App\Models\Organisation;
use App\Models\Sensibilisateur;
use App\Models\User;
use App\Models\OrdinaryAccount;
use App\Models\OrdinaryAccountTransaction;
use App\Models\Deposit;
use App\Services\BonusCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
            // Rechercher si c'est un code entrepreneur (member_code MEM-xxxxx)
            $ind = Individual::with('user')
                ->where('member_code', $code)
                ->first();

            if ($ind && $ind->user) {
                $s = Sensibilisateur::firstOrCreate(
                    ['entity_type' => 'user', 'user_id' => $ind->user_id],
                    [
                        'name'       => $ind->user->name,
                        'code_promo' => $ind->member_code,
                        'is_active'  => true,
                        'branch_id'  => $ind->branch_id ?? 1,
                    ]
                );
            }
        }

        if (!$s) {
            return response()->json(['error' => 'Aucun sensibilisateur ou parrain trouvé avec ce code.'], 404);
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

    /**
     * POST /api/sensibilisateurs/assign-marketer
     * Désigner un utilisateur en Animateur / Marketeur officiel par l'Admin.
     */
    public function assignMarketer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'    => 'required|integer|exists:users,id',
            'code_promo' => 'nullable|string|max:30|unique:sensibilisateurs,code_promo',
            'name'       => 'nullable|string|max:150',
        ]);

        $user = User::findOrFail($validated['user_id']);

        // Code promo automatique ou personnalisé
        $codePromo = !empty($validated['code_promo'])
            ? strtoupper(trim($validated['code_promo']))
            : 'MKT-' . strtoupper(Str::random(6));

        $sensibilisateur = Sensibilisateur::firstOrNew([
            'user_id'     => $user->id,
            'entity_type' => 'user',
        ]);

        $sensibilisateur->name       = $validated['name'] ?? $user->name;
        $sensibilisateur->code_promo = $sensibilisateur->code_promo ?: $codePromo;
        $sensibilisateur->is_active  = true;
        $sensibilisateur->branch_id  = $user->branch_id ?? 1;
        $sensibilisateur->save();

        return response()->json([
            'success'         => true,
            'message'         => "L'utilisateur {$user->name} a été nommé Animateur / Marketeur avec succès.",
            'sensibilisateur' => [
                'id'         => $sensibilisateur->id,
                'user_id'    => $sensibilisateur->user_id,
                'name'       => $sensibilisateur->name,
                'code_promo' => $sensibilisateur->code_promo,
                'is_active'  => $sensibilisateur->is_active,
            ],
        ]);
    }

    /**
     * GET /api/sensibilisateurs/marketer-space
     * Espace Animateur / Marketeur de l'utilisateur connecté sur Mobile.
     */
    public function marketerSpace(Request $request): JsonResponse
    {
        $userId = auth()->id();
        $sensibilisateur = Sensibilisateur::where('user_id', $userId)
            ->where('is_active', true)
            ->first();

        $user = User::with('individual')->find($userId);
        $memberCode = $user?->member_code ?? ('MEM-' . str_pad($userId, 5, '0', STR_PAD_LEFT));

        // Récupérer les personnes sensibilisées / recrutées (soit par sponsor_user_id, soit par sensibilisateur_id)
        $recruits = Individual::where(function ($q) use ($userId, $sensibilisateur) {
                $q->where('sponsor_user_id', $userId);
                if ($sensibilisateur) {
                    $q->orWhere('sensibilisateur_id', $sensibilisateur->id);
                }
            })
            ->with(['user'])
            ->get()
            ->map(function ($ind) {
                $u = $ind->user;
                $uId = $ind->user_id;

                // Cautions déposées
                $cautionsCount = Deposit::where('user_id', $uId)
                    ->where('type', 'caution')
                    ->where('status', 'active')
                    ->count();

                $totalCaution = (float) Deposit::where('user_id', $uId)
                    ->where('type', 'caution')
                    ->where('status', 'active')
                    ->sum('actual_amount');

                // Total de tous les dépôts (caution + solidaire + cycle)
                $allDepositsCount = Deposit::where('user_id', $uId)->count();
                $totalDeposited = (float) Deposit::where('user_id', $uId)->sum('actual_amount');

                // Compte ordinaire et statut découvert
                $oa = OrdinaryAccount::where('user_id', $uId)->first();
                $oaBalance = $oa ? (float) $oa->balance : 0.0;
                $isNegative = $oaBalance < 0;
                $consecutiveNegativeMonths = $oa ? ($oa->consecutive_negative_months ?? 0) : 0;

                // Prélèvements effectués sous découvert
                $overdraftDebitsCount = 0;
                if ($oa) {
                    $overdraftDebitsCount = OrdinaryAccountTransaction::where('ordinary_account_id', $oa->id)
                        ->where('direction', 'debit')
                        ->where('is_funded_by_overdraft', true)
                        ->count();
                }

                $hasOverdraftDeposit = $isNegative || ($overdraftDebitsCount > 0);

                return [
                    'individual_id'               => $ind->id,
                    'user_id'                     => $uId,
                    'name'                        => $u ? $u->name : "Membre #{$ind->id}",
                    'prenom'                      => $u ? ($u->prenom ?? '') : '',
                    'phone'                       => $u ? ($u->phone ?? '') : '',
                    'member_code'                 => $ind->member_code ?? ('MEM-' . str_pad($uId, 5, '0', STR_PAD_LEFT)),
                    'date_joined'                 => $ind->created_at ? $ind->created_at->format('d/m/Y') : null,
                    'cautions_count'              => $cautionsCount,
                    'total_caution_deposited'     => $totalCaution,
                    'all_deposits_count'          => $allDepositsCount,
                    'total_deposited'             => $totalDeposited,
                    'ordinary_account_balance'    => $oaBalance,
                    'is_ordinary_negative'        => $isNegative,
                    'consecutive_negative_months' => $consecutiveNegativeMonths,
                    'overdraft_deposits_count'    => $overdraftDebitsCount,
                    'has_overdraft_deposit'       => $hasOverdraftDeposit,
                    'caution_status_label'        => $hasOverdraftDeposit
                        ? "Dépôt sous découvert ({$consecutiveNegativeMonths} mois négatif)"
                        : ($allDepositsCount > 0 ? "À jour ({$allDepositsCount} dépôts)" : "Aucun dépôt"),
                ];
            });

        return response()->json([
            'success'                => true,
            'is_marketer'            => $sensibilisateur ? true : false,
            'sensibilisateur_id'     => $sensibilisateur?->id,
            'name'                   => $sensibilisateur?->display_name ?? $user?->name,
            'code_promo'             => $sensibilisateur?->code_promo ?? $memberCode,
            'member_code'            => $memberCode,
            'total_recruited'        => $recruits->count(),
            'total_bonus_earned'     => $sensibilisateur?->total_bonus_earned ?? 0.0,
            'bonus_pending_year_end' => $sensibilisateur?->bonus_pending_year_end ?? 0.0,
            'recruits'               => $recruits->values(),
        ]);
    }
}
