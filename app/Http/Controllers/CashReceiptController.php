<?php

namespace App\Http\Controllers;

use App\Models\CashReceipt;
use App\Models\SystemLedgerEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CashReceiptController extends Controller
{
    /**
     * Liste des reçus de caisse
     */
    public function index(Request $request)
    {
        $query = CashReceipt::with(['cashier', 'member', 'branch', 'imputedBy']);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                  ->orWhereHas('member', function ($mq) use ($search) {
                      $mq->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $receipts = $query->orderBy('created_at', 'desc')->get()->map(function ($r) {
            return [
                'id'             => $r->id,
                'receipt_number' => $r->receipt_number,
                'cashier_id'     => $r->cashier_id,
                'cashier_name'   => $r->cashier ? $r->cashier->name : 'Caissier',
                'branch_id'      => $r->branch_id,
                'branch_name'    => $r->branch ? $r->branch->name : null,
                'member_id'      => $r->member_id,
                'member_name'    => $r->member ? $r->member->name : 'Membre',
                'member_code'    => $r->member ? ('MEM-' . str_pad($r->member->id, 4, '0', STR_PAD_LEFT)) : null,
                'amount'         => (float) $r->amount,
                'payment_method' => $r->payment_method,
                'purpose'        => $r->purpose,
                'status'         => $r->status,
                'imputed_by_id'  => $r->imputed_by_id,
                'imputed_by_name'=> $r->imputedBy ? $r->imputedBy->name : null,
                'imputed_at'     => $r->imputed_at ? $r->imputed_at->toIso8601String() : null,
                'created_at'     => $r->created_at ? $r->created_at->toIso8601String() : now()->toIso8601String(),
            ];
        });

        return response()->json([
            'receipts' => $receipts
        ]);
    }

    /**
     * Émission d'un nouveau reçu par la caisse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'member_id'      => 'required|integer',
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|string',
            'purpose'        => 'required|string',
            'branch_id'      => 'nullable|integer',
        ]);

        $user = Auth::user();

        // Numéro de reçu séquentiel ou unique
        $count = CashReceipt::count() + 1;
        $receiptNumber = 'REC-CAISSE-' . str_pad($count, 4, '0', STR_PAD_LEFT);

        $receipt = CashReceipt::create([
            'receipt_number' => $receiptNumber,
            'cashier_id'     => $user ? $user->id : 1,
            'branch_id'      => $validated['branch_id'] ?? ($user ? $user->branch_id : null),
            'member_id'      => $validated['member_id'],
            'amount'         => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'purpose'        => $validated['purpose'],
            'status'         => 'pending_imputation',
        ]);

        // Écriture miroir automatique dans le grand livre de caisse
        SystemLedgerEntry::create([
            'type'           => 'produit',
            'source'         => 'auto',
            'category'       => $validated['purpose'] === 'deposit' ? 'deposit' : 'credit_repayment',
            'title'          => "Encaissement Reçu {$receiptNumber}",
            'description'    => "Encaissement caisse pour membre ID #{$validated['member_id']} - Motif: {$validated['purpose']}",
            'amount'         => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'reference_type' => CashReceipt::class,
            'reference_id'   => $receipt->id,
            'status'         => 'validated',
            'created_by'     => $user ? $user->id : null,
            'branch_id'      => $receipt->branch_id,
        ]);

        return response()->json([
            'message' => 'Reçu émis avec succès.',
            'receipt' => [
                'id'             => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'cashier_id'     => $receipt->cashier_id,
                'cashier_name'   => $user ? $user->name : 'Caissier',
                'branch_id'      => $receipt->branch_id,
                'member_id'      => $receipt->member_id,
                'amount'         => (float) $receipt->amount,
                'payment_method' => $receipt->payment_method,
                'purpose'        => $receipt->purpose,
                'status'         => $receipt->status,
                'created_at'     => $receipt->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Vérification et verrouillage d'un reçu
     */
    public function verify($receiptNumber)
    {
        $receipt = CashReceipt::with(['cashier', 'member', 'branch'])
            ->where('receipt_number', $receiptNumber)
            ->firstOrFail();

        return response()->json([
            'receipt' => [
                'id'             => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'cashier_id'     => $receipt->cashier_id,
                'cashier_name'   => $receipt->cashier ? $receipt->cashier->name : 'Caissier',
                'branch_id'      => $receipt->branch_id,
                'member_id'      => $receipt->member_id,
                'amount'         => (float) $receipt->amount,
                'payment_method' => $receipt->payment_method,
                'purpose'        => $receipt->purpose,
                'status'         => $receipt->status,
                'created_at'     => $receipt->created_at->toIso8601String(),
            ]
        ]);
    }
}
