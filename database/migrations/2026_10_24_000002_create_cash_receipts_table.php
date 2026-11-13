<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->unique();
            $table->foreignId('cashier_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->onDelete('set null');
            $table->foreignId('member_id')->constrained('users')->onDelete('cascade');
            $table->decimal('amount', 15, 2);
            $table->string('payment_method')->default('cash');
            $table->string('purpose')->default('deposit'); // 'deposit', 'loan_repayment'
            $table->string('status')->default('pending_imputation'); // 'pending_imputation', 'imputed', 'cancelled'
            $table->foreignId('imputed_by_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('imputed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_receipts');
    }
};
