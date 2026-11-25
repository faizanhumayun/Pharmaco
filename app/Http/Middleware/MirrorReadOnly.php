<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Support\MirrorHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A shop's online copy can be read, and nothing else.
 *
 * The copy is replaced whole each time the shop's computer sends a snapshot,
 * so anything entered into it would vanish at the next one — and until then
 * the two would disagree. Every request that would change something is turned
 * away here, in one place, rather than hiding buttons screen by screen.
 * Signing in and out are the only exceptions.
 */
class MirrorReadOnly
{
    private const ALLOWED = ['login', 'back-office/login', 'logout', 'security-code'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->is(...self::ALLOWED)) {
            return $next($request);
        }

        // The whole site is a shop's copy (a database of its own), or the
        // business in the address is one kept beside the platform's own.
        $business = $request->route('business');
        $copy = MirrorHost::active() || ($business instanceof Business && $business->isReadOnlyCopy());

        if (! $copy) {
            return $next($request);
        }

        $message = 'This is the read-only online copy of the shop\'s records. Changes can only be made on the shop\'s own computer.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return back()->withErrors(['status' => $message]);
    }
}
