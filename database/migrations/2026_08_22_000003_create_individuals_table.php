<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('individuals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->unsignedBigInteger('organisation_id')->nullable();
            $table->unsignedBigInteger('sponsor_user_id')->nullable();
            $table->unsignedBigInteger('sensibilisateur_id')->nullable();
            $table->string('member_code')->unique();
            $table->string('national_id_number')->nullable();
            $table->string('profession')->nullable();
            $table->string('activity_sector')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->string('emergency_contact_relation')->nullable();
            $table->decimal('trust_score', 5, 2)->default(100.00);
            $table->integer('deposit_streak_months')->default(0);
            $table->enum('status', ['pending', 'active', 'suspended', 'closed'])->default('active');
            $table->timestamps();

            $table->foreign('sponsor_user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('individuals');
    }
};
