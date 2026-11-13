<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnnualBonusMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'annual_bonus_account_id',
        'movement_type',
        'amount',
        'balance_before',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
    ];

    protected $casts = [
        'amount'         => 'float',
        'balance_before' => 'float',
        'balance_after'  => 'float',
    ];

    public function account()
    {
        return $this->belongsTo(AnnualBonusAccount::class, 'annual_bonus_account_id');
    }
}
