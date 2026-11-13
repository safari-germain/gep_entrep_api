<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Rapport Global des Cotisations Solidaires en Cours</title>
<style>
  @page {
    margin: 12mm 10mm 12mm 10mm;
    size: a4 landscape;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
    font-size: 8.5px;
    line-height: 1.35;
    color: #1a202c;
    background: #ffffff;
  }
  .w-full { width: 100%; }
  table { border-collapse: collapse; width: 100%; }
  td, th { vertical-align: middle; }

  /* En-tête */
  .header-box {
    background: #0d47a1;
    color: #ffffff;
    padding: 12px 18px;
    border-radius: 4px;
    margin-bottom: 12px;
  }
  .brand-title {
    font-size: 16px;
    font-weight: bold;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    color: #ffffff;
  }
  .brand-sub {
    font-size: 8.5px;
    color: #bbdefb;
    margin-top: 2px;
  }
  .report-pill {
    background: #ffffff;
    color: #0d47a1;
    font-weight: bold;
    padding: 4px 10px;
    border-radius: 10px;
    font-size: 8.5px;
    display: inline-block;
  }

  /* KPI Cards */
  .kpi-table {
    margin-bottom: 12px;
  }
  .kpi-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    padding: 8px 10px;
    text-align: center;
  }
  .kpi-title {
    font-size: 7.5px;
    text-transform: uppercase;
    color: #64748b;
    font-weight: bold;
    margin-bottom: 4px;
  }
  .kpi-value {
    font-size: 13px;
    font-weight: bold;
    color: #0d47a1;
  }
  .kpi-value.green { color: #166534; }
  .kpi-value.amber { color: #d97706; }

  /* Tableau principal */
  .data-table {
    border: 1px solid #cbd5e1;
    margin-bottom: 14px;
  }
  .data-table th {
    background: #1e3a8a;
    color: #ffffff;
    font-size: 7.5px;
    font-weight: bold;
    text-transform: uppercase;
    padding: 6px 5px;
    border: 1px solid #1e3a8a;
    text-align: left;
  }
  .data-table th.center, .data-table td.center { text-align: center; }
  .data-table th.right, .data-table td.right { text-align: right; }
  .data-table td {
    padding: 5px 5px;
    border: 1px solid #e2e8f0;
    font-size: 8px;
  }
  .data-table tr:nth-child(even) td {
    background: #f8fafc;
  }
  .badge-entity {
    display: inline-block;
    padding: 2px 5px;
    border-radius: 3px;
    font-size: 7px;
    font-weight: bold;
    text-transform: uppercase;
  }
  .badge-ind { background: #e0f2fe; color: #0369a1; }
  .badge-org { background: #fef3c7; color: #b45309; }
  .badge-branch { background: #f3e8ff; color: #7e22ce; }

  .prog-bar-bg {
    background: #e2e8f0;
    border-radius: 3px;
    height: 6px;
    width: 60px;
    display: inline-block;
    vertical-align: middle;
    margin-right: 4px;
  }
  .prog-bar-fill {
    background: #16a34a;
    border-radius: 3px;
    height: 6px;
  }

  /* Totaux */
  .total-row td {
    background: #e2e8f0 !important;
    font-weight: bold;
    font-size: 8.5px;
    border-top: 2px solid #0d47a1;
  }

  /* Signatures */
  .signatures-table {
    margin-top: 15px;
  }
  .sig-box {
    border-top: 1px dashed #94a3b8;
    padding-top: 4px;
    margin-top: 30px;
    text-align: center;
    font-size: 7.5px;
    color: #64748b;
  }
  .footer-note {
    font-size: 7px;
    color: #94a3b8;
    margin-top: 10px;
    text-align: center;
  }
</style>
</head>
<body>

  <!-- EN-TÊTE -->
  <div class="header-box">
    <table class="w-full">
      <tr>
        <td style="width: 70%;">
          <div class="brand-title">GEP SOLIDARITÉ — MICROFINANCE & ENTREPRENARIAT</div>
          <div class="brand-sub">Rapport officiel des Cotisations Solidaires & Suivi des Cycles 12 Mois</div>
        </td>
        <td style="width: 30%; text-align: right;">
          <div class="report-pill">CYCLE ANNUEL EN COURS</div>
          <div style="font-size: 7.5px; color: #bbdefb; margin-top: 4px;">Édité le {{ $date_generation }}</div>
        </td>
      </tr>
    </table>
  </div>

  <!-- KPI SUMMARY -->
  <table class="w-full kpi-table">
    <tr>
      <td style="width: 20%; padding-right: 6px;">
        <div class="kpi-card">
          <div class="kpi-title">Cycles Actifs</div>
          <div class="kpi-value">{{ $kpis['total_active_cycles'] }}</div>
        </div>
      </td>
      <td style="width: 20%; padding-right: 6px;">
        <div class="kpi-card">
          <div class="kpi-title">Montant Total Attendu (12M)</div>
          <div class="kpi-value">{{ number_format($kpis['total_expected'], 2, ',', ' ') }} $</div>
        </div>
      </td>
      <td style="width: 20%; padding-right: 6px;">
        <div class="kpi-card">
          <div class="kpi-title">Montant Déjà Déposé</div>
          <div class="kpi-value green">{{ number_format($kpis['total_deposited'], 2, ',', ' ') }} $</div>
        </div>
      </td>
      <td style="width: 20%; padding-right: 6px;">
        <div class="kpi-card">
          <div class="kpi-title">Reste à Recouvrer</div>
          <div class="kpi-value amber">{{ number_format($kpis['total_remaining'], 2, ',', ' ') }} $</div>
        </div>
      </td>
      <td style="width: 20%;">
        <div class="kpi-card">
          <div class="kpi-title">Taux de Progression</div>
          <div class="kpi-value">{{ $kpis['global_progress_pct'] }} %</div>
        </div>
      </td>
    </tr>
  </table>

  <!-- TABLEAU DES CYCLES EN COURS -->
  <table class="w-full data-table">
    <thead>
      <tr>
        <th style="width: 3%;" class="center">#</th>
        <th style="width: 17%;">Entité / Bénéficiaire</th>
        <th style="width: 8%;">Type</th>
        <th style="width: 11%;">Succursale</th>
        <th style="width: 14%;" class="center">Période (12 Mois)</th>
        <th style="width: 6%;" class="center">Jour Auto</th>
        <th style="width: 8%;" class="center">Date Récup.</th>
        <th style="width: 8%;" class="right">Cotis. / Mois</th>
        <th style="width: 8%;" class="right">Total Attendu</th>
        <th style="width: 8%;" class="right">Déjà Déposé</th>
        <th style="width: 9%;" class="center">Progression</th>
      </tr>
    </thead>
    <tbody>
      @forelse($cycles as $idx => $cycle)
      <tr>
        <td class="center">{{ $idx + 1 }}</td>
        <td>
          <strong>{{ $cycle['target_name'] }}</strong>
          @if(!empty($cycle['notes']))
            <div style="font-size: 6.5px; color: #64748b;">{{ $cycle['notes'] }}</div>
          @endif
        </td>
        <td>
          @if($cycle['entity_type'] === 'individual')
            <span class="badge-entity badge-ind">Entrepreneur</span>
          @elseif($cycle['entity_type'] === 'organisation')
            <span class="badge-entity badge-org">Organisation</span>
          @else
            <span class="badge-entity badge-branch">Succursale</span>
          @endif
        </td>
        <td>{{ $cycle['branch_name'] }}</td>
        <td class="center">{{ $cycle['start_date'] }} → {{ $cycle['end_date'] }}</td>
        <td class="center">Le {{ $cycle['auto_deposit_day'] }} du mois</td>
        <td class="center"><strong>{{ $cycle['recovery_date'] }}</strong></td>
        <td class="right">{{ number_format($cycle['montant_cotisation_mensuelle'], 2, ',', ' ') }} $</td>
        <td class="right"><strong>{{ number_format($cycle['montant_total_cycle'], 2, ',', ' ') }} $</strong></td>
        <td class="right" style="color: #166534; font-weight: bold;">{{ number_format($cycle['total_deposited'], 2, ',', ' ') }} $</td>
        <td class="center">
          <div class="prog-bar-bg">
            <div class="prog-bar-fill" style="width: {{ min(100, $cycle['progress_pct']) }}%;"></div>
          </div>
          <span>{{ $cycle['progress_pct'] }}%</span>
        </td>
      </tr>
      @empty
      <tr>
        <td colspan="11" class="center" style="padding: 16px; color: #64748b;">
          Aucun cycle de cotisation actif actuellement.
        </td>
      </tr>
      @endforelse

      @if(count($cycles) > 0)
      <tr class="total-row">
        <td colspan="8" class="right">TOTAUX GÉNÉRAUX :</td>
        <td class="right">{{ number_format($kpis['total_expected'], 2, ',', ' ') }} $</td>
        <td class="right" style="color: #166534;">{{ number_format($kpis['total_deposited'], 2, ',', ' ') }} $</td>
        <td class="center">{{ $kpis['global_progress_pct'] }} %</td>
      </tr>
      @endif
    </tbody>
  </table>

  <!-- SIGNATURES -->
  <table class="w-full signatures-table">
    <tr>
      <td style="width: 30%;">
        <div class="sig-box">
          Le Responsable des Opérations & Suivi
        </div>
      </td>
      <td style="width: 40%;"></td>
      <td style="width: 30%;">
        <div class="sig-box">
          La Direction Générale Financière
        </div>
      </td>
    </tr>
  </table>

  <div class="footer-note">
    Document certifié par le système central GEP Solidarité — Rapport généré automatiquement le {{ $date_generation }}
  </div>

</body>
</html>
