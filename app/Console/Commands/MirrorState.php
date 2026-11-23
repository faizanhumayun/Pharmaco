<?php

namespace App\Console\Commands;

use App\Domain\Mirror\ReturnApplier;
use App\Domain\Mirror\ShopPause;
use App\Models\ShopMirror;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * The shop's computer asking the platform, once a minute, where its books
 * are being entered — and doing what the answer calls for.
 *
 *   shop       carry on (and open again, if it was locked)
 *   pausing    lock, send the last entries, stay locked once they are taken
 *   office     stay locked: the back office is working on the books
 *   returning  take the changes back, check them, tell the platform, open
 *
 * It also settles whether the platform can be reached at all, which is what
 * the Online / Offline mark on every screen reads.
 */
class MirrorState extends Command
{
    protected $signature = 'mirror:state';

    protected $description = 'Ask the platform whether this shop is paused for a fix, and act on the answer';

    public function handle(ShopPause $pause, ReturnApplier $applier): int
    {
        $url = (string) config('mirror.push_url');
        $token = (string) config('mirror.push_token');

        if (! config('mirror.shop') || blank($url) || blank($token)) {
            return self::SUCCESS;
        }

        $base = preg_replace('#/receive/?$#', '', $url);

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(8)->connectTimeout(3)->get($base.'/state');
        } catch (ConnectionException) {
            // No internet: nothing changes. A locked shop stays locked, an
            // open one stays open.
            Cache::put('mirror.reachable', false, 150);

            return self::SUCCESS;
        } catch (\Throwable) {
            return self::SUCCESS;
        }

        Cache::put('mirror.reachable', true, 150);

        // A platform that does not know the question yet has no pauses.
        if (! $response->successful() || ! is_string($response->json('entry'))) {
            return self::SUCCESS;
        }

        $entry = $response->json('entry');
        $phone = $response->json('phone');

        match ($entry) {
            ShopMirror::PAUSING => $this->lockAndSend($pause, $phone),
            ShopMirror::OFFICE => $pause->confirm($phone),
            ShopMirror::RETURNING => $this->takeBack($pause, $applier, $base, $token, $phone),
            default => $this->open($pause),
        };

        return self::SUCCESS;
    }

    /**
     * Locked first, then sent: nothing can be entered behind the last
     * entries. If they do not get through, the lock comes off again and it
     * is tried next minute — a shop is never left locked for a fix that has
     * not started.
     */
    private function lockAndSend(ShopPause $pause, ?string $phone): void
    {
        $pause->start($phone);

        $this->call('mirror:push', ['--final' => true]);

        if (! $pause->details()['confirmed']) {
            $pause->clear();
            $this->warn('The last entries did not get through. This computer stays open and will try again.');
        }
    }

    private function takeBack(ShopPause $pause, ReturnApplier $applier, string $base, string $token, ?string $phone): void
    {
        // Locked while it happens, whatever state this computer was in.
        $pause->confirm($phone);

        $disk = Storage::disk('local');
        $disk->makeDirectory('mirror/return');
        $path = $disk->path('mirror/return/package.jsonl.gz');

        try {
            $response = Http::withToken($token)->timeout(600)->connectTimeout(5)->sink($path)->get($base.'/return');

            if (! $response->successful()) {
                throw new \RuntimeException(json_decode((string) @file_get_contents($path), true)['message'] ?? 'the platform answered '.$response->status());
            }

            $done = $applier->apply($path);
        } catch (ConnectionException) {
            return; // Next minute.
        } catch (\Throwable $e) {
            $pause->note($phone, mb_substr($e->getMessage(), 0, 300));
            $this->error('The changes could not be taken back: '.$e->getMessage());

            rescue(fn () => Http::withToken($token)->acceptJson()->timeout(20)
                ->post($base.'/returned', ['ok' => false, 'message' => mb_substr($e->getMessage(), 0, 900)]), report: false);

            return;
        } finally {
            @unlink($path);
        }

        try {
            $confirmed = Http::withToken($token)->acceptJson()->timeout(30)->post($base.'/returned', ['ok' => true]);
        } catch (\Throwable) {
            // The books are back and right; the platform will be told next
            // minute (applying the same package twice changes nothing).
            return;
        }

        if ($confirmed->successful()) {
            $this->info(sprintf('The books are back: %d rows changed, %d removed.', $done['changed'], $done['removed']));
            $this->open($pause);
        }
    }

    /** Opens the shop again, and sends its books so the online copy is the shop's once more. */
    private function open(ShopPause $pause): void
    {
        if (! $pause->active()) {
            return;
        }

        $pause->clear();
        $this->call('mirror:push', ['--force' => true]);
    }
}
