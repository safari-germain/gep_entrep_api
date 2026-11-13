<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Ajouter les colonnes de blocage sur cycles si pas encore présentes
        Schema::table('cycles', function (Blueprint $table) {
            if (!Schema::hasColumn('cycles', 'is_blocked')) {
                $table->boolean('is_blocked')->default(false)->after('status');
            }
            if (!Schema::hasColumn('cycles', 'blocked_at')) {
                $table->timestamp('blocked_at')->nullable()->after('is_blocked');
            }
            if (!Schema::hasColumn('cycles', 'blocked_by_user_id')) {
                $table->foreignId('blocked_by_user_id')->nullable()->constrained('users')->onDelete('set null')->after('blocked_at');
            }
            if (!Schema::hasColumn('cycles', 'block_reason')) {
                $table->text('block_reason')->nullable()->after('blocked_by_user_id');
            }
        });

        // 2. Créer la table des consignations de caution
        if (!Schema::hasTable('consignations')) {
            Schema::create('consignations', function (Blueprint $table) {
                $table->id();
                $table->string('reference')->unique();
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('cycle_id')->nullable()->constrained('cycles')->onDelete('set null');
                $table->foreignId('branch_id')->nullable()->constrained('branches')->onDelete('set null');
                $table->decimal('amount', 15, 2);
                $table->decimal('initial_caution_amount', 15, 2)->default(0);
                $table->decimal('bonus_applied', 15, 2)->default(0);
                $table->decimal('malus_deducted', 15, 2)->default(0);
                $table->string('status')->default('active'); // 'active', 'reconduit', 'libere', 'retire'
                $table->timestamp('consignation_date')->useCurrent();
                $table->timestamp('release_date')->nullable();
                $table->unsignedBigInteger('released_to_account_id')->nullable();
                $table->unsignedBigInteger('rollover_cycle_id')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->onDelete('set null');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consignations');

        Schema::table('cycles', function (Blueprint $table) {
            if (Schema::hasColumn('cycles', 'block_reason')) {
                $table->dropColumn('block_reason');
            }
            if (Schema::hasColumn('cycles', 'blocked_by_user_id')) {
                $table->dropForeign(['blocked_by_user_id']);
                $table->dropColumn('blocked_by_user_id');
            }
            if (Schema::hasColumn('cycles', 'blocked_at')) {
                $table->dropColumn('blocked_at');
            }
            if (Schema::hasColumn('cycles', 'is_blocked')) {
                $table->dropColumn('is_blocked');
            }
        });
    }
};
