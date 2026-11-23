<?php

namespace App\Console\Commands;

use App\Domain\Mirror\ShopPause;
use App\Domain\Mirror\Snapshot;
use App\Domain\Mirror\SyncStatus;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The shop's computer: take a snapshot of the books and send it to the
 * platform, where the owner can read it from anywhere.
 *
 * Runs every few minutes from the scheduler. With no internet it does the one
 * thing it still can — keeps the snapshot on this computer's own disk as a
 * backup — and tries the sending again next time. Unchanged books are not
 * sent twice.
 */
class MirrorPush extends Command
{
    protected $signature = 'mirror:push
        {--local : Only write the snapshot to this computer; send nothing}
        {--force : Send even if nothing has changed since the last one sent}
        {--final : The last entries before this computer locks for a fix}';

    protected $description = 'Snapshot the books and send them to the platform as the read-only copy';

    /** Small enough for any server's upload limit. */
    private const PIECE = 1024 * 1024;

    public function handle(Snapshot $snapshot, SyncStatus $status, ShopPause $pause): int
    {
        $final = (bool) $this->option('final');

        // Locked for a fix: the books are the back office's for now, and
        // sending this computer's would only be refused.
        if ($pause->active() && ! $final) {
            $this->info('Paused for a fix: nothing is sent until the books come back.');

            return self::SUCCESS;
        }

        // Read before the snapshot, so anything entered while it is being
        // taken still counts as not yet sent.
        $marker = $status->marker();

        $disk = Storage::disk('local');
        $disk->makeDirectory('mirror/out');

        $name = 'mirror/out/books-'.now()->format('Y-m-d-His').'.jsonl.gz';
        $made = $snapshot->write($disk->path($name));

        $this->info(sprintf('Snapshot written: %s rows in %d tables (%s).', number_format($made['rows']), $made['tables'], basename($name)));

        $this->prune($disk);

        if ($this->option('local')) {
            return self::SUCCESS;
        }

        $url = config('mirror.push_url');
        $token = config('mirror.push_token');

        if (blank($url) || blank($token)) {
            $this->warn('Not sent: MIRROR_PUSH_URL and MIRROR_PUSH_TOKEN are not set on this computer.');

            return self::SUCCESS;
        }

        $last = $disk->exists('mirror/last-sent.json') ? json_decode((string) $disk->get('mirror/last-sent.json'), true) : null;

        if (! $this->option('force') && ! $final && ($last['hash'] ?? null) === $made['hash']) {
            $this->info('Nothing has changed since the last one sent.');
            // The same books twice over is one backup too many.
            $disk->delete($name);
            $status->recordAttempt('unchanged', null, $marker);

            return self::SUCCESS;
        }

        try {
            $answer = $this->send($disk->path($name), (string) $url, (string) $token, $final);
        } catch (ConnectionException $e) {
            // "No internet" is the usual reason, but not the only one: a
            // mistyped address or a certificate this computer does not trust
            // look the same from here. The reason is kept so it can be read.
            $this->warn('The platform could not be reached. The snapshot is kept on this computer and will be sent next time.');
            $this->line('  Reason: '.$e->getMessage());
            $status->recordAttempt('offline', mb_substr($e->getMessage(), 0, 300), $marker);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('The platform did not accept the snapshot: '.$e->getMessage());
            $status->recordAttempt('failed', mb_substr($e->getMessage(), 0, 300), $marker);

            return self::FAILURE;
        }

        $disk->put('mirror/last-sent.json', json_encode(['hash' => $made['hash'], 'sent_at' => now()->toIso8601String()]));
        $status->recordAttempt('sent', null, $marker);
        $this->info('Sent. The online copy is up to date.');

        // The platform took them as the last entries: the lock holds.
        if ($final && ($answer['entry'] ?? null) === \App\Models\ShopMirror::OFFICE) {
            $pause->confirm();
        }

        return self::SUCCESS;
    }

    /** In pieces, so the size of the books never meets a server's upload limit. */
    private function send(string $path, string $url, string $token, bool $final = false): array
    {
        $upload = (string) Str::uuid();
        $total = max(1, (int) ceil(filesize($path) / self::PIECE));
        $file = fopen($path, 'rb');

        try {
            for ($index = 0; $index < $total; $index++) {
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->timeout($index === $total - 1 ? 600 : 60)
                    ->attach('piece', (string) fread($file, self::PIECE), 'piece')
                    ->post($url, ['upload' => $upload, 'index' => $index, 'total' => $total] + ($final ? ['final' => 1] : []));

                if (! $response->successful()) {
                    throw new \RuntimeException($response->json('message') ?? 'it answered '.$response->status());
                }
            }
        } finally {
            fclose($file);
        }

        return (array) ($response->json() ?? []);
    }

    private function prune($disk): void
    {
        $files = collect($disk->files('mirror/out'))->sort()->values();

        $files->slice(0, max(0, $files->count() - max(1, (int) config('mirror.keep'))))->each(fn ($old) => $disk->delete($old));
    }
}
