<?php

namespace App\Services;

use App\Models\Credit;
use App\Models\CreditGuarantor;
use App\Models\CreditSchedule;
use App\Models\Deposit;
use App\Models\Individual;
use App\Models\Malus;

class MalusService
{
    /**
     * 1. MALUS SUR RETARD DE COTISATION
     */
    public static function applyLateDepositMalus(Deposit $deposit, ?int $operatorId = null): Malus
    {
        $penaltyPct = (float) FinancialRuleService::get('cotisation_penalite_retard_pct', 5.0);
        $penaltyAmount = round($deposit->expected_amount * ($penaltyPct / 100.0), 2);

        $malus = Malus::create([
            'user_id'              => $deposit->user_id,
            'branch_id'            => $deposit->branch_id,
            'deposit_id'           => $deposit->id,
            'malus_type'           => 'retard_cotisation',
            'penalty_amount'       => $penaltyAmount,
            'trust_score_deduction'=> 2.0,
            'reason'               => "Retard de dépôt sur le cycle mois #{$deposit->cycle_month} ({$deposit->delay_days} jours de retard)",
            'is_active'            => true,
            'applied_by_operator_id' => $operatorId,
        ]);

        $individual = Individual::where('user_id', $deposit->user_id)->first();
        if ($individual) {
            $individual->recalculateStarsAndRedDot();
        }

        return $malus;
    }

    /**
     * 2. MALUS RETARD DE PAIEMENT EMPRUNT & MALUS SUR PARRAINAGE DYNAMIQUE
     * Quand l'emprunteur est en retard, on applique le malus au débiteur, ET on affiche
     * aux parrains le malus au prorata de leur pourcentage parrainé sur la dette en retard !
     */
    public static function applyLateCreditScheduleMalus(CreditSchedule $schedule, ?int $operatorId = null): array
    {
        $credit = $schedule->credit;
        $overdueBalance = (float) ($schedule->total_due - $schedule->amount_paid);

        $penaltyRate = (float) FinancialRuleService::get('pret_penalite_retard_jour_pct', 1.0);
        $penaltyAmount = round($overdueBalance * ($penaltyRate / 100.0), 2);

        $schedule->status = 'overdue';
        $schedule->penalty_due += $penaltyAmount;
        $schedule->save();

        $credit->has_red_dot = true;
        $credit->save();

        $createdMaluses = [];

        // 1. Malus direct sur l'emprunteur
        $createdMaluses[] = Malus::create([
            'user_id'              => $credit->user_id,
            'branch_id'            => $credit->branch_id,
            'credit_id'            => $credit->id,
            'malus_type'           => 'retard_emprunt',
            'penalty_amount'       => $penaltyAmount,
            'trust_score_deduction'=> 3.0,
            'reason'               => "Échéance #{$schedule->installment_number} impayée (Total dû: \${$overdueBalance})",
            'is_active'            => true,
            'applied_by_operator_id' => $operatorId,
        ]);

        // 2. MALUS SUR PARRAINAGE : répercussion proportionnelle sur chaque parrain
        $guarantors = CreditGuarantor::where('credit_id', $credit->id)->get();
        foreach ($guarantors as $guarantor) {
            $pct = (float) $guarantor->guaranteed_percentage;
            if ($pct > 0) {
                $guarantorDebtShare = round($overdueBalance * ($pct / 100.0), 2);
                $guarantor->active_malus_amount = $guarantorDebtShare;
                $guarantor->save();

                if ($guarantor->guarantor_user_id) {
                    $createdMaluses[] = Malus::create([
                        'user_id'              => $guarantor->guarantor_user_id,
                        'branch_id'            => $credit->branch_id,
                        'credit_id'            => $credit->id,
                        'malus_type'           => 'parrainage_impaye',
                        'penalty_amount'       => 0.00,
                        'guaranteed_debt_share'=> $guarantorDebtShare,
                        'trust_score_deduction'=> 1.5,
                        'reason'               => "Cautionnement : dette en retard de l'emprunteur {$credit->user->name} ({$pct}% garanti soit \${$guarantorDebtShare})",
                        'is_active'            => true,
                        'applied_by_operator_id' => $operatorId,
                    ]);

                    $gIndividual = Individual::where('user_id', $guarantor->guarantor_user_id)->first();
                    if ($gIndividual) {
                        $gIndividual->recalculateStarsAndRedDot();
                    }
                }
            }
        }

        // Recalculer le débiteur
        $borrowerIndividual = Individual::where('user_id', $credit->user_id)->first();
        if ($borrowerIndividual) {
            $borrowerIndividual->recalculateStarsAndRedDot();
        }

        return $createdMaluses;
    }

