<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // 'charge' | 'produit'
            $table->string('source')->default('manual'); // 'auto' | 'manual'
            $table->string('category')->default('custom'); // 'bonus', 'credit_interest', 'debit_interest', 'penalty', 'caution', 'deposit', 'withdrawal', 'credit_disburse', 'credit_repayment', 'custom'
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('payment_method')->default('cash'); // 'cash', 'airtel_money', 'mpesa', 'orange_money', 'afri_money', 'bank', 'internal'
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('status')->default('validated'); // 'pending' | 'validated' | 'rejected'
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('validated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('cycle_id')->nullable()->constrained('cycles')->onDelete('set null');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->onDelete('set null');
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_ledger_entries');
    }
};
