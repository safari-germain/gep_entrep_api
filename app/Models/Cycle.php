<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cycle extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'entity_type',
        'entity_id',
        'user_id',
        'ordinary_account_id',
        'year',
        'start_date',
        'end_date',
        'recovery_date',
        'auto_deposit_day',
        'auto_deposit_next_date',
        'status',
        'is_blocked',
        'blocked_at',
        'blocked_by_user_id',
        'block_reason',
        'branch_id',
        'montant_cotisation_mensuelle',
        'montant_total_cycle',
        'total_collected',
        'total_deposited',
        'total_members',
        'closed_at',
        'opened_by_user_id',
        'closed_by_user_id',
        'notes',
    ];

    protected $casts = [
        'start_date'                   => 'date',
        'end_date'                     => 'date',
        'recovery_date'                => 'date',
        'auto_deposit_next_date'       => 'date',
        'closed_at'                    => 'datetime',
        'auto_deposit_day'             => 'integer',
        'is_blocked'                   => 'boolean',
        'blocked_at'                   => 'datetime',
        'montant_cotisation_mensuelle' => 'float',
        'montant_total_cycle'          => 'float',
        'total_collected'              => 'float',
        'total_deposited'              => 'float',
        'total_members'                => 'integer',
    ];

    protected $appends = [
        'target_name',
        'progress_pct',
        'remaining_to_deposit',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function individual(): BelongsTo
    {
        return $this->belongsTo(Individual::class, 'entity_id');
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'entity_id');
    }

    public function ordinaryAccount(): BelongsTo
    {
        return $this->belongsTo(OrdinaryAccount::class);
    }

    public function getTargetNameAttribute(): string
    {
        if ($this->entity_type === 'organisation') {
            $org = $this->organisation ?? Organisation::find($this->entity_id);
            return $org ? $org->name : "Organisation #{$this->entity_id}";
        }

        if ($this->entity_type === 'branch') {
            $branch = $this->branch ?? Branch::find($this->entity_id);
            return $branch ? $branch->name : "Succursale #{$this->entity_id}";
        }

        // individual
        $ind = $this->individual ?? Individual::with('user')->find($this->entity_id);
        if ($ind && $ind->user) {
            return $ind->user->name;
        }

        if ($this->user) {
            return $this->user->name;
        }

        return $this->name ?? "Cycle #{$this->id}";
    }

    public function getProgressPctAttribute(): float
    {
        $target = (float) ($this->montant_total_cycle ?: ($this->montant_cotisation_mensuelle * 12));
        if ($target <= 0) return 0.0;
        $deposited = (float) ($this->total_deposited ?: $this->total_collected);
        return round(min(100.0, ($deposited / $target) * 100), 1);
    }

    public function getRemainingToDepositAttribute(): float
    {
        $target = (float) ($this->montant_total_cycle ?: ($this->montant_cotisation_mensuelle * 12));
        $deposited = (float) ($this->total_deposited ?: $this->total_collected);
        return max(0.0, round($target - $deposited, 2));
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }
}
