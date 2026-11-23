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
Schedule::command('ledger:verify')->dailyAt('02:00')->skip(fn () => app(\App\Domain\Mirror\ShopPause::class)->active());
Schedule::command('closings:rebuild --check')->dailyAt('02:15')->skip(fn () => app(\App\Domain\Mirror\ShopPause::class)->active());

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

/*
 * Once a minute the shop's computer asks the platform where its books are
 * being entered: carry on, lock for a fix, or take the books back. The same
 * question settles "Online" or "Offline", so no screen waits on the internet.
 */
Schedule::command('mirror:state')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->when(fn () => config('mirror.shop') && filled(config('mirror.push_url')));
