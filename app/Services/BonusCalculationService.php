<?php

namespace App\Services;

use App\Models\Bonus;
use App\Models\Credit;
use App\Models\CreditGuarantor;
use App\Models\Deposit;
use App\Models\Individual;
use App\Models\Organisation;
use App\Models\Sensibilisateur;
use App\Models\User;

class BonusCalculationService
{
    /**
     * 1. BONUS MOBILISATION
     * Au 2ème versement de caution solidaire, le sensibilisateur reçoit 50% de la quote-part de caution.
     * Les autres 50% sont mis en réserve pour la clôture annuelle.
     */
    public static function processMobilisationBonus(Deposit $deposit): ?Bonus
    {
        $individual = Individual::where('user_id', $deposit->user_id)->first();
        if (!$individual || !$individual->sensibilisateur_id) {
            return null;
        }

        $sensibilisateur = Sensibilisateur::find($individual->sensibilisateur_id);
        if (!$sensibilisateur) {
            return null;
        }

        // Compter le nombre de versements effectués par ce membre
        $depositCount = Deposit::where('user_id', $deposit->user_id)->count();

        // Déclenché au 2ème versement
        if ($depositCount === 2) {
            $baseRate = (float) FinancialRuleService::get('sensibilisateur_base_bonus_pct', 50.0);
            $tranche1Rate = (float) FinancialRuleService::get('sensibilisateur_bonus_versement_2_pct', 50.0);

            // Montant total du bonus prévu = caution * baseRate%
            $totalExpectedBonus = $deposit->actual_amount * ($baseRate / 100.0);
            // Tranche 1 = totalExpectedBonus * tranche1Rate%
            $tranche1Amount = round($totalExpectedBonus * ($tranche1Rate / 100.0), 2);
            $tranche2Pending = round($totalExpectedBonus - $tranche1Amount, 2);

            // Créditer le sensibilisateur
            $sensibilisateur->total_bonus_earned += $tranche1Amount;
            $sensibilisateur->bonus_balance += $tranche1Amount;
            $sensibilisateur->bonus_pending_year_end += $tranche2Pending;
            $sensibilisateur->save();

            // Créer l'entrée de bonus
            return Bonus::create([
                'user_id'              => $sensibilisateur->user_id,
                'branch_id'            => $sensibilisateur->branch_id ?? $deposit->branch_id,
                'sensibilisateur_id'   => $sensibilisateur->id,
                'organisation_id'      => $sensibilisateur->organisation_id,
                'bonus_type'           => 'mobilisation',
                'amount'               => $tranche1Amount,
                'tranche'              => 'versement_2',
                'description'          => "Commission Mobilisation (Tranche 1 au 2ème versement) pour recrutement de l'entrepreneur #{$deposit->user_id}",
                'granted_by_operator_id' => $deposit->operator_id,
            ]);
        }

        return null;
    }

    /**
     * 2. BONUS COTISATION (INTÉRÊT / RENDEMENT SUR CAUTION)
     * Même en cas de retard sur le versement, le membre cumule son bonus/intérêt de cotisation (ex: 8.33%).
     * La pénalité (malus) est appliquée séparément, permettant d'avoir simultanément malus ET bonus.
     */
    public static function processDepositInterestBonus(Deposit $deposit): ?Bonus
    {
        $interestPct = (float) FinancialRuleService::get('bonus_fidelite_cotisation_pct', 8.33);
        if ($interestPct <= 0) {
            return null;
        }

        $bonusAmount = round($deposit->actual_amount * ($interestPct / 100.0), 2);
        if ($bonusAmount <= 0) {
            return null;
        }

        $individual = Individual::where('user_id', $deposit->user_id)->first();
        if ($individual) {
            $individual->bonus_caution += $bonusAmount;
            $individual->bonus_earned += $bonusAmount;
            $individual->save();
        }

        $user = User::find($deposit->user_id);
        if ($user && \Illuminate\Support\Facades\Schema::hasColumn('users', 'bonus_balance')) {
            $user->bonus_balance += $bonusAmount;
            $user->save();
        }

        return Bonus::create([
            'user_id'                => $deposit->user_id,
            'branch_id'              => $deposit->branch_id,
            'deposit_id'             => $deposit->id,
            'bonus_type'             => 'epargne_interet',
            'amount'                 => $bonusAmount,
            'tranche'                => 'mensuel',
            'description'            => "Bonus Intérêt/Rendement Cotisation ({$interestPct}%) sur versement caution #{$deposit->deposit_reference}",
            'granted_by_operator_id' => $deposit->operator_id,
        ]);
    }

