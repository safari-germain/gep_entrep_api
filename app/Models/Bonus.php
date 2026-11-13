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
        'sensibilisateur_id',
        'organisation_id',
        'credit_id',
        'deposit_id',
        'bonus_type', // 'mobilisation', 'parrainage', 'epargne_interet', 'recouvrement_penalite', 'caution_yield_bonus'
        'amount',
        'held_for_annual_payout',
        'annual_bonus_account_id',
        'tranche', // 'versement_2_50', 'cloture_50', 'complet'
        'description',
        'granted_by_operator_id',
    ];

    protected $casts = [
        'amount'                 => 'float',
        'held_for_annual_payout' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sensibilisateur(): BelongsTo
    {
        return $this->belongsTo(Sensibilisateur::class);
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(Credit::class);
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    public function grantedByOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_operator_id');
    }
}
