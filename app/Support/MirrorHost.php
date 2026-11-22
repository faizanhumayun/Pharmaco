<?php

namespace App\Support;

use App\Models\ShopMirror;
use Illuminate\Support\Facades\DB;

/**
 * Serves a shop's read-only copy at the shop's own address.
 *
 * A shop that runs on its own computer has a database of its own on the
 * platform. A request that arrives on that shop's address is answered from
 * that database: the default connection is pointed at it before anything else
 * reads or writes, so every model, the sessions and the cache all work inside
 * the copy without knowing it. Everything else about the request is ordinary.
 *
 * The copy is read-only ({@see \App\Http\Middleware\MirrorReadOnly}): it is
 * replaced whole by the shop's next snapshot, so anything entered here would
 * be lost, and two places entering into one set of books is how books stop
 * agreeing.
 */
class MirrorHost
{
    /** The cookie that says which shop's copy this browser has stepped into. */
    public const COOKIE = 'pharmaco_copy';

    /**
     * Points the request at a shop's copy: because this browser stepped into
     * one (/copy/shopname), or because the request came in on an address that
     * belongs to one.
     */
    public static function apply(string $host, ?string $chosen = null): ?ShopMirror
    {
        try {
            $mirror = self::chosen($chosen) ?? self::forHost($host);
        } catch (\Throwable) {
            // Before the table exists (a fresh install), there are no copies.
            return null;
        }

        // One kept in the platform's own database changes nothing about the
        // request: its businesses are ordinary rows, marked read-only.
        if ($mirror === null || $mirror->isMerged()) {
            return null;
        }

        $steppedIn = $mirror->host === null || strtolower($host) !== strtolower($mirror->host);

        config([
            'database.connections.mysql' => array_merge(config('database.connections.mysql'), $mirror->connectionConfig()),
            'mirror.active' => [
                'name' => $mirror->name,
                'made_at' => $mirror->snapshot_made_at,
                'synced_at' => $mirror->last_synced_at,
                // Under the platform's own address: there is a way back out.
                'can_leave' => $steppedIn,
            ],
        ]);

        // Signed in to a copy is not signed in to the platform, and the two
        // share an address: each keeps a session cookie of its own.
        if ($steppedIn) {
            config(['session.cookie' => config('session.cookie').'_copy_'.$mirror->id]);
        }

        DB::purge('mysql');

        return $mirror;
    }

    /** The cookie a browser carries while it is inside a shop's copy. */
    public static function cookieFor(ShopMirror $mirror): \Symfony\Component\HttpFoundation\Cookie
    {
        // Until the browser is closed. Not secret, only tamper-proof: it names
        // a copy, and the copy still asks for its own sign-in.
        return cookie(self::COOKIE, $mirror->id.'.'.self::sign((string) $mirror->id), 0, '/', null, null, true, false, 'lax');
    }

    private static function chosen(?string $cookie): ?ShopMirror
    {
        if ($cookie === null || ! str_contains($cookie, '.')) {
            return null;
        }

        [$id, $signature] = explode('.', $cookie, 2);

        if (! ctype_digit($id) || ! hash_equals(self::sign($id), $signature)) {
            return null;
        }

        return ShopMirror::query()->find((int) $id);
    }

    private static function forHost(string $host): ?ShopMirror
    {
        $host = strtolower($host);

        // The platform's own address is never a copy; do not ask.
        if ($host === '' || $host === strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))) {
            return null;
        }

        return ShopMirror::query()->where('host', $host)->first();
    }

    private static function sign(string $id): string
    {
        return hash_hmac('sha256', 'shop-copy:'.$id, (string) config('app.key'));
    }

    /** Whether this request is being served from a shop's read-only copy. */
    public static function active(): bool
    {
        return config('mirror.active') !== null;
    }
}
