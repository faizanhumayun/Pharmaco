<?php

namespace App\Http\Controllers;

use App\Domain\Mirror\ReturnPackage;
use App\Models\ShopMirror;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * A shop's computer asking the platform where its books are being entered,
 * and taking them back when a fix is done.
 *
 * Machine to machine, like {@see MirrorReceiveController}: proved by the
 * shop's token, no session. The shop's computer always asks; the platform
 * never reaches into a shop.
 */
class MirrorHandoverController extends Controller
{
    /** Asked once a minute: carry on, lock, stay locked, or take the books back. */
    public function state(Request $request): JsonResponse
    {
        $mirror = ShopMirror::forToken($request->bearerToken());

        if ($mirror === null) {
            return response()->json(['message' => 'That token is not recognised.'], 401);
        }

        return response()->json([
            'entry' => $mirror->entry ?: ShopMirror::SHOP,
            'since' => $mirror->entry_changed_at?->toIso8601String(),
            // Shown on the shop's locked screen.
            'phone' => SystemSetting::get('support_phone'),
        ]);
    }

    /** The changes the back office made, for the shop's computer to apply. */
    public function package(Request $request, ReturnPackage $package): Response
    {
        $mirror = ShopMirror::forToken($request->bearerToken());

        if ($mirror === null) {
            return response()->json(['message' => 'That token is not recognised.'], 401);
        }

        if ($mirror->entry !== ShopMirror::RETURNING) {
            return response()->json(['message' => 'There is nothing to take back at the moment.'], 409);
        }

        $disk = Storage::disk('local');
        $disk->makeDirectory('mirror/return');
        $path = $disk->path('mirror/return/'.$mirror->id.'.jsonl.gz');

        try {
            $package->write($mirror, $path);
        } catch (\Throwable $e) {
            report($e);
            $mirror->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();

            return response()->json(['message' => mb_substr($e->getMessage(), 0, 300)], 422);
        }

        return response()->download($path, 'return.jsonl.gz')->deleteFileAfterSend();
    }

    /** The shop's computer saying it has its books back — or why it has not. */
    public function returned(Request $request, ReturnPackage $package): JsonResponse
    {
        $mirror = ShopMirror::forToken($request->bearerToken());

        if ($mirror === null) {
            return response()->json(['message' => 'That token is not recognised.'], 401);
        }

        $data = $request->validate([
            'ok' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($mirror->entry !== ShopMirror::RETURNING) {
            return response()->json(['entry' => $mirror->entry]);
        }

        if (! $data['ok']) {
            // Still locked, still ours: the back office sees why and decides.
            $mirror->forceFill(['last_error' => 'The shop\'s computer could not take the changes back: '.($data['message'] ?? 'no reason given')])->save();

            return response()->json(['entry' => $mirror->entry]);
        }

        // The shop has them. From here its computer is the one that is right,
        // and this copy is read-only again until its next snapshot replaces it.
        $package->forgetBaseline($mirror);
        $mirror->forceFill(['last_error' => null])->save();
        $mirror->moveTo(ShopMirror::SHOP);

        return response()->json(['entry' => ShopMirror::SHOP]);
    }
}
