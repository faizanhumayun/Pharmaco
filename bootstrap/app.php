<?php

use App\Http\Middleware\CheckSecurityCode;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\MirrorReadOnly;
use App\Http\Middleware\SetCurrentBusiness;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Where a shop's computer sends its snapshots: machine to machine,
        // proved by a token, so it has no session, no CSRF token and no
        // security-code page in front of it.
        then: function () {
            \Illuminate\Support\Facades\Route::post('mirror/receive', \App\Http\Controllers\MirrorReceiveController::class)
                ->middleware('throttle:240,1')
                ->name('mirror.receive');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // A deactivated account loses access on its next request, not its next login.
        $middleware->appendToGroup('web', EnsureUserIsActive::class);

        // The site-wide access gate. Does nothing unless it is switched on
        // (config/security-code.php, or Security code in the back office).
        $middleware->appendToGroup('web', CheckSecurityCode::class);

        // A shop's online copy is for reading: anything that would change it
        // is turned away. Does nothing on the platform's own address.
        $middleware->appendToGroup('web', MirrorReadOnly::class);

        $middleware->alias([
            'platform.admin' => EnsurePlatformAdmin::class,
            'business' => SetCurrentBusiness::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
