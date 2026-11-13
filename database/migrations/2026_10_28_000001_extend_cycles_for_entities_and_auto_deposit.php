<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cycles', function (Blueprint $table) {
            if (!Schema::hasColumn('cycles', 'entity_type')) {
                $table->string('entity_type')->default('individual')->after('name');
            }
            if (!Schema::hasColumn('cycles', 'entity_id')) {
                $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
            }
            if (!Schema::hasColumn('cycles', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('entity_id');
            }
            if (!Schema::hasColumn('cycles', 'auto_deposit_day')) {
                $table->unsignedTinyInteger('auto_deposit_day')->default(1)->after('branch_id');
            }
            if (!Schema::hasColumn('cycles', 'auto_deposit_next_date')) {
                $table->date('auto_deposit_next_date')->nullable()->after('auto_deposit_day');
            }
            if (!Schema::hasColumn('cycles', 'montant_total_cycle')) {
                $table->decimal('montant_total_cycle', 15, 2)->default(600.00)->after('montant_cotisation_mensuelle');
            }
            if (!Schema::hasColumn('cycles', 'recovery_date')) {
                $table->date('recovery_date')->nullable()->after('end_date');
            }
            if (!Schema::hasColumn('cycles', 'total_deposited')) {
                $table->decimal('total_deposited', 15, 2)->default(0.00)->after('montant_total_cycle');
            }
            if (!Schema::hasColumn('cycles', 'ordinary_account_id')) {
                $table->unsignedBigInteger('ordinary_account_id')->nullable()->after('user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cycles', function (Blueprint $table) {
            $cols = [
                'entity_type', 'entity_id', 'user_id', 'auto_deposit_day',
                'auto_deposit_next_date', 'montant_total_cycle', 'recovery_date',
                'total_deposited', 'ordinary_account_id'
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('cycles', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
