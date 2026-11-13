<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bonuses', function (Blueprint $table) {
            if (!Schema::hasColumn('bonuses', 'deposit_id')) {
                $table->unsignedBigInteger('deposit_id')->nullable()->after('credit_id');
                $table->foreign('deposit_id')->references('id')->on('deposits')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bonuses', function (Blueprint $table) {
            if (Schema::hasColumn('bonuses', 'deposit_id')) {
                $table->dropForeign(['deposit_id']);
                $table->dropColumn('deposit_id');
            }
        });
    }
};
