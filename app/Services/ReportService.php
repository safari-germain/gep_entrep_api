<?php

namespace App\Services;

use App\Models\Bonus;
use App\Models\Branch;
use App\Models\Credit;
use App\Models\CreditPayment;
use App\Models\Deposit;
use App\Models\Malus;
use Carbon\Carbon;

class ReportService
{
    /**
     * Calcule le rapport pour la période demandée :
     * 'daily', 'weekly', 'monthly', 'semi_annual', 'annual' ou personnalisé
     */
    public static function generateReport(
        string $period = 'monthly',
        ?string $startDate = null,
        ?string $endDate = null,
        ?int $branchId = null
    ): array {
        $now = Carbon::now();

        if ($startDate && $endDate) {
            $start = Carbon::parse($startDate)->startOfDay();
            $end = Carbon::parse($endDate)->endOfDay();
            $periodName = "Personnalisé ($startDate au $endDate)";
        } else {
            switch ($period) {
                case 'daily':
                    $start = $now->copy()->startOfDay();
                    $end = $now->copy()->endOfDay();
                    $periodName = "Journalier (" . $now->format('d/m/Y') . ")";
                    break;
                case 'weekly':
                    $start = $now->copy()->subDays(7)->startOfDay();
                    $end = $now->copy()->endOfDay();
                    $periodName = "Hebdomadaire (7 derniers jours)";
                    break;
                case 'semi_annual':
                    $start = $now->copy()->subMonths(6)->startOfDay();
                    $end = $now->copy()->endOfDay();
                    $periodName = "Mi-Annuel / Semestriel (6 derniers mois)";
                    break;
                case 'annual':
                    $start = $now->copy()->subYear()->startOfDay();
                    $end = $now->copy()->endOfDay();
                    $periodName = "Annuel (12 derniers mois)";
                    break;
                case 'monthly':
                default:
                    $start = $now->copy()->subDays(30)->startOfDay();
                    $end = $now->copy()->endOfDay();
                    $periodName = "Mensuel (30 derniers jours)";
                    break;
            }
        }

        // 1. Dépôts / Cautions
        $depositQuery = Deposit::whereBetween('created_at', [$start, $end]);
        if ($branchId) {
            $depositQuery->where('branch_id', $branchId);
        }
        $totalDepots = (float) $depositQuery->sum('actual_amount');

        // 2. Prêts décaissés et encours
        $creditQuery = Credit::whereBetween('created_at', [$start, $end]);
        if ($branchId) {
            $creditQuery->where('branch_id', $branchId);
        }
        $totalPretsOctroyes = (float) $creditQuery->sum('principal_amount');
        $totalPretsActifs = (float) Credit::when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->whereIn('status', ['active', 'in_arrears', 'defaulted'])
            ->sum('remaining_balance');

        // 3. Intérêts perçus
        $totalInterets = (float) CreditPayment::whereBetween('created_at', [$start, $end])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->sum('interest_portion');

        // 4. Pénalités perçues
        $totalPenalites = (float) Malus::whereBetween('created_at', [$start, $end])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->sum('penalty_amount');

        // 5. Ventilation des Bonus distribués
        $bonusQuery = Bonus::whereBetween('created_at', [$start, $end]);
        if ($branchId) {
            $bonusQuery->where('branch_id', $branchId);
        }
        $bonusMobilisation = (float) (clone $bonusQuery)->where('bonus_type', 'mobilisation')->sum('amount');
        $bonusParrainage = (float) (clone $bonusQuery)->where('bonus_type', 'parrainage')->sum('amount');
        $bonusEpargne = (float) (clone $bonusQuery)->where('bonus_type', 'epargne_interet')->sum('amount');
        $bonusRecouvrement = (float) (clone $bonusQuery)->where('bonus_type', 'recouvrement_penalite')->sum('amount');
        $totalBonusDistribues = $bonusMobilisation + $bonusParrainage + $bonusEpargne + $bonusRecouvrement;

        // 6. Remboursements de capital perçus
        $totalCapitalRembourse = (float) CreditPayment::whereBetween('created_at', [$start, $end])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->sum('principal_portion');

        // 7. Bénéfice net d'exploitation du centre
        $totalProduits = round($totalInterets + $totalPenalites, 2);
        $totalCharges  = round($totalBonusDistribues, 2);
        $beneficesNet  = round($totalProduits - $totalCharges, 2);

        // 8. Trésorerie nette d'exploitation
        $tresorerieNette = round(($totalDepots + $totalCapitalRembourse + $totalInterets + $totalPenalites) - ($totalPretsOctroyes + $totalBonusDistribues), 2);

        // 9. Dernières transactions
        $recentTransactions = Deposit::with(['user', 'branch'])
            ->whereBetween('created_at', [$start, $end])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->orderBy('id', 'desc')
            ->limit(25)
            ->get()
            ->map(function ($d) {
                return [
                    'recu_numero' => $d->deposit_reference,
                    'created_at'  => $d->created_at->toIso8601String(),
                    'user'        => ['name' => $d->user->name ?? 'Membre'],
                    'type'        => 'Dépôt Caution',
                    'amount'      => $d->actual_amount,
                ];
            });

        $branchName = 'Toutes les succursales';
        if ($branchId) {
            $branch = Branch::find($branchId);
            $branchName = $branch ? $branch->name : "Succursale #$branchId";
        }

        return [
            'meta' => [
                'period'         => $period,
                'period_name'    => $periodName,
                'start_date'     => $start->toDateString(),
                'end_date'       => $end->toDateString(),
                'generated_at'   => $now->toIso8601String(),
                'succursale_nom' => $branchName,
                'currency'       => 'USD',
                'currency_symbol'=> '$',
            ],
            'kpis' => [
                'total_depots'             => $totalDepots,
                'total_sorties'            => 0.0,
                'total_prets_octroyes'     => $totalPretsOctroyes,
                'total_prets_actifs'       => $totalPretsActifs,
                'total_capital_rembourse'  => $totalCapitalRembourse,
                'total_interets'           => $totalInterets,
                'total_penalites'          => $totalPenalites,
                'total_bonus_distribues'   => $totalBonusDistribues,
                'bonus_mobilisation'       => $bonusMobilisation,
                'bonus_parrainage'         => $bonusParrainage,
                'bonus_epargne_13eme_mois' => $bonusEpargne,
                'bonus_recouvrement'       => $bonusRecouvrement,
                'benefices_net'            => $beneficesNet,
                'tresorerie_nette'         => $tresorerieNette,
            ],
            'compte_resultat' => [
                'produits' => [
                    'interets_credits' => $totalInterets,
                    'penalites_retard' => $totalPenalites,
                    'total_produits'   => $totalProduits,
                ],
                'charges' => [
                    'bonus_mobilisation'       => $bonusMobilisation,
                    'bonus_parrainage'         => $bonusParrainage,
                    'bonus_epargne_13eme_mois' => $bonusEpargne,
                    'bonus_recouvrement'       => $bonusRecouvrement,
                    'total_charges'            => $totalCharges,
                ],
                'benefice_net_centre' => $beneficesNet,
            ],
            'bilan_tresorerie' => [
                'entrees' => [
                    'cautions_collectees'    => $totalDepots,
                    'remboursements_capital' => $totalCapitalRembourse,
                    'interets_percus'        => $totalInterets,
                    'penalites_recouvrement' => $totalPenalites,
                ],
                'sorties' => [
                    'prets_octroyes'         => $totalPretsOctroyes,
                    'bonus_redistribues'     => $totalBonusDistribues,
                ],
                'solde_tresorerie' => $tresorerieNette,
                'encours_prets_actifs' => $totalPretsActifs,
            ],
            'transactions' => $recentTransactions,
        ];
    }
}
