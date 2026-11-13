<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Individual extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'branch_id',
        'organisation_id',
        'sponsor_user_id',
        'sensibilisateur_id',
        'member_code',
        'national_id_number',
        'profession',
        'activity_sector',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relation',
        'trust_score',
        'cotisation_stars',
        'credit_stars',
        'has_red_dot',
        'deposit_streak_months',
        'late_deposits_count',
        'late_credits_count',
        'status',
    ];

    protected $casts = [
        'trust_score'           => 'float',
        'cotisation_stars'      => 'float',
        'credit_stars'          => 'float',
        'has_red_dot'           => 'boolean',
        'deposit_streak_months' => 'integer',
        'late_deposits_count'   => 'integer',
        'late_credits_count'    => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sponsor_user_id');
    }

    public function sensibilisateur(): BelongsTo
    {
        return $this->belongsTo(Sensibilisateur::class);
    }

    public function heirs(): HasMany
    {
        return $this->hasMany(Heir::class, 'user_id', 'user_id');
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class, 'user_id', 'user_id');
    }

    public function credits(): HasMany
    {
        return $this->hasMany(Credit::class, 'user_id', 'user_id');
    }

    public function recalculateStarsAndRedDot(): void
    {
        // 1. Cotisation Stars: 3 stars base over 12 months
        $lateDeposits = Deposit::where('user_id', $this->user_id)
            ->where('is_on_time', false)
            ->count();
        $this->late_deposits_count = $lateDeposits;
        $this->cotisation_stars = max(0.0, round(3.0 - ($lateDeposits / 12.0) * 3.0, 2));

        // 2. Credit Stars & Overdues
        $credits = Credit::where('user_id', $this->user_id)->get();
        $overdueCount = 0;
        $activePenalty = false;

        foreach ($credits as $credit) {
            $overdueSchedules = CreditSchedule::where('credit_id', $credit->id)
                ->where('status', 'overdue')
                ->count();
            if ($overdueSchedules > 0) {
                $overdueCount += $overdueSchedules;
                $activePenalty = true;
                $credit->update(['has_red_dot' => true]);
            }
        }
        $this->late_credits_count = $overdueCount;
        $this->credit_stars = max(0.0, round(3.0 - min(3.0, $overdueCount * 0.75), 2));

        // 3. Check active maluses for red dot
        $activeMalusCount = Malus::where('user_id', $this->user_id)
            ->where('is_active', true)
            ->count();
        if ($activeMalusCount > 0) {
            $activePenalty = true;
        }

        $this->has_red_dot = $activePenalty;
        $this->save();
    }
}
