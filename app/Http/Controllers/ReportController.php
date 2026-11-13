<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Dashboard des Rapports financiers multi-périodes :
     * - journalier ('daily')
     * - hebdomadaire ('weekly')
     * - mensuel ('monthly')
     * - mi-annuel ('semi_annual')
     * - annuel ('annual')
     */
    public function dashboard(Request $request): JsonResponse
    {
        $period = $request->query('period', 'monthly');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $branchId = $request->query('branch_id') ? (int) $request->query('branch_id') : null;

        $report = ReportService::generateReport($period, $startDate, $endDate, $branchId);

        return response()->json($report);
    }
}
