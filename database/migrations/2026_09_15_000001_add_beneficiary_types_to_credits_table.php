<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credits', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('beneficiary_type')->default('individual')->after('credit_number'); // 'individual', 'branch', 'organisation'
            $table->unsignedBigInteger('target_branch_id')->nullable()->after('beneficiary_type');
            $table->unsignedBigInteger('target_organisation_id')->nullable()->after('target_branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('credits', function (Blueprint $table) {
            $table->dropColumn(['beneficiary_type', 'target_branch_id', 'target_organisation_id']);
        });
    }
};
