<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Nightly integrity checks.
 *
 * Closing itself is deliberately NOT automated: a cron job that closes days
 * unattended reintroduces exactly the problem this system exists to solve —
 * numbers nobody looked at. What is automated is checking that the numbers
 * still add up.
 */
Schedule::command('ledger:verify')->dailyAt('02:00');
Schedule::command('closings:rebuild --check')->dailyAt('02:15');

/*
 * A shop running on its own computer: snapshot the books and send them to the
 * platform every few minutes. Without internet it still leaves a backup on
 * this computer; unchanged books are not sent twice. Does nothing where no
 * platform address is set (the platform itself, a developer's machine).
 */
Schedule::command('mirror:push')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->when(fn () => filled(config('mirror.push_url')));
