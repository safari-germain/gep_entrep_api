<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('organisations')) {
            Schema::create('organisations', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('type')->default('association');
                $table->string('code')->nullable()->unique();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->text('address')->nullable();
                $table->string('representative_name')->nullable();
                $table->string('representative_phone')->nullable();
                $table->text('notes')->nullable();
                $table->string('status')->default('active');
                $table->decimal('caution_balance', 15, 2)->default(0.00);
                $table->decimal('general_balance', 15, 2)->default(0.00);
                $table->decimal('bonus_balance', 15, 2)->default(0.00);
                $table->decimal('credit_balance', 15, 2)->default(0.00);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('prenom')->nullable();
                $table->string('email')->unique()->nullable();
                $table->string('phone')->nullable();
                $table->string('profile')->nullable();
                $table->string('password')->default(bcrypt('password'));
                $table->string('adresse')->nullable();
                $table->string('marketeur_code')->nullable()->unique();
                $table->string('genre')->default('M');
                $table->enum('status', ['pending', 'confirm', 'actif', 'inactif'])->default('actif');
                $table->enum('role', ['user', 'volontaire', 'marketeur', 'org_admin', 'super_admin'])->default('user');
                $table->foreignId('organisation_id')->nullable()->constrained('organisations')->onDelete('set null');
                $table->string('profile_level')->nullable();
                $table->string('otp_code')->nullable();
                $table->dateTime('otp_expires_at')->nullable();
                $table->timestamp('email_verified_at')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('organisations');
    }
};
