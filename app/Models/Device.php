<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A computer (strictly, a browser on one) that signs in: "Laptop POS 1".
 *
 * Belongs to the installation rather than to a business, so it is not
 * business-scoped; what it entered is, through the records that name it.
 */
class Device extends Model
{
    protected $fillable = ['uuid', 'name', 'user_agent', 'last_ip', 'last_user_id', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function lastUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_user_id');
    }

    /** What to call it on screen: its given name, or what kind of machine it looks like. */
    public function label(): string
    {
        return $this->name ?: 'Unnamed '.$this->kind();
    }

    public function isNamed(): bool
    {
        return filled($this->name);
    }

    /** A plain guess at the machine, from what the browser says of itself. */
    public function kind(): string
    {
        $agent = (string) $this->user_agent;

        return match (true) {
            str_contains($agent, 'Android') => 'Android device',
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Windows') => 'Windows computer',
            str_contains($agent, 'Macintosh') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux computer',
            default => 'device',
        };
    }
}
