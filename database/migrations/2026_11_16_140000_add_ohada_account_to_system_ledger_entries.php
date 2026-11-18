<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('system_ledger_entries')) {
            Schema::table('system_ledger_entries', function (Blueprint $table) {
                if (!Schema::hasColumn('system_ledger_entries', 'ohada_account_id')) {
                    $table->unsignedBigInteger('ohada_account_id')->nullable()->after('branch_id');
                    $table->foreign('ohada_account_id')->references('id')->on('ohada_accounts')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('system_ledger_entries')) {
            Schema::table('system_ledger_entries', function (Blueprint $table) {
                if (Schema::hasColumn('system_ledger_entries', 'ohada_account_id')) {
                    $table->dropForeign(['ohada_account_id']);
                    $table->dropColumn('ohada_account_id');
                }
            });
        }
    }
};
