<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensibilisateurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->string('code_promo')->unique();
            $table->integer('total_recruited')->default(0);
            $table->integer('active_recruited')->default(0);
            $table->decimal('repayment_rate_quality', 5, 2)->default(100.00);
            $table->decimal('total_bonus_earned', 15, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensibilisateurs');
    }
};
