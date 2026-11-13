<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
  @page {
    margin: 15mm 15mm 15mm 15mm;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
    font-size: 10px;
    line-height: 1.45;
    color: #1a202c;
    background: #ffffff;
  }

  /* Table utilities for DomPDF */
  .w-full { width: 100%; }
  table { border-collapse: collapse; width: 100%; }
  td, th { vertical-align: top; }

  /* En-tête */
  .header-box {
    background: #0d47a1;
    color: #ffffff;
    padding: 16px 20px;
    border-radius: 6px;
    margin-bottom: 12px;
  }
  .header-left {
    width: 68%;
  }
  .header-right {
    width: 32%;
    text-align: right;
  }
  .brand-title {
    font-size: 18px;
    font-weight: bold;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    color: #ffffff;
  }
  .brand-sub {
    font-size: 9px;
    color: #bbdefb;
    margin-top: 2px;
  }
  .contract-pill {
    background: #ffffff;
    color: #0d47a1;
    font-weight: bold;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 9px;
    display: inline-block;
  }
  .contract-ref {
    font-size: 8.5px;
    color: #bbdefb;
    margin-top: 4px;
  }

  /* Titres de sections */
  .section-header {
    background: #f1f5f9;
    border-left: 4px solid #0d47a1;
    padding: 5px 10px;
    font-size: 10px;
    font-weight: bold;
    color: #0d47a1;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-top: 10px;
    margin-bottom: 6px;
  }

  /* Grilles d'informations */
  .info-table {
    margin-bottom: 4px;
  }
  .info-table td {
    padding: 3px 6px;
    font-size: 9.5px;
  }
  .label-cell {
    width: 25%;
    font-weight: bold;
    color: #4a5568;
  }
  .value-cell {
    width: 75%;
    color: #1a202c;
  }
  .highlight-badge {
    background: #e3f2fd;
    color: #0d47a1;
    font-weight: bold;
    padding: 2px 7px;
    border-radius: 4px;
    border: 1px solid #90caf9;
  }
  .date-badge {
    background: #e8f5e9;
    color: #1b5e20;
    font-weight: bold;
    padding: 2px 7px;
    border-radius: 4px;
    border: 1px solid #a5d6a7;
  }

  /* Tableau des 12 mois */
  .schedule-table {
    margin: 6px 0;
  }
  .schedule-table th {
    background: #0d47a1;
    color: #ffffff;
    font-size: 8px;
    font-weight: bold;
    text-align: center;
    padding: 4px 2px;
    border: 1px solid #0d47a1;
  }
  .schedule-table td {
    font-size: 8.5px;
    text-align: center;
    padding: 4px 2px;
    border: 1px solid #cbd5e1;
    background: #f8fafc;
    color: #0d47a1;
    font-weight: bold;
  }

  /* Boîte des clauses et règles financières */
  .rules-box {
    background: #fafafa;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    padding: 8px 12px;
    margin: 6px 0;
  }
  .article-item {
    margin-bottom: 6px;
    font-size: 8.5px;
    line-height: 1.4;
    text-align: justify;
  }
  .article-title {
    font-weight: bold;
    color: #0d47a1;
  }

  /* Signatures */
  .signatures-table {
    margin-top: 14px;
    page-break-inside: avoid;
  }
  .sig-col {
    width: 50%;
    text-align: center;
    padding: 0 15px;
  }
  .sig-title {
    font-size: 9px;
    font-weight: bold;
    color: #2d3748;
    margin-bottom: 30px;
  }
  .sig-line {
    border-top: 1px solid #718096;
    margin: 0 auto 4px auto;
    width: 80%;
  }
  .sig-sub {
    font-size: 8px;
    color: #718096;
    font-style: italic;
  }

  /* Pied de page */
  .footer {
    position: fixed;
    bottom: -10mm;
    left: 0;
    right: 0;
    text-align: center;
    font-size: 8px;
    color: #94a3b8;
    border-top: 1px solid #e2e8f0;
    padding-top: 5px;
  }
</style>
</head>
<body>

<!-- PIED DE PAGE FIXE -->
<div class="footer">
  Contrat officiel GEP Entreprendre • Cycle de Caution Solidaire de 12 Mois • Réf: {{ $contract_ref }} • Édité le {{ $generated_at }}
