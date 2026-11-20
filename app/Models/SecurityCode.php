<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A site-wide access code. NOT business-scoped — one set of codes gates the
 * whole platform. A code is valid only while its status is on.
 */
class SecurityCode extends Model
{
    protected $fillable = ['code', 'label', 'status'];

    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }

    /** A code is valid only if it exists and is switched on. */
    public static function isValid(string $code): bool
    {
        return static::query()
            ->where('code', trim($code))
            ->where('status', true)
            ->exists();
    }
}
