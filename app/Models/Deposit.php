<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deposit extends Model
{
    use HasFactory;

    protected $fillable = [
        'deposit_reference',
        'entity_type',
        'user_id',
        'branch_id',
        'target_branch_id',
        'organisation_id',
        'operator_id',
        'expected_amount',
        'actual_amount',
        'expected_date',
        'actual_date',
        'delay_days',
        'wallet_destination',
        'cycle_id',
        'payment_method',
        'payment_reference',
        'is_on_time',
        'remarks',
    ];

    protected $casts = [
        'expected_amount' => 'float',
        'actual_amount' => 'float',
        'delay_days' => 'integer',
        'is_on_time' => 'boolean',
        'expected_date' => 'date',
        'actual_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function targetBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'target_branch_id');
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}

