<?php

namespace App\Http\Middleware;

use App\Domain\Mirror\ShopPause;
use App\Models\Business;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A shop's computer that is locked while the back office fixes something.
 *
 * Every screen is answered with the same notice, and nothing reaches the
 * application behind it — so nothing can be entered, and nothing is written
 * that the books coming back would have to be reconciled with. The notice
 * looks again by itself, and the shop is simply there again when the lock
 * comes off.
 */
class ShopPaused
{
    public function __construct(private readonly ShopPause $pause) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Until the platform has the last entries the lock is provisional
        // and may come off again; the screen says so only once it holds.
        if (! $this->pause->active()) {
            return $next($request);
        }

        $details = $this->pause->details();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'We are fixing an issue. Nothing can be entered on this computer for a short while.', 'paused' => true], 503);
        }

        return response()->view('shop-paused', [
            'details' => $details,
            'shop' => rescue(fn () => Business::query()->orderBy('id')->value('name'), null, false),
            'zone' => rescue(fn () => Business::query()->orderBy('id')->value('timezone'), null, false) ?: config('app.timezone'),
        ], 503)->header('Retry-After', '30');
    }
}
