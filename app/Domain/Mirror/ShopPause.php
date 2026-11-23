<?php

namespace App\Domain\Mirror;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Whether this shop's computer is locked while the back office fixes
 * something in its books.
 *
 * Kept in a file rather than the database: the database is the books, and it
 * is the books that are being held still. While the file is there nothing can
 * be entered on this computer ({@see \App\Http\Middleware\ShopPaused}); it is
 * written and removed only by {@see \App\Console\Commands\MirrorState}, on
 * the platform's say-so.
 */
class ShopPause
{
    private const FILE = 'mirror/paused.json';

    public function active(): bool
    {
        return (bool) config('mirror.shop') && Storage::disk('local')->exists(self::FILE);
    }

    /** @return array{since: ?Carbon, phone: ?string, sent_at: ?Carbon, confirmed: bool, error: ?string} */
    public function details(): array
    {
        $data = $this->read();

        return [
            'since' => isset($data['since']) ? Carbon::parse($data['since']) : null,
            'phone' => $data['phone'] ?? null,
            'sent_at' => isset($data['sent_at']) ? Carbon::parse($data['sent_at']) : null,
            // The platform has the last entries; until then the lock is provisional.
            'confirmed' => (bool) ($data['confirmed'] ?? false),
            'error' => $data['error'] ?? null,
        ];
    }

    public function start(?string $phone): void
    {
        $this->write(['since' => now()->toIso8601String(), 'phone' => $phone] + $this->read());
    }

    /** The platform has the last entries: locked until it gives the books back. */
    public function confirm(?string $phone = null): void
    {
        $data = $this->read();

        $this->write(array_filter([
            'since' => $data['since'] ?? now()->toIso8601String(),
            'phone' => $phone ?? ($data['phone'] ?? null),
            'sent_at' => $data['sent_at'] ?? now()->toIso8601String(),
            'confirmed' => true,
            'error' => $data['error'] ?? null,
        ], fn ($value) => $value !== null));
    }

    public function note(?string $phone, ?string $error): void
    {
        if (! Storage::disk('local')->exists(self::FILE)) {
            return;
        }

        $data = $this->read();
        $data['phone'] = $phone ?? ($data['phone'] ?? null);
        $data['error'] = $error;

        $this->write($data);
    }

    public function clear(): void
    {
        Storage::disk('local')->delete(self::FILE);
    }

    private function read(): array
    {
        $disk = Storage::disk('local');

        return $disk->exists(self::FILE) ? (json_decode((string) $disk->get(self::FILE), true) ?: []) : [];
    }

    private function write(array $data): void
    {
        Storage::disk('local')->put(self::FILE, json_encode($data));
    }
}
