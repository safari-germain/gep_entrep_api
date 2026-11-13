<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ReportService;

class FinancialReportController extends Controller
{
    /**
     * GÃ©nÃ¨re un rapport pÃ©riodique
     * ?period=daily | weekly | monthly | semi-annual | annual
     */
    public function index(Request $request)
    {
        $period = $request->query('period', 'monthly');
        $refDate = $request->query('date');
        $branchId = $request->filled('branch_id') ? (int)$request->query('branch_id') : null;

        $report = ReportService::generateFinancialReport($period, $refDate, $branchId);

        return response()->json([
            'status' => 'success',
            'report' => $report,
        ], 200);
    }
}
