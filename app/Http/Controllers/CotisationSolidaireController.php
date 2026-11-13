<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Cycle;
use App\Models\Deposit;
use App\Models\Individual;
use App\Models\Organisation;
use App\Models\User;
use App\Services\FinancialRuleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CotisationSolidaireController extends Controller
{
    /**
     * GET /api/cotisation-solidaire/overview
     * KPIs globaux du cycle actif
     */
    public function overview(Request $request): JsonResponse
    {
        $cycle = Cycle::where('status', 'active')->latest('start_date')->first();

        if (!$cycle) {
            return response()->json([
                'cycle'            => null,
                'message'          => 'Aucun cycle actif. Veuillez ouvrir un nouveau cycle.',
                'total_collected'  => 0,
                'total_members'    => 0,
                'regularity_rate'  => 0,
                'total_bonus'      => 0,
                'total_malus'      => 0,
                'on_time_count'    => 0,
                'late_count'       => 0,
            ]);
        }

        $deposits = Deposit::where('cycle_id', $cycle->id)->get();
        $totalCollected  = $deposits->sum('actual_amount');
        $totalMembers    = $deposits->pluck('user_id')->unique()->count();
        $lateCount       = $deposits->where('is_on_time', false)->count();
        $onTimeCount     = $deposits->where('is_on_time', true)->count();
        $regularityRate  = $deposits->count() > 0
            ? round($onTimeCount / $deposits->count() * 100, 1)
            : 0;

        return response()->json([
            'cycle'           => $cycle,
            'total_collected' => $totalCollected,
            'total_members'   => $totalMembers,
            'regularity_rate' => $regularityRate,
            'on_time_count'   => $onTimeCount,
            'late_count'      => $lateCount,
        ]);
    }

    /**
     * GET /api/cotisation-solidaire/report/{cycleId}
     * Rapport détaillé : tableau par membre × mois (1→12)
     */
    public function report(int $cycleId): JsonResponse
    {
        $cycle = Cycle::findOrFail($cycleId);

        $deposits = Deposit::where('cycle_id', $cycle->id)
            ->with('user:id,name,phone')
            ->orderBy('user_id')
            ->orderBy('actual_date')
            ->get();

        // Grouper par entrepreneur
        $byMember = [];
        foreach ($deposits as $deposit) {
            $uid = $deposit->user_id;
            if (!isset($byMember[$uid])) {
                $byMember[$uid] = [
                    'user_id'        => $uid,
                    'user_name'      => $deposit->user->name ?? '-',
                    'user_phone'     => $deposit->user->phone ?? '-',
                    'total_paid'     => 0,
                    'months'         => array_fill(1, 12, null), // null = pas encore payé
                    'on_time_count'  => 0,
                    'late_count'     => 0,
                ];
            }

            // Identifier le mois du cycle depuis la colonne remarks
            preg_match('/mois #(\d+)/', $deposit->remarks ?? '', $matches);
            $moisNum = isset($matches[1]) ? (int) $matches[1] : null;

            if ($moisNum && $moisNum >= 1 && $moisNum <= 12) {
                $byMember[$uid]['months'][$moisNum] = [
                    'amount'      => $deposit->actual_amount,
                    'is_on_time'  => $deposit->is_on_time,
                    'date'        => $deposit->actual_date?->format('d/m/Y'),
                    'reference'   => $deposit->deposit_reference,
                    'delay_days'  => $deposit->delay_days,
                ];
                $byMember[$uid]['total_paid'] += $deposit->actual_amount;
                if ($deposit->is_on_time) {
                    $byMember[$uid]['on_time_count']++;
                } else {
                    $byMember[$uid]['late_count']++;
                }
            }
        }

        // Calculer la régularité par membre
        foreach ($byMember as &$member) {
            $total = $member['on_time_count'] + $member['late_count'];
            $member['regularity_pct'] = $total > 0
                ? round($member['on_time_count'] / $total * 100, 1)
                : 0;
        }
        unset($member);

        return response()->json([
            'cycle'      => $cycle,
            'members'    => array_values($byMember),
            'total_rows' => count($byMember),
        ]);
    }

    /**
     * GET /api/cotisation-solidaire/contract/{userId?}
     * Génère le PDF de contrat officiel du 1er versement de cycle (12 mois)
     */
    public function generateContract(Request $request, ?int $userId = null): JsonResponse
    {
        $depositId = $request->query('deposit_id');
        $entityType = $request->query('entity_type');
        $entityId = $request->query('entity_id') ?? $userId;

        $deposit = null;
        if ($depositId) {
            $deposit = Deposit::with(['user', 'individual.branch', 'individual.sensibilisateur', 'organisation.branch', 'branch', 'targetBranch'])->find($depositId);
            if ($deposit) {
                $entityType = $deposit->entity_type;
                if ($entityType === 'individual') {
                    $entityId = $deposit->user_id;
                } elseif ($entityType === 'organisation') {
                    $entityId = $deposit->organisation_id;
                } elseif ($entityType === 'branch') {
                    $entityId = $deposit->target_branch_id ?: $deposit->branch_id;
                }
            }
        }

        $entityType = $entityType ?: 'individual';

        $entityName = 'Souscripteur';
        $entityCode = '-';
        $entityPhone = '—';
        $entityEmail = '—';
        $entityAddress = '—';
        $branchName = '—';
        $managerOrSensibilisateur = '—';
        $firstDeposit = null;

        if ($entityType === 'individual') {
            $user = User::findOrFail($entityId);
            $individual = Individual::where('user_id', $user->id)->with(['branch', 'sensibilisateur'])->first();
            $entityName = $user->name;
            $entityCode = $individual?->member_code ?? ('MBR-' . str_pad($user->id, 5, '0', STR_PAD_LEFT));
            $entityPhone = $user->phone ?? '—';
            $entityEmail = $user->email ?? '—';
            $entityAddress = $individual?->home_address ?? ($individual?->business_address ?? '—');
            $branchName = $individual?->branch?->name ?? '—';
            $managerOrSensibilisateur = $individual?->sensibilisateur?->name ?? '—';

            $firstDeposit = Deposit::where('user_id', $user->id)->oldest('actual_date')->first();
        } elseif ($entityType === 'organisation') {
            $org = Organisation::with('branch')->findOrFail($entityId);
            $entityName = $org->name;
            $entityCode = $org->code ?? ('ORG-' . str_pad($org->id, 5, '0', STR_PAD_LEFT));
            $entityPhone = $org->phone ?? '—';
            $entityEmail = $org->email ?? '—';
            $entityAddress = $org->address ?? '—';
            $branchName = $org->branch?->name ?? '—';
            $managerOrSensibilisateur = $org->legal_representative ?? '—';

            $firstDeposit = Deposit::where('organisation_id', $org->id)->oldest('actual_date')->first();
        } elseif ($entityType === 'branch') {
            $branch = Branch::findOrFail($entityId);
            $entityName = "Succursale {$branch->name}";
            $entityCode = $branch->code ?? ('BR-' . str_pad($branch->id, 4, '0', STR_PAD_LEFT));
            $entityPhone = $branch->phone ?? '—';
            $entityEmail = 'contact@gep-entreprendre.com';
            $entityAddress = ($branch->address ? $branch->address . ', ' : '') . ($branch->territory_city ?? $branch->province ?? '—');
            $branchName = $branch->name;
            $managerOrSensibilisateur = $branch->manager_name ?? '—';

            $firstDeposit = Deposit::where('entity_type', 'branch')
                ->where(function($q) use ($branch) {
                    $q->where('target_branch_id', $branch->id)->orWhere('branch_id', $branch->id);
                })->oldest('actual_date')->first();
        }

        if ($deposit) {
            $currentDeposit = $deposit;
        } else {
            $currentDeposit = $firstDeposit;
        }

        // Date de début du cycle = date du 1er versement de l'entité
        $startDate = $firstDeposit && $firstDeposit->actual_date
            ? Carbon::parse($firstDeposit->actual_date)
            : Carbon::now();
        $endDate = $startDate->copy()->addMonths(12);

        // Montant mensuel selon le 1er versement ou 50.00 par défaut
        $montantMensuel = (float) ($currentDeposit?->actual_amount ?? 50.00);

        // Paramètres de configuration système (FinancialRuleService)
        $delaiJourMax = (int) FinancialRuleService::get('cotisation_delai_jour_max', 10);
        $joursRetardGrace = (int) FinancialRuleService::get('cotisation_jours_retard_grace', 2);
        $penaliteRetardPct = (float) FinancialRuleService::get('cotisation_penalite_retard_pct', 5.0);
        $interetTauxAnnuel = (float) FinancialRuleService::get('cotisation_interet_taux_annuel', 8.33);
        $pretPourcentageMax = (float) FinancialRuleService::get('pret_pourcentage_max', 500.0);
        $bonus13emeActif = (bool) FinancialRuleService::get('epargne_bonus_13eme_mois_actif', true);

        $contractRef = 'CTR-' . strtoupper(str_replace(' ', '', $entityCode)) . '-' . $startDate->format('Ymd');

        $data = [
            'entity_type'              => $entityType,
            'entity_name'              => $entityName,
            'entity_code'              => $entityCode,
            'entity_phone'             => $entityPhone,
            'entity_email'             => $entityEmail,
            'entity_address'           => $entityAddress,
            'branch_name'              => $branchName,
            'manager_sensibilisateur'  => $managerOrSensibilisateur,
            'contract_ref'             => $contractRef,
            'generated_at'             => Carbon::now()->format('d/m/Y à H:i'),
            'date_debut'               => $startDate->format('d/m/Y'),
            'date_fin'                 => $endDate->format('d/m/Y'),
            'montant'                  => $montantMensuel,
            'total_prevu'              => $montantMensuel * 12,
            'delai_jour_max'           => $delaiJourMax,
            'jours_retard_grace'       => $joursRetardGrace,
            'penalite_retard_pct'      => $penaliteRetardPct,
            'interet_taux_annuel'      => $interetTauxAnnuel,
            'pret_pourcentage_max'     => $pretPourcentageMax,
            'bonus_13eme_actif'        => $bonus13emeActif,
        ];

        $pdf = Pdf::loadView('pdf.contrat_cotisation', $data)
            ->setPaper('a4', 'portrait');

        $base64 = base64_encode($pdf->output());
        $cleanName = preg_replace('/[^A-Za-z0-9_]/', '_', $entityName);

        return response()->json([
            'filename'    => "Contrat_GEP_{$cleanName}_{$startDate->format('Y')}.pdf",
            'pdf_base64'  => $base64,
            'entity_name' => $entityName,
            'entity_type' => $entityType,
            'date_debut'  => $startDate->format('d/m/Y'),
            'date_fin'    => $endDate->format('d/m/Y'),
            'montant'     => $montantMensuel,
        ]);
    }

    /**
     * GET /api/cotisation-solidaire/active-cycles
     * Liste des cycles de cotisation actuellement actifs avec leur progression
     */
    public function activeCycles(Request $request): JsonResponse
    {
        $cycles = Cycle::with([
            'branch:id,name',
            'ordinaryAccount',
            'user:id,name,phone',
            'individual.user:id,name,phone',
            'organisation:id,name,phone',
        ])
        ->where('status', 'active')
        ->orderByDesc('created_at')
        ->get();

        $mappedCycles = $cycles->map(function ($c) {
            $target = (float) ($c->montant_total_cycle ?: ($c->montant_cotisation_mensuelle * 12));
            $deposited = (float) ($c->total_deposited ?: $c->total_collected);
            $remaining = max(0.0, round($target - $deposited, 2));
            $pct = $target > 0 ? min(100.0, round(($deposited / $target) * 100, 1)) : 0.0;

            return [
                'id'                           => $c->id,
                'name'                         => $c->name,
                'entity_type'                  => $c->entity_type,
                'entity_id'                    => $c->entity_id,
                'target_name'                  => $c->target_name,
                'branch_id'                    => $c->branch_id,
                'branch_name'                  => $c->branch?->name ?? 'Siège',
                'start_date'                   => $c->start_date?->format('d/m/Y'),
                'end_date'                     => $c->end_date?->format('d/m/Y'),
                'recovery_date'                => $c->recovery_date ? $c->recovery_date->format('d/m/Y') : ($c->end_date ? $c->end_date->copy()->addDay()->format('d/m/Y') : '-'),
                'auto_deposit_day'             => (int) ($c->auto_deposit_day ?: 5),
                'montant_total_cycle'          => $target,
                'montant_cotisation_mensuelle' => (float) ($c->montant_cotisation_mensuelle ?: round($target / 12, 2)),
                'total_deposited'              => $deposited,
                'remaining_to_deposit'         => $remaining,
                'progress_pct'                 => $pct,
                'status'                       => $c->status,
                'notes'                        => $c->notes,
                'ordinary_account_balance'     => $c->ordinaryAccount ? (float) $c->ordinaryAccount->balance : 0.0,
            ];
        });

        $totalExpected  = (float) $mappedCycles->sum('montant_total_cycle');
        $totalDeposited = (float) $mappedCycles->sum('total_deposited');
        $totalRemaining = max(0.0, round($totalExpected - $totalDeposited, 2));
        $globalProgressPct = $totalExpected > 0 ? round(($totalDeposited / $totalExpected) * 100, 1) : 0.0;

        return response()->json([
            'cycles' => $mappedCycles,
            'kpis' => [
                'total_active_cycles' => $mappedCycles->count(),
                'total_expected'      => $totalExpected,
                'total_deposited'     => $totalDeposited,
                'total_remaining'     => $totalRemaining,
                'global_progress_pct' => $globalProgressPct,
            ],
        ]);
    }

    /**
     * GET /api/cotisation-solidaire/pdf-report
     * Génère le rapport PDF complet de tous les cycles de cotisation en cours
     */
    public function pdfReport(Request $request): JsonResponse
    {
        $cyclesResponse = $this->activeCycles($request);
        $data = $cyclesResponse->getData(true);

        $cycles = $data['cycles'] ?? [];
        $kpis   = $data['kpis'] ?? [];

        $pdf = Pdf::loadView('pdf.rapport_cotisations_solidaires', [
            'cycles'          => $cycles,
            'kpis'            => $kpis,
            'date_generation' => Carbon::now()->format('d/m/Y à H:i'),
        ])->setPaper('a4', 'landscape');

        $base64 = base64_encode($pdf->output());
        $filename = 'Rapport_Cotisations_Solidaires_Encours_' . Carbon::now()->format('Ymd_His') . '.pdf';

        return response()->json([
            'filename'   => $filename,
            'pdf_base64' => $base64,
            'kpis'       => $kpis,
            'count'      => count($cycles),
        ]);
    }
}
