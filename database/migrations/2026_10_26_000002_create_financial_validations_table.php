<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_validations', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('operation_type'); // 'deposit', 'withdrawal', 'caution_payment', 'credit_repayment', 'cycle_settlement', 'admin_adjustment'
            $table->string('status')->default('pending'); // 'pending', 'validated', 'rejected'
            $table->foreignId('initiated_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('initiated_at');
            $table->foreignId('validated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('validated_at')->nullable();
            $table->text('validator_note')->nullable();
            $table->string('entity_type')->default('individual');
            $table->unsignedBigInteger('entity_id');
            $table->decimal('amount', 15, 2);
            $table->text('description')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_validations');
    }
};
