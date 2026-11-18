<?php

namespace App\Console\Commands;

use App\Services\OrdinaryAccountService;
use App\Models\Cycle;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessDailyOrdinaryAccountJobs extends Command
{
    /**
     * Commande Artisan pour traiter les tâches automatiques quotidiennes :
     *  - Intérêts débiteurs sur les comptes ordinaires en négatif
     *  - Remboursements automatiques des échéances de crédit échus
     *  - Prélèvement mensuel automatique de la caution solidaire (si date anniversaire)
     */
    protected $signature   = 'compte-ordinaire:process-daily {--dry-run : Simulation sans modifier la base}';
    protected $description = 'Traitement automatique quotidien : intérêts débiteurs, remboursements crédit et cotisations caution';

    public function handle(OrdinaryAccountService $service): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('========================================');
        $this->info('  GEP - Traitement Quotidien Compte Ordinaire');
        $this->info('  Date : ' . now()->format('d/m/Y H:i:s'));
        $this->info('========================================');

        // ── 1. Intérêts débiteurs sur soldes négatifs ────────────────────────
        $this->info('');
        $this->info('▶ [1/3] Application des intérêts débiteurs sur comptes en découvert...');

        if (!$dryRun) {
            try {
                $result = $service->applyOverdraftDebitInterests();
                $count  = count($result['charged'] ?? []);
                $this->info("  ✅ {$count} compte(s) débité(s) — délai grâce: {$result['grace_days']}j, taux: {$result['rate_pct']}%");
                if ($count > 0 && $this->getOutput()->isVerbose()) {
                    foreach ($result['charged'] as $row) {
                        $this->line("     Compte #{$row['account_id']} — intérêt: {$row['interest_charged']}$, nouveau solde: {$row['new_balance']}$");
                    }
                }
                Log::info('[compte-ordinaire:process-daily] Intérêts débiteurs', $result);
            } catch (\Throwable $e) {
                $this->error("  ❌ Erreur intérêts débiteurs: " . $e->getMessage());
                Log::error('[compte-ordinaire:process-daily] Erreur intérêts débiteurs: ' . $e->getMessage());
            }
        } else {
            $this->warn('  [DRY-RUN] Ignoré.');
        }

        // ── 2. Remboursements automatiques des échéances crédit ──────────────
        $this->info('');
        $this->info('▶ [2/3] Traitement des échéances de crédit arrivées à terme...');

        if (!$dryRun) {
            try {
                // Application préalable des pénalités sur les échéances en retard
                $penalties = \App\Services\CreditPenaltyService::processAllOverduePenalties();
                $penCount = count($penalties);
                if ($penCount > 0) {
                    $this->info("  ⚡ {$penCount} pénalité(s) de retard appliquée(s) selon délai de grâce et taux dynamique.");
                }

                $result = $service->processCreditRepayments();
                $count  = count($result['processed'] ?? []);
                $this->info("  ✅ {$count} échéance(s) traitée(s)");
                if ($count > 0 && $this->getOutput()->isVerbose()) {
                    foreach ($result['processed'] as $row) {
                        $this->line("     Crédit #{$row['credit_id']} échéance #{$row['installment_no']} — montant: {$row['amount_paid']}$, pénalité: {$row['penalty']}$");
                    }
                }
                Log::info('[compte-ordinaire:process-daily] Remboursements crédit', $result);
            } catch (\Throwable $e) {
                $this->error("  ❌ Erreur remboursements crédit: " . $e->getMessage());
                Log::error('[compte-ordinaire:process-daily] Erreur remboursements crédit: ' . $e->getMessage());
            }
        } else {
            $this->warn('  [DRY-RUN] Ignoré.');
        }

        // ── 3. Prélèvement mensuel de la caution solidaire ───────────────────
        // Ne s'exécute que pour les cycles dont c'est l'anniversaire mensuel aujourd'hui
        $this->info('');
        $this->info('▶ [3/3] Prélèvement mensuel automatique de la caution solidaire...');

        $today = Carbon::today();

        try {
            $cycles = Cycle::where('status', 'active')->get();
            $totalDeducted = 0;

            foreach ($cycles as $cycle) {
                // Déterminer si le prélèvement est dû aujourd'hui
                $autoDay = (int) ($cycle->auto_deposit_day ?: Carbon::parse($cycle->start_date)->day);
                $nextDate = $cycle->auto_deposit_next_date ? Carbon::parse($cycle->auto_deposit_next_date)->startOfDay() : null;

                // Est dû si la date d'échéance programmée est atteinte/dépassée OU si c'est le jour calendaire
                $isDue = ($nextDate && $nextDate->lessThanOrEqualTo($today)) || ($autoDay === $today->day);

                if ($isDue) {
                    $this->line("  🔄 Cycle #{$cycle->id} '{$cycle->name}' — échéance de prélèvement (jour {$autoDay})");

                    if (!$dryRun) {
                        try {
                            $result = $service->deductCycleCaution($cycle->id);
                            $count  = count($result['processed'] ?? []);
                            $this->info("     ✅ {$count} prélèvement(s) de caution effectué(s) pour ce cycle.");
                            $totalDeducted += $count;
                            Log::info("[compte-ordinaire:process-daily] Caution solidaire Cycle #{$cycle->id}", $result);
                        } catch (\Throwable $e) {
                            $this->error("     ❌ Erreur cycle #{$cycle->id}: " . $e->getMessage());
                            Log::error("[compte-ordinaire:process-daily] Erreur caution cycle #{$cycle->id}: " . $e->getMessage());
                        }
                    } else {
                        $this->warn("     [DRY-RUN] Prélèvement ignoré.");
                    }
                }
            }

            if ($totalDeducted === 0 && !$dryRun) {
                $this->line("  ℹ️  Aucun cycle avec anniversaire mensuel aujourd'hui (jour {$today->day}).");
            }
        } catch (\Throwable $e) {
            $this->error("  ❌ Erreur prélèvement caution: " . $e->getMessage());
            Log::error('[compte-ordinaire:process-daily] Erreur prélèvement caution: ' . $e->getMessage());
        }

        // ── 4. Bonus quotidien sur compte ordinaire positif (proratisé sur 365 jours) ──
        $this->info('');
        $this->info('▶ [4/5] Attribution du bonus quotidien sur soldes positifs (prorata 365 jours)...');

        if (!$dryRun) {
            try {
                $bonusRes = $service->applyDailyPositiveBalanceBonus();
                if ($bonusRes['status']) {
                    $this->info("  ✅ {$bonusRes['count']} compte(s) rémunéré(s) (taux annuel: {$bonusRes['annual_rate_pct']}%, taux/jour: {$bonusRes['daily_rate_pct']}%, total: {$bonusRes['total_bonus_awarded']}$)");
                } else {
                    $this->line("  ℹ️  {$bonusRes['message']}");
                }
            } catch (\Throwable $e) {
                $this->error("  ❌ Erreur bonus quotidien: " . $e->getMessage());
                Log::error('[compte-ordinaire:process-daily] Erreur bonus: ' . $e->getMessage());
            }
        } else {
            $this->warn('  [DRY-RUN] Bonus ignoré.');
        }

        // ── 5. Notifications sur comptes ordinaires négatifs (Matin / Soir) ──
        $this->info('');
        $this->info('▶ [5/5] Notifications alertes découvert sur comptes ordinaires négatifs...');

        if (!$dryRun) {
            try {
                $currentTiming = now()->hour < 14 ? 'matin' : 'soir';
                $notifRes = $service->sendOverdraftNotifications($currentTiming);
                $this->info("  🔔 {$notifRes['notified']} notification(s) envoyée(s) [créneau: {$currentTiming}].");
            } catch (\Throwable $e) {
                $this->error("  ❌ Erreur notifications découvert: " . $e->getMessage());
                Log::error('[compte-ordinaire:process-daily] Erreur notifications: ' . $e->getMessage());
            }
        }

        $this->info('');
        $this->info('========================================');
        $this->info('  Traitement terminé avec succès.');
        $this->info('========================================');

        return Command::SUCCESS;
    }
}
