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
