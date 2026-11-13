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
        'entity_type', // 'user', 'organisation', 'branch'
        'user_id',
        'organisation_id',
        'branch_id',
        'name',
        'code_promo',
        'total_recruited',
        'active_recruited',
        'repayment_rate_quality',
        'total_bonus_earned',
        'bonus_pending_year_end',
        'caution_balance',
        'general_balance',
        'bonus_balance',
        'is_active',
    ];

    protected $casts = [
        'is_active'               => 'boolean',
        'repayment_rate_quality'  => 'float',
        'total_bonus_earned'      => 'float',
        'bonus_pending_year_end'  => 'float',
        'caution_balance'         => 'float',
        'general_balance'         => 'float',
        'bonus_balance'           => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function referredIndividuals(): HasMany
    {
        return $this->hasMany(Individual::class, 'sensibilisateur_id');
    }

    public function bonuses(): HasMany
    {
        return $this->hasMany(Bonus::class, 'sensibilisateur_id');
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->name) {
            return $this->name;
        }
        if ($this->entity_type === 'user' && $this->user) {
            return $this->user->name . ' ' . ($this->user->prenom ?? '');
        }
        if ($this->entity_type === 'organisation' && $this->organisation) {
            return $this->organisation->name;
        }
        if ($this->entity_type === 'branch' && $this->branch) {
            return 'Succursale: ' . $this->branch->name;
        }
        return 'Sensibilisateur #' . $this->id;
    }
}
