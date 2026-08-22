<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'credit_id', 'installment_number', 'due_date',
        'principal_due', 'interest_due', 'total_due',
        'amount_paid', 'penalty_due', 'paid_date', 'status',
    ];

    protected $casts = [
        'installment_number' => 'integer',
        'principal_due' => 'float',
        'interest_due' => 'float',
        'total_due' => 'float',
        'amount_paid' => 'float',
        'penalty_due' => 'float',
        'due_date' => 'date',
        'paid_date' => 'date',
    ];

    public function credit(): BelongsTo
    {
        return $this->belongsTo(Credit::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CreditPayment::class);
    }
}
