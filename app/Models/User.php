<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $table = 'users';

    protected $fillable = [
        'profile',
        'name',
        'prenom',
        'email',
        'genre',
        'phone',
        'password',
        'adresse',
        'role',
        'status',
        'profile_level',
        'organisation_id',
        'marketeur_code',
        'otp_code',
        'otp_expires_at',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'otp_expires_at'    => 'datetime',
    ];

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class, 'owner_id');
    }

    public function getBalance($type)
    {
        $wallet = Wallet::where('owner_id', $this->id)
            ->where('point_type', $type)
            ->first();
        return $wallet ? $wallet->balance : 0;
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user')
            ->withPivot(['role', 'permissions', 'assigned_by'])
            ->withTimestamps();
    }

    public function individual(): HasOne
    {
        return $this->hasOne(Individual::class);
    }

    public function sensibilisateur(): HasOne
    {
        return $this->hasOne(Sensibilisateur::class);
    }

    public function organisation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'operator_user_id');
    }
}
