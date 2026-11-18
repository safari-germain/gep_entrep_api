<?php

namespace App\Http\Controllers;

use App\Models\OrdinaryAccount;
use App\Models\OrdinaryAccountTransaction;
use App\Models\Individual;
use App\Models\Organisation;
use App\Models\FinancialValidation;
use App\Services\OrdinaryAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrdinaryAccountController extends Controller
{
    protected OrdinaryAccountService $accountService;

    public function __construct(OrdinaryAccountService $accountService)
    {
        $this->accountService = $accountService;
    }

    /**
     * GET /api/ordinary-accounts
     * Liste tous les comptes ordinaires avec soldes et propriétaires
     */
    public function index(Request $request)
    {
        $query = OrdinaryAccount::with(['user', 'individual.user', 'organisation'])
            ->orderBy('balance', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")
                       ->orWhere('phone', 'like', "%{$search}%")
                       ->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('individual.user', function ($iq) use ($search) {
                    $iq->where('name', 'like', "%{$search}%")
                       ->orWhere('phone', 'like', "%{$search}%")
                       ->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('individual', function ($iq) use ($search) {
                    $iq->where('member_code', 'like', "%{$search}%");
                })->orWhereHas('organisation', function ($oq) use ($search) {
                    $oq->where('name', 'like', "%{$search}%");
                });
            });
        }

        $accounts = $query->get()->map(function ($acc) {
            $ownerName = 'Inconnu';
            $ownerPhone = '-';
            $memberCode = '-';
            $branchName = '-';

            if ($acc->entity_type === 'individual' && $acc->individual) {
                $ownerName = $acc->individual->user?->name ?? 'Membre #' . $acc->entity_id;
                $ownerPhone = $acc->individual->user?->phone ?? '-';
                $memberCode = $acc->individual->member_code ?? '-';
                $branchName = $acc->individual->branch?->name ?? '-';
            } elseif ($acc->entity_type === 'user' && $acc->user) {
                $ownerName = $acc->user->name;
                $ownerPhone = $acc->user->phone ?? '-';
                $memberCode = 'USR-' . $acc->user->id;
            } elseif ($acc->entity_type === 'organisation' && $acc->organisation) {
                $ownerName = $acc->organisation->name;
                $ownerPhone = $acc->organisation->phone ?? '-';
                $memberCode = $acc->organisation->code ?? 'ORG-' . $acc->entity_id;
            } elseif ($acc->user) {
                $ownerName = $acc->user->name;
                $ownerPhone = $acc->user->phone ?? '-';
            }

            return [
                'id'                           => $acc->id,
                'entity_type'                  => $acc->entity_type,
                'entity_id'                    => $acc->entity_id,
                'user_id'                      => $acc->user_id,
                'owner_name'                   => $ownerName,
                'owner_phone'                  => $ownerPhone,
                'member_code'                  => $memberCode,
                'branch_name'                  => $branchName,
                'balance'                      => (float) $acc->balance,
                'status'                       => $acc->status ?? 'active',
                'total_debit_interest_charged' => (float) $acc->total_debit_interest_charged,
                'negative_since'               => $acc->negative_since,
                'updated_at'                   => $acc->updated_at,
            ];
        });

        return response()->json([
            'accounts' => $accounts,
            'summary'  => [
                'total_accounts' => $accounts->count(),
                'total_balance'  => round($accounts->sum('balance'), 2),
                'active_count'   => $accounts->where('status', 'active')->count(),
                'blocked_count'  => $accounts->where('status', 'blocked')->count(),
            ],
        ]);
    }

    /**
     * POST /api/ordinary-accounts/{id}/toggle-block
     * Bloquer ou débloquer un compte ordinaire
     */
    public function toggleBlock(Request $request, int $id)
    {
        $account = OrdinaryAccount::findOrFail($id);
        $newStatus = ($account->status === 'blocked') ? 'active' : 'blocked';
        if ($request->filled('status')) {
            $newStatus = $request->status;
        }

        $account->update(['status' => $newStatus]);

        return response()->json([
            'status'     => true,
            'new_status' => $newStatus,
            'message'    => ($newStatus === 'blocked') 
                ? 'Le compte ordinaire a été bloqué.' 
                : 'Le compte ordinaire a été débloqué avec succès.',
            'account'    => $account,
        ]);
    }

    /**
     * POST /api/ordinary-accounts/{id}/admin-withdraw
     * Retrait direct au guichet par un administrateur
     */
    public function adminWithdraw(Request $request, int $id)
    {
        $validated = $request->validate([
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string',
            'description'    => 'nullable|string',
        ]);

        $account = OrdinaryAccount::findOrFail($id);

        if ($account->status === 'blocked') {
            return response()->json(['message' => 'Opération impossible : Ce compte est bloqué.'], 422);
        }

        $user = Auth::user();
        $amount = (float) $validated['amount'];

        if ($account->balance < $amount) {
            return response()->json([
                'message' => 'Solde insuffisant. Solde disponible : $' . number_format($account->balance, 2)
            ], 422);
        }

        $tx = $this->accountService->withdraw(
            $account,
            $amount,
            $validated['payment_method'] ?? 'cash',
            $validated['description'] ?? 'Retrait au guichet par administration',
            $user ? $user->id : null,
            'withdrawal'
        );

        return response()->json([
            'status'      => true,
            'message'     => 'Retrait de $' . number_format($amount, 2) . ' effectué avec succès.',
            'transaction' => $tx,
            'account'     => $account->fresh(),
        ]);
    }

    /**
     * Compte ordinaire de l'utilisateur connecté
     */
    public function me(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        // Si l'utilisateur a un profil individual, rattacher à l'individual
        $individual = Individual::where('user_id', $user->id)->first();
        if ($individual) {
            $account = OrdinaryAccount::getOrCreateForEntity('individual', $individual->id, $user->id);
        } else {
            $account = OrdinaryAccount::getOrCreateForEntity('user', $user->id, $user->id);
        }

        $account->load(['transactions' => function ($q) {
            $q->orderBy('created_at', 'desc')->limit(50);
        }]);

        return response()->json([
            'account' => $account
        ]);
    }

    /**
     * Compte d'une entité spécifique (pour admin)
     */
    public function showByEntity(string $type, $entityId)
    {
        if (!is_numeric($entityId)) {
            return response()->json(['error' => 'ID d\'entité invalide.'], 400);
        }
        $account = OrdinaryAccount::getOrCreateForEntity($type, (int) $entityId);
        $account->load(['transactions' => function ($q) {
            $q->orderBy('created_at', 'desc')->limit(50);
        }]);

        return response()->json([
            'account' => $account
        ]);
    }

    /**
     * Liste des transactions d'un compte ordinaire
     */
    public function transactions(int $accountId)
    {
        $account = OrdinaryAccount::findOrFail($accountId);
        $transactions = OrdinaryAccountTransaction::where('ordinary_account_id', $account->id)
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        return response()->json([
            'transactions' => $transactions
        ]);
    }

    /**
     * Dépôt sur le compte ordinaire
     */
    public function deposit(Request $request)
    {
        $validated = $request->validate([
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string',
            'description'    => 'nullable|string',
            'entity_type'    => 'nullable|string',
            'entity_id'      => 'nullable|integer',
        ]);

        $user = Auth::user();
        $entityType = $validated['entity_type'] ?? null;
        $entityId   = $validated['entity_id'] ?? null;

        if ($entityType && $entityId) {
            $account = OrdinaryAccount::getOrCreateForEntity($entityType, $entityId);
        } else {
            $individual = Individual::where('user_id', $user->id)->first();
            if ($individual) {
                $account = OrdinaryAccount::getOrCreateForEntity('individual', $individual->id, $user->id);
            } else {
                $account = OrdinaryAccount::getOrCreateForEntity('user', $user->id, $user->id);
            }
        }

        $tx = $this->accountService->deposit(
            $account,
            (float) $validated['amount'],
            $validated['payment_method'] ?? 'cash',
            $validated['description'] ?? 'Dépôt ordinaire',
            $user->id
        );

        $account->refresh();

        return response()->json([
            'status'      => true,
            'message'     => 'Dépôt effectué avec succès.',
            'account'     => $account,
            'transaction' => $tx,
        ], 201);
    }

    /**
     * Retrait depuis le compte ordinaire
     */
    public function withdraw(Request $request)
    {
        $validated = $request->validate([
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string',
            'description'    => 'nullable|string',
            'entity_type'    => 'nullable|string',
            'entity_id'      => 'nullable|integer',
        ]);

        $user = Auth::user();
        $entityType = $validated['entity_type'] ?? null;
        $entityId   = $validated['entity_id'] ?? null;

        if ($entityType && $entityId) {
            $account = OrdinaryAccount::getOrCreateForEntity($entityType, $entityId);
        } else {
            $individual = Individual::where('user_id', $user->id)->first();
            if ($individual) {
                $account = OrdinaryAccount::getOrCreateForEntity('individual', $individual->id, $user->id);
            } else {
                $account = OrdinaryAccount::getOrCreateForEntity('user', $user->id, $user->id);
            }
        }

        $tx = $this->accountService->withdraw(
            $account,
            (float) $validated['amount'],
            $validated['payment_method'] ?? 'cash',
            $validated['description'] ?? 'Retrait ordinaire',
            $user->id
        );

        $account->refresh();

        return response()->json([
            'status'      => true,
            'message'     => 'Retrait effectué avec succès.',
            'account'     => $account,
            'transaction' => $tx,
        ], 200);
    }

    /**
     * Déduction automatique de caution mensuelle
     */
    public function deductCaution(Request $request)
    {
        $validated = $request->validate([
            'cycle_id'      => 'required|integer',
            'individual_id' => 'nullable|integer',
        ]);

        $result = $this->accountService->deductCycleCaution(
            $validated['cycle_id'],
            $validated['individual_id'] ?? null
        );

        return response()->json($result);
    }

    /**
     * Calcul et application des intérêts débiteurs sur comptes négatifs (>3 jours de retard)
     */
    public function applyOverdraftInterests(Request $request)
    {
        $result = $this->accountService->applyOverdraftDebitInterests();
        return response()->json($result);
    }

    /**
     * Traitement automatique des échéances de dettes en cours
     */
    public function processCreditRepayments(Request $request)
    {
        $result = $this->accountService->processCreditRepayments();
        return response()->json($result);
    }

    /**
     * Demande de retrait depuis le mobile (soumis à validation administrative avec mot de passe)
     */
    public function requestWithdrawal(Request $request)
    {
        $validated = $request->validate([
            'amount'              => 'required|numeric|min:1',
            'withdrawal_mode'     => 'required|string|in:mobile_money,bank,cash',
            'operator'            => 'nullable|string|in:airtel_money,mpesa,orange_money,afri_money',
            'phone'               => 'nullable|string',
            'account_name'        => 'nullable|string|max:255',
            'bank_name'           => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:255',
            'beneficiary_name'    => 'nullable|string|max:255',
            'description'         => 'nullable|string|max:500',
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $individual = Individual::where('user_id', $user->id)->first();
        if ($individual) {
            $account = OrdinaryAccount::getOrCreateForEntity('individual', $individual->id, $user->id);
            $entityType = 'individual';
            $entityId   = $individual->id;
        } else {
            $account = OrdinaryAccount::getOrCreateForEntity('user', $user->id, $user->id);
            $entityType = 'user';
            $entityId   = $user->id;
        }

        $amount = (float) $validated['amount'];
        $mode   = $validated['withdrawal_mode'];

        // Formatage de la description
        $desc = $validated['description'] ?? '';
        if (empty($desc)) {
            if ($mode === 'mobile_money') {
                $opLabel = strtoupper(str_replace('_', ' ', $validated['operator'] ?? 'Mobile Money'));
                $desc = "Retrait {$opLabel} vers {$validated['phone']} ({$validated['account_name']})";
            } elseif ($mode === 'bank') {
                $desc = "Virement bancaire {$validated['bank_name']} N° {$validated['bank_account_number']} ({$validated['account_name']})";
            } else {
                $desc = "Retrait cash au bureau pour {$validated['beneficiary_name']}";
            }
        }

        $ref = 'RET-' . date('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(6));

        $paymentMethod = 'cash';
        if ($mode === 'mobile_money') {
            $paymentMethod = $validated['operator'] ?? 'mobile_money';
        } elseif ($mode === 'bank') {
            $paymentMethod = 'bank';
        }

        $validation = FinancialValidation::create([
            'reference'      => $ref,
            'operation_type' => 'withdrawal',
            'status'         => 'pending',
            'initiated_by'   => $user->id,
            'initiated_at'   => now(),
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
            'amount'         => $amount,
            'description'    => $desc,
            'payload'        => [
                'type'                => 'withdrawal',
                'withdrawal_mode'     => $mode,
                'operator'            => $validated['operator'] ?? null,
                'payment_method'      => $paymentMethod,
                'phone'               => $validated['phone'] ?? null,
                'account_name'        => $validated['account_name'] ?? null,
                'bank_name'           => $validated['bank_name'] ?? null,
                'bank_account_number' => $validated['bank_account_number'] ?? null,
                'beneficiary_name'    => $validated['beneficiary_name'] ?? ($validated['account_name'] ?? $user->name),
                'current_balance'     => $account->balance,
            ],
        ]);

        return response()->json([
            'status'     => true,
            'message'    => 'Votre demande de retrait de $' . number_format($amount, 2) . ' a été transmise à l\'administration avec succès.',
            'validation' => $validation,
        ], 201);
    }

    /**
     * Enregistrement d'une intention de dépôt Mobile Money
     */
    public function requestDeposit(Request $request)
    {
        $validated = $request->validate([
            'amount'      => 'required|numeric|min:0.5',
            'operator'    => 'required|string|in:airtel_money,mpesa,orange_money,afri_money',
            'phone'       => 'required|string',
            'description' => 'nullable|string',
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $individual = Individual::where('user_id', $user->id)->first();
        if ($individual) {
            $account = OrdinaryAccount::getOrCreateForEntity('individual', $individual->id, $user->id);
            $entityType = 'individual';
            $entityId   = $individual->id;
        } else {
            $account = OrdinaryAccount::getOrCreateForEntity('user', $user->id, $user->id);
            $entityType = 'user';
            $entityId   = $user->id;
        }

        $ref = 'DEP-' . date('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(6));
        $opLabel = strtoupper(str_replace('_', ' ', $validated['operator']));

        $validation = FinancialValidation::create([
            'reference'      => $ref,
            'operation_type' => 'deposit',
            'status'         => 'pending',
            'initiated_by'   => $user->id,
            'initiated_at'   => now(),
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
            'amount'         => (float) $validated['amount'],
            'description'    => "Dépôt {$opLabel} depuis {$validated['phone']}",
            'payload'        => [
                'type'           => 'deposit',
                'operator'       => $validated['operator'],
                'payment_method' => $validated['operator'],
                'phone'          => $validated['phone'],
                'current_balance'=> $account->balance,
            ],
        ]);

        return response()->json([
            'status'     => true,
            'message'    => 'Demande de dépôt enregistrée. Effectuez le transfert vers les numéros GEP officiels.',
            'reference'  => $ref,
            'validation' => $validation,
        ], 201);
    }
}
