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
        ];
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
