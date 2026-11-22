<?php

namespace App\Domain\Mirror;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Where a shop's computer stands with its online copy: can it reach the
 * platform, when did it last send, and has anything changed since.
 *
 * Kept cheap, because every screen asks. "Has anything changed" is not worked
 * out by taking a snapshot — that reads every row — but from a marker: the
 * highest id in the handful of tables that every piece of work touches. It
 * moves whenever something is entered, and that is all the question needs.
 */
class SyncStatus
{
    private const SENT = 'mirror/last-sent.json';
    private const ATTEMPT = 'mirror/last-attempt.json';

    /** Whether this installation is a shop's own computer. */
    public function isShop(): bool
    {
        return (bool) config('mirror.shop') || $this->configured();
    }

    /** Whether it has been told where to send its books. */
    public function configured(): bool
    {
        return filled(config('mirror.push_url')) && filled(config('mirror.push_token'));
    }

    /**
     * A value that changes whenever something is entered into the books.
     */
    public function marker(): string
    {
        $parts = [];

        foreach (['activity_log', 'ledger_entries', 'stock_movements', 'pos_bills', 'company_products', 'transactions'] as $table) {
            $parts[] = (int) DB::table($table)->max('id');
        }

        // Edits change no id: the newest change to a product or a business does.
        $parts[] = (string) DB::table('company_products')->max('updated_at');
        $parts[] = (string) DB::table('businesses')->max('updated_at');

        return implode('.', $parts);
    }

    /** Written by the sender after every run, whatever came of it. */
    public function recordAttempt(string $result, ?string $message, string $marker): void
    {
        $disk = Storage::disk('local');

        $disk->put(self::ATTEMPT, json_encode(['at' => now()->toIso8601String(), 'result' => $result, 'message' => $message]));

        // The books as they stood are online: sent now, or already there.
        if (in_array($result, ['sent', 'unchanged'], true)) {
            $previous = $this->read(self::SENT);

            $disk->put(self::SENT, json_encode([
                'hash' => $previous['hash'] ?? null,
                'sent_at' => $result === 'sent' ? now()->toIso8601String() : ($previous['sent_at'] ?? now()->toIso8601String()),
                'marker' => $marker,
            ]));
        }
    }

    /**
     * @return array{shop: bool, configured: bool, online: ?bool, synced: bool, pending: bool, last_sent_at: ?string, last_sent: ?string, attempt: ?string, message: ?string}
     */
    public function status(): array
    {
        $sent = $this->read(self::SENT);
        $attempt = $this->read(self::ATTEMPT);
        $configured = $this->configured();

        $pending = $configured && ($sent === null || ($sent['marker'] ?? null) !== $this->marker());
        $sentAt = isset($sent['sent_at']) ? Carbon::parse($sent['sent_at']) : null;

        return [
            'shop' => $this->isShop(),
            'configured' => $configured,
            'online' => $configured ? $this->reachable() : null,
            'synced' => $configured && ! $pending,
            'pending' => $pending,
            'last_sent_at' => $sentAt?->toIso8601String(),
            'last_sent' => $sentAt?->diffForHumans(),
            'attempt' => $attempt['result'] ?? null,
            // Why the last one did not go, when it did not.
            'message' => in_array($attempt['result'] ?? null, ['failed', 'offline'], true) ? ($attempt['message'] ?? null) : null,
        ];
    }

    /**
     * Whether the platform answers. Any answer counts — it is the connection
     * that is being asked about, not the page. Remembered briefly, so a screen
     * that asks every few seconds does not knock on the door each time.
     */
    public function reachable(bool $fresh = false): bool
    {
        $ask = function () {
            $url = (string) config('mirror.push_url');
            $origin = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).(parse_url($url, PHP_URL_PORT) ? ':'.parse_url($url, PHP_URL_PORT) : '');

            try {
                Http::timeout(4)->connectTimeout(3)->head($origin.'/up');

                return true;
            } catch (ConnectionException) {
                return false;
            } catch (\Throwable) {
                // It answered, in some way that was not a refused connection.
                return true;
            }
        };

        if ($fresh) {
            Cache::forget('mirror.reachable');
        }

        return (bool) Cache::remember('mirror.reachable', 20, $ask);
    }

    private function read(string $file): ?array
    {
        $disk = Storage::disk('local');

        return $disk->exists($file) ? (json_decode((string) $disk->get($file), true) ?: null) : null;
    }
}
