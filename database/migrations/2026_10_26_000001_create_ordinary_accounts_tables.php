<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordinary_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type')->default('individual'); // 'individual', 'organisation', 'user'
            $table->unsignedBigInteger('entity_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->decimal('balance', 15, 2)->default(0.00); // Solde pouvant être négatif
            $table->decimal('total_debit_interest_charged', 15, 2)->default(0.00);
            $table->string('status')->default('active'); // 'active', 'suspended', 'closed'
            $table->timestamp('negative_since')->nullable(); // Date à partir de laquelle le solde est devenu négatif
            $table->timestamp('last_interest_calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id']);
        });

        Schema::create('ordinary_account_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordinary_account_id')->constrained('ordinary_accounts')->onDelete('cascade');
            $table->string('transaction_type'); // 'deposit', 'withdrawal', 'caution_payment', 'credit_repayment', 'debit_interest', 'penalty_deduction', 'cycle_settlement', 'admin_adjustment'
            $table->string('direction'); // 'credit', 'debit'
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->text('description')->nullable();
            $table->string('reference')->unique();
            $table->string('payment_method')->nullable()->default('cash');
            $table->foreignId('operator_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordinary_account_transactions');
        Schema::dropIfExists('ordinary_accounts');
    }
};
