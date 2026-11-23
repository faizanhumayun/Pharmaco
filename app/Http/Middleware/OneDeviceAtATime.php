<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * One person in a business is signed in on one device at a time.
 *
 * Signing in on a device makes it the person's device ({@see self::claim()}).
 * Any other device still signed in as them is signed out on its next request,
 * and told where they went. The newest sign-in wins, so nobody is ever locked
 * out by a computer left signed in somewhere.
 *
 * The App Owner is left alone: the back office is not a counter. So is a
 * shop's read-only copy, which cannot record anything.
 */
class OneDeviceAtATime
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isPlatformAdmin() || config('mirror.active')) {
            return $next($request);
        }

        $here = (string) $request->cookie(IdentifyDevice::COOKIE);
        $theirs = $user->signin_device;

        // Signed in before this rule existed: wherever they are now is theirs.
        if ($theirs === null) {
            if (Str::isUuid($here)) {
                self::remember($user, $here);
            }

            return $next($request);
        }

        if (hash_equals($theirs, $here)) {
            return $next($request);
        }

        $name = Device::where('uuid', $theirs)->value('name');
        $message = 'You were signed out here because this account signed in on '
            .($name ? '"'.$name.'"' : 'another device').'. One person can be signed in on one device at a time.';

        // Only this browser: the device they moved to stays signed in.
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }

        return redirect()->route('login')->with('status', $message);
    }

    /**
     * Makes this browser the person's one device. Called when they sign in.
     *
     * A browser that has never signed in has no device code yet, so it is
     * given one here, the same one {@see IdentifyDevice} will then find.
     */
    public static function claim(Request $request, User $user): void
    {
        $uuid = (string) $request->cookie(IdentifyDevice::COOKIE);

        if (! Str::isUuid($uuid)) {
            $uuid = (string) Str::uuid();
            $request->cookies->set(IdentifyDevice::COOKIE, $uuid);
            Cookie::queue(cookie(IdentifyDevice::COOKIE, $uuid, 60 * 24 * 365 * 5, '/', null, null, true, false, 'lax'));
        }

        self::remember($user, $uuid);
    }

    private static function remember(User $user, string $uuid): void
    {
        try {
            $user->forceFill(['signin_device' => $uuid])->saveQuietly();
        } catch (\Throwable) {
            // Before the column exists: the rule simply does not apply yet.
        }
    }
}
