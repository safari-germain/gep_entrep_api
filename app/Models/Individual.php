<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Individual extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'branch_id',
        'organisation_id',
        'sponsor_user_id',
        'sensibilisateur_id',
        'member_code',
        'national_id_number',
        'profession',
        'activity_sector',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relation',
        'trust_score',
        'deposit_streak_months',
        'status',
    ];

    protected $casts = [
        'trust_score' => 'float',
        'deposit_streak_months' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sponsor_user_id');
    }

    public function sensibilisateur(): BelongsTo
    {
        return $this->belongsTo(Sensibilisateur::class);
    }

    public function heirs(): HasMany
    {
        return $this->hasMany(Heir::class, 'user_id', 'user_id');
    }
}
