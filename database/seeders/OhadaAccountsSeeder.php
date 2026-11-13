<?php

namespace Database\Seeders;

use App\Models\OhadaAccount;
use Illuminate\Database\Seeder;

class OhadaAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            // ── CLASSE 1 : RESSOURCES DURABLES (PASSIF) ──────────────────────
            ['account_number' => '101000', 'label' => 'Capital social & Dotation initiale', 'category' => 'passif', 'ohada_class' => 1],
            ['account_number' => '111000', 'label' => 'Réserves légales et statutaires', 'category' => 'passif', 'ohada_class' => 1],
            ['account_number' => '121000', 'label' => 'Report à nouveau (Résultats antérieurs)', 'category' => 'passif', 'ohada_class' => 1],
            ['account_number' => '131000', 'label' => 'Résultat net de l\'exercice', 'category' => 'passif', 'ohada_class' => 1],
            ['account_number' => '165000', 'label' => 'Dépôts de cautions solidaires bloquées', 'category' => 'passif', 'ohada_class' => 1],
            ['account_number' => '165100', 'label' => 'Cautions consignées à terme (12 mois rémunérées)', 'category' => 'passif', 'ohada_class' => 1],

            // ── CLASSE 2 : ACTIF IMMOBILISÉ (ACTIF) ──────────────────────────
            ['account_number' => '241000', 'label' => 'Matériel d\'exploitation et agencements', 'category' => 'actif', 'ohada_class' => 2],
            ['account_number' => '244000', 'label' => 'Mobilier et matériel de bureau', 'category' => 'actif', 'ohada_class' => 2],
            ['account_number' => '245000', 'label' => 'Matériel informatique et serveurs', 'category' => 'actif', 'ohada_class' => 2],

            // ── CLASSE 4 : COMPTES DE TIERS (ACTIF & PASSIF CIRCULANT) ────────
            ['account_number' => '411000', 'label' => 'Portefeuille Crédits Sains (Moins de 90 jours)', 'category' => 'actif', 'ohada_class' => 4],
            ['account_number' => '412000', 'label' => 'Crédits en Souffrance (Retard 90 à 180 jours)', 'category' => 'actif', 'ohada_class' => 4],
            ['account_number' => '413000', 'label' => 'Crédits Douteux et Litigieux (> 180 jours / 6 mois)', 'category' => 'actif', 'ohada_class' => 4],
            ['account_number' => '419000', 'label' => 'Créances sur Comptes Ordinaires en Découvert', 'category' => 'actif', 'ohada_class' => 4],
            ['account_number' => '421000', 'label' => 'Comptes Ordinaires des Membres (Soldes Créditeurs)', 'category' => 'passif', 'ohada_class' => 4],
            ['account_number' => '461000', 'label' => 'Provisions Bonus Annuels à verser (Séquestre)', 'category' => 'passif', 'ohada_class' => 4],
            ['account_number' => '465000', 'label' => 'Dettes de Couverture dues par les Parrains', 'category' => 'passif', 'ohada_class' => 4],

            // ── CLASSE 5 : TRÉSORERIE (ACTIF & PASSIF DE TRÉSORERIE) ─────────
            ['account_number' => '521000', 'label' => 'Banque centrale / Dépôts bancaires', 'category' => 'actif', 'ohada_class' => 5],
            ['account_number' => '571000', 'label' => 'Caisse centrale espèces (Guichet)', 'category' => 'actif', 'ohada_class' => 5],
            ['account_number' => '572100', 'label' => 'Portefeuille Airtel Money', 'category' => 'actif', 'ohada_class' => 5],
            ['account_number' => '572200', 'label' => 'Portefeuille M-Pesa (Vodacom)', 'category' => 'actif', 'ohada_class' => 5],
            ['account_number' => '572300', 'label' => 'Portefeuille Orange Money', 'category' => 'actif', 'ohada_class' => 5],
            ['account_number' => '572400', 'label' => 'Portefeuille AfriMoney', 'category' => 'actif', 'ohada_class' => 5],

            // ── CLASSE 6 : CHARGES D'EXPLOITATION (COMPTE D'EXPLOITATION) ─────
            ['account_number' => '654000', 'label' => 'Pertes et provisions sur créances irrécouvrables', 'category' => 'charge', 'ohada_class' => 6],
            ['account_number' => '661000', 'label' => 'Bonus et quote-part d\'intérêts alloués aux parrains', 'category' => 'charge', 'ohada_class' => 6],
            ['account_number' => '662000', 'label' => 'Bonus annuel de rendement sur caution (1 mois offert)', 'category' => 'charge', 'ohada_class' => 6],
            ['account_number' => '663000', 'label' => 'Rémunérations sur cautions consignées (+1.5%/mois)', 'category' => 'charge', 'ohada_class' => 6],
            ['account_number' => '664000', 'label' => 'Bonus de mobilisation et parrainage d\'adhérents', 'category' => 'charge', 'ohada_class' => 6],
            ['account_number' => '624000', 'label' => 'Frais de télécommunications, SMS et réseaux', 'category' => 'charge', 'ohada_class' => 6],
            ['account_number' => '631000', 'label' => 'Frais généraux d\'administration et fournitures', 'category' => 'charge', 'ohada_class' => 6],

            // ── CLASSE 7 : PRODUITS D'EXPLOITATION (COMPTE D'EXPLOITATION) ────
            ['account_number' => '701000', 'label' => 'Intérêts créditeurs perçus sur crédits solidaires', 'category' => 'produit', 'ohada_class' => 7],
            ['account_number' => '702000', 'label' => 'Intérêts créditeurs perçus sur crédits express', 'category' => 'produit', 'ohada_class' => 7],
            ['account_number' => '703000', 'label' => 'Intérêts créditeurs perçus sur crédits spéciaux', 'category' => 'produit', 'ohada_class' => 7],
            ['account_number' => '706000', 'label' => 'Intérêts débiteurs (Agios 4%/mois sur découverts)', 'category' => 'produit', 'ohada_class' => 7],
            ['account_number' => '751000', 'label' => 'Pénalités et malus perçus sur retards de remboursement', 'category' => 'produit', 'ohada_class' => 7],
            ['account_number' => '752000', 'label' => 'Frais d\'adhésion, dossiers et souscriptions', 'category' => 'produit', 'ohada_class' => 7],
        ];

        foreach ($accounts as $acc) {
            OhadaAccount::updateOrCreate(
                ['account_number' => $acc['account_number']],
                $acc
            );
        }
    }
}
