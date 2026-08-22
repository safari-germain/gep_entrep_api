<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MicrofinanceLedgerTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'user_id',
        'operator_id',
        'branch_id',
        'source_wallet_type',
        'destination_wallet_type',
        'amount',
        'currency',
        'operation_type',
        'payment_method',
        'payment_reference',
        'status',
        'description',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'float',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
