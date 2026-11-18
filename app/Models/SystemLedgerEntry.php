<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemLedgerEntry extends Model
{
    use HasFactory;

    protected $table = 'system_ledger_entries';

    protected $fillable = [
        'type',
        'source',
        'category',
        'title',
        'description',
        'amount',
        'payment_method',
        'reference_type',
        'reference_id',
        'status',
        'created_by',
        'validated_by',
        'validated_at',
        'cycle_id',
        'branch_id',
        'ohada_account_id',
        'rejection_reason',
    ];

    protected $casts = [
        'amount' => 'float',
        'validated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function cycle()
    {
        return $this->belongsTo(Cycle::class, 'cycle_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function ohadaAccount()
    {
        return $this->belongsTo(OhadaAccount::class, 'ohada_account_id');
    }
}
