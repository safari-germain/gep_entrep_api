<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Restructuring extends Model
{
    use HasFactory;

    protected $fillable = [
        'original_credit_id',
        'new_credit_id',
        'approved_by_operator_id',
        'branch_id',
        'reason',
        'old_remaining_balance',
        'new_principal_amount',
        'old_duration_months',
        'new_duration_months',
        'old_schedule_snapshot',
    ];

    protected $casts = [
        'old_remaining_balance' => 'float',
        'new_principal_amount' => 'float',
        'old_duration_months' => 'integer',
        'new_duration_months' => 'integer',
        'old_schedule_snapshot' => 'array',
    ];

    public function originalCredit(): BelongsTo
    {
        return $this->belongsTo(Credit::class, 'original_credit_id');
    }

    public function newCredit(): BelongsTo
    {
        return $this->belongsTo(Credit::class, 'new_credit_id');
    }

    public function approvedByOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_operator_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