    /**
     * 3. MALUS PARRAIN LORS DE PRÉLÈVEMENT SUR COMPTE ORDINAIRE NÉGATIF
     * Si l'emprunteur paye son échéance mais que son compte ordinaire est négatif (en découvert),
     * les parrains reçoivent un malus proportionnel à leur part de parrainage.
     */
    public static function applyGuarantorOverdraftRepaymentMalus(Credit $credit, CreditSchedule $schedule, float $amountDeducted): array
    {
        $createdMaluses = [];
        $guarantors = CreditGuarantor::where('credit_id', $credit->id)
            ->where('approval_status', 'approved')
            ->where('guarantor_role', '!=', 'centre_gep')
            ->get();

        foreach ($guarantors as $guarantor) {
            $pct = (float) $guarantor->guaranteed_percentage;
            if ($pct <= 0 || !$guarantor->guarantor_user_id) continue;

            $guarantorShare = round($amountDeducted * ($pct / 100.0), 2);
            $guarantor->active_malus_amount = ($guarantor->active_malus_amount ?? 0) + $guarantorShare;
            $guarantor->save();

            $malus = Malus::create([
                'user_id'               => $guarantor->guarantor_user_id,
                'branch_id'             => $credit->branch_id,
                'credit_id'             => $credit->id,
                'malus_type'            => 'parrainage_compte_negatif',
                'penalty_amount'        => $guarantorShare,
                'guaranteed_debt_share' => $guarantorShare,
                'trust_score_deduction' => 1.0,
                'reason'                => "Prélèvement d'échéance #{$schedule->installment_number} sur compte ordinaire en découvert de l'emprunteur ({$pct}% garanti soit \${$guarantorShare})",
                'is_active'             => true,
            ]);

            // Déduction directe sur le compte bonus annuel du parrain
            try {
                app(\App\Services\AnnualBonusService::class)->accrueMalus(
                    $guarantor->guarantor_user_id,
                    $guarantorShare,
                    "Malus parrainage sur découvert emprunteur - Échéance #{$schedule->installment_number}"
                );
            } catch (\Exception $e) {
                // Silencieux si table non disponible
            }

            $gInd = Individual::where('user_id', $guarantor->guarantor_user_id)->first();
            if ($gInd) {
                $gInd->recalculateStarsAndRedDot();
            }

            $createdMaluses[] = $malus;
        }

        return $createdMaluses;
    }

    /**
     * Résolution des malus après paiement
     */
    public static function resolveCreditScheduleMalus(CreditSchedule $schedule): void
    {
        $credit = $schedule->credit;

        Malus::where('credit_id', $credit->id)
            ->where('malus_type', 'retard_emprunt')
            ->update(['is_active' => false]);

        Malus::where('credit_id', $credit->id)
            ->where('malus_type', 'parrainage_impaye')
            ->update(['is_active' => false]);

        CreditGuarantor::where('credit_id', $credit->id)
            ->update(['active_malus_amount' => 0.00]);

        $borrowerIndividual = Individual::where('user_id', $credit->user_id)->first();
        if ($borrowerIndividual) {
            $borrowerIndividual->recalculateStarsAndRedDot();
        }

        $guarantors = CreditGuarantor::where('credit_id', $credit->id)->get();
        foreach ($guarantors as $guarantor) {
            if ($guarantor->guarantor_user_id) {
                $gInd = Individual::where('user_id', $guarantor->guarantor_user_id)->first();
                if ($gInd) {
                    $gInd->recalculateStarsAndRedDot();
                }
            }
        }
    }
}
