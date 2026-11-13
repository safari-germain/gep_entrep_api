<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashReceipt extends Model
{
    use HasFactory;

    protected $table = 'cash_receipts';

    protected $fillable = [
        'receipt_number',
        'cashier_id',
        'branch_id',
        'member_id',
        'amount',
        'payment_method',
        'purpose',
        'status',
        'imputed_by_id',
        'imputed_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'imputed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function member()
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function imputedBy()
    {
        return $this->belongsTo(User::class, 'imputed_by_id');
    }
}
