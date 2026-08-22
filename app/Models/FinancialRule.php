<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'value_numeric',
        'value_string',
        'value_json',
        'type',
        'is_active',
    ];

    protected $casts = [
        'value_numeric' => 'float',
        'value_json' => 'array',
        'is_active' => 'boolean',
    ];

    public static function getRuleValue(string $key, mixed $default = null)
    {
        $rule = static::where('key', $key)->where('is_active', true)->first();
        if (!$rule) {
            return $default;
        }

        return match ($rule->type) {
            'amount', 'percentage', 'multiplier', 'months' => (float) $rule->value_numeric,
            'boolean' => (bool) $rule->value_numeric,
            'json' => $rule->value_json,
            default => $rule->value_string ?? $rule->value_numeric,
        };
    }
}
