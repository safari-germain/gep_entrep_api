<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrdinaryAccountOverdraftCycle extends Model
{
    use HasFactory;

    protected $fillable = [
        'ordinary_account_id',
        'started_at',
        'resolved_at',
        'consecutive_months_count',
        'peak_negative_balance',
        'total_interest_charged',
        'credit_payments_count_under_overdraft',
        'caution_payments_count_under_overdraft',
        'status',
    ];

    protected $casts = [
        'started_at'                             => 'datetime',
        'resolved_at'                            => 'datetime',
        'consecutive_months_count'               => 'integer',
        'peak_negative_balance'                  => 'float',
        'total_interest_charged'                 => 'float',
        'credit_payments_count_under_overdraft'  => 'integer',
        'caution_payments_count_under_overdraft' => 'integer',
    ];

    public function account()
    {
        return $this->belongsTo(OrdinaryAccount::class, 'ordinary_account_id');
    }
}
