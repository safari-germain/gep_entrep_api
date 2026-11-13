<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountingEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'entry_number',
        'entry_date',
        'period',
        'source_type',
        'source_id',
        'branch_id',
        'label',
        'total_debit',
        'total_credit',
        'status',
        'created_by',
    ];

    protected $casts = [
        'entry_date'   => 'date',
        'total_debit'  => 'float',
        'total_credit' => 'float',
    ];

    public function lines()
    {
        return $this->hasMany(AccountingEntryLine::class, 'accounting_entry_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
