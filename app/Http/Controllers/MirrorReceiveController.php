<?php

namespace App\Http\Controllers;

use App\Domain\Mirror\SnapshotImporter;
use App\Models\ShopMirror;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Where a shop's computer sends its snapshots.
 *
 * Not a page: one machine talking to another. The shop proves who it is with
 * the token it was given when it was registered, sends the file in pieces,
 * and on the last piece the shop's read-only copy is replaced with it.
 *
 * Nothing the shop sends can reach the platform's own database or another
 * shop's: a token names exactly one copy, and the import is pointed at that
 * copy's database and nowhere else.
 */
class MirrorReceiveController extends Controller
{
    public function __invoke(Request $request, SnapshotImporter $importer): JsonResponse
    {
        $mirror = ShopMirror::forToken($request->bearerToken());

        if ($mirror === null) {
            return response()->json(['message' => 'That token is not recognised.'], 401);
        }

        // Belt and braces: a snapshot replaces everything in the database it
        // is loaded into, so it is never the platform's own.
        if ($mirror->database === config('database.connections.main.database')) {
            return response()->json(['message' => 'This shop is registered against the platform\'s own database. Nothing was loaded.'], 422);
        }

        $data = $request->validate([
            'upload' => ['required', 'uuid'],
            'index' => ['required', 'integer', 'min:0', 'max:9999'],
            'total' => ['required', 'integer', 'min:1', 'max:10000'],
            'piece' => ['required', 'file', 'max:2048'],
        ]);

        $disk = Storage::disk('local');
        $dir = 'mirror/in/'.$mirror->id.'/'.$data['upload'];

        $request->file('piece')->storeAs($dir, str_pad((string) $data['index'], 5, '0', STR_PAD_LEFT), 'local');

        if ((int) $data['index'] < (int) $data['total'] - 1) {
            return response()->json(['received' => (int) $data['index'] + 1]);
        }

        $pieces = collect($disk->files($dir))->sort()->values();

        if ($pieces->count() !== (int) $data['total']) {
            $disk->deleteDirectory($dir);

            return response()->json(['message' => 'Part of the snapshot did not arrive. Send it again.'], 422);
        }

        $path = $disk->path($dir.'.jsonl.gz');
        $whole = fopen($path, 'wb');

        foreach ($pieces as $piece) {
            $part = fopen($disk->path($piece), 'rb');
            stream_copy_to_stream($part, $whole);
            fclose($part);
        }

        fclose($whole);
        $disk->deleteDirectory($dir);

        try {
            config(['database.connections.mirror' => $mirror->connectionConfig()]);
            DB::purge('mirror');

            $done = $importer->import($path, 'mirror');
        } catch (\Throwable $e) {
            report($e);
            $mirror->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();

            return response()->json(['message' => 'The snapshot could not be loaded: '.mb_substr($e->getMessage(), 0, 300)], 422);
        } finally {
            @unlink($path);
            DB::purge('mirror');
        }

        $mirror->forceFill([
            'last_synced_at' => now(),
            'snapshot_made_at' => $done['made_at'] ? Carbon::parse($done['made_at']) : now(),
            'last_error' => null,
        ])->save();

        return response()->json(['loaded' => true, 'tables' => $done['tables'], 'rows' => $done['rows']]);
    }
}
