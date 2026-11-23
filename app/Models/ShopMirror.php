<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A shop that runs on its own computer and keeps a read-only copy here.
 *
 * Always read from the platform's own database (`main`), because a request
 * served from a shop's copy has `mysql` pointed at that shop's database.
 */
class ShopMirror extends Model
{
    protected $connection = 'main';

    protected $fillable = ['name', 'slug', 'host', 'database', 'db_username', 'db_password'];

    protected $hidden = ['db_password', 'token_hash'];

    protected function casts(): array
    {
        return [
            'db_password' => 'encrypted',
            'last_synced_at' => 'datetime',
            'snapshot_made_at' => 'datetime',
            'entry_changed_at' => 'datetime',
        ];
    }

    /** Where the books are entered: see the migration that added `entry`. */
    public const SHOP = 'shop';

    public const PAUSING = 'pausing';

    public const OFFICE = 'office';

    public const RETURNING = 'returning';

    /** Whether the books are, right now, entered here rather than in the shop. */
    public function isOffice(): bool
    {
        return $this->entry === self::OFFICE;
    }

    /** Whether the shop's computer is, or is about to be, locked. */
    public function isPaused(): bool
    {
        return $this->entry !== null && $this->entry !== self::SHOP;
    }

    public function moveTo(string $entry): void
    {
        $this->forceFill(['entry' => $entry, 'entry_changed_at' => now()])->save();
    }

    /**
     * Whether the copy is kept in the platform's own database, beside the
     * businesses that work online, rather than in a database of its own.
     */
    public function isMerged(): bool
    {
        return blank($this->database);
    }

    /** A new token for the shop to send with. Returned once; only its hash is kept. */
    public function issueToken(): string
    {
        $token = Str::random(64);

        $this->forceFill(['token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    public static function forToken(?string $token): ?self
    {
        return $token === null || $token === ''
            ? null
            : static::query()->where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * The connection settings for this shop's copy: the platform's own, aimed
     * at the copy's database.
     *
     * @return array<string, mixed>
     */
    public function connectionConfig(): array
    {
        return array_merge(config('database.connections.main'), array_filter([
            'database' => $this->database,
            'username' => $this->db_username,
            'password' => $this->db_password,
        ], fn ($value) => $value !== null && $value !== ''));
    }
}
