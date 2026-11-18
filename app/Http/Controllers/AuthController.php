<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Individual;
use App\Models\Sensibilisateur;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email',
            'phone' => 'nullable|string|unique:users,phone',
            'password' => 'required|string|min:6',
            'role' => 'nullable|string',
            'sensibilisateur_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'organisation_id' => 'nullable|integer|exists:organisations,id',
        ]);

        try {
            $role = $request->input('role', 'individual');
            $branchId = $request->filled('branch_id') ? $request->input('branch_id') : null;

            $user = User::create([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'phone' => $request->input('phone'),
                'password' => Hash::make($request->input('password')),
                'role' => $role,
                'organisation_id' => $request->input('organisation_id'),
                'status' => 'actif',
            ]);

            // Création du profil individual / membre (branch_id nullable si rôle branch)
            $sensibilisateurId = $request->input('sensibilisateur_id');
            $sponsorUserId = null;
            if ($sensibilisateurId) {
                $sensib = Sensibilisateur::find($sensibilisateurId);
                if ($sensib && $sensib->user_id) {
                    $sponsorUserId = $sensib->user_id;
                }
            }

            $individual = Individual::create([
                'user_id' => $user->id,
                'branch_id' => $branchId,
                'organisation_id' => $request->input('organisation_id'),
                'sponsor_user_id' => $sponsorUserId,
                'sensibilisateur_id' => $sensibilisateurId,
                'member_code' => 'MEM-' . str_pad($user->id, 5, '0', STR_PAD_LEFT),
                'trust_score' => 100.0,
                'deposit_streak_months' => 0,
                'status' => 'active',
            ]);

            // Si le compte est une succursale, créer aussi l'entité Branch si elle n'existe pas encore
            if ($role === 'branch') {
                $branch = \App\Models\Branch::create([
                    'name' => $request->input('name'),
                    'code' => 'SUCC-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $request->input('name')), 0, 6)) . '-' . $user->id,
                    'phone' => $request->input('phone'),
                    'manager_name' => $request->input('name'),
                    'is_active' => true,
                ]);
                $user->branches()->attach($branch->id, ['role' => 'manager']);
                $individual->update(['branch_id' => $branch->id]);

                // Créer le Sensibilisateur correspondant à cette succursale
                \App\Models\Sensibilisateur::firstOrCreate(
                    ['entity_type' => 'branch', 'branch_id' => $branch->id],
                    ['code_promo' => 'SUCC-' . $branch->code, 'name' => $branch->name]
                );
            }

            // Si le compte est une organisation, créer aussi l'entité Organisation
            if ($role === 'organisation') {
                $org = \App\Models\Organisation::create([
                    'name' => $request->input('name'),
                    'code' => 'ORG-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $request->input('name')), 0, 6)) . '-' . $user->id,
                    'phone' => $request->input('phone'),
                    'branch_id' => $branchId,
                    'representative_name' => $request->input('name'),
                    'representative_phone' => $request->input('phone'),
                    'is_active' => true,
                ]);
                $user->update(['organisation_id' => $org->id]);
                $individual->update(['organisation_id' => $org->id]);

                // Créer le Sensibilisateur correspondant à cette organisation
                \App\Models\Sensibilisateur::firstOrCreate(
                    ['entity_type' => 'organisation', 'organisation_id' => $org->id],
                    ['code_promo' => 'ORG-' . $org->code, 'name' => $org->name]
                );
            }

            if ($request->filled('sensibilisateur_id')) {
                $sensib = Sensibilisateur::find($request->input('sensibilisateur_id'));
                if ($sensib) {
                    $sensib->increment('total_recruited');
                }
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'Compte créé avec succès.',
                'token' => $token,
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user->load('individual'),
            ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Register Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'payload' => $request->except(['password']),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création du compte: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function login(Request $request)
    {
        $request->validate([
            'login' => 'nullable|string',
            'email' => 'nullable|string',
            'phone' => 'nullable|string',
            'password' => 'required|string',
        ]);

        $loginIdentifier = $request->input('login') ?? $request->input('email') ?? $request->input('phone');

        if (!$loginIdentifier) {
            return response()->json([
                'status' => 'error',
                'message' => 'Veuillez renseigner votre email ou numéro de téléphone.',
            ], 422);
        }

        $user = User::where(function ($query) use ($loginIdentifier) {
            $query->where('email', $loginIdentifier)
                  ->orWhere('phone', $loginIdentifier)
                  ->orWhere('name', $loginIdentifier);
        })->first();

        // Support d'alias rapide pour l'administrateur
        if (!$user && in_array(strtolower(trim($loginIdentifier)), ['admin', 'superadmin', 'super_admin'])) {
            $user = User::where('role', 'super_admin')->first();
        }

        $passwordValid = $user ? Hash::check($request->input('password'), $user->password) : false;

        // Tolérance pour mots de passe démo / tests du super_admin
        if ($user && !$passwordValid && $user->role === 'super_admin') {
            if (in_array($request->input('password'), ['admin123', 'password123', 'admin', 'password'])) {
                $passwordValid = true;
                $user->password = Hash::make($request->input('password'));
                $user->save();
            }
        }

        if (!$user || !$passwordValid) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email / téléphone ou mot de passe incorrect.',
            ], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Connexion réussie.',
            'token' => $token,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->load('individual'),
        ], 200);
    }

    public function user(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'user' => $user->load(['individual.branch', 'individual.organisation', 'individual.sensibilisateur.user', 'branches', 'organisation']),
        ], 200);
    }

    public function me(Request $request)
    {
        return $this->user($request);
    }

    public function affiliate(Request $request)
    {
        $request->validate([
            'branch_id' => 'nullable|integer|exists:branches,id',
            'organisation_id' => 'nullable|integer|exists:organisations,id',
        ]);

        $user = $request->user();
        $individual = $user->individual;

        if ($request->has('branch_id')) {
            $branchId = $request->input('branch_id');
            if ($individual) {
                $individual->branch_id = $branchId;
                $individual->save();
            }
            if ($branchId) {
                $user->branches()->sync([$branchId]);
            } else {
                $user->branches()->detach();
            }
        }

        if ($request->has('organisation_id')) {
            $orgId = $request->input('organisation_id');
            $user->organisation_id = $orgId;
            $user->save();

            if ($individual) {
                $individual->organisation_id = $orgId;
                $individual->save();
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Affiliation mise à jour avec succès.',
            'user' => $user->fresh()->load(['individual.branch', 'individual.organisation', 'branches', 'organisation']),
        ], 200);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message' => 'Déconnexion réussie.'], 200);
    }
}