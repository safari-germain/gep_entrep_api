<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CreditApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_number',
        'user_id',
        'branch_id',
        'requested_amount',
        'requested_duration_months',
        'eligible_caution_snapshot',
        'trust_score_snapshot',
        'max_allowed_credit_calculated',
        'purpose',
        'status',
        'reviewed_by_operator_id',
        'review_notes',
    ];

    protected $casts = [
        'requested_amount' => 'float',
        'eligible_caution_snapshot' => 'float',
        'trust_score_snapshot' => 'float',
        'max_allowed_credit_calculated' => 'float',
        'requested_duration_months' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function reviewedByOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_operator_id');
    }

    public function credit(): HasOne
    {
        return $this->hasOne(Credit::class);
    }
}
