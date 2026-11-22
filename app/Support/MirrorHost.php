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
    /** Points the request at a shop's copy when its host is one. */
    public static function apply(string $host): ?ShopMirror
    {
        $host = strtolower($host);

        // The platform's own address is never a copy; do not ask.
        if ($host === '' || $host === strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))) {
            return null;
        }

        try {
            $mirror = ShopMirror::query()->where('host', $host)->first();
        } catch (\Throwable) {
            // Before the table exists (a fresh install), there are no copies.
            return null;
        }

        if ($mirror === null) {
            return null;
        }

        config([
            'database.connections.mysql' => array_merge(config('database.connections.mysql'), $mirror->connectionConfig()),
            'mirror.active' => [
                'name' => $mirror->name,
                'made_at' => $mirror->snapshot_made_at,
                'synced_at' => $mirror->last_synced_at,
            ],
        ]);

        DB::purge('mysql');

        return $mirror;
    }

    /** Whether this request is being served from a shop's read-only copy. */
    public static function active(): bool
    {
        return config('mirror.active') !== null;
    }
}
