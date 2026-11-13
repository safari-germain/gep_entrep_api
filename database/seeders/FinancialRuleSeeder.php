<?php

namespace Database\Seeders;

use App\Models\FinancialRule;
use Illuminate\Database\Seeder;

class FinancialRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            // 1. Cycle & Cotisation
            [
                'key' => 'cycle_solidarite_duree_mois',
                'name' => 'Durée du Cycle de Solidarité (Mois)',
                'description' => 'Durée normale en mois d\'un cycle complet de cotisations solidaires (ex: 12 mois)',
                'value_numeric' => 12,
                'type' => 'months',
            ],
            [
                'key' => 'cotisation_delai_jour_max',
                'name' => 'Délai de Grâce de Versement (Jours)',
                'description' => 'Nombre de jours autorisés avant d\'appliquer une pénalité de retard sur versement',
                'value_numeric' => 10,
                'type' => 'amount',
            ],
            [
                'key' => 'bonus_fidelite_cotisation_pct',
                'name' => 'Bonus Intérêt / Fidélité Cotisation (%)',
                'description' => 'Pourcentage mensuel accumulé équivalent à 1 mois complet offert à la clôture (8.33% x 12 ≈ 100% d\'un mois)',
                'value_numeric' => 8.33,
                'type' => 'percentage',
            ],
            [
                'key' => 'cotisation_penalite_retard_pct',
                'name' => 'Pénalité de Retard sur Cotisation (%)',
                'description' => 'Pourcentage de pénalité appliqué lors d\'un versement tardif de caution solidaire',
                'value_numeric' => 5.0,
                'type' => 'percentage',
            ],

            // 2. Prêts & Plafond dynamique
            [
                'key' => 'pret_pourcentage_max',
                'name' => 'Capacité d\'Emprunt Maximale (%)',
                'description' => 'Plafond d\'emprunt autorisé par rapport au montant de caution disponible (ex: 500%)',
                'value_numeric' => 500.0,
                'type' => 'percentage',
            ],
            [
                'key' => 'pret_taux_interet_pct',
                'name' => 'Taux d\'Intérêt Annuel / Cycle du Prêt (%)',
                'description' => 'Taux d\'intérêt standard appliqué sur les crédits solidaires',
                'value_numeric' => 10.0,
                'type' => 'percentage',
            ],
            [
                'key' => 'pret_penalite_retard_jour_pct',
                'name' => 'Pénalité de Retard sur Échéance Prêt (%)',
                'description' => 'Pourcentage de pénalité journalière ou par échéance en cas de retard de remboursement',
                'value_numeric' => 1.0,
                'type' => 'percentage',
            ],

            // 3. Mobilisation (Sensibilisateurs)
            [
                'key' => 'sensibilisateur_base_bonus_pct',
                'name' => 'Taux de Base Commission Mobilisation (%)',
                'description' => 'Quote-part globale de caution solidaire dédiée au bonus du sensibilisateur',
                'value_numeric' => 50.0,
                'type' => 'percentage',
            ],
            [
                'key' => 'sensibilisateur_bonus_versement_2_pct',
                'name' => 'Tranche 1 au 2ème Versement (%)',
                'description' => 'Part du bonus versée immédiatement au deuxième versement de l\'adhérent (50%)',
                'value_numeric' => 50.0,
                'type' => 'percentage',
            ],
            [
                'key' => 'sensibilisateur_bonus_cloture_pct',
                'name' => 'Tranche 2 à la Clôture Annuelle (%)',
                'description' => 'Part du bonus mise en réserve et libérée à la clôture des 12 mois (50%)',
                'value_numeric' => 50.0,
                'type' => 'percentage',
            ],

            // 4. Parrainage d'Emprunt (Part Centre & Redistribution)
            [
                'key' => 'parrainage_part_centre_pct',
                'name' => 'Quote-part Intérêts Centre GEP (%)',
                'description' => 'Part prioritaire prélevée par le centre GEP sur les intérêts du crédit (ex: 20%)',
                'value_numeric' => 20.0,
                'type' => 'percentage',
            ],
            [
                'key' => 'parrainage_part_parrains_pct',
                'name' => 'Quote-part Intérêts Redistribuée aux Parrains (%)',
                'description' => 'Part des intérêts distribuée aux parrains proportionnellement à leur caution (ex: 80%)',
                'value_numeric' => 80.0,
                'type' => 'percentage',
            ],

            // 5. Bonus 13ème Mois (Intérêt Épargne)
            [
                'key' => 'epargne_bonus_13eme_mois_actif',
                'name' => 'Activation Bonus 13ème Mois Épargne',
                'description' => 'Accorde l\'équivalent de 1 mois supplémentaire après 12 mois sans aucun retard',
                'value_numeric' => 1,
                'type' => 'boolean',
            ],
            [
                'key' => 'epargne_bonus_mois_nb',
                'name' => 'Nombre de Mois Offerts en Bonus',
                'description' => 'Nombre de mensualités offertes en prime fidélité à la clôture annuelle',
                'value_numeric' => 1.0,
                'type' => 'amount',
            ],

            // 6. Recouvrement de Pénalité
            [
                'key' => 'recouvrement_part_agent_pct',
                'name' => 'Part Recouvrement Agent Direct (%)',
                'description' => 'Commission versée à l\'agent ayant assuré le recouvrement de la pénalité (ex: 20%)',
                'value_numeric' => 20.0,
                'type' => 'percentage',
            ],
            [
                'key' => 'recouvrement_part_structure_pct',
                'name' => 'Part Recouvrement Structure/Succursale (%)',
                'description' => 'Commission envoyée à l\'organisation ou succursale du sensibilisateur (ex: 30%)',
                'value_numeric' => 30.0,
                'type' => 'percentage',
            ],
            [
                'key' => 'recouvrement_part_centre_pct',
                'name' => 'Part Recouvrement Centre GEP (%)',
                'description' => 'Quote-part conservée par le centre GEP sur les pénalités recouvrées (ex: 50%)',
                'value_numeric' => 50.0,
                'type' => 'percentage',
            ],

            // 7. Étoiles & Point Rouge
            [
                'key' => 'cotisation_etoiles_base',
                'name' => 'Nombre d\'Étoiles Initiales de Cotisation',
                'description' => 'Nombre d\'étoiles au début du contrat de cotisation (par défaut 3 étoiles or)',
                'value_numeric' => 3.0,
                'type' => 'amount',
            ],
            [
                'key' => 'admin_point_rouge_actif',
                'name' => 'Point Rouge Alerte Admin Actif',
                'description' => 'Affiche une pastille/point rouge en cas de pénalité ou retard non résolu',
                'value_numeric' => 1,
                'type' => 'boolean',
            ],

            // 8. Compte Ordinaire, Découvert & Prélèvements automatiques
            [
                'key' => 'compte_ordinaire_decouvert_delai_grace_jours',
                'name' => 'Délai de Grâce Découvert Compte Ordinaire (Jours)',
                'description' => 'Nombre de jours de retard tolérés sur solde négatif avant application des intérêts débiteurs (ex: 3 jours)',
                'value_numeric' => 3,
                'type' => 'amount',
            ],
            [
                'key' => 'compte_ordinaire_decouvert_interet_pct',
                'name' => 'Taux d\'Intérêt Débiteur sur Compte Négatif (%)',
                'description' => 'Pourcentage d\'intérêts débiteurs incrémenté lors d\'un découvert prolongé au-delà du délai de grâce',
                'value_numeric' => 2.0,
                'type' => 'percentage',
            ],
            [
                'key' => 'compte_ordinaire_prelevement_auto_caution',
                'name' => 'Prélèvement Automatique Caution sur Compte Ordinaire',
                'description' => 'Déduit automatiquement la caution solidaire mensuelle depuis le compte ordinaire à chaque échéance de cycle',
                'value_numeric' => 1,
                'type' => 'boolean',
            ],
            [
                'key' => 'credit_echeance_delai_grace_jours',
                'name' => 'Délai de Grâce Échéance Prêt (Jours)',
                'description' => 'Nombre de jours de retard tolérés sur remboursement de prêt avant application des pénalités',
                'value_numeric' => 3,
                'type' => 'amount',
            ],
            [
                'key' => 'credit_echeance_penalite_pct',
                'name' => 'Pénalité de Retard sur Échéance Prêt (%)',
                'description' => 'Pourcentage de pénalité appliqué sur échéance de dette impayée au-delà du délai de grâce',
                'value_numeric' => 1.0,
                'type' => 'percentage',
            ],

            // 9. Nouveaux Types de Crédits (Solidaire, Express, Spécial)
            [
                'key' => 'credit_solidaire_min_peer_guarantors',
                'name' => 'Nombre Min de Parrains Pairs (Solidaire)',
                'description' => 'Nombre minimum d\'entrepreneurs pairs devant parrainer un crédit solidaire (défaut 3)',
                'value_numeric' => 3,
                'type' => 'amount',
            ],
            [
                'key' => 'credit_solidaire_min_peer_pct_each',
                'name' => 'Pourcentage Min par Parrain Pair (Solidaire %)',
                'description' => 'Pourcentage minimum garanti par chaque entrepreneur pair (défaut 10%)',
                'value_numeric' => 10.0,
                'type' => 'percentage',
            ],
            [
                'key' => 'credit_solidaire_org_default_pct',
                'name' => 'Pourcentage Organisation par Défaut (Solidaire %)',
                'description' => 'Pourcentage pris en charge par l\'organisation de parrainage par défaut (défaut 50%)',
                'value_numeric' => 50.0,
                'type' => 'percentage',
            ],
            [
                'key' => 'credit_solidaire_centre_gep_pct',
                'name' => 'Pourcentage Centre GEP (Solidaire %)',
                'description' => 'Pourcentage restant garanti par le Centre GEP (défaut 20%)',
                'value_numeric' => 20.0,
                'type' => 'percentage',
            ],
            [
                'key' => 'credit_express_grace_days',
                'name' => 'Délai de Grâce Retard Crédit Express (Jours)',
                'description' => 'Nombre de jours tolérés avant application du malus/débit créditaire pour crédit express',
                'value_numeric' => 3,
                'type' => 'amount',
            ],
            [
                'key' => 'credit_express_penalty_pct',
                'name' => 'Taux Pénalité Retard Crédit Express (%)',
                'description' => 'Pourcentage de pénalité sur retard pour crédit express',
                'value_numeric' => 2.0,
                'type' => 'percentage',
            ],
            [
                'key' => 'credit_special_grace_days',
                'name' => 'Délai de Grâce Retard Crédit Spécial (Jours)',
                'description' => 'Nombre de jours tolérés avant application de pénalité de retard pour crédit spécial',
                'value_numeric' => 5,
                'type' => 'amount',
            ],
            [
                'key' => 'credit_special_penalty_pct',
                'name' => 'Taux Pénalité Retard Crédit Spécial (%)',
                'description' => 'Pourcentage de pénalité sur retard pour crédit spécial',
                'value_numeric' => 1.5,
                'type' => 'percentage',
            ],
        ];

        foreach ($rules as $r) {
            FinancialRule::updateOrCreate(['key' => $r['key']], $r);
        }
    }
}
