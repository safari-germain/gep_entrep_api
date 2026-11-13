<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Malus extends Model
{
    use HasFactory;

    protected $table = 'maluses';

    protected $fillable = [
        'user_id',
        'branch_id',
        'credit_id',
        'deposit_id',
        'organisation_id',
        'malus_type', // 'retard_cotisation', 'retard_emprunt', 'penalite_emprunt', 'parrainage_impaye'
        'penalty_amount',
        'guaranteed_debt_share',
        'trust_score_deduction',
        'reason',
        'is_active',
        'applied_by_operator_id',
    ];

    protected $casts = [
        'penalty_amount'        => 'float',
        'guaranteed_debt_share' => 'float',
        'trust_score_deduction' => 'float',
        'is_active'             => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(Credit::class);
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function appliedByOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_operator_id');
    }
}
