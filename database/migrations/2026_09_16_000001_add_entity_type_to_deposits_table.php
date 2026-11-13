<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('entity_type')->default('individual')->after('deposit_reference'); // 'individual', 'branch', 'organisation'
            $table->unsignedBigInteger('target_branch_id')->nullable()->after('branch_id');
            $table->unsignedBigInteger('organisation_id')->nullable()->after('target_branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropColumn(['entity_type', 'target_branch_id', 'organisation_id']);
        });
    }
};