    /**
     * Clôture annuelle sensibilisateur : libère la tranche 2 (50% mise en réserve)
     */
    public static function releaseYearEndMobilisationBonus(Sensibilisateur $sensibilisateur, ?int $operatorId = null): ?Bonus
    {
        $pending = (float) $sensibilisateur->bonus_pending_year_end;
        if ($pending <= 0) {
            return null;
        }

        $sensibilisateur->total_bonus_earned += $pending;
        $sensibilisateur->bonus_balance += $pending;
        $sensibilisateur->bonus_pending_year_end = 0;
        $sensibilisateur->save();

        return Bonus::create([
            'user_id'              => $sensibilisateur->user_id,
            'branch_id'            => $sensibilisateur->branch_id,
            'sensibilisateur_id'   => $sensibilisateur->id,
            'organisation_id'      => $sensibilisateur->organisation_id,
            'bonus_type'           => 'mobilisation',
            'amount'               => $pending,
            'tranche'              => 'cloture_annuelle',
            'description'          => "Commission Mobilisation (Tranche 2 à la clôture annuelle) pour {$sensibilisateur->name}",
            'granted_by_operator_id' => $operatorId,
        ]);
    }

    /**
     * 3. PARRAINAGE D'EMPRUNT (Multi-garants)
     * Répartition des intérêts de crédit :
     * 20% prioritaire au centre GEP
     * 80% redistribués aux garants au prorata de leur caution engagée
     */
    public static function distributeParrainageBonus(Credit $credit, float $totalInterestAmount, ?int $operatorId = null): array
    {
        $centerPct = (float) FinancialRuleService::get('parrainage_part_centre_pct', 20.0);
        $sponsorsPct = (float) FinancialRuleService::get('parrainage_part_parrains_pct', 80.0);

        $centerAmount = round($totalInterestAmount * ($centerPct / 100.0), 2);
        $sponsorsPool = round($totalInterestAmount * ($sponsorsPct / 100.0), 2);

        $credit->center_interest_amount = $centerAmount;
        $credit->sponsors_interest_amount = $sponsorsPool;
        $credit->save();

        $bonuses = [];
        $guarantors = CreditGuarantor::where('credit_id', $credit->id)->get();

        if ($guarantors->isNotEmpty()) {
            foreach ($guarantors as $guarantor) {
                $pct = (float) $guarantor->guaranteed_percentage;
                if ($pct <= 0) continue;

                $guarantorShare = round($sponsorsPool * ($pct / 100.0), 2);
                $guarantor->interest_bonus_earned += $guarantorShare;
                $guarantor->save();

                $targetUserId = null;
                $targetOrgId = null;

                if ($guarantor->guarantor_type === 'organisation') {
                    $targetOrgId = $guarantor->guarantor_id;
                    $org = Organisation::find($targetOrgId);
                    if ($org) {
                        $org->bonus_balance += $guarantorShare;
                        $org->save();
                    }
                } else {
                    $targetUserId = $guarantor->guarantor_id;
                    $user = User::find($targetUserId);
                    if ($user && \Illuminate\Support\Facades\Schema::hasColumn('users', 'bonus_balance')) {
                        $user->bonus_balance += $guarantorShare;
                        $user->save();
                    }
                }

                $bonus = Bonus::create([
                    'user_id'              => $targetUserId,
                    'branch_id'            => $credit->branch_id,
                    'organisation_id'      => $targetOrgId,
                    'credit_id'            => $credit->id,
                    'bonus_type'           => 'parrainage',
                    'amount'               => $guarantorShare,
                    'tranche'              => 'complet',
                    'description'          => "Bonus Parrainage ({$pct}% garanti) sur intérêts du crédit #{$credit->credit_number}",
                    'granted_by_operator_id' => $operatorId ?? $credit->disbursed_by_operator_id,
                ]);
                $bonuses[] = $bonus;
            }
        }

        return $bonuses;
    }

