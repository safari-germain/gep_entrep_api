<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditGuarantor extends Model
{
    use HasFactory;

    protected $fillable = [
        'credit_id', 'guarantor_user_id', 'organisation_id',
        'guarantor_type', 'guaranteed_amount', 'notes',
    ];

    protected $casts = ['guaranteed_amount' => 'float'];

    public function credit(): BelongsTo { return $this->belongsTo(Credit::class); }
    public function guarantorUser(): BelongsTo { return $this->belongsTo(User::class, 'guarantor_user_id'); }
    public function organisation(): BelongsTo { return $this->belongsTo(Organisation::class); }
}
