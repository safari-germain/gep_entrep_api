<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BonusCaution extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'branch_id',
        'granted_amount',
        'available_amount',
        'blocked_amount',
        'used_amount',
        'canceled_amount',
        'reason_granted',
        'status',
    ];

    protected $casts = [
        'granted_amount' => 'float',
        'available_amount' => 'float',
        'blocked_amount' => 'float',
        'used_amount' => 'float',
        'canceled_amount' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
