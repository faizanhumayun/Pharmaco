<?php

namespace App\Console\Commands;

use App\Domain\Mirror\SnapshotImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Puts the books back from a snapshot file — onto a replacement computer, or
 * onto a new one that is taking over from the platform. The file is either
 * one of this computer's own backups, or a shop's books downloaded from the
 * platform (Shop copies → Download books).
 *
 * It replaces everything in this installation's database, so it asks first.
 * This computer's own back-office login survives it: a downloaded file does
 * not carry the platform administrator's password, and a backup's may be
 * older than the one in use.
 */
class MirrorRestore extends Command
{
    protected $signature = 'mirror:restore {file : A snapshot file made by mirror:push, or downloaded from Shop copies} {--force : Do not ask}';

    protected $description = 'Replace this installation\'s books with those in a snapshot file';

    public function handle(SnapshotImporter $importer): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error('There is no file at '.$file);

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('This replaces EVERYTHING in this installation\'s database with the snapshot. Continue?')) {
            return self::SUCCESS;
        }

        $db = DB::connection((string) config('database.default'));

        // This computer's back-office logins, as they are now.
        $admins = $db->getSchemaBuilder()->hasTable('users')
            ? $db->table('users')->where('is_platform_admin', true)->get()->map(fn ($user) => (array) $user)->all()
            : [];

        $done = $importer->import($file, (string) config('database.default'));

        $this->keepAdmins($db, $admins);

        $this->info(sprintf('Restored %s rows into %d tables. Snapshot made %s.', number_format($done['rows']), $done['tables'], $done['made_at'] ?? 'at an unknown time'));

        foreach ($admins as $admin) {
            $this->line('Back-office login kept: '.$admin['email']);
        }

        return self::SUCCESS;
    }

    /**
     * The back-office logins this computer had before the restore: the same
     * person in the file (by email) gets this computer's password and the
     * administrator flag back; one the file does not have is added.
     *
     * @param  list<array<string, mixed>>  $admins
     */
    private function keepAdmins($db, array $admins): void
    {
        $columns = array_flip($db->getSchemaBuilder()->getColumnListing('users'));

        foreach ($admins as $admin) {
            $here = $db->table('users')->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $admin['email'])])->first();

            if ($here !== null) {
                $db->table('users')->where('id', $here->id)->update([
                    'password' => $admin['password'],
                    'is_platform_admin' => true,
                    'is_active' => true,
                    'updated_at' => now(),
                ]);

                continue;
            }

            $row = array_intersect_key($admin, $columns);
            unset($row['id']);
            $db->table('users')->insert(array_merge($row, array_intersect_key(['remember_token' => null, 'signin_device' => null], $columns), ['updated_at' => now()]));
        }
    }
}
