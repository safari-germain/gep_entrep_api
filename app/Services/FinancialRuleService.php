<?php

namespace App\Services;

use App\Models\FinancialRule;

class FinancialRuleService
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $defaults = [
            'pret_pourcentage_max'                  => 500.0, // 500% max loan capacity
            'pret_taux_interet_pct'                 => 10.0,  // 10% loan interest
            'cotisation_penalite_retard_pct'        => 5.0,   // 5% late deposit penalty
            'pret_penalite_retard_jour_pct'         => 1.0,   // 1% daily/installment late penalty
            'sensibilisateur_bonus_versement_2_pct' => 50.0,  // 50% at 2nd deposit
            'sensibilisateur_bonus_cloture_pct'     => 50.0,  // 50% at year-end closure
            'sensibilisateur_base_bonus_pct'        => 50.0,  // Base mobilization %
            'parrainage_part_centre_pct'            => 20.0,  // 20% to central GEP
            'parrainage_part_parrains_pct'          => 80.0,  // 80% to sponsors
            'epargne_bonus_13eme_mois_actif'        => true,  // 13th month bonus enabled
            'epargne_bonus_mois_nb'                 => 1.0,   // 1 month deposit equivalent
            'recouvrement_part_agent_pct'           => 20.0,  // 20% to recovery agent
            'recouvrement_part_structure_pct'       => 30.0,  // 30% to organization/branch
            'recouvrement_part_centre_pct'          => 50.0,  // 50% to central GEP
            'cotisation_etoiles_base'               => 3.0,   // 3 stars base
            'cotisation_cycle_mois'                 => 12,    // 12 months cycle
            'admin_point_rouge_actif'               => true,
            'credit_notification_jours_avant'       => 3,     // Jours d'envoi notification avant échéance crédit
            'credit_penalite_grace_jours'           => 2,     // Jours de grâce avant calcul pénalité crédit
            'cotisation_delai_jour_max'             => 10,    // Jour limite du mois (échéance cotisation)
            'cotisation_notification_jours_avant'   => 3,     // Jours d'envoi notification avant échéance cotisation
            'cotisation_jours_retard_grace'         => 2,     // Jours de retard tolérés avant calcul pénalité cotisation
            'cotisation_interet_taux_annuel'        => 8.33,  // 8.33% équivalent bonus annuel (1/12)
            'compte_ordinaire_decouvert_delai_grace_jours' => 3,    // Jours de grâce solde négatif avant intérêts débiteurs
            'compte_ordinaire_decouvert_interet_pct'       => 2.0,  // Taux d'intérêt débiteur (%) sur solde négatif
            'compte_ordinaire_prelevement_auto_caution'    => true, // Prélèvement automatique mensuel caution de cycle
            'credit_echeance_delai_grace_jours'            => 3,    // Délai de grâce pour échéance crédit
            'credit_echeance_penalite_pct'                 => 1.0,  // Pénalité de retard sur échéance crédit (%)
            'credit_solidaire_min_peer_guarantors'         => 3,    // Nb min de parrains pairs pour crédit solidaire
            'credit_solidaire_min_peer_pct_each'           => 10.0, // % min garanti par chaque parrain pair (solidaire)
            'credit_solidaire_org_default_pct'             => 50.0, // % garanti par l'organisation par défaut (solidaire)
            'credit_solidaire_centre_gep_pct'              => 20.0, // % garanti par le centre GEP (solidaire)
            'credit_express_grace_days'                    => 3,    // Jours de grâce pour crédit express
            'credit_express_penalty_pct'                   => 2.0,  // Pénalité de retard journalière/échéance (%) crédit express
            'credit_special_grace_days'                    => 5,    // Jours de grâce pour crédit spécial
            'credit_special_penalty_pct'                   => 1.5,  // Pénalité de retard journalière/échéance (%) crédit spécial
            'compte_ordinaire_annual_bonus_pct'            => 5.0,  // Bonus annuel (%) sur solde positif ordinaire, calculé par jour (/ 365)
            'overdraft_notification_frequency'             => 'les_deux', // Fréquence notifications découvert: 'matin', 'soir', 'les_deux'
            'credit_solidaire_default_sponsors'            => '[]', // Organisations parraines par défaut pour crédit solidaire (JSON)
        ];

        return FinancialRule::getRuleValue($key, $defaults[$key] ?? $default);
    }

    public static function set(string $key, mixed $value, string $name = null, string $type = 'amount'): FinancialRule
    {
        $rule = FinancialRule::firstOrNew(['key' => $key]);
        if ($name && !$rule->exists) {
            $rule->name = $name;
        } elseif (!$rule->exists) {
            $rule->name = ucwords(str_replace('_', ' ', $key));
        }

        $rule->type = $type;
        $rule->is_active = true;

        if (is_numeric($value)) {
            $rule->value_numeric = (float) $value;
        } elseif (is_bool($value)) {
            $rule->value_numeric = $value ? 1 : 0;
            $rule->type = 'boolean';
        } elseif (is_array($value)) {
            $rule->value_json = $value;
            $rule->type = 'json';
        } else {
            $rule->value_string = (string) $value;
        }

        $rule->save();
        return $rule;
    }

    public static function batchUpdate(array $configs): void
    {
        foreach ($configs as $key => $val) {
            self::set($key, $val);
        }
    }
}
