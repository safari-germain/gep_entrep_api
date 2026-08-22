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
        'credit_application_id',
        'user_id',
        'branch_id',
        'disbursed_by_operator_id',
        'principal_amount',
        'interest_rate_percentage',
        'total_interest_amount',
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
    ];

    protected $casts = [
        'principal_amount' => 'float',
        'interest_rate_percentage' => 'float',
        'total_interest_amount' => 'float',
        'total_amount_due' => 'float',
        'total_amount_paid' => 'float',
        'remaining_balance' => 'float',
        'caution_pledged_real' => 'float',
        'caution_pledged_bonus' => 'float',
        'duration_months' => 'integer',
        'disbursement_date' => 'date',
        'first_due_date' => 'date',
        'final_due_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function disbursedByOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by_operator_id');
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

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }
}
