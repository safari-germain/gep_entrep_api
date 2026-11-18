<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FinancialRuleController;
use App\Http\Controllers\IndividualController;
use App\Http\Controllers\SensibilisateurController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\CycleController;
use App\Http\Controllers\CotisationSolidaireController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\BonusMalusController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\OrganisationController;
use App\Http\Controllers\SystemLedgerController;
use App\Http\Controllers\CashReceiptController;
use App\Http\Controllers\OrdinaryAccountController;
use App\Http\Controllers\FinancialValidationController;
use App\Http\Controllers\ConsignationController;
use App\Http\Controllers\OhadaReportController;
use App\Http\Controllers\CreditCoverageController;
use App\Http\Controllers\AnnualBonusController;

// ==================== PUBLIC ROUTES ====================
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::get('/health', function () { return response()->json(['status' => 'ok', 'app' => 'GEP Entreprendre API']); });

// Routes publiques nécessaires pour l'écran d'inscription mobile (avant connexion)
Route::get('/branches', [BranchController::class, 'index']);
Route::get('/organisations', [OrganisationController::class, 'index']);
Route::get('/sensibilisateurs/by-code', [SensibilisateurController::class, 'findByCode']);

// ==================== PROTECTED ROUTES ====================
Route::middleware(['auth:sanctum'])->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/user/affiliate', [AuthController::class, 'affiliate']);

    // ---- CONFIGURATION DYNAMIQUE (Admin) ----
    Route::get('/admin/configurations', [FinancialRuleController::class, 'index']);
    Route::post('/admin/configurations/batch', [FinancialRuleController::class, 'batchUpdate']);

    // ---- SUCCURSALES / BRANCHES ----
    Route::post('/branches', [BranchController::class, 'store']);
    Route::get('/branches/{id}', [BranchController::class, 'show']);

    // ---- ORGANISATIONS ----
    Route::post('/organisations', [OrganisationController::class, 'store']);
    Route::get('/organisations/{id}', [OrganisationController::class, 'show']);
    Route::post('/organisations/{id}/members', [OrganisationController::class, 'addMember']);
    Route::delete('/organisations/{id}/members/{userId}', [OrganisationController::class, 'removeMember']);

    // ---- SENSIBILISATEURS ----
    Route::get('/sensibilisateurs', [SensibilisateurController::class, 'index']);
    Route::get('/sensibilisateurs/options', [SensibilisateurController::class, 'options']); // Mobile: selection du sensibilisateur
    Route::post('/sensibilisateurs/select', [SensibilisateurController::class, 'selectSensibilisateur']); // Mobile: choisir son sensibilisateur
    Route::post('/sensibilisateurs/{id}/release-year-end', [SensibilisateurController::class, 'releaseYearEnd']); // Cloture annuelle
    Route::post('/sensibilisateurs/assign-marketer', [SensibilisateurController::class, 'assignMarketer']); // Admin: nommer animateur
    Route::get('/sensibilisateurs/marketer-space', [SensibilisateurController::class, 'marketerSpace']); // Mobile: espace animateur / marketeur

    // ---- MEMBRES / INDIVIDUALS ----
    Route::get('/individuals', [IndividualController::class, 'index']);
    Route::post('/individuals', [IndividualController::class, 'store']);
    Route::get('/individuals/{id}', [IndividualController::class, 'show']);
    Route::post('/individuals/{userId}/heirs', [IndividualController::class, 'addHeir']);

    // ---- COTISATIONS / DEPOTS ----
    Route::get('/deposits', [DepositController::class, 'index']);
    Route::post('/deposits', [DepositController::class, 'store']);

    // ---- PRETS / CREDITS ----
    Route::get('/credits', [CreditController::class, 'index']);
    Route::post('/credits', [CreditController::class, 'store']);
    Route::post('/credits/apply', [CreditController::class, 'apply']); // Créer demande (solidaire, express, special)
    Route::get('/credits/pending-approval', [CreditController::class, 'pendingApproval']); // Dossiers en attente financier
    Route::get('/credits/published', [CreditController::class, 'published']); // Demandes publiées / parrainage campagne
    Route::get('/credits/admin-overview', [CreditController::class, 'adminOverview']); // Vue admin: crédits classifiés
    Route::get('/credits/capacity/{userId}', [CreditController::class, 'capacity']); // Capacite emprunt = 500% caution
    Route::get('/credits/{id}/guarantor-summary', [CreditController::class, 'guarantorSummary']); // Résumé parrains
    Route::post('/credits/{id}/guarantors', [CreditController::class, 'addGuarantor']); // Ajouter parrain
    Route::post('/credits/{id}/guarantors/{gid}/approve', [CreditController::class, 'approveGuarantor']); // Parrain approuve
    Route::post('/credits/{id}/guarantors/{gid}/reject', [CreditController::class, 'rejectGuarantor']); // Parrain refuse
    Route::post('/credits/{id}/submit-to-financier', [CreditController::class, 'submitToFinancier']); // Soumettre financier
    Route::post('/credits/{id}/financier-approve', [CreditController::class, 'financierApprove']); // Approbation financier
    Route::post('/credits/{id}/financier-reject', [CreditController::class, 'financierReject']); // Rejet financier
    Route::post('/credits/{id}/disburse', [CreditController::class, 'disburse']); // Décaissement effectif
    Route::post('/credits/{id}/waive-penalties', [CreditController::class, 'waivePenalties']); // Pardonner pénalités/amendes
    Route::post('/credits/{id}/repay', [CreditController::class, 'repay']); // Remboursement echeance
    Route::post('/credits/{id}/payment', [CreditController::class, 'repay']); // Alias mobile pour remboursement
    Route::post('/credits/{id}/assign-collector', [CreditController::class, 'assignCollector']); // Assigner agent recouvrement
    Route::post('/credits/{id}/recover-penalty', [CreditController::class, 'recoverPenalty']); // Repartition 20/30/50%


    // ---- BONUS & MALUS ----
    Route::get('/bonuses', [BonusMalusController::class, 'bonuses']);
    Route::get('/maluses', [BonusMalusController::class, 'maluses']);
    Route::get('/mobile/bonus-malus-history', [BonusMalusController::class, 'history']); // Mobile 4 bonus + 3 malus

    // ---- RAPPORTS & DASHBOARD MOBILE ----
    // period=daily|weekly|monthly|semi_annual|annual
    Route::get('/reports/dashboard', [ReportController::class, 'dashboard']);
    Route::get('/reports/member-dashboard', [\App\Http\Controllers\MobileDashboardController::class, 'getMemberDashboard']);
    Route::get('/mobile/dashboard', [\App\Http\Controllers\MobileDashboardController::class, 'getMemberDashboard']);

    // ---- CYCLES (Gestion du cycle annuel 12 mois) ----
    Route::get('/cycles/current', [CycleController::class, 'currentCycle']); // AVANT {id} !
    Route::get('/cycles/check-entity-eligibility', [CycleController::class, 'checkEntityEligibility']);
    Route::delete('/cycles/clear-all', [CycleController::class, 'clearAllCycles']);
    Route::get('/cycles/tracking', [CycleController::class, 'tracking']); // Suivi multi-entités
    Route::get('/cycles', [CycleController::class, 'index']);
    Route::post('/cycles', [CycleController::class, 'store']); // Ouvrir un nouveau cycle
    Route::post('/cycles/{id}/close', [CycleController::class, 'close']); // Fermer le cycle
    Route::post('/cycles/{id}/block', [CycleController::class, 'blockCycle']); // Bloquer cycle et compte ordinaire
    Route::post('/cycles/{id}/unblock', [CycleController::class, 'unblockCycle']); // Débloquer cycle
    Route::post('/cycles/{id}/disburse-to-ordinary-account', [CycleController::class, 'disburseToOrdinaryAccount']); // Clôture et versement net
    Route::get('/cycles/{id}/stats', [CycleController::class, 'stats']); // Statistiques du cycle
    Route::get('/cycles/{id}/settlement-preview', [CycleController::class, 'settlementPreview']); // Prévisualisation liquidation
    Route::post('/cycles/{id}/settle-and-rollover', [CycleController::class, 'settleAndRollover']); // Solder et prolonger/rollover

    // ---- COTISATION SOLIDAIRE ----
    Route::get('/cotisation-solidaire/active-cycles', [CotisationSolidaireController::class, 'activeCycles']);
    Route::get('/cotisation-solidaire/pdf-report', [CotisationSolidaireController::class, 'pdfReport']);
    Route::get('/cotisation-solidaire/overview', [CotisationSolidaireController::class, 'overview']);
    Route::get('/cotisation-solidaire/report/{cycleId}', [CotisationSolidaireController::class, 'report']);
    Route::get('/cotisation-solidaire/contract/{userId?}', [CotisationSolidaireController::class, 'generateContract']); // PDF base64

    // ---- GRAND LIVRE DE CAISSE (SYSTEM LEDGER) ----
    Route::get('/system-ledger/summary', [SystemLedgerController::class, 'summary']);
    Route::get('/system-ledger/entries', [SystemLedgerController::class, 'entries']);
    Route::post('/system-ledger/entries', [SystemLedgerController::class, 'store']);
    Route::post('/system-ledger/entries/{id}/validate', [SystemLedgerController::class, 'validateEntry']);
    Route::post('/system-ledger/entries/{id}/reject', [SystemLedgerController::class, 'rejectEntry']);
    Route::get('/system-ledger/title-suggestions', [SystemLedgerController::class, 'titleSuggestions']);
    Route::get('/system-ledger/ohada-accounts', [SystemLedgerController::class, 'ohadaAccounts']);

    // ---- CAISSE & REÇUS (CASH RECEIPTS) ----
    Route::get('/cash-receipts', [CashReceiptController::class, 'index']);
    Route::post('/cash-receipts', [CashReceiptController::class, 'store']);
    Route::get('/cash-receipts/verify/{receiptNumber}', [CashReceiptController::class, 'verify']);

    // ---- COMPTE ORDINAIRE (PICKUP / TOUTES TRANSACTIONS) ----
    Route::get('/ordinary-accounts', [OrdinaryAccountController::class, 'index']);
    Route::get('/ordinary-accounts/me', [OrdinaryAccountController::class, 'me']);
    Route::get('/ordinary-accounts/{accountId}/transactions', [OrdinaryAccountController::class, 'transactions'])->whereNumber('accountId');
    Route::get('/ordinary-accounts/{type}/{entityId}', [OrdinaryAccountController::class, 'showByEntity'])->whereNumber('entityId');
    Route::post('/ordinary-accounts/deposit', [OrdinaryAccountController::class, 'deposit']);
    Route::post('/ordinary-accounts/withdraw', [OrdinaryAccountController::class, 'withdraw']);
    Route::post('/ordinary-accounts/{id}/toggle-block', [OrdinaryAccountController::class, 'toggleBlock']);
    Route::post('/ordinary-accounts/{id}/admin-withdraw', [OrdinaryAccountController::class, 'adminWithdraw']);
    Route::post('/ordinary-accounts/request-withdrawal', [OrdinaryAccountController::class, 'requestWithdrawal']);
    Route::post('/ordinary-accounts/request-deposit', [OrdinaryAccountController::class, 'requestDeposit']);
    Route::post('/ordinary-accounts/deduct-cycle-caution', [OrdinaryAccountController::class, 'deductCaution']);
    Route::post('/ordinary-accounts/process-overdraft-interests', [OrdinaryAccountController::class, 'applyOverdraftInterests']);
    Route::post('/ordinary-accounts/process-credit-repayments', [OrdinaryAccountController::class, 'processCreditRepayments']);

    // ---- GESTION DE CONSIGNATION (Cautions consignées) ----
    Route::get('/consignations', [ConsignationController::class, 'index']);
    Route::post('/consignations', [ConsignationController::class, 'store']);
    Route::post('/consignations/{id}/release', [ConsignationController::class, 'release']);
    Route::post('/consignations/{id}/rollover', [ConsignationController::class, 'rollover']);

    // ---- VALIDATIONS FINANCIÈRES & LIQUIDATION DE CYCLE ----
    Route::get('/financial-validations', [FinancialValidationController::class, 'index']);
    Route::post('/financial-validations/{id}/approve', [FinancialValidationController::class, 'approve']);
    Route::post('/financial-validations/{id}/reject', [FinancialValidationController::class, 'reject']);
    Route::post('/cycles/{cycleId}/liquidate', [FinancialValidationController::class, 'liquidateCycle']);

    // ---- RAPPORTS COMPTABLES OHADA (SYSCOHADA RÉVISÉ) ----
    Route::get('/reports/ohada/bilan', [OhadaReportController::class, 'bilan']);
    Route::get('/reports/ohada/compte-exploitation', [OhadaReportController::class, 'compteExploitation']);
    Route::get('/reports/ohada/export-html', [OhadaReportController::class, 'exportHtml']);
    Route::get('/reports/ohada/dashboard', [OhadaReportController::class, 'dashboard']);

    // ---- COMPTE DE COUVERTURE DES PARRAINS ----
    Route::get('/coverage/my-account', [CreditCoverageController::class, 'myAccount']);
    Route::get('/coverage/users/{userId}', [CreditCoverageController::class, 'userAccount']);
    Route::post('/coverage/calls/{callId}/settle', [CreditCoverageController::class, 'settle']);
    Route::get('/coverage/admin/pending', [CreditCoverageController::class, 'adminPending']);
    Route::post('/coverage/trigger-check', [CreditCoverageController::class, 'triggerCheck']);

    // ---- COMPTE BONUS ANNUEL ----
    Route::get('/annual-bonus/my-account', [AnnualBonusController::class, 'myAccount']);
    Route::get('/annual-bonus/users/{userId}', [AnnualBonusController::class, 'userAccount']);
    Route::get('/annual-bonus/admin/all', [AnnualBonusController::class, 'adminAll']);
    Route::post('/annual-bonus/disburse', [AnnualBonusController::class, 'disburse']);
});