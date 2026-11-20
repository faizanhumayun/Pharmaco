<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master toggle
    |--------------------------------------------------------------------------
    |
    | When enabled, visitors must enter a valid security code (a row in the
    | `security_codes` table that is switched on) before reaching the site.
    | Off by default so the app is never accidentally locked. This is the
    | default only: the back office switch (Security code) overrides it once
    | it has been set.
    |
    */

    'enabled' => env('SECURITY_CODE_ENABLED', false),

    // Session flag set once a valid code is entered.
    'session_key' => 'security_code_verified',

    /*
    |--------------------------------------------------------------------------
    | Exemptions
    |--------------------------------------------------------------------------
    |
    | Route names and URI glob patterns that bypass the gate. The code page
    | itself and the sign-in flows stay open so people can still log in.
    |
    | The back office is exempt on purpose: it is where the gate is switched
    | off and where codes are kept, so it must never be locked behind them.
    | It is still guarded by sign-in and the platform check.
    |
    */

    'exempt_route_names' => [
        'security-code', 'security-code.verify',
        'login', 'backoffice.login', 'backoffice.login.store', 'logout',
        'password.request', 'password.email', 'password.reset', 'password.store',
        'verification.notice', 'verification.verify', 'verification.send',
    ],

    'exempt_patterns' => [
        'security-code',
        'up',
        'login',
        'admin',
        'admin/*',
    ],

];
