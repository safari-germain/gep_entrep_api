<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credits', function (Blueprint $table) {
            if (!Schema::hasColumn('credits', 'credit_type')) {
                $table->enum('credit_type', ['solidaire', 'express', 'special'])->default('solidaire')->after('credit_number');
            }
            if (!Schema::hasColumn('credits', 'approval_status')) {
                $table->enum('approval_status', [
                    'draft',
                    'pending_guarantors',
                    'pending_org',
                    'pending_financier',
                    'approved',
                    'rejected',
                    'disbursed'
                ])->default('draft')->after('credit_type');
            }
            if (!Schema::hasColumn('credits', 'financier_user_id')) {
                $table->unsignedBigInteger('financier_user_id')->nullable()->after('approval_status');
            }
            if (!Schema::hasColumn('credits', 'financier_approved_at')) {
                $table->timestamp('financier_approved_at')->nullable()->after('financier_user_id');
            }
            if (!Schema::hasColumn('credits', 'financier_notes')) {
                $table->text('financier_notes')->nullable()->after('financier_approved_at');
            }
            if (!Schema::hasColumn('credits', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('financier_notes');
            }
            if (!Schema::hasColumn('credits', 'overdraft_grace_days')) {
                $table->unsignedInteger('overdraft_grace_days')->default(3)->after('rejection_reason');
            }
            if (!Schema::hasColumn('credits', 'overdraft_penalty_pct')) {
                $table->decimal('overdraft_penalty_pct', 5, 2)->default(1.00)->after('overdraft_grace_days');
            }
            if (!Schema::hasColumn('credits', 'penalties_waived')) {
                $table->decimal('penalties_waived', 15, 2)->default(0.00)->after('overdraft_penalty_pct');
            }
            if (!Schema::hasColumn('credits', 'penalties_waived_reason')) {
                $table->text('penalties_waived_reason')->nullable()->after('penalties_waived');
            }
            if (!Schema::hasColumn('credits', 'penalties_waived_by')) {
                $table->unsignedBigInteger('penalties_waived_by')->nullable()->after('penalties_waived_reason');
            }
            if (!Schema::hasColumn('credits', 'capital_written_off')) {
                $table->boolean('capital_written_off')->default(false)->after('penalties_waived_by');
            }
            if (!Schema::hasColumn('credits', 'written_off_by')) {
                $table->unsignedBigInteger('written_off_by')->nullable()->after('capital_written_off');
            }
            if (!Schema::hasColumn('credits', 'written_off_at')) {
                $table->timestamp('written_off_at')->nullable()->after('written_off_by');
            }
            if (!Schema::hasColumn('credits', 'written_off_reason')) {
                $table->text('written_off_reason')->nullable()->after('written_off_at');
            }
        });

        Schema::table('credit_guarantors', function (Blueprint $table) {
            if (!Schema::hasColumn('credit_guarantors', 'guaranteed_percentage')) {
                $table->decimal('guaranteed_percentage', 5, 2)->default(0.00)->after('guaranteed_amount');
            }
            if (!Schema::hasColumn('credit_guarantors', 'guarantor_role')) {
                $table->enum('guarantor_role', ['peer', 'org_default', 'centre_gep'])->default('peer')->after('guarantor_type');
            }
            if (!Schema::hasColumn('credit_guarantors', 'approval_status')) {
                $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending')->after('guarantor_role');
            }
            if (!Schema::hasColumn('credit_guarantors', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approval_status');
            }
            if (!Schema::hasColumn('credit_guarantors', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('approved_at');
            }
            if (!Schema::hasColumn('credit_guarantors', 'interest_share_pct')) {
                $table->decimal('interest_share_pct', 5, 2)->default(0.00)->after('rejection_reason');
            }
            if (!Schema::hasColumn('credit_guarantors', 'interest_earned')) {
                $table->decimal('interest_earned', 15, 2)->default(0.00)->after('interest_share_pct');
            }
        });
    }

    public function down(): void
    {
        Schema::table('credits', function (Blueprint $table) {
            $cols = [
                'credit_type', 'approval_status', 'financier_user_id', 'financier_approved_at',
                'financier_notes', 'rejection_reason', 'overdraft_grace_days', 'overdraft_penalty_pct',
                'penalties_waived', 'penalties_waived_reason', 'penalties_waived_by',
                'capital_written_off', 'written_off_by', 'written_off_at', 'written_off_reason'
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('credits', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('credit_guarantors', function (Blueprint $table) {
            $cols = [
                'guaranteed_percentage', 'guarantor_role', 'approval_status',
                'approved_at', 'rejection_reason', 'interest_share_pct', 'interest_earned'
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('credit_guarantors', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
