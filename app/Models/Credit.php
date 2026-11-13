<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Credit extends Model
{
    use HasFactory;

    protected $fillable = [
        'credit_number',
        'credit_type',
        'approval_status',
        'financier_user_id',
        'financier_approved_at',
        'financier_notes',
        'rejection_reason',
        'overdraft_grace_days',
        'overdraft_penalty_pct',
        'penalties_waived',
        'penalties_waived_reason',
        'penalties_waived_by',
        'capital_written_off',
        'written_off_by',
        'written_off_at',
        'written_off_reason',
        'credit_application_id',
        'beneficiary_type',
        'target_branch_id',
        'target_organisation_id',
        'user_id',
        'branch_id',
        'disbursed_by_operator_id',
        'assigned_collector_id',
        'assigned_collector_type',
        'principal_amount',
        'interest_rate_percentage',
        'total_interest_amount',
        'center_interest_amount',
        'sponsors_interest_amount',
        'total_amount_due',
        'total_amount_paid',
        'remaining_balance',
        'duration_months',
        'disbursement_date',
        'first_due_date',
        'final_due_date',
        'caution_pledged_real',
        'caution_pledged_bonus',
        'status',
        'credit_stars',
        'has_red_dot',
        'defaulted_at',
        'coverage_triggered_at',
        'coverage_status',
    ];

    protected $casts = [
        'defaulted_at'             => 'datetime',
        'coverage_triggered_at'    => 'datetime',
        'principal_amount'         => 'float',
        'interest_rate_percentage' => 'float',
        'total_interest_amount'    => 'float',
        'center_interest_amount'   => 'float',
        'sponsors_interest_amount' => 'float',
        'total_amount_due'         => 'float',
        'total_amount_paid'        => 'float',
        'remaining_balance'        => 'float',
        'caution_pledged_real'     => 'float',
        'caution_pledged_bonus'    => 'float',
        'credit_stars'             => 'float',
        'has_red_dot'              => 'boolean',
        'duration_months'          => 'integer',
        'overdraft_grace_days'     => 'integer',
        'overdraft_penalty_pct'    => 'float',
        'penalties_waived'         => 'float',
        'capital_written_off'      => 'boolean',
        'disbursement_date'        => 'date',
        'first_due_date'           => 'date',
        'final_due_date'           => 'date',
        'financier_approved_at'    => 'datetime',
        'written_off_at'           => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targetBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'target_branch_id');
    }

    public function targetOrganisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'target_organisation_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }


    public function disbursedByOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by_operator_id');
    }

    public function assignedCollector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_collector_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(CreditApplication::class, 'credit_application_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(CreditSchedule::class)->orderBy('installment_number');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CreditPayment::class);
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(CreditGuarantor::class);
    }

    public function financier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'financier_user_id');
    }

    public function coverageCalls(): HasMany
    {
        return $this->hasMany(CreditCoverageCall::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }
}
