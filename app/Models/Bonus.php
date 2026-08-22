<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bonus extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'branch_id',
        'bonus_type',
        'amount',
        'description',
        'granted_by_operator_id',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function grantedByOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_operator_id');
    }
}
