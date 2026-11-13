<?php

namespace App\Http\Controllers;

use App\Models\SystemLedgerEntry;
use App\Models\Deposit;
use App\Models\Credit;
use App\Models\Bonus;
use App\Models\Malus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SystemLedgerController extends Controller
{
    /**
     * Résumé financier global pour le Grand Livre de Caisse
     */
    public function summary(Request $request)
    {
        $cycleId = $request->query('cycle_id');

        $query = SystemLedgerEntry::where('status', 'validated');
        if ($cycleId) {
            $query->where('cycle_id', $cycleId);
        }

        $validatedEntries = $query->get();

        $totalProduits = (float) $validatedEntries->where('type', 'produit')->sum('amount');
        $totalCharges  = (float) $validatedEntries->where('type', 'charge')->sum('amount');
        $resultatNet   = $totalProduits - $totalCharges;

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
                'in'  => $in,
                'out' => $out,
                'net' => $in - $out,
            ];
        }

        // Répartition par catégorie
        $categories = [
            'custom', 'bonus', 'credit_interest', 'debit_interest', 'penalty',
            'caution', 'deposit', 'withdrawal', 'credit_disburse', 'credit_repayment'
        ];
        $byCategory = [];
        foreach ($categories as $cat) {
            $catProduits = (float) $validatedEntries->where('category', $cat)->where('type', 'produit')->sum('amount');
            $catCharges  = (float) $validatedEntries->where('category', $cat)->where('type', 'charge')->sum('amount');
            $byCategory[$cat] = [
                'total_produits' => $catProduits,
                'total_charges'  => $catCharges,
            ];
        }

        return response()->json([
            'summary' => [
                'total_produits' => $totalProduits,
                'total_charges'  => $totalCharges,
                'resultat_net'   => $resultatNet,
                'caisse'         => $caisse,
                'by_category'    => $byCategory,
                'pending_count'  => $pendingCount,
                'pending_amount' => $pendingAmount,
            ]
        ]);
    }

    /**
     * Liste paginée des écritures du Grand Livre
     */
    public function entries(Request $request)
    {
        $query = SystemLedgerEntry::query();

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
     * Ajout manuel d'une écriture
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type'           => 'required|in:charge,produit',
            'title'          => 'required|string|max:255',
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|string',
            'category'       => 'nullable|string',
            'description'    => 'nullable|string',
            'cycle_id'       => 'nullable|integer',
        ]);

        $user = Auth::user();
        $isAdmin = $user && in_array($user->role, ['super_admin', 'admin', 'manager']);

        $entry = SystemLedgerEntry::create([
            'type'           => $validated['type'],
            'source'         => 'manual',
            'category'       => $validated['category'] ?? 'custom',
            'title'          => $validated['title'],
            'description'    => $validated['description'] ?? null,
            'amount'         => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'status'         => $isAdmin ? 'validated' : 'pending',
            'created_by'     => $user ? $user->id : null,
            'validated_by'   => $isAdmin && $user ? $user->id : null,
            'validated_at'   => $isAdmin ? now() : null,
            'cycle_id'       => $validated['cycle_id'] ?? null,
            'branch_id'      => $user ? ($user->branch_id ?? null) : null,
        ]);

        return response()->json([
            'message' => 'Écriture enregistrée avec succès.',
            'entry'   => $entry,
        ], 201);
    }

    /**
     * Valider une écriture en attente
     */
    public function validateEntry(Request $request, $id)
    {
        $entry = SystemLedgerEntry::findOrFail($id);
        $user  = Auth::user();

        $entry->update([
            'status'       => 'validated',
            'validated_by' => $user ? $user->id : null,
            'validated_at' => now(),
            'rejection_reason' => null,
        ]);

        return response()->json([
            'message' => 'Écriture validée avec succès.',
            'entry'   => $entry,
        ]);
    }

    /**
     * Rejeter une écriture en attente
     */
    public function rejectEntry(Request $request, $id)
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
    public function titleSuggestions(Request $request)
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

        // Récupérer aussi les titres existants dans la base
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
}
