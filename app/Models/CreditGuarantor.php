<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditGuarantor extends Model
{
    use HasFactory;

    protected $fillable = [
        'credit_id',
        'guarantor_user_id',
        'organisation_id',
        'guarantor_type',
        'guarantor_role',
        'approval_status',
        'approved_at',
        'rejection_reason',
        'guaranteed_amount',
        'guaranteed_percentage',
        'interest_share_pct',
        'interest_earned',
        'interest_bonus_earned',
        'active_malus_amount',
        'coverage_called_amount',
        'coverage_settled_amount',
        'coverage_status',
        'notes',
    ];

    protected $casts = [
        'guaranteed_amount'       => 'float',
        'guaranteed_percentage'   => 'float',
        'interest_share_pct'      => 'float',
        'interest_earned'         => 'float',
        'interest_bonus_earned'   => 'float',
        'active_malus_amount'     => 'float',
        'coverage_called_amount'  => 'float',
        'coverage_settled_amount' => 'float',
        'approved_at'             => 'datetime',
    ];

    public function credit(): BelongsTo
    {
        return $this->belongsTo(Credit::class);
    }

    public function coverageCalls()
    {
        return $this->hasMany(CreditCoverageCall::class, 'credit_guarantor_id');
    }

    public function guarantorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guarantor_user_id');
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
