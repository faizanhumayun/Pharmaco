<?php

namespace App\Http\Controllers;

use App\Models\ShopMirror;
use App\Support\MirrorHost;
use Illuminate\Http\RedirectResponse;

/**
 * Opening a shop's read-only copy under the platform's own address.
 *
 *   /copy/tayyaba   steps into that shop's copy, and shows its sign-in page
 *   /copy-leave     steps back out to the platform
 *
 * Stepping in only chooses which books the following pages are read from. It
 * lets nobody in: the copy has its own sign-in, with the shop's own logins.
 */
class ShopCopyController extends Controller
{
    public function enter(string $slug): RedirectResponse
    {
        $mirror = ShopMirror::query()->where('slug', $slug)->firstOrFail();

        // Kept in the platform's own database: its businesses are simply
        // there for the shop's people once they sign in.
        if ($mirror->isMerged()) {
            return redirect('/login');
        }

        return redirect('/login')->withCookie(MirrorHost::cookieFor($mirror));
    }

    public function leave(): RedirectResponse
    {
        return redirect('/')->withoutCookie(MirrorHost::COOKIE);
    }
}
