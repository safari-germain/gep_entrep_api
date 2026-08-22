<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Heir extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'full_name',
        'relationship',
        'phone',
        'national_id_number',
        'percentage',
        'status',
    ];

    protected $casts = [
        'percentage' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
