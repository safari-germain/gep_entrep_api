<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('annual_bonus_accounts')) {
            Schema::create('annual_bonus_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->year('fiscal_year');
                $table->decimal('total_bonuses_accumulated', 15, 2)->default(0.00);
                $table->decimal('total_maluses_deducted', 15, 2)->default(0.00);
                $table->decimal('net_bonus_balance', 15, 2)->default(0.00); // Max(0, bonus - malus)
                $table->decimal('disbursed_amount', 15, 2)->default(0.00);
                $table->enum('status', ['accruing', 'locked_evaluation', 'disbursed', 'closed'])->default('accruing');
                $table->timestamp('disbursed_at')->nullable();
                $table->unsignedBigInteger('ordinary_account_transaction_id')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->unique(['user_id', 'fiscal_year']);
            });
        }

        if (!Schema::hasTable('annual_bonus_movements')) {
            Schema::create('annual_bonus_movements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('annual_bonus_account_id');
                $table->enum('movement_type', ['bonus_accrual', 'malus_deduction', 'year_end_disbursement', 'manual_adjustment']);
                $table->decimal('amount', 15, 2);
                $table->decimal('balance_before', 15, 2);
                $table->decimal('balance_after', 15, 2);
                $table->string('reference_type', 50)->nullable(); // bonus, malus, ordinary_account_transaction
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->string('description', 255)->nullable();
                $table->timestamps();

                $table->foreign('annual_bonus_account_id')->references('id')->on('annual_bonus_accounts')->onDelete('cascade');
            });
        }

        Schema::table('bonuses', function (Blueprint $table) {
            if (!Schema::hasColumn('bonuses', 'held_for_annual_payout')) {
                $table->boolean('held_for_annual_payout')->default(true)->after('amount');
            }
            if (!Schema::hasColumn('bonuses', 'annual_bonus_account_id')) {
                $table->unsignedBigInteger('annual_bonus_account_id')->nullable()->after('held_for_annual_payout');
                $table->foreign('annual_bonus_account_id')->references('id')->on('annual_bonus_accounts')->onDelete('set null');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bonuses', function (Blueprint $table) {
            $table->dropForeign(['annual_bonus_account_id']);
            $table->dropColumn(['held_for_annual_payout', 'annual_bonus_account_id']);
        });

        Schema::dropIfExists('annual_bonus_movements');
        Schema::dropIfExists('annual_bonus_accounts');
    }
};
