<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrdinaryAccountTransaction extends Model
{
    use HasFactory;

    protected $table = 'ordinary_account_transactions';

    protected $fillable = [
        'ordinary_account_id',
        'transaction_type',
        'direction',
        'amount',
        'balance_before',
        'balance_after',
        'description',
        'reference',
        'payment_method',
        'operator_id',
        'is_funded_by_overdraft',
        'overdraft_months_count',
        'operation_nature',
    ];

    protected $casts = [
        'amount'                 => 'float',
        'balance_before'         => 'float',
        'balance_after'          => 'float',
        'is_funded_by_overdraft' => 'boolean',
        'overdraft_months_count' => 'integer',
        'created_at'             => 'datetime',
        'updated_at'     => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(OrdinaryAccount::class, 'ordinary_account_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
