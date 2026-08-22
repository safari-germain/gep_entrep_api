<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    use HasFactory;

    protected $fillable = [
        'sponsor_user_id',
        'referee_user_id',
        'promo_code_used',
        'bonus_sponsor_amount',
        'bonus_referee_amount',
        'status',
    ];

    protected $casts = [
        'bonus_sponsor_amount' => 'float',
        'bonus_referee_amount' => 'float',
    ];

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sponsor_user_id');
    }

    public function referee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referee_user_id');
    }
}