</div>

<!-- EN-TETE OFFICIEL -->
<div class="header-box">
  <table class="w-full">
    <tr>
      <td class="header-left">
        <div class="brand-title">GEP ENTREPRENDRE</div>
        <div class="brand-sub">Réseau d'Épargne, Caution Solidaire & Micro-Investissement</div>
        <div style="margin-top: 5px; font-size: 11px; font-weight: bold; color: #ffffff;">
          CONTRAT D'ADHÉSION & CAUTION SOLIDAIRE (CYCLE 12 MOIS)
        </div>
      </td>
      <td class="header-right">
        <div class="contract-pill">{{ $entity_code }}</div>
        <div class="contract-ref">Réf : {{ $contract_ref }}</div>
        <div class="contract-ref">Statut : <strong>ACTIF</strong></div>
      </td>
    </tr>
  </table>
</div>

<!-- PREAMBULE -->
<p style="font-size: 8.5px; margin-bottom: 6px; text-align: justify; color: #334155;">
  Le présent contrat scelle l'adhésion au programme officiel de <strong>Caution Solidaire</strong> de GEP Entreprendre. 
  Conformément aux statuts, le cycle contractuel s'étend sur une durée fixe de <strong>12 mois consécutifs</strong>, 
  débutant le jour du premier versement de l'entité souscriptrice.
</p>

<!-- SECTION 1 : ENTITE SOUSCTRIPTRICE -->
<div class="section-header">
  1. Identification de l'Entité Souscriptrice
  @if($entity_type === 'organisation')
    (Organisation Partenaire)
  @elseif($entity_type === 'branch')
    (Succursale GEP)
  @else
    (Entrepreneur Individuel)
  @endif
</div>

<table class="info-table">
  <tr>
    <td class="label-cell">Nom / Dénomination :</td>
    <td class="value-cell"><strong>{{ $entity_name }}</strong></td>
    <td class="label-cell">Code Enregistrement :</td>
    <td class="value-cell"><span class="highlight-badge">{{ $entity_code }}</span></td>
  </tr>
  <tr>
    <td class="label-cell">Téléphone de contact :</td>
    <td class="value-cell">{{ $entity_phone }}</td>
    <td class="label-cell">Adresse / Localisation :</td>
    <td class="value-cell">{{ $entity_address }}</td>
  </tr>
  <tr>
    <td class="label-cell">Succursale de rattachement :</td>
    <td class="value-cell"><strong>{{ $branch_name }}</strong></td>
    <td class="label-cell">
      @if($entity_type === 'individual')
        Sensibilisateur(trice) :
      @else
        Représentant(e) / Gestionnaire :
      @endif
    </td>
    <td class="value-cell">{{ $manager_sensibilisateur }}</td>
  </tr>
</table>

<!-- SECTION 2 : CALENDRIER DU CYCLE ET DUREE -->
<div class="section-header">2. Durée du Cycle & Engagement Financier (12 Mois)</div>

<table class="info-table">
  <tr>
    <td class="label-cell">Date de Début du Cycle :</td>
    <td class="value-cell"><span class="date-badge">{{ $date_debut }} (1er versement)</span></td>
    <td class="label-cell">Date d'Échéance / Fin du Cycle :</td>
    <td class="value-cell"><span class="date-badge">{{ $date_fin }} (Clôture à 12 mois)</span></td>
  </tr>
  <tr>
    <td class="label-cell">Versement Mensuel Fixé :</td>
    <td class="value-cell"><strong>${{ number_format($montant, 2) }} / mois</strong></td>
    <td class="label-cell">Caution Totale Engagée (12 mois) :</td>
    <td class="value-cell"><strong style="color: #0d47a1; font-size: 10.5px;">${{ number_format($total_prevu, 2) }}</strong></td>
  </tr>
  <tr>
    <td class="label-cell">Jour d'échéance mensuel :</td>
    <td class="value-cell">Au plus tard le <strong>{{ $delai_jour_max }} de chaque mois</strong></td>
    <td class="label-cell">Période de grâce autorisée :</td>
    <td class="value-cell"><strong>{{ $jours_retard_grace }} jours</strong> calendaires</td>
  </tr>
