<?php

namespace App\Http\Controllers;

use App\Models\FinancialValidation;
use App\Models\OrdinaryAccount;
use App\Models\Cycle;
use App\Services\OrdinaryAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class FinancialValidationController extends Controller
{
    protected OrdinaryAccountService $accountService;

    public function __construct(OrdinaryAccountService $accountService)
    {
        $this->accountService = $accountService;
    }

    /**
     * Liste des validations financières
     */
    public function index(Request $request)
    {
        $query = FinancialValidation::with(['initiator', 'validator']);

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('status', $request->query('status'));
        }

        $validations = $query->orderBy('created_at', 'desc')->get()->map(function ($v) {
            return [
                'id'              => $v->id,
                'reference'       => $v->reference,
                'operation_type'  => $v->operation_type,
                'status'          => $v->status,
                'initiated_by'    => $v->initiated_by,
                'initiated_at'    => $v->initiated_at ? $v->initiated_at->toIso8601String() : $v->created_at->toIso8601String(),
                'initiator'       => $v->initiator ? ['id' => $v->initiator->id, 'name' => $v->initiator->name, 'phone' => $v->initiator->phone] : null,
                'validated_by'    => $v->validated_by,
                'validated_at'    => $v->validated_at ? $v->validated_at->toIso8601String() : null,
                'validator'       => $v->validator ? ['id' => $v->validator->id, 'name' => $v->validator->name] : null,
                'validator_note'  => $v->validator_note,
                'entity_type'     => $v->entity_type,
                'entity_id'       => $v->entity_id,
                'amount'          => (float) $v->amount,
                'description'     => $v->description,
                'payload'         => $v->payload,
            ];
        });

        return response()->json([
            'data' => $validations
        ]);
    }

    /**
     * Approuver une validation financière avec confirmation par mot de passe administrateur
     */
    public function approve(Request $request, $id)
    {
        $request->validate([
            'password'       => 'required|string',
            'validator_note' => 'nullable|string',
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        // Vérification de sécurité obligatoire par mot de passe de l'administrateur
        if (!\Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
            return response()->json([
                'status'  => false,
                'message' => 'Mot de passe administrateur incorrect. La confirmation a échoué.',
            ], 403);
        }

        $validation = FinancialValidation::findOrFail($id);

        if ($validation->status !== 'pending') {
            return response()->json([
                'status'  => false,
                'message' => 'Cette demande a déjà été traitée (statut : ' . $validation->status . ').',
            ], 422);
        }

        $validation->update([
            'status'         => 'validated',
            'validated_by'   => $user->id,
            'validated_at'   => now(),
            'validator_note' => $request->input('validator_note', 'Retrait approuvé et validé avec confirmation de mot de passe.'),
        ]);

        // Si l'opération avait une action différée sur le compte ordinaire
        if ($validation->payload && isset($validation->payload['type'])) {
            $type = $validation->payload['type'];
            $account = OrdinaryAccount::getOrCreateForEntity($validation->entity_type, $validation->entity_id);

            if ($type === 'deposit') {
                $this->accountService->deposit(
                    $account,
                    (float) $validation->amount,
                    $validation->payload['payment_method'] ?? 'cash',
                    $validation->description ?? 'Dépôt ordinaire validé',
                    $user->id
                );
            } elseif ($type === 'withdrawal') {
                $this->accountService->withdraw(
                    $account,
                    (float) $validation->amount,
                    $validation->payload['payment_method'] ?? 'cash',
                    $validation->description ?? 'Retrait ordinaire validé',
                    $user->id
                );
            }
        }

        return response()->json([
            'status'     => true,
            'message'    => 'Validation approuvée et exécutée avec succès.',
            'validation' => $validation,
        ]);
    }

    /**
     * Rejeter une validation financière
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'validator_note' => 'required|string|max:1000',
        ]);

        $validation = FinancialValidation::findOrFail($id);
        $user = Auth::user();

        $validation->update([
            'status'         => 'rejected',
            'validated_by'   => $user ? $user->id : null,
            'validated_at'   => now(),
            'validator_note' => $request->input('validator_note'),
        ]);

        return response()->json([
            'status'     => true,
            'message'    => 'Validation rejetée.',
            'validation' => $validation,
        ]);
    }

    /**
     * Initier la liquidation de cycle
     */
    public function liquidateCycle(Request $request, $cycleId)
    {
        $validated = $request->validate([
            'settlements'                          => 'required|array',
            'settlements.*.entity_type'            => 'required|string',
            'settlements.*.entity_id'              => 'required|integer',
            'settlements.*.caution_refund_amount'  => 'required|numeric',
            'settlements.*.bonus_amount'           => 'required|numeric',
            'settlements.*.malus_deduction_amount' => 'required|numeric',
            'settlements.*.notes'                  => 'nullable|string',
        ]);

        $cycle = Cycle::findOrFail($cycleId);
        $user = Auth::user();
        $created = [];

        foreach ($validated['settlements'] as $s) {
            $netRefund = round(
                ((float) $s['caution_refund_amount']) +
                ((float) $s['bonus_amount']) -
                ((float) $s['malus_deduction_amount']),
                2
            );

            $ref = 'VAL-LIQ-' . date('Ymd') . '-' . strtoupper(Str::random(6));

            $val = FinancialValidation::create([
                'reference'      => $ref,
                'operation_type' => 'cycle_settlement',
                'status'         => 'pending',
                'initiated_by'   => $user ? $user->id : 1,
                'initiated_at'   => now(),
                'entity_type'    => $s['entity_type'],
                'entity_id'      => $s['entity_id'],
                'amount'         => $netRefund,
                'description'    => "Liquidation Cycle {$cycle->name}: Caution({$s['caution_refund_amount']}$) + Bonus({$s['bonus_amount']}$) - Malus({$s['malus_deduction_amount']}$)",
                'payload'        => $s,
            ]);

            $created[] = $val;
        }

        return response()->json([
            'status'      => true,
            'message'     => count($created) . ' liquidation(s) soumise(s) pour validation.',
            'validations' => $created,
        ], 201);
    }
}
