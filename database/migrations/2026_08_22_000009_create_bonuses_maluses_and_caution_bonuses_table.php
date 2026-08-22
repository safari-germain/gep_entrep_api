<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->string('bonus_type');
            $table->decimal('amount', 15, 2);
            $table->text('description')->nullable();
            $table->foreignId('granted_by_operator_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        Schema::create('maluses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->string('malus_type');
            $table->decimal('penalty_amount', 15, 2)->default(0.00);
            $table->decimal('trust_score_deduction', 5, 2)->default(0.00);
            $table->text('reason')->nullable();
            $table->foreignId('applied_by_operator_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        Schema::create('bonus_cautions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->decimal('granted_amount', 15, 2);
            $table->decimal('available_amount', 15, 2);
            $table->decimal('blocked_amount', 15, 2)->default(0.00);
            $table->decimal('used_amount', 15, 2)->default(0.00);
            $table->decimal('canceled_amount', 15, 2)->default(0.00);
            $table->string('reason_granted');
            $table->enum('status', ['available', 'blocked', 'partially_used', 'fully_used', 'canceled'])->default('available');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_cautions');
        Schema::dropIfExists('maluses');
        Schema::dropIfExists('bonuses');
    }
};
