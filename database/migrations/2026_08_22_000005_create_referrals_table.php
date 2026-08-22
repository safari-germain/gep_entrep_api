<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sponsor_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('referee_user_id')->constrained('users')->onDelete('cascade');
            $table->string('promo_code_used')->nullable();
            $table->decimal('bonus_sponsor_amount', 15, 2)->default(0.00);
            $table->decimal('bonus_referee_amount', 15, 2)->default(0.00);
            $table->enum('status', ['pending', 'validated', 'rejected'])->default('validated');
            $table->timestamps();

            $table->unique(['sponsor_user_id', 'referee_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
