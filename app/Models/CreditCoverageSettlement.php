<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreditCoverageSettlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'credit_coverage_call_id',
        'settlement_source',
        'amount',
        'ordinary_account_transaction_id',
        'processed_by_user_id',
        'description',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    public function coverageCall()
    {
        return $this->belongsTo(CreditCoverageCall::class, 'credit_coverage_call_id');
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }

    public function ordinaryTransaction()
    {
        return $this->belongsTo(OrdinaryAccountTransaction::class, 'ordinary_account_transaction_id');
    }
}
