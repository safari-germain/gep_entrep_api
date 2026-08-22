<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_id')->constrained('credits')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('operator_id')->constrained('users')->onDelete('cascade');
            $table->string('action_type');
            $table->text('notes');
            $table->date('next_followup_date')->nullable();
            $table->enum('status', ['open', 'in_progress', 'resolved', 'escalated'])->default('open');
            $table->timestamps();
        });

        Schema::create('restructurings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('original_credit_id')->constrained('credits')->onDelete('cascade');
            $table->foreignId('new_credit_id')->nullable()->constrained('credits')->onDelete('set null');
            $table->foreignId('approved_by_operator_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->text('reason');
            $table->decimal('old_remaining_balance', 15, 2);
            $table->decimal('new_principal_amount', 15, 2);
            $table->integer('old_duration_months');
            $table->integer('new_duration_months');
            $table->json('old_schedule_snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restructurings');
        Schema::dropIfExists('collections');
    }
};
