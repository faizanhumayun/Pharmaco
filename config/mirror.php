<?php

/*
 * Running Pharmaco in the shop, with a copy online.
 *
 * A shop can run the whole application on its own computer, so the counter
 * never depends on the internet. That computer holds the real books. Whenever
 * it is online it sends a snapshot of them to the platform, which keeps it as
 * a read-only copy the owner can open from anywhere.
 *
 * These settings are for the SHOP's computer. Leave them empty on the
 * platform itself; the copies it serves are registered in the back office
 * (Shop copies).
 */
return [

    // This installation is a shop's own computer. Set by the installers; it
    // is what puts the internet-and-sync indicator at the top of its screens.
    'shop' => (bool) env('PHARMACO_SHOP', false),

    // Where snapshots are sent: https://your-platform.example/mirror/receive
    'push_url' => env('MIRROR_PUSH_URL'),

    // The token shown once when the shop was registered in the back office.
    'push_token' => env('MIRROR_PUSH_TOKEN'),

    // Snapshots kept on the shop's own disk. Each is a full backup.
    'keep' => (int) env('MIRROR_KEEP_SNAPSHOTS', 14),

    /*
     * Tables that are never copied: the machinery of one installation, not
     * the books. Sessions, caches and queues belong to whichever computer is
     * running; the security code and platform switches are each side's own.
     */
    'skip_tables' => [
        'migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'password_reset_tokens', 'shop_mirrors', 'system_settings', 'security_codes',
    ],

    // Filled in for the length of a request served from a shop's copy.
    'active' => null,

];
