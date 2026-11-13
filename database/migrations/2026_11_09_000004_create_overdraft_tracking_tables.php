<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Alter ordinary_accounts
        Schema::table('ordinary_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('ordinary_accounts', 'consecutive_negative_months')) {
                $table->unsignedInteger('consecutive_negative_months')->default(0)->after('negative_since');
            }
            if (!Schema::hasColumn('ordinary_accounts', 'total_overdraft_operations_count')) {
                $table->unsignedInteger('total_overdraft_operations_count')->default(0)->after('consecutive_negative_months');
            }
        });

        // 2. Alter ordinary_account_transactions
        Schema::table('ordinary_account_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('ordinary_account_transactions', 'is_funded_by_overdraft')) {
                $table->boolean('is_funded_by_overdraft')->default(false)->after('balance_after');
            }
            if (!Schema::hasColumn('ordinary_account_transactions', 'overdraft_months_count')) {
                $table->unsignedInteger('overdraft_months_count')->default(0)->after('is_funded_by_overdraft');
            }
            if (!Schema::hasColumn('ordinary_account_transactions', 'operation_nature')) {
                $table->string('operation_nature', 50)->default('general')->after('overdraft_months_count'); // credit_installment, caution_contribution, etc.
            }
        });

        // 3. Create ordinary_account_overdraft_cycles
        if (!Schema::hasTable('ordinary_account_overdraft_cycles')) {
            Schema::create('ordinary_account_overdraft_cycles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ordinary_account_id');
                $table->dateTime('started_at');
                $table->dateTime('resolved_at')->nullable();
                $table->unsignedInteger('consecutive_months_count')->default(1);
                $table->decimal('peak_negative_balance', 15, 2)->default(0.00);
                $table->decimal('total_interest_charged', 15, 2)->default(0.00);
                $table->unsignedInteger('credit_payments_count_under_overdraft')->default(0);
                $table->unsignedInteger('caution_payments_count_under_overdraft')->default(0);
                $table->enum('status', ['active', 'resolved', 'critical_warning'])->default('active');
                $table->timestamps();

                $table->foreign('ordinary_account_id')->references('id')->on('ordinary_accounts')->onDelete('cascade');
                $table->index(['ordinary_account_id', 'status'], 'idx_overdraft_acc_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ordinary_account_overdraft_cycles');

        Schema::table('ordinary_account_transactions', function (Blueprint $table) {
            $table->dropColumn(['is_funded_by_overdraft', 'overdraft_months_count', 'operation_nature']);
        });

        Schema::table('ordinary_accounts', function (Blueprint $table) {
            $table->dropColumn(['consecutive_negative_months', 'total_overdraft_operations_count']);
        });
    }
};
