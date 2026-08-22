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
        'malus_type',
        'penalty_amount',
        'trust_score_deduction',
        'reason',
        'applied_by_operator_id',
    ];

    protected $casts = [
        'penalty_amount' => 'float',
        'trust_score_deduction' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function appliedByOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_operator_id');
    }
}
