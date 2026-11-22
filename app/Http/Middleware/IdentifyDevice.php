<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Support\CurrentDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out which computer a signed-in request came from.
 *
 * A browser is given a long-lived cookie the first time someone signs in on
 * it, and that cookie is the device from then on. Nothing is recorded for a
 * visitor who is not signed in: the public pages are not devices.
 */
class IdentifyDevice
{
    public const COOKIE = 'pharmaco_device';

    public function __construct(private readonly CurrentDevice $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $uuid = (string) $request->cookie(self::COOKIE);
        $fresh = ! Str::isUuid($uuid);

        if ($fresh) {
            $uuid = (string) Str::uuid();
        }

        try {
            $device = Device::firstOrCreate(['uuid' => $uuid], [
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
                'last_ip' => $request->ip(),
                'last_user_id' => $user->id,
                'last_seen_at' => now(),
            ]);

            // Kept roughly current, without a write on every request.
            if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinutes(5)) || $device->last_user_id !== $user->id) {
                $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip(), 'last_user_id' => $user->id])->saveQuietly();
            }
        } catch (\Throwable) {
            // Before the table exists, or on a read-only copy: carry on without one.
            return $next($request);
        }

        $session = $request->hasSession() ? $request->session()->getId() : null;
        $this->current->set($device, $session ? substr(hash('sha256', $session), 0, 12) : null);

        $response = $next($request);

        if ($fresh && method_exists($response, 'withCookie')) {
            // Five years: the device is the machine, not the sign-in.
            $response->withCookie(cookie(self::COOKIE, $uuid, 60 * 24 * 365 * 5, '/', null, null, true, false, 'lax'));
        }

        return $response;
    }
}
