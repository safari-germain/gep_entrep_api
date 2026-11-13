<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sensibilisateurs', function (Blueprint $table) {
            $table->string('entity_type')->default('user')->after('id'); // 'user', 'organisation', 'branch'
            $table->foreignId('organisation_id')->nullable()->after('branch_id');
            $table->string('name')->nullable()->after('code_promo');
            $table->decimal('bonus_pending_year_end', 15, 2)->default(0.00)->after('total_bonus_earned');
            $table->decimal('caution_balance', 15, 2)->default(0.00)->after('bonus_pending_year_end');
            $table->decimal('general_balance', 15, 2)->default(0.00)->after('caution_balance');
            $table->decimal('bonus_balance', 15, 2)->default(0.00)->after('general_balance');
        });

        Schema::table('individuals', function (Blueprint $table) {
            $table->decimal('cotisation_stars', 3, 2)->default(3.00)->after('trust_score');
            $table->decimal('credit_stars', 3, 2)->default(3.00)->after('cotisation_stars');
            $table->boolean('has_red_dot')->default(false)->after('credit_stars');
            $table->integer('late_deposits_count')->default(0)->after('deposit_streak_months');
            $table->integer('late_credits_count')->default(0)->after('late_deposits_count');
        });

        Schema::table('credit_guarantors', function (Blueprint $table) {
            $table->decimal('guaranteed_percentage', 5, 2)->default(0.00)->after('guaranteed_amount');
            $table->decimal('interest_bonus_earned', 15, 2)->default(0.00)->after('guaranteed_percentage');
            $table->decimal('active_malus_amount', 15, 2)->default(0.00)->after('interest_bonus_earned');
        });

        Schema::table('credits', function (Blueprint $table) {
            $table->decimal('credit_stars', 3, 2)->default(3.00)->after('status');
            $table->boolean('has_red_dot')->default(false)->after('credit_stars');
            $table->decimal('center_interest_amount', 15, 2)->default(0.00)->after('total_interest_amount');
            $table->decimal('sponsors_interest_amount', 15, 2)->default(0.00)->after('center_interest_amount');
            $table->unsignedBigInteger('assigned_collector_id')->nullable()->after('disbursed_by_operator_id');
            $table->string('assigned_collector_type')->nullable()->after('assigned_collector_id');
        });

        Schema::table('bonuses', function (Blueprint $table) {
            $table->unsignedBigInteger('sensibilisateur_id')->nullable()->after('branch_id');
            $table->unsignedBigInteger('organisation_id')->nullable()->after('sensibilisateur_id');
            $table->unsignedBigInteger('credit_id')->nullable()->after('organisation_id');
            $table->string('tranche')->nullable()->after('amount');
        });

        Schema::table('maluses', function (Blueprint $table) {
            $table->unsignedBigInteger('credit_id')->nullable()->after('branch_id');
            $table->unsignedBigInteger('deposit_id')->nullable()->after('credit_id');
            $table->unsignedBigInteger('organisation_id')->nullable()->after('deposit_id');
            $table->decimal('guaranteed_debt_share', 15, 2)->default(0.00)->after('penalty_amount');
            $table->boolean('is_active')->default(true)->after('reason');
        });
    }

    public function down(): void
    {
    }
};
