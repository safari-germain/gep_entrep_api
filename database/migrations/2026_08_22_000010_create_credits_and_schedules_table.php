<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_number')->unique();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->decimal('requested_amount', 15, 2);
            $table->integer('requested_duration_months')->default(12);
            $table->decimal('eligible_caution_snapshot', 15, 2);
            $table->decimal('trust_score_snapshot', 5, 2);
            $table->decimal('max_allowed_credit_calculated', 15, 2);
            $table->text('purpose')->nullable();
            $table->enum('status', ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'disbursed', 'cancelled'])->default('submitted');
            $table->foreignId('reviewed_by_operator_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('review_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('credits', function (Blueprint $table) {
            $table->id();
            $table->string('credit_number')->unique();
            $table->foreignId('credit_application_id')->nullable()->constrained('credit_applications')->onDelete('set null');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('disbursed_by_operator_id')->constrained('users')->onDelete('cascade');
            $table->decimal('principal_amount', 15, 2);
            $table->decimal('interest_rate_percentage', 5, 2)->default(0.00);
            $table->decimal('total_interest_amount', 15, 2)->default(0.00);
            $table->decimal('total_amount_due', 15, 2);
            $table->decimal('total_amount_paid', 15, 2)->default(0.00);
            $table->decimal('remaining_balance', 15, 2);
            $table->integer('duration_months')->default(12);
            $table->date('disbursement_date');
            $table->date('first_due_date');
            $table->date('final_due_date');
            $table->decimal('caution_pledged_real', 15, 2)->default(0.00);
            $table->decimal('caution_pledged_bonus', 15, 2)->default(0.00);
            $table->enum('status', ['active', 'in_arrears', 'defaulted', 'restructured', 'paid_off', 'written_off'])->default('active');
            $table->timestamps();
        });

        Schema::create('credit_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_id')->constrained('credits')->onDelete('cascade');
            $table->integer('installment_number');
            $table->date('due_date');
            $table->decimal('principal_due', 15, 2);
            $table->decimal('interest_due', 15, 2)->default(0.00);
            $table->decimal('total_due', 15, 2);
            $table->decimal('amount_paid', 15, 2)->default(0.00);
            $table->decimal('penalty_due', 15, 2)->default(0.00);
            $table->date('paid_date')->nullable();
            $table->enum('status', ['pending', 'partially_paid', 'paid', 'overdue'])->default('pending');
            $table->timestamps();
        });

        Schema::create('credit_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_reference')->unique();
            $table->foreignId('credit_id')->constrained('credits')->onDelete('cascade');
            $table->foreignId('credit_schedule_id')->nullable()->constrained('credit_schedules')->onDelete('set null');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('operator_id')->constrained('users')->onDelete('cascade');
            $table->decimal('amount_paid', 15, 2);
            $table->decimal('principal_portion', 15, 2);
            $table->decimal('interest_portion', 15, 2)->default(0.00);
            $table->decimal('penalty_portion', 15, 2)->default(0.00);
            $table->string('payment_method')->default('cash');
            $table->string('external_reference')->nullable();
            $table->timestamps();
        });

        Schema::create('credit_guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_id')->constrained('credits')->onDelete('cascade');
            $table->foreignId('guarantor_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('organisation_id')->nullable()->constrained('organisations')->onDelete('set null');
            $table->string('guarantor_type');
            $table->decimal('guaranteed_amount', 15, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_guarantors');
        Schema::dropIfExists('credit_payments');
        Schema::dropIfExists('credit_schedules');
        Schema::dropIfExists('credits');
        Schema::dropIfExists('credit_applications');
    }
};
