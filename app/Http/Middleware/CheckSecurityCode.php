<?php

namespace App\Http\Middleware;

use App\Models\SecurityCode;
use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Site access gate. When enabled (config/security-code.php), every
 * non-exempt request is redirected to the security-code page until a valid
 * active code has been entered (tracked in the session).
 *
 * This stands in front of sign-in; it replaces none of it. Authentication,
 * the business middleware and the policies all still apply behind it.
 */
class CheckSecurityCode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! static::gateEnabled()) {
            return $next($request);
        }

        if ($this->shouldPassThrough($request)) {
            return $next($request);
        }

        if ($request->session()->has(config('security-code.session_key'))) {
            return $next($request);
        }

        // Remember where they were headed, then send them to the code page.
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('security-code');
    }

    protected function shouldPassThrough(Request $request): bool
    {
        $routeName = $request->route()?->getName();

        if ($routeName && in_array($routeName, config('security-code.exempt_route_names', []), true)) {
            return true;
        }

        foreach (config('security-code.exempt_patterns', []) as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the gate is on. The back office switch (SystemSetting
     * `security_code_enabled`) is the live override; the
     * `SECURITY_CODE_ENABLED` env value is the default when it was never set.
     */
    public static function gateEnabled(): bool
    {
        return (bool) SystemSetting::get('security_code_enabled', config('security-code.enabled'));
    }

    /** Verify a submitted code against the active codes in the database. */
    public static function verifyCode(string $code): bool
    {
        return SecurityCode::isValid($code);
    }
}
