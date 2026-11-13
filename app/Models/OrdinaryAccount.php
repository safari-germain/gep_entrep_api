<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrdinaryAccount extends Model
{
    use HasFactory;

    protected $table = 'ordinary_accounts';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'user_id',
        'balance',
        'total_debit_interest_charged',
        'status',
        'negative_since',
        'last_interest_calculated_at',
        'coverage_debt_amount',
        'has_coverage_restriction',
        'consecutive_negative_months',
        'total_overdraft_operations_count',
    ];

    protected $casts = [
        'balance'                          => 'float',
        'total_debit_interest_charged'     => 'float',
        'coverage_debt_amount'             => 'float',
        'has_coverage_restriction'         => 'boolean',
        'consecutive_negative_months'      => 'integer',
        'total_overdraft_operations_count' => 'integer',
        'negative_since'                   => 'datetime',
        'last_interest_calculated_at'  => 'datetime',
        'created_at'                   => 'datetime',
        'updated_at'                   => 'datetime',
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

    public function transactions(): HasMany
    {
        return $this->hasMany(OrdinaryAccountTransaction::class, 'ordinary_account_id')->orderBy('created_at', 'desc');
    }

    public function overdraftCycles(): HasMany
    {
        return $this->hasMany(OrdinaryAccountOverdraftCycle::class, 'ordinary_account_id')->orderByDesc('started_at');
    }

    /**
     * Vérifie si le compte ordinaire a été approvisionné/alimenté au moins une fois
     */
    public function hasBeenFunded(): bool
    {
        return $this->transactions()->where('direction', 'credit')->exists()
            || $this->balance > 0;
    }

    /**
     * Helper pour trouver ou initialiser le compte ordinaire d'une entité
     */
    public static function getOrCreateForEntity(string $entityType, int $entityId, ?int $userId = null): self
    {
        $account = static::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->first();

        if (!$account) {
            // Résolution du user_id si non fourni
            if (!$userId) {
                if ($entityType === 'user') {
                    $userId = $entityId;
                } elseif ($entityType === 'individual') {
                    $ind = Individual::find($entityId);
                    $userId = $ind ? $ind->user_id : null;
                }
            }

            $account = static::create([
                'entity_type'                  => $entityType,
                'entity_id'                    => $entityId,
                'user_id'                      => $userId,
                'balance'                      => 0.00,
                'total_debit_interest_charged' => 0.00,
                'status'                       => 'active',
            ]);
        }

        return $account;
    }
}
