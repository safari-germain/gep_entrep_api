<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sensibilisateur extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'branch_id',
        'code_promo',
        'total_recruited',
        'active_recruited',
        'repayment_rate_quality',
        'total_bonus_earned',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'repayment_rate_quality' => 'float',
        'total_bonus_earned' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function referredIndividuals(): HasMany
    {
        return $this->hasMany(Individual::class, 'sensibilisateur_id');
    }
}
