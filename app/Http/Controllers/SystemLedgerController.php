<?php

namespace App\Http\Controllers;

use App\Models\SystemLedgerEntry;
use App\Models\Deposit;
use App\Models\Credit;
use App\Models\Bonus;
use App\Models\Malus;
use App\Models\OhadaAccount;
use App\Models\AccountingEntry;
use App\Models\AccountingEntryLine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SystemLedgerController extends Controller
{
    /**
     * Résumé financier global pour le Grand Livre de Caisse
     */
    public function summary(Request $request): JsonResponse
    {
        $cycleId = $request->query('cycle_id');

        $query = SystemLedgerEntry::where('status', 'validated');
        if ($cycleId) {
            $query->where('cycle_id', $cycleId);
        }

        $validatedEntries = $query->get();

        $totalProduits = (float) $validatedEntries->where('type', 'produit')->sum('amount');
        $totalCharges  = (float) $validatedEntries->where('type', 'charge')->sum('amount');
        $resultatNet   = round($totalProduits - $totalCharges, 2);

        // Pending
        $pendingQuery = SystemLedgerEntry::where('status', 'pending');
        if ($cycleId) {
            $pendingQuery->where('cycle_id', $cycleId);
        }
        $pendingCount  = $pendingQuery->count();
        $pendingAmount = (float) $pendingQuery->sum('amount');

        // Canaux de caisse standard
        $channels = ['cash', 'airtel_money', 'mpesa', 'orange_money', 'afri_money', 'bank', 'internal'];
        $caisse = [];
        foreach ($channels as $ch) {
            $in  = (float) $validatedEntries->where('payment_method', $ch)->where('type', 'produit')->sum('amount');
            $out = (float) $validatedEntries->where('payment_method', $ch)->where('type', 'charge')->sum('amount');
            $caisse[$ch] = [
                'in'  => round($in, 2),
                'out' => round($out, 2),
                'net' => round($in - $out, 2),
            ];
        }

        // Répartition par catégorie
        $categories = [
            'custom', 'bonus', 'credit_interest', 'debit_interest', 'penalty',
            'caution', 'deposit', 'withdrawal', 'credit_disburse', 'credit_repayment',
            'charge_exploitation', 'produit_exploitation'
        ];
        $byCategory = [];
        foreach ($categories as $cat) {
            $catProduits = (float) $validatedEntries->where('category', $cat)->where('type', 'produit')->sum('amount');
            $catCharges  = (float) $validatedEntries->where('category', $cat)->where('type', 'charge')->sum('amount');
            $byCategory[$cat] = [
                'total_produits' => round($catProduits, 2),
                'total_charges'  => round($catCharges, 2),
            ];
        }

        return response()->json([
            'summary' => [
                'total_entrees'            => round($totalProduits, 2),
                'total_sorties'            => round($totalCharges, 2),
                'total_net'                => $resultatNet,
                'total_produits'           => round($totalProduits, 2),
                'total_charges'            => round($totalCharges, 2),
                'resultat_net'             => $resultatNet,
                'total_all_operations'     => $validatedEntries->count(),
                'caisse'                   => $caisse,
                'by_category'              => $byCategory,
                'pending_count'            => $pendingCount,
                'pending_amount'           => round($pendingAmount, 2),
            ]
        ]);
    }

    /**
     * Liste des comptes OHADA disponibles pour l'écriture manuelle
     */
    public function ohadaAccounts(): JsonResponse
    {
        $accounts = OhadaAccount::where('is_active', true)
            ->orderBy('account_number')
            ->get(['id', 'account_number', 'label', 'category', 'ohada_class']);

        return response()->json([
            'accounts' => $accounts,
        ]);
    }

    /**
     * Liste paginée des écritures du Grand Livre avec détails explicites
     */
    public function entries(Request $request): JsonResponse
    {
        $query = SystemLedgerEntry::with([
            'creator:id,name',
            'validator:id,name',
            'ohadaAccount:id,account_number,label,category,ohada_class',
            'branch:id,name',
            'cycle:id,name',
        ]);

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->query('payment_method'));
        }
        if ($request->filled('cycle_id')) {
            $query->where('cycle_id', $request->query('cycle_id'));
        }

        $perPage = (int) $request->query('per_page', 30);
        $entries = $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'entries' => $entries
        ]);
    }

    /**
     * Ajout manuel d'une écriture avec imputation OHADA (Charges Classe 6, Produits Classe 7)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'             => 'required|in:charge,produit',
            'title'            => 'required|string|max:255',
            'amount'           => 'required|numeric|min:0.01',
            'payment_method'   => 'required|string',
            'category'         => 'nullable|string',
            'description'      => 'nullable|string',
            'cycle_id'         => 'nullable|integer',
            'ohada_account_id' => 'nullable|integer|exists:ohada_accounts,id',
        ]);

        $user = Auth::user();
        $isAdmin = $user && in_array($user->role, ['super_admin', 'admin', 'manager']);

        $category = $validated['category'] ?? ($validated['type'] === 'charge' ? 'charge_exploitation' : 'produit_exploitation');

        $entry = SystemLedgerEntry::create([
            'type'             => $validated['type'],
            'source'           => 'manual',
            'category'         => $category,
            'title'            => $validated['title'],
            'description'      => $validated['description'] ?? null,
            'amount'           => $validated['amount'],
            'payment_method'   => $validated['payment_method'],
            'status'           => $isAdmin ? 'validated' : 'pending',
            'created_by'       => $user ? $user->id : null,
            'validated_by'     => $isAdmin && $user ? $user->id : null,
            'validated_at'     => $isAdmin ? now() : null,
            'cycle_id'         => $validated['cycle_id'] ?? null,
            'branch_id'        => $user ? ($user->branch_id ?? null) : null,
            'ohada_account_id' => $validated['ohada_account_id'] ?? null,
        ]);

        // Si l'écriture est immédiatement validée et possède un compte OHADA, générer l'écriture comptable
        if ($entry->status === 'validated' && $entry->ohada_account_id) {
            $this->createAccountingEntryForLedger($entry);
        }

        return response()->json([
            'message' => 'Écriture enregistrée avec succès.',
            'entry'   => $entry->load('ohadaAccount'),
        ], 201);
    }

    /**
     * Valider une écriture en attente
     */
    public function validateEntry(Request $request, $id): JsonResponse
    {
        $entry = SystemLedgerEntry::findOrFail($id);
        $user  = Auth::user();

        $entry->update([
            'status'           => 'validated',
            'validated_by'     => $user ? $user->id : null,
            'validated_at'     => now(),
            'rejection_reason' => null,
        ]);

        if ($entry->ohada_account_id) {
            $this->createAccountingEntryForLedger($entry);
        }

        return response()->json([
            'message' => 'Écriture validée avec succès.',
            'entry'   => $entry->load('ohadaAccount'),
        ]);
    }

    /**
     * Rejeter une écriture en attente
     */
    public function rejectEntry(Request $request, $id): JsonResponse
    {
        $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $entry = SystemLedgerEntry::findOrFail($id);
        $user  = Auth::user();

        $entry->update([
            'status'           => 'rejected',
            'validated_by'     => $user ? $user->id : null,
            'validated_at'     => now(),
            'rejection_reason' => $request->input('rejection_reason'),
        ]);

        return response()->json([
            'message' => 'Écriture rejetée.',
            'entry'   => $entry,
        ]);
    }

    /**
     * Suggestions automatiques de titres
     */
    public function titleSuggestions(Request $request): JsonResponse
    {
        $type  = $request->query('type', 'charge');
        $query = $request->query('query', '');

        $defaults = [
            'charge' => [
                'Frais de transport & carburant',
                'Fournitures de bureau et papeterie',
                'Frais de communication et internet',
                'Loyer bureau succursale',
                'Maintenance matériel informatique',
                'Rémunération et primes du personnel',
                'Frais bancaires et transactions mobiles',
                'Frais d\'électricité et eau',
                'Imprévus et charges diverses',
            ],
            'produit' => [
                'Commissions perçues sur transferts',
                'Frais d\'adhésion et de dossier',
                'Pénalités de retard perçues',
                'Intérêts créditeurs bancaires',
                'Vente formulaires & carnets',
                'Produits exceptionnels divers',
            ],
        ];

        $list = $defaults[$type] ?? [];

        $dbTitles = SystemLedgerEntry::where('type', $type)
            ->when($query, function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%");
            })
            ->distinct()
            ->limit(10)
            ->pluck('title')
            ->toArray();

        $merged = array_unique(array_merge($dbTitles, array_filter($list, function ($item) use ($query) {
            return empty($query) || stripos($item, $query) !== false;
        })));

        return response()->json([
            'suggestions' => array_values($merged),
        ]);
    }

    /**
     * Génère l'écriture en partie double conforme SYSCOHADA pour une saisie Grand Livre
     */
    private function createAccountingEntryForLedger(SystemLedgerEntry $entry): void
    {
        $ohadaAccount = OhadaAccount::find($entry->ohada_account_id);
        if (!$ohadaAccount) return;

        // Trésorerie selon moyen de paiement
        $treasuryAccountNumber = match ($entry->payment_method) {
            'airtel_money' => '572100',
            'mpesa'        => '572200',
            'orange_money' => '572300',
            'afri_money'   => '572400',
            'bank'         => '521000',
            default        => '571000',
        };
        $treasuryAccount = OhadaAccount::where('account_number', $treasuryAccountNumber)->first();

        $accEntry = AccountingEntry::create([
            'entry_number' => 'ECR-' . date('Ymd') . '-' . str_pad($entry->id, 5, '0', STR_PAD_LEFT),
            'entry_date'   => now()->toDateString(),
            'period'       => now()->format('Y-m'),
            'source_type'  => 'manual',
            'source_id'    => $entry->id,
            'branch_id'    => $entry->branch_id,
            'label'        => $entry->title,
            'total_debit'  => $entry->amount,
            'total_credit' => $entry->amount,
            'status'       => 'validated',
            'created_by'   => $entry->created_by,
        ]);

        if ($entry->type === 'charge') {
            // Débit: Compte de charge (Classe 6)
            AccountingEntryLine::create([
                'accounting_entry_id' => $accEntry->id,
                'ohada_account_id'    => $ohadaAccount->id,
                'direction'           => 'debit',
                'amount'              => $entry->amount,
                'narration'           => $entry->title . ($entry->description ? " - {$entry->description}" : ''),
            ]);
            // Crédit: Compte de trésorerie (Classe 5)
            if ($treasuryAccount) {
                AccountingEntryLine::create([
                    'accounting_entry_id' => $accEntry->id,
                    'ohada_account_id'    => $treasuryAccount->id,
                    'direction'           => 'credit',
                    'amount'              => $entry->amount,
                    'narration'           => "Règlement via {$entry->payment_method}",
                ]);
            }
        } else {
            // Débit: Compte de trésorerie (Classe 5)
            if ($treasuryAccount) {
                AccountingEntryLine::create([
                    'accounting_entry_id' => $accEntry->id,
                    'ohada_account_id'    => $treasuryAccount->id,
                    'direction'           => 'debit',
                    'amount'              => $entry->amount,
                    'narration'           => "Encaissement via {$entry->payment_method}",
                ]);
            }
            // Crédit: Compte de produit (Classe 7)
            AccountingEntryLine::create([
                'accounting_entry_id' => $accEntry->id,
                'ohada_account_id'    => $ohadaAccount->id,
                'direction'           => 'credit',
                'amount'              => $entry->amount,
                'narration'           => $entry->title . ($entry->description ? " - {$entry->description}" : ''),
            ]);
        }
    }
}