<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OhadaAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_number',
        'label',
        'category',
        'ohada_class',
        'parent_id',
        'is_reconcilable',
        'is_active',
    ];

    protected $casts = [
        'ohada_class'     => 'integer',
        'is_reconcilable' => 'boolean',
        'is_active'       => 'boolean',
    ];

    public function parent()
    {
        return $this->belongsTo(OhadaAccount::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(OhadaAccount::class, 'parent_id');
    }

    public function entryLines()
    {
        return $this->hasMany(AccountingEntryLine::class, 'ohada_account_id');
    }
}
