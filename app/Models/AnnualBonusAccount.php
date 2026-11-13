<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnnualBonusAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'fiscal_year',
        'total_bonuses_accumulated',
        'total_maluses_deducted',
        'net_bonus_balance',
        'disbursed_amount',
        'status',
        'disbursed_at',
        'ordinary_account_transaction_id',
    ];

    protected $casts = [
        'fiscal_year'               => 'integer',
        'total_bonuses_accumulated' => 'float',
        'total_maluses_deducted'    => 'float',
        'net_bonus_balance'         => 'float',
        'disbursed_amount'          => 'float',
        'disbursed_at'              => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function movements()
    {
        return $this->hasMany(AnnualBonusMovement::class, 'annual_bonus_account_id');
    }

    public function ordinaryTransaction()
    {
        return $this->belongsTo(OrdinaryAccountTransaction::class, 'ordinary_account_transaction_id');
    }
}
