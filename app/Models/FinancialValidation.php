<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialValidation extends Model
{
    use HasFactory;

    protected $table = 'financial_validations';

    protected $fillable = [
        'reference',
        'operation_type',
        'status',
        'initiated_by',
        'initiated_at',
        'validated_by',
        'validated_at',
        'validator_note',
        'entity_type',
        'entity_id',
        'amount',
        'description',
        'payload',
    ];

    protected $casts = [
        'amount'       => 'float',
        'initiated_at' => 'datetime',
        'validated_at' => 'datetime',
        'payload'      => 'array',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}
