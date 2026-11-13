<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreditCoverageCall extends Model
{
    use HasFactory;

    protected $fillable = [
        'credit_id',
        'credit_guarantor_id',
        'guarantor_user_id',
        'organisation_id',
        'called_at',
        'credit_remaining_balance_at_call',
        'guaranteed_percentage',
        'liability_amount',
        'settled_amount',
        'remaining_liability',
        'status',
        'notes',
    ];

    protected $casts = [
        'called_at'                         => 'datetime',
        'credit_remaining_balance_at_call' => 'float',
        'guaranteed_percentage'            => 'float',
        'liability_amount'                 => 'float',
        'settled_amount'                   => 'float',
        'remaining_liability'              => 'float',
    ];

    public function credit()
    {
        return $this->belongsTo(Credit::class, 'credit_id');
    }

    public function guarantor()
    {
        return $this->belongsTo(CreditGuarantor::class, 'credit_guarantor_id');
    }

    public function guarantorUser()
    {
        return $this->belongsTo(User::class, 'guarantor_user_id');
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class, 'organisation_id');
    }

    public function settlements()
    {
        return $this->hasMany(CreditCoverageSettlement::class, 'credit_coverage_call_id');
    }
}
