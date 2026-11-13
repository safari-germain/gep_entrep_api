<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Credit;
use App\Models\Collection;
use App\Services\DebtRecoveryService;

class DebtRecoveryController extends Controller
{
    public function index(Request $request)
    {
        $collections = Collection::with(['credit.user', 'assignedAgent', 'organisation', 'branch'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'collections' => $collections,
        ], 200);
    }

    public function assign(Request $request)
    {
        $request->validate([
            'credit_id' => 'required|integer',
            'assigned_to_user_id' => 'required|integer',
            'organisation_id' => 'nullable|integer',
            'strategy' => 'nullable|string',
        ]);

        $credit = Credit::findOrFail($request->input('credit_id'));
        $operator = $request->user();

        $collection = DebtRecoveryService::assignRecovery(
            credit: $credit,
            assignedToUserId: (int)$request->input('assigned_to_user_id'),
            organisationId: $request->input('organisation_id'),
            branchId: $credit->branch_id,
            operatorId: $operator->id,
            strategy: $request->input('strategy', 'amicable_reminder')
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Dossier de recouvrement assignÃ© avec succÃ¨s.',
            'collection' => $collection,
        ], 201);
    }

    public function collectPenalty(Request $request, int $collectionId)
    {
        $request->validate([
            'penalty_amount' => 'required|numeric|min:1',
        ]);

        $collection = Collection::findOrFail($collectionId);
        $operator = $request->user();

        $result = DebtRecoveryService::recordPenaltyRecovery(
            collection: $collection,
            penaltyCollected: (float)$request->input('penalty_amount'),
            operatorId: $operator->id
        );

        return response()->json([
            'status' => 'success',
            'message' => 'PÃ©nalitÃ© encaissÃ©e et rÃ©partie (20% agent, 30% organisation, 50% centre GEP).',
            'data' => $result,
        ], 200);
    }
}