</table>

<!-- TABLEAU RECAPITULATIF DES 12 VERSEMENTS -->
<table class="schedule-table">
  <thead>
    <tr>
      @for ($m = 1; $m <= 12; $m++)
        <th>Mois {{ $m }}</th>
      @endfor
    </tr>
  </thead>
  <tbody>
    <tr>
      @for ($m = 1; $m <= 12; $m++)
        <td>${{ number_format($montant, 0) }}</td>
      @endfor
    </tr>
  </tbody>
</table>

<!-- SECTION 3 : REGLES FINANCIERES, PENALITES & AVANTAGES -->
<div class="section-header">3. Règles Financières, Pénalités & Avantages Contractuels</div>

<div class="rules-box">
  <div class="article-item">
    <span class="article-title">Article 1 — Calendrier & Délais de Grâce :</span>
    Le souscripteur s'engage à effectuer chaque versement mensuel de ${{ number_format($montant, 2) }} au plus tard le
    <strong>{{ $delai_jour_max }} de chaque mois</strong>. Une tolérance stricte de <strong>{{ $jours_retard_grace }} jours de grâce</strong>
    est accordée. Tout versement au-delà de cette marge est classé comme <em>versement en retard</em>.
  </div>

  <div class="article-item">
    <span class="article-title">Article 2 — Pénalités & Application du Malus de Retard :</span>
    En cas de versement tardif au-delà des {{ $jours_retard_grace }} jours de grâce, une pénalité contractuelle de
    <strong>{{ number_format($penalite_retard_pct, 1) }}%</strong> est immédiatement calculée et déduite sous forme de
    <em>Malus de retard</em>. Un retard répété active un <strong>signalement d'alerte (Point Rouge)</strong> dans le système central,
    dégradant l'indice de crédibilité et bloquant toute demande de crédit.
  </div>

  <div class="article-item">
    <span class="article-title">Article 3 — Rendement, Bonus de Régularité & 13ème Mois :</span>
    Chaque cotisation effectuée dans les délais ouvre droit à un <strong>Bonus de Rendement de {{ number_format($interet_taux_annuel, 2) }}%</strong>.
    @if($bonus_13eme_actif)
      De surcroît, le respect rigoureux et sans aucun retard des 12 versements du cycle confère au souscripteur le
      <strong>Bonus de Fidélité du 13ème Mois</strong>, correspondant à l'équivalent d'une mensualité entière offerte par GEP Entreprendre.
    @endif
  </div>

  <div class="article-item">
    <span class="article-title">Article 4 — Effet de Levier & Accès au Crédit Solidaire :</span>
    La caution solidaire déposée confère un droit d'emprunt préférentiel. Selon le niveau d'étoiles et de conformité obtenu,
    le souscripteur peut solliciter un microcrédit solidaire allant jusqu'à <strong>{{ number_format($pret_pourcentage_max, 0) }}%</strong>
    du montant total de sa caution accumulée.
  </div>

  <div class="article-item">
    <span class="article-title">Article 5 — Clôture du Cycle & Restitution :</span>
    À la date finale du <strong>{{ $date_fin }}</strong>, le cycle arrive à terme. Un état financier récapitulatif est édité :
    la caution totale nette ainsi que les bonus cumulés peuvent être reconduits pour un nouveau cycle de 12 mois ou restitués
    selon la demande du souscripteur.
  </div>
</div>

<!-- SECTION 4 : SIGNATURES ET ENGAGEMENT -->
<table class="signatures-table">
  <tr>
    <td class="sig-col">
      <div class="sig-title">Pour le Souscripteur / L'Adhérent(e)</div>
      <div class="sig-line"></div>
      <div class="sig-sub">Nom : <strong>{{ $entity_name }}</strong><br>(Précédé de la mention manuscrite « Lu et approuvé »)</div>
    </td>
    <td class="sig-col">
      <div class="sig-title">Pour GEP Entreprendre (Direction / Admin)</div>
      <div class="sig-line"></div>
      <div class="sig-sub">Visa & Sceau de l'Organisation<br>Fait à {{ $branch_name }}, le {{ $date_debut }}</div>
    </td>
  </tr>
</table>

</body>
</html>
