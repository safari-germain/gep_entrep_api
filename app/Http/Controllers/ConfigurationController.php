<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\FinancialRuleService;
use App\Models\FinancialRule;

class ConfigurationController extends Controller
{
    public function index()
    {
        $rules = FinancialRuleService::allRules();
        return response()->json([
            'status' => 'success',
            'configurations' => array_values($rules),
            'rules_map' => $rules,
        ], 200);
    }

    public function batchUpdate(Request $request)
    {
        $configs = $request->input('configs', []);

        foreach ($configs as $key => $val) {
            FinancialRuleService::set($key, $val);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Configurations mises Ã  jour avec succÃ¨s.',
            'configurations' => array_values(FinancialRuleService::allRules()),
        ], 200);
    }

    public function update(Request $request, string $key)
    {
        $val = $request->input('value');
        $rule = FinancialRuleService::set($key, $val, $request->input('name'), $request->input('description'));

        return response()->json([
            'status' => 'success',
            'message' => "RÃ¨gle {$key} mise Ã  jour.",
            'rule' => $rule,
        ], 200);
    }
}
