<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Individual;
use App\Models\Organisation;
use App\Models\Sensibilisateur;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(FinancialRuleSeeder::class);

        // 1. Succursale principale
        $branch = Branch::firstOrCreate(
            ['code' => 'SUCC-GOM-01'],
            [
                'name'           => 'Succursale Goma Centre',
                'province'       => 'Nord-Kivu',
                'territory_city' => 'Goma',
                'address'        => 'Avenue de la Paix, No 12',
                'phone'          => '+243990000001',
                'manager_name'   => 'Directeur Agence Goma',
                'is_active'      => true,
            ]
        );

        // 2. Organisation solidaire
        $org = Organisation::firstOrCreate(
            ['code' => 'ORG-COOPEC-01'],
            [
                'name'                 => 'Coopérative des Entrepreneurs du Kivu',
                'type'                 => 'cooperative',
                'phone'                => '+243880000002',
                'email'                => 'contact@entrepreneurs-kivu.cd',
                'address'              => 'Quartier Les Volcans',
                'representative_name'  => 'Maman Furaha',
                'representative_phone' => '+243991234567',
                'status'               => 'active',
                'is_active'            => true,
            ]
        );

        // 3. Utilisateur Administrateur
        $admin = User::firstOrCreate(
            ['email' => 'admin@gep.cd'],
            [
                'name'     => 'Super',
                'prenom'   => 'Admin',
                'password' => Hash::make('admin123'),
                'phone'    => '+243990000000',
                'role'     => 'super_admin',
                'status'   => 'actif',
            ]
        );

        // 4. Sensibilisateur Individu
        $sensibUser = User::firstOrCreate(
            ['email' => 'sensibilisateur@gep.cd'],
            [
                'name'     => 'Kavira',
                'prenom'   => 'Aline',
                'password' => Hash::make('password'),
                'phone'    => '+243990111222',
                'role'     => 'marketeur',
                'status'   => 'actif',
            ]
        );

        $sensibilisateurIndiv = Sensibilisateur::firstOrCreate(
            ['user_id' => $sensibUser->id],
            [
                'entity_type'            => 'user',
                'branch_id'              => $branch->id,
                'code_promo'             => 'SENSIB-ALINE',
                'name'                   => 'Aline Kavira',
                'total_recruited'        => 0,
                'active_recruited'       => 0,
                'repayment_rate_quality' => 100.00,
                'total_bonus_earned'     => 0.00,
                'is_active'              => true,
            ]
        );

        // Sensibilisateur Organisation
        Sensibilisateur::firstOrCreate(
            ['entity_type' => 'organisation', 'organisation_id' => $org->id],
            [
                'code_promo' => 'ORG-KIVU',
                'name'       => $org->name,
                'branch_id'  => $branch->id,
                'is_active'  => true,
            ]
        );

        // Sensibilisateur Succursale
        Sensibilisateur::firstOrCreate(
            ['entity_type' => 'branch', 'branch_id' => $branch->id],
            [
                'code_promo' => 'SUCC-GOMA',
                'name'       => 'Succursale Goma Centre',
                'is_active'  => true,
            ]
        );

        // 5. Entrepreneur test
        $entrepreneurUser = User::firstOrCreate(
            ['email' => 'entrepreneur@gep.cd'],
            [
                'name'     => 'Safari',
                'prenom'   => 'Germain',
                'password' => Hash::make('password'),
                'phone'    => '+243997778899',
                'role'     => 'user',
                'status'   => 'actif',
            ]
        );

        Individual::firstOrCreate(
            ['user_id' => $entrepreneurUser->id],
            [
                'branch_id'             => $branch->id,
                'organisation_id'       => $org->id,
                'sensibilisateur_id'    => $sensibilisateurIndiv->id,
                'member_code'           => 'MEM-0001',
                'profession'            => 'Commerçant Import-Export',
                'activity_sector'       => 'Commerce général',
                'emergency_contact_name'=> 'Safari Bahati',
                'emergency_contact_phone'=> '+243998887766',
                'emergency_contact_relation' => 'Frère',
                'trust_score'           => 100.0,
                'cotisation_stars'      => 3.0,
                'credit_stars'          => 3.0,
                'has_red_dot'           => false,
                'deposit_streak_months' => 0,
                'status'                => 'active',
            ]
        );

        // 6. Grand Livre de Caisse & Reçus
        \App\Models\SystemLedgerEntry::firstOrCreate(
            ['title' => 'Encaissement Caution - Safari'],
            [
                'type'           => 'produit',
                'source'         => 'auto',
                'category'       => 'deposit',
                'description'    => 'Dépôt initial caution solidaire membre MEM-0001',
                'amount'         => 150.00,
                'payment_method' => 'cash',
                'status'         => 'validated',
                'created_by'     => $admin->id,
                'validated_by'   => $admin->id,
                'validated_at'   => now(),
                'branch_id'      => $branch->id,
            ]
        );

        \App\Models\SystemLedgerEntry::firstOrCreate(
            ['title' => 'Frais internet & communication'],
            [
                'type'           => 'charge',
                'source'         => 'manual',
                'category'       => 'custom',
                'description'    => 'Forfait mensuel connexion bureau Goma',
                'amount'         => 35.00,
                'payment_method' => 'airtel_money',
                'status'         => 'validated',
                'created_by'     => $admin->id,
                'validated_by'   => $admin->id,
                'validated_at'   => now(),
                'branch_id'      => $branch->id,
            ]
        );

        \App\Models\SystemLedgerEntry::firstOrCreate(
            ['title' => 'Achat papeterie & reçus'],
            [
                'type'           => 'charge',
                'source'         => 'manual',
                'category'       => 'custom',
                'description'    => 'Impressions carnets et fiches de caisse',
                'amount'         => 20.00,
                'payment_method' => 'cash',
                'status'         => 'pending',
                'created_by'     => $admin->id,
                'branch_id'      => $branch->id,
            ]
        );

        \App\Models\CashReceipt::firstOrCreate(
            ['receipt_number' => 'REC-CAISSE-0001'],
            [
                'cashier_id'     => $admin->id,
                'branch_id'      => $branch->id,
                'member_id'      => $entrepreneurUser->id,
                'amount'         => 150.00,
                'payment_method' => 'cash',
                'purpose'        => 'deposit',
                'status'         => 'pending_imputation',
            ]
        );
    }
}
