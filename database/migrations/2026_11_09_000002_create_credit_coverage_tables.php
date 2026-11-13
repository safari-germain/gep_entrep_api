<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add fields to credits if missing
        Schema::table('credits', function (Blueprint $table) {
            if (!Schema::hasColumn('credits', 'defaulted_at')) {
                $table->timestamp('defaulted_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('credits', 'coverage_triggered_at')) {
                $table->timestamp('coverage_triggered_at')->nullable()->after('defaulted_at');
            }
            if (!Schema::hasColumn('credits', 'coverage_status')) {
                $table->enum('coverage_status', ['none', 'pending', 'called', 'partially_covered', 'fully_covered'])->default('none')->after('coverage_triggered_at');
            }
        });

        // Add fields to credit_guarantors if missing
        Schema::table('credit_guarantors', function (Blueprint $table) {
            if (!Schema::hasColumn('credit_guarantors', 'coverage_called_amount')) {
                $table->decimal('coverage_called_amount', 15, 2)->default(0.00)->after('interest_earned');
            }
            if (!Schema::hasColumn('credit_guarantors', 'coverage_settled_amount')) {
                $table->decimal('coverage_settled_amount', 15, 2)->default(0.00)->after('coverage_called_amount');
            }
            if (!Schema::hasColumn('credit_guarantors', 'coverage_status')) {
                $table->enum('coverage_status', ['normal', 'called', 'cleared', 'defaulted'])->default('normal')->after('coverage_settled_amount');
            }
        });

        // Add coverage restriction to ordinary_accounts if missing
        Schema::table('ordinary_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('ordinary_accounts', 'coverage_debt_amount')) {
                $table->decimal('coverage_debt_amount', 15, 2)->default(0.00)->after('status');
            }
            if (!Schema::hasColumn('ordinary_accounts', 'has_coverage_restriction')) {
                $table->boolean('has_coverage_restriction')->default(false)->after('coverage_debt_amount');
            }
        });

        // Create credit_coverage_calls table
        if (!Schema::hasTable('credit_coverage_calls')) {
            Schema::create('credit_coverage_calls', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('credit_id');
                $table->unsignedBigInteger('credit_guarantor_id');
                $table->unsignedBigInteger('guarantor_user_id')->nullable();
                $table->unsignedBigInteger('organisation_id')->nullable();
                $table->timestamp('called_at');
                $table->decimal('credit_remaining_balance_at_call', 15, 2);
                $table->decimal('guaranteed_percentage', 5, 2);
                $table->decimal('liability_amount', 15, 2); // Quote-part due par ce garant
                $table->decimal('settled_amount', 15, 2)->default(0.00); // Montant déjà apuré
                $table->decimal('remaining_liability', 15, 2); // Solde négatif restant
                $table->enum('status', ['pending', 'partially_settled', 'fully_settled', 'cleared_by_repayment'])->default('pending');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->foreign('credit_id')->references('id')->on('credits')->onDelete('cascade');
                $table->foreign('credit_guarantor_id')->references('id')->on('credit_guarantors')->onDelete('cascade');
                $table->foreign('guarantor_user_id')->references('id')->on('users')->onDelete('set null');
                $table->foreign('organisation_id')->references('id')->on('organisations')->onDelete('set null');
                $table->index(['guarantor_user_id', 'status']);
            });
        }

        // Create credit_coverage_settlements table
        if (!Schema::hasTable('credit_coverage_settlements')) {
            Schema::create('credit_coverage_settlements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('credit_coverage_call_id');
                $table->enum('settlement_source', [
                    'ordinary_account_deduction',  // Déduit volontairement du compte ordinaire du parrain
                    'borrower_repayment',         // L'emprunteur initial a remboursé
                    'bonus_offset',               // Déduction sur bonus annuel
                    'manual_adjustment'           // Ajustement admin
                ]);
                $table->decimal('amount', 15, 2);
                $table->unsignedBigInteger('ordinary_account_transaction_id')->nullable();
                $table->unsignedBigInteger('processed_by_user_id')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();

                $table->foreign('credit_coverage_call_id')->references('id')->on('credit_coverage_calls')->onDelete('cascade');
                $table->foreign('processed_by_user_id')->references('id')->on('users')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_coverage_settlements');
        Schema::dropIfExists('credit_coverage_calls');

        Schema::table('ordinary_accounts', function (Blueprint $table) {
            $table->dropColumn(['coverage_debt_amount', 'has_coverage_restriction']);
        });

        Schema::table('credit_guarantors', function (Blueprint $table) {
            $table->dropColumn(['coverage_called_amount', 'coverage_settled_amount', 'coverage_status']);
        });

        Schema::table('credits', function (Blueprint $table) {
            $table->dropColumn(['defaulted_at', 'coverage_triggered_at', 'coverage_status']);
        });
    }
};
