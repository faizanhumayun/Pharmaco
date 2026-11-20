<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value switches that belong to the platform itself, kept by the back
 * office. Not business-scoped, and never a place for a figure.
 */
class SystemSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->first()?->value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
