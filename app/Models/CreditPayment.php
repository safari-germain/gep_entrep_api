<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_reference', 'credit_id', 'credit_schedule_id',
        'user_id', 'branch_id', 'operator_id',
        'amount_paid', 'principal_portion', 'interest_portion',
        'penalty_portion', 'payment_method', 'external_reference',
    ];

    protected $casts = [
        'amount_paid' => 'float',
        'principal_portion' => 'float',
        'interest_portion' => 'float',
        'penalty_portion' => 'float',
    ];

    public function credit(): BelongsTo { return $this->belongsTo(Credit::class); }
    public function schedule(): BelongsTo { return $this->belongsTo(CreditSchedule::class, 'credit_schedule_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function operator(): BelongsTo { return $this->belongsTo(User::class, 'operator_id'); }
}
