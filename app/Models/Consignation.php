<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consignation extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'user_id',
        'cycle_id',
        'branch_id',
        'amount',
        'initial_caution_amount',
        'bonus_applied',
        'malus_deducted',
        'status', // 'active', 'reconduit', 'libere', 'retire'
        'consignation_date',
        'release_date',
        'released_to_account_id',
        'rollover_cycle_id',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'amount'                 => 'float',
        'initial_caution_amount' => 'float',
        'bonus_applied'          => 'float',
        'malus_deducted'         => 'float',
        'consignation_date'      => 'datetime',
        'release_date'           => 'datetime',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
