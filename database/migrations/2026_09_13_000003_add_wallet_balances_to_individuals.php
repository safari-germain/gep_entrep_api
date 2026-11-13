<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('individuals', function (Blueprint $table) {
            $table->decimal('caution_balance', 15, 2)->default(0.00)->after('status');
            $table->decimal('bonus_caution', 15, 2)->default(0.00)->after('caution_balance');
            $table->decimal('general_balance', 15, 2)->default(0.00)->after('bonus_caution');
            $table->decimal('bonus_earned', 15, 2)->default(0.00)->after('general_balance');
            $table->decimal('total_malus', 15, 2)->default(0.00)->after('bonus_earned');
        });
    }

    public function down(): void
    {
        Schema::table('individuals', function (Blueprint $table) {
            $table->dropColumn(['caution_balance', 'bonus_caution', 'general_balance', 'bonus_earned', 'total_malus']);
        });
    }
};