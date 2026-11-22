<?php

namespace App\Http\Controllers;

use App\Domain\Mirror\SyncStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

/**
 * For the indicator at the top of a shop's screens: is there internet, is the
 * online copy up to date, and a button to send it now.
 */
class MirrorStatusController extends Controller
{
    public function show(SyncStatus $status): JsonResponse
    {
        return response()->json($status->status());
    }

    /** Send now, rather than waiting for the next five-minute turn. */
    public function sync(SyncStatus $status): JsonResponse
    {
        abort_unless($status->configured(), 422, 'The online copy has not been set up on this computer.');

        $status->reachable(fresh: true);
        Artisan::call('mirror:push');

        return response()->json($status->status());
    }
}
