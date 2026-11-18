<?php

namespace App\Services;

use App\Models\OhadaAccount;
use App\Models\AccountingEntry;
use App\Models\AccountingEntryLine;
use App\Models\OrdinaryAccount;
use App\Models\Credit;
use App\Models\Deposit;
use App\Models\SystemLedgerEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OhadaAccountingService
{
    /**
     * Génère le Bilan OHADA (SYSCOHADA Révisé) pour une période donnée.
     * Retourne structure Actif / Passif conformes à la nomenclature EMF.
     */
    public function getBilan(?string $periodStart = null, ?string $periodEnd = null): array
    {
        $endDate = $periodEnd ? Carbon::parse($periodEnd)->endOfDay() : Carbon::now()->endOfDay();
        $startDate = $periodStart ? Carbon::parse($periodStart)->startOfDay() : Carbon::now()->startOfYear()->startOfDay();

        // ── ACTIF ──────────────────────────────────────────────────────────────────
        // Classe 1: Ressources durables (Passif) → pas dans actif
        // Classe 2: Immobilisations → Actif immobilisé
        // Classe 4: Créances (409, 41, 44) → Actif circulant
        // Classe 5: Trésorerie → Actif trésorerie

        // Encours crédits (prêts accordés) = créances clients / classe 41 OHADA EMF
        $encoursCreditsTotaux = Credit::whereIn('status', ['active', 'in_arrears', 'disbursed'])
            ->sum('remaining_balance');

        // Dépôts de caution (garanties reçues = actif trésorerie)
        $depositsCaution = (float) Deposit::where('wallet_destination', 'caution')
            ->sum('actual_amount');

        // Comptes ordinaires agrégés (soldes positifs = trésorerie)
        $tresorerieCompteOrdinaire = OrdinaryAccount::where('balance', '>', 0)->sum('balance');

        // Pénalités à recevoir (créances litigieuses classe 476)
        $penalitesARecevoir = DB::table('credit_schedules')
            ->where('status', '!=', 'paid')
            ->sum('penalty_due');

        // Intérêts à recevoir sur crédits en cours (classe 471)
        $interetsARecevoir = DB::table('credit_schedules')
            ->whereIn('status', ['pending', 'overdue'])
            ->sum('interest_due');

        // Immobilisations (accounting_entry_lines classe 2)
        $immobilisations = $this->sumAccountClass('2', $startDate, $endDate, 'debit');

        // Fonds de réserve investis (accounting_entry_lines classe 1)
        $reservesActif = $this->sumAccountClass('1', $startDate, $endDate, 'debit');

        $actifImmobilise = [
            'label' => 'ACTIF IMMOBILISÉ',
            'montant' => round($immobilisations, 2),
            'lignes' => [
                ['compte' => '20', 'libelle' => 'Immobilisations incorporelles', 'montant' => round($this->sumAccount('20', $startDate, $endDate, 'debit'), 2)],
                ['compte' => '21', 'libelle' => 'Terrains & constructions', 'montant' => round($this->sumAccount('21', $startDate, $endDate, 'debit'), 2)],
                ['compte' => '24', 'libelle' => 'Matériel et mobilier', 'montant' => round($this->sumAccount('24', $startDate, $endDate, 'debit'), 2)],
                ['compte' => '28', 'libelle' => 'Amortissements (- val.)', 'montant' => -round($this->sumAccount('28', $startDate, $endDate, 'credit'), 2)],
            ],
        ];

        $actifCirculant = [
            'label' => 'ACTIF CIRCULANT',
            'montant' => round($encoursCreditsTotaux + $interetsARecevoir + $penalitesARecevoir, 2),
            'lignes' => [
                ['compte' => '411', 'libelle' => 'Encours crédits accordés', 'montant' => round($encoursCreditsTotaux, 2)],
                ['compte' => '471', 'libelle' => 'Intérêts courus à recevoir', 'montant' => round($interetsARecevoir, 2)],
                ['compte' => '476', 'libelle' => 'Pénalités de retard à recevoir', 'montant' => round($penalitesARecevoir, 2)],
            ],
        ];

        $actifTresorerie = [
            'label' => 'TRÉSORERIE ACTIVE',
            'montant' => round($tresorerieCompteOrdinaire + $depositsCaution, 2),
            'lignes' => [
                ['compte' => '521', 'libelle' => 'Comptes ordinaires membres (soldes positifs)', 'montant' => round($tresorerieCompteOrdinaire, 2)],
                ['compte' => '523', 'libelle' => 'Dépôts de caution collectés', 'montant' => round($depositsCaution, 2)],
            ],
        ];

        $totalActif = round(
            $actifImmobilise['montant'] + $actifCirculant['montant'] + $actifTresorerie['montant'],
            2
        );

        // ── PASSIF ─────────────────────────────────────────────────────────────────
        // Classe 1: Capitaux propres & emprunts à long terme
        // Classe 4: Dettes fournisseurs, bonus dus, malus, cotisations à verser

        // Fonds propres / capital social (classe 10 OHADA)
        $fondsPropres = $this->sumAccount('10', $startDate, $endDate, 'credit');

        // Réserves accumulées (classe 11-13)
        $reserves = $this->sumAccount('11', $startDate, $endDate, 'credit')
            + $this->sumAccount('12', $startDate, $endDate, 'credit')
            + $this->sumAccount('13', $startDate, $endDate, 'credit');

        // Résultat de l'exercice (calculé via compte exploitation)
        $exploitation = $this->getCompteExploitation($periodStart, $periodEnd);
        $resultatExercice = $exploitation['resultat_net'];

        // Dettes envers membres (bonus annuels en attente, malus dus)
        $bonusEnAttente = DB::table('annual_bonus_accounts')
            ->where('status', 'pending')
            ->sum('net_bonus_balance');

        // Comptes ordinaires en négatif (passif — obligation de remboursement théorique)
        $comptesNegatifs = abs(OrdinaryAccount::where('balance', '<', 0)->sum('balance'));

        // Cotisations collectées (dépôts associés aux cycles solidaires)
        $cotisationsCollectees = (float) Deposit::whereNotNull('cycle_id')
            ->sum('actual_amount');

        $passifCapitaux = [
            'label' => 'CAPITAUX PROPRES & ASSIMILÉS',
            'montant' => round($fondsPropres + $reserves + $resultatExercice, 2),
            'lignes' => [
                ['compte' => '101', 'libelle' => 'Capital social / Fonds propres', 'montant' => round($fondsPropres, 2)],
                ['compte' => '11', 'libelle' => 'Réserves légales et statutaires', 'montant' => round($reserves, 2)],
                ['compte' => '13', 'libelle' => 'Résultat net de l\'exercice', 'montant' => round($resultatExercice, 2)],
            ],
        ];

        $passifDettes = [
            'label' => 'DETTES & OBLIGATIONS',
            'montant' => round($bonusEnAttente + $comptesNegatifs + $cotisationsCollectees, 2),
            'lignes' => [
                ['compte' => '421', 'libelle' => 'Bonus annuels membres (à verser fin d\'année)', 'montant' => round($bonusEnAttente, 2)],
                ['compte' => '422', 'libelle' => 'Découverts membres (soldes ordinaires négatifs)', 'montant' => round($comptesNegatifs, 2)],
                ['compte' => '471', 'libelle' => 'Cotisations collectées en attente d\'affectation', 'montant' => round($cotisationsCollectees, 2)],
            ],
        ];

        $totalPassif = round(
            $passifCapitaux['montant'] + $passifDettes['montant'],
            2
        );

        return [
            'periode' => [
                'debut' => $startDate->toDateString(),
                'fin' => $endDate->toDateString(),
            ],
            'actif' => [
                'actif_immobilise' => $actifImmobilise,
                'actif_circulant' => $actifCirculant,
                'actif_tresorerie' => $actifTresorerie,
                'total' => $totalActif,
            ],
            'passif' => [
                'passif_capitaux' => $passifCapitaux,
                'passif_dettes' => $passifDettes,
                'total' => $totalPassif,
            ],
            'equilibre' => [
                'difference' => round($totalActif - $totalPassif, 2),
                'equilibre' => abs($totalActif - $totalPassif) < 1,
            ],
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Génère le Compte de Résultat / Compte d'Exploitation OHADA.
     * Produits (Classe 7) - Charges (Classe 6) = Résultat net.
     */
    public function getCompteExploitation(?string $periodStart = null, ?string $periodEnd = null): array
    {
        $endDate = $periodEnd ? Carbon::parse($periodEnd)->endOfDay() : Carbon::now()->endOfDay();
        $startDate = $periodStart ? Carbon::parse($periodStart)->startOfDay() : Carbon::now()->startOfYear()->startOfDay();

        // ── PRODUITS (Classe 7) ───────────────────────────────────────────────────
        // Intérêts sur crédits accordés (71 - Intérêts et produits assimilés)
        $interetsRecus = DB::table('credit_schedules')
            ->where('status', 'paid')
            ->whereBetween('paid_date', [$startDate, $endDate])
            ->sum('interest_due');

        // Pénalités de retard encaissées (741)
        $penalitesEncaissees = DB::table('credit_schedules')
            ->where('status', 'paid')
            ->whereBetween('paid_date', [$startDate, $endDate])
            ->sum('penalty_due');

        // Commissions et frais de dossier (742)
        $commissionsEncaissees = DB::table('system_ledger_entries')
            ->where('category', 'commission')
            ->where('type', 'produit')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount');

        // Intérêts débiteurs prélevés sur comptes ordinaires négatifs (751)
        $interetsDebiteurs = OrdinaryAccount::whereBetween('last_interest_calculated_at', [$startDate, $endDate])
            ->sum('total_debit_interest_charged');

        // Autres produits (accounting_entry_lines classe 7)
        $autresProduits = $this->sumAccountClass('7', $startDate, $endDate, 'credit');

        $totalProduits = round($interetsRecus + $penalitesEncaissees + $commissionsEncaissees + $autresProduits, 2);

        $produits = [
            'label' => 'PRODUITS D\'EXPLOITATION',
            'total' => $totalProduits,
            'lignes' => [
                ['compte' => '71', 'libelle' => 'Intérêts sur crédits accordés', 'montant' => round($interetsRecus, 2)],
                ['compte' => '741', 'libelle' => 'Pénalités de retard encaissées', 'montant' => round($penalitesEncaissees, 2)],
                ['compte' => '742', 'libelle' => 'Commissions & frais de dossier', 'montant' => round($commissionsEncaissees, 2)],
                ['compte' => '751', 'libelle' => 'Intérêts débiteurs (comptes négatifs)', 'montant' => round($interetsDebiteurs, 2)],
                ['compte' => '79', 'libelle' => 'Autres produits', 'montant' => round($autresProduits - $interetsRecus - $penalitesEncaissees - $commissionsEncaissees, 2)],
            ],
        ];

        // ── CHARGES (Classe 6) ────────────────────────────────────────────────────
        // Frais généraux / charges de fonctionnement (61)
        $chargesGenerales = DB::table('system_ledger_entries')
            ->where('type', 'charge')
            ->whereNotIn('category', ['withdrawal', 'credit_deduction'])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount');

        // Bonus versés aux membres (641)
        $bonusVerses = (float) DB::table('bonuses')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount');

        // Provisions pour créances douteuses (691)
        $provisionsCreances = Credit::where('status', 'in_arrears')
            ->whereBetween('updated_at', [$startDate, $endDate])
            ->sum('remaining_balance') * 0.1; // 10% provision

        // Autres charges (accounting_entry_lines classe 6)
        $autresCharges = $this->sumAccountClass('6', $startDate, $endDate, 'debit');

        $totalCharges = round($chargesGenerales + $bonusVerses + $provisionsCreances + $autresCharges, 2);

        $charges = [
            'label' => 'CHARGES D\'EXPLOITATION',
            'total' => $totalCharges,
            'lignes' => [
                ['compte' => '61', 'libelle' => 'Charges de fonctionnement', 'montant' => round($chargesGenerales, 2)],
                ['compte' => '641', 'libelle' => 'Bonus versés aux membres', 'montant' => round($bonusVerses, 2)],
                ['compte' => '691', 'libelle' => 'Provisions créances douteuses (10%)', 'montant' => round($provisionsCreances, 2)],
                ['compte' => '69', 'libelle' => 'Autres charges', 'montant' => round($autresCharges - $chargesGenerales - $bonusVerses, 2)],
            ],
        ];

        $resultatNet = round($totalProduits - $totalCharges, 2);

        return [
            'periode' => [
                'debut' => $startDate->toDateString(),
                'fin' => $endDate->toDateString(),
            ],
            'produits' => $produits,
            'charges' => $charges,
            'resultat_net' => $resultatNet,
            'resultat_nature' => $resultatNet >= 0 ? 'bénéfice' : 'déficit',
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Somme des mouvements d'une classe OHADA (ex: '6' = charges, '7' = produits).
     */
    private function sumAccountClass(string $class, Carbon $from, Carbon $to, string $direction): float
    {
        return (float) DB::table('accounting_entry_lines')
            ->join('ohada_accounts', 'accounting_entry_lines.ohada_account_id', '=', 'ohada_accounts.id')
            ->where('ohada_accounts.account_number', 'like', $class . '%')
            ->where('accounting_entry_lines.direction', $direction)
            ->whereBetween('accounting_entry_lines.created_at', [$from, $to])
            ->sum('accounting_entry_lines.amount');
    }

    /**
     * Somme d'un compte précis (ex: '521', '20').
     */
    private function sumAccount(string $accountPrefix, Carbon $from, Carbon $to, string $direction): float
    {
        return (float) DB::table('accounting_entry_lines')
            ->join('ohada_accounts', 'accounting_entry_lines.ohada_account_id', '=', 'ohada_accounts.id')
            ->where('ohada_accounts.account_number', 'like', $accountPrefix . '%')
            ->where('accounting_entry_lines.direction', $direction)
            ->whereBetween('accounting_entry_lines.created_at', [$from, $to])
            ->sum('accounting_entry_lines.amount');
    }

    /**
     * Génère un rapport PDF du Bilan + Compte d'Exploitation.
     * Retourne le contenu HTML du rapport (imprimable via navigateur / puppeteer).
     */
    public function generateHtmlReport(array $bilan, array $exploitation, string $institution = 'GEP Solidarité'): string
    {
        $dateGeneration = Carbon::now()->format('d/m/Y à H:i:s');
        $periodeDebut = $bilan['periode']['debut'];
        $periodeFin = $bilan['periode']['fin'];

        $totalActif = number_format($bilan['actif']['total'], 2, ',', ' ');
        $totalPassif = number_format($bilan['passif']['total'], 2, ',', ' ');
        $resultatNet = number_format($exploitation['resultat_net'], 2, ',', ' ');
        $totalProduits = number_format($exploitation['produits']['total'], 2, ',', ' ');
        $totalCharges = number_format($exploitation['charges']['total'], 2, ',', ' ');

        $actifRows = '';
        foreach ($bilan['actif'] as $section) {
            if (!is_array($section) || !isset($section['lignes'])) continue;
            $actifRows .= "<tr class='section-header'><td colspan='3'><b>{$section['label']}</b></td></tr>";
            foreach ($section['lignes'] as $ligne) {
                $m = number_format($ligne['montant'], 2, ',', ' ');
                $actifRows .= "<tr><td>{$ligne['compte']}</td><td>{$ligne['libelle']}</td><td class='amount'>{$m} XAF</td></tr>";
            }
        }

        $passifRows = '';
        foreach ($bilan['passif'] as $section) {
            if (!is_array($section) || !isset($section['lignes'])) continue;
            $passifRows .= "<tr class='section-header'><td colspan='3'><b>{$section['label']}</b></td></tr>";
            foreach ($section['lignes'] as $ligne) {
                $m = number_format($ligne['montant'], 2, ',', ' ');
                $passifRows .= "<tr><td>{$ligne['compte']}</td><td>{$ligne['libelle']}</td><td class='amount'>{$m} XAF</td></tr>";
            }
        }

        $produitsRows = '';
        foreach ($exploitation['produits']['lignes'] as $ligne) {
            $m = number_format($ligne['montant'], 2, ',', ' ');
            $produitsRows .= "<tr><td>{$ligne['compte']}</td><td>{$ligne['libelle']}</td><td class='amount'>{$m} XAF</td></tr>";
        }

        $chargesRows = '';
        foreach ($exploitation['charges']['lignes'] as $ligne) {
            $m = number_format($ligne['montant'], 2, ',', ' ');
            $chargesRows .= "<tr><td>{$ligne['compte']}</td><td>{$ligne['libelle']}</td><td class='amount'>{$m} XAF</td></tr>";
        }

        $resultatClass = $exploitation['resultat_net'] >= 0 ? 'benefice' : 'deficit';
        $equilibreStatus = $bilan['equilibre']['equilibre'] ? '✅ Bilan équilibré' : '⚠️ Écart: ' . number_format($bilan['equilibre']['difference'], 2, ',', ' ') . ' XAF';

        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Bilan OHADA – {$institution} – {$periodeFin}</title>
<style>
  body { font-family: Arial, sans-serif; font-size: 11px; color: #222; margin: 20px; }
  h1 { font-size: 16px; text-align: center; color: #1a3c5e; }
  h2 { font-size: 13px; color: #1a3c5e; border-bottom: 2px solid #1a3c5e; padding-bottom: 4px; }
  .header-info { text-align: center; margin-bottom: 20px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
  th { background: #1a3c5e; color: white; padding: 6px 8px; text-align: left; }
  td { padding: 4px 8px; border-bottom: 1px solid #ddd; }
  .amount { text-align: right; font-family: monospace; }
  .section-header td { background: #e8f0f8; font-weight: bold; }
  .total-row td { font-weight: bold; background: #1a3c5e; color: white; }
  .benefice { color: #2e7d32; font-weight: bold; }
  .deficit { color: #c62828; font-weight: bold; }
  .signature-zone { margin-top: 40px; display: flex; justify-content: space-between; }
  .signature-box { width: 200px; border-top: 1px solid #333; padding-top: 8px; text-align: center; }
  .equilibre { text-align: center; padding: 8px; margin: 10px 0; background: #f0f8f0; border: 1px solid #4caf50; border-radius: 4px; }
  .two-col { display: flex; gap: 20px; }
  .col { flex: 1; }
  @media print { body { margin: 10mm; } .no-print { display: none; } }
</style>
</head>
<body>
<div class="header-info">
  <h1>{$institution}</h1>
  <p><b>BILAN COMPTABLE – Norme SYSCOHADA Révisée</b></p>
  <p>Période du <b>{$periodeDebut}</b> au <b>{$periodeFin}</b></p>
  <p>Généré le {$dateGeneration}</p>
</div>

<div class="two-col">
  <div class="col">
    <h2>ACTIF</h2>
    <table>
      <thead><tr><th>Cpte</th><th>Libellé</th><th>Montant</th></tr></thead>
      <tbody>{$actifRows}</tbody>
      <tfoot><tr class="total-row"><td colspan="2">TOTAL ACTIF</td><td class="amount">{$totalActif} XAF</td></tr></tfoot>
    </table>
  </div>
  <div class="col">
    <h2>PASSIF</h2>
    <table>
      <thead><tr><th>Cpte</th><th>Libellé</th><th>Montant</th></tr></thead>
      <tbody>{$passifRows}</tbody>
      <tfoot><tr class="total-row"><td colspan="2">TOTAL PASSIF</td><td class="amount">{$totalPassif} XAF</td></tr></tfoot>
    </table>
  </div>
</div>

<div class="equilibre">{$equilibreStatus}</div>

<h2>COMPTE DE RÉSULTAT (EXPLOITATION)</h2>
<div class="two-col">
  <div class="col">
    <table>
      <thead><tr><th>Cpte</th><th>PRODUITS</th><th>Montant</th></tr></thead>
      <tbody>{$produitsRows}</tbody>
      <tfoot><tr class="total-row"><td colspan="2">TOTAL PRODUITS</td><td class="amount">{$totalProduits} XAF</td></tr></tfoot>
    </table>
  </div>
  <div class="col">
    <table>
      <thead><tr><th>Cpte</th><th>CHARGES</th><th>Montant</th></tr></thead>
      <tbody>{$chargesRows}</tbody>
      <tfoot><tr class="total-row"><td colspan="2">TOTAL CHARGES</td><td class="amount">{$totalCharges} XAF</td></tr></tfoot>
    </table>
  </div>
</div>

<p class="{$resultatClass}">
  RÉSULTAT NET : {$resultatNet} XAF ({$exploitation['resultat_nature']})
</p>

<div class="signature-zone">
  <div class="signature-box">Directeur Général<br><br><br><br>_________________</div>
  <div class="signature-box">Directeur Financier<br><br><br><br>_________________</div>
  <div class="signature-box">Auditeur / Contrôleur<br><br><br><br>_________________</div>
</div>
</body>
</html>
HTML;
    }
}
