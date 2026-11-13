<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountingEntryLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'accounting_entry_id',
        'ohada_account_id',
        'direction',
        'amount',
        'auxiliary_type',
        'auxiliary_id',
        'narration',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    public function entry()
    {
        return $this->belongsTo(AccountingEntry::class, 'accounting_entry_id');
    }

    public function account()
    {
        return $this->belongsTo(OhadaAccount::class, 'ohada_account_id');
    }
}
