<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organisation extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'code',
        'phone',
        'email',
        'address',
        'representative_name',
        'representative_phone',
        'branch_id',
        'notes',
        'status',
        'caution_balance',
        'general_balance',
        'bonus_balance',
        'credit_balance',
        'is_active',
    ];

    protected $casts = [
        'branch_id'       => 'integer',
        'caution_balance' => 'float',
        'general_balance' => 'float',
        'bonus_balance'   => 'float',
        'credit_balance'  => 'float',
        'is_active'       => 'boolean',
    ];

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function individuals(): HasMany
    {
        return $this->hasMany(Individual::class);
    }

    public function sensibilisateur(): HasOne
    {
        return $this->hasOne(Sensibilisateur::class, 'organisation_id');
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    public function credits(): HasMany
    {
        return $this->hasMany(Credit::class, 'target_organisation_id');
    }

    public function bonuses(): HasMany
    {
        return $this->hasMany(Bonus::class);
    }

    public function maluses(): HasMany
    {
        return $this->hasMany(Malus::class);
    }
}