    /**
     * 4. BONUS SUR INTÉRÊT / CLÔTURE ÉPARGNE (13ème mois)
     * À la clôture après 12 mois de cotisations régulières, 1 mois complet est offert en bonus.
     */
    public static function checkAndApply13thMonthBonus(int $userId, ?int $operatorId = null): ?Bonus
    {
        $enabled = (bool) FinancialRuleService::get('epargne_bonus_13eme_mois_actif', true);
        if (!$enabled) {
            return null;
        }

        $deposits = Deposit::where('user_id', $userId)
            ->where('is_on_time', true)
            ->orderBy('cycle_month', 'asc')
            ->get();

        // Vérifier si 12 mois de dépôts réguliers sont atteints
        if ($deposits->count() >= 12) {
            $existing = Bonus::where('user_id', $userId)
                ->where('bonus_type', 'epargne_interet')
                ->where('tranche', '13eme_mois')
                ->first();
            if ($existing) {
                return null;
            }

            $monthlyAvg = $deposits->avg('actual_amount');
            $bonusMonths = (float) FinancialRuleService::get('epargne_bonus_mois_nb', 1.0);
            $bonusAmount = round($monthlyAvg * $bonusMonths, 2);

            $individual = Individual::where('user_id', $userId)->first();
            if ($individual) {
                $individual->bonus_caution += $bonusAmount;
                $individual->save();
            }

            return Bonus::create([
                'user_id'              => $userId,
                'branch_id'            => $individual->branch_id ?? null,
                'bonus_type'           => 'epargne_interet',
                'amount'               => $bonusAmount,
                'tranche'              => '13eme_mois',
                'description'          => "Bonus Épargne 13ème Mois (Fidélité 12 mois sans retard)",
                'granted_by_operator_id' => $operatorId,
            ]);
        }

        return null;
    }

    /**
     * 5. BONUS SUR RECOUVREMENT DE PÉNALITÉ
     * Répartition : 20% agent de recouvrement, 30% organisation / succursale, 50% centre GEP
     */
    public static function distributePenaltyRecovery(
        Credit $credit,
        float $penaltyAmount,
        int $collectorUserId,
        ?int $operatorId = null
    ): array {
        $agentPct = (float) FinancialRuleService::get('recouvrement_part_agent_pct', 20.0);
        $structPct = (float) FinancialRuleService::get('recouvrement_part_structure_pct', 30.0);
        $centerPct = (float) FinancialRuleService::get('recouvrement_part_centre_pct', 50.0);

        $agentShare = round($penaltyAmount * ($agentPct / 100.0), 2);
        $structShare = round($penaltyAmount * ($structPct / 100.0), 2);
        $centerShare = round($penaltyAmount * ($centerPct / 100.0), 2);

        $bonuses = [];

        // 1. Part Agent de recouvrement
        $bonuses[] = Bonus::create([
            'user_id'              => $collectorUserId,
            'branch_id'            => $credit->branch_id,
            'credit_id'            => $credit->id,
            'bonus_type'           => 'recouvrement_penalite',
            'amount'               => $agentShare,
            'tranche'              => 'agent_20',
            'description'          => "Commission Recouvrement Agent (20%) sur pénalité crédit #{$credit->credit_number}",
            'granted_by_operator_id' => $operatorId,
        ]);

        // 2. Part Structure (Succursale ou Organisation du collecteur)
        $collectorUser = User::find($collectorUserId);
        $bonuses[] = Bonus::create([
            'user_id'              => null,
            'branch_id'            => $credit->branch_id,
            'organisation_id'      => $collectorUser->organisation_id ?? null,
            'credit_id'            => $credit->id,
            'bonus_type'           => 'recouvrement_penalite',
            'amount'               => $structShare,
            'tranche'              => 'structure_30',
            'description'          => "Commission Recouvrement Structure (30%) sur pénalité crédit #{$credit->credit_number}",
            'granted_by_operator_id' => $operatorId,
        ]);

        return $bonuses;
    }
}