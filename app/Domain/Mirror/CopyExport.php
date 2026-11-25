<?php

namespace App\Domain\Mirror;

use App\Models\ShopMirror;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A shop's books as the platform holds them, written out as an ordinary
 * {@see Snapshot} — for a replacement computer when the shop's own is lost,
 * stolen or broken. `mirror:restore` puts it on the new one.
 *
 * A copy in a database of its own is the shop's snapshot as it was sent, so
 * it is simply written out again. A copy kept in the platform's own database
 * is turned back into the shop's numbering first — the same subtraction
 * {@see ReturnPackage} makes for a fix — and given what the shop's computer
 * needs that the platform shares between shops:
 *
 *   - logins: every one the shop's records name, and every one the shop
 *     brought. A platform administrator goes without its password and
 *     without the administrator flag: the new computer's own back-office
 *     login takes that place when the file is restored;
 *   - payment methods, roles and permissions: the platform's own lists, with
 *     their ids, which the shop's records already point at.
 *
 * Read only, and checked before it is handed over: every table holds as many
 * rows as the copy, and the ledger adds up to the copy's and balances.
 *
 * Raw rows without their models, by the same exception as the snapshot.
 */
class CopyExport
{
    /** Platform-only rows a record may point at: the pointer is dropped. */
    private const DROPPED = ['device_id'];

    /** Columns of the platform's own on a login; the shop starts them afresh. */
    private const USER_RESET = ['mirror_id' => null, 'remember_token' => null, 'signin_device' => null, 'last_login_at' => null];

    public function __construct(
        private readonly MergedImporter $importer,
        private readonly ReturnPackage $returns,
        private readonly Snapshot $snapshot,
    ) {}

    /**
     * @return array{tables: int, rows: int, businesses: int}
     */
    public function write(ShopMirror $mirror, string $path): array
    {
        if (! $mirror->isMerged()) {
            return $this->writeOwnDatabase($mirror, $path);
        }

        $base = MergedImporter::baseFor($mirror);
        $top = $base + MergedImporter::BLOCK;
        $db = DB::connection('main');
        $schema = Schema::connection('main');

        if (! $db->table('businesses')->where('mirror_id', $mirror->id)->exists()) {
            throw new RuntimeException("There is no copy of {$mirror->name}'s books here yet, so there is nothing to download.");
        }

        // Rows of this shop's that were numbered outside its block (written
        // without a model during a fix) are brought into it first.
        $this->returns->adoptStrays($mirror);

        $file = gzopen($path, 'wb6');

        if ($file === false) {
            throw new RuntimeException('The file could not be written.');
        }

        $done = ['tables' => 0, 'rows' => 0, 'businesses' => 0];
        $users = [];
        $ledger = ['debit' => '0', 'credit' => '0'];

        try {
            gzwrite($file, $this->line(['meta' => [
                'version' => Snapshot::VERSION,
                'made_at' => $mirror->snapshot_made_at?->toIso8601String() ?? now()->toIso8601String(),
                'app' => config('app.name'),
                'kind' => 'copy',
                'shop' => $mirror->name,
            ]]));

            foreach ($this->snapshot->tables('main') as $table) {
                if (in_array($table, MergedImporter::matchedTables(), true)) {
                    continue;
                }

                $columns = $schema->getColumnListing($table);
                $column = in_array('id', $columns, true) ? 'id' : (in_array('business_id', $columns, true) ? 'business_id' : null);

                if ($column === null) {
                    continue;
                }

                $kinds = $this->importer->columnKinds($table, $columns);

                gzwrite($file, $this->line(['table' => $table, 'columns' => $columns]));
                $done['tables']++;
                $sent = 0;

                $rows = $db->table($table)->where($column, '>=', $base)->where($column, '<', $top);

                foreach (($column === 'id' ? $rows->orderBy('id') : $rows)->cursor() as $row) {
                    $row = (array) $row;
                    $out = $this->homeward($table, $row, $kinds, $base, $users);

                    if ($table === 'businesses') {
                        $out['mirror_id'] = null;
                        $out['slug'] = $this->ownSlug((string) $out['slug'], $mirror);
                        $done['businesses']++;
                    }

                    if ($table === 'ledger_entries') {
                        $ledger['debit'] = bcadd($ledger['debit'], (string) ($row['debit'] ?? '0'), 2);
                        $ledger['credit'] = bcadd($ledger['credit'], (string) ($row['credit'] ?? '0'), 2);
                    }

                    gzwrite($file, $this->line(['row' => array_values($out)]));
                    $sent++;
                }

                $kept = $db->table($table)->where($column, '>=', $base)->where($column, '<', $top)->count();

                if ($kept !== $sent) {
                    throw new RuntimeException("{$table} changed while it was being written ({$sent} of {$kept} rows). Try again.");
                }

                $done['rows'] += $sent;
            }

            $sums = $db->table('ledger_entries')->where('id', '>=', $base)->where('id', '<', $top)
                ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();

            if (bccomp((string) $sums->d, $ledger['debit'], 2) !== 0 || bccomp((string) $sums->c, $ledger['credit'], 2) !== 0) {
                throw new RuntimeException('The ledger written out does not add up to the copy\'s. Nothing was downloaded.');
            }

            if (bccomp($ledger['debit'], $ledger['credit'], 2) !== 0) {
                throw new RuntimeException('The copy\'s ledger does not balance (debits '.$ledger['debit'].', credits '.$ledger['credit'].'). Nothing was downloaded.');
            }

            $done['rows'] += $this->writeShared($file, $db, $schema, $mirror, $users);
            $done['tables'] += 5;
        } catch (\Throwable $e) {
            gzclose($file);
            @unlink($path);

            throw $e;
        }

        gzclose($file);

        return $done;
    }

    // ---------------------------------------------------------------------

    /** A copy in a database of its own is the shop's snapshot already. */
    private function writeOwnDatabase(ShopMirror $mirror, string $path): array
    {
        config(['database.connections.mirror' => $mirror->connectionConfig()]);
        DB::purge('mirror');

        try {
            if (! Schema::connection('mirror')->hasTable('businesses') || ! DB::connection('mirror')->table('businesses')->exists()) {
                throw new RuntimeException("There is no copy of {$mirror->name}'s books here yet, so there is nothing to download.");
            }

            $done = $this->snapshot->write($path, 'mirror');

            return ['tables' => $done['tables'], 'rows' => $done['rows'], 'businesses' => DB::connection('mirror')->table('businesses')->count()];
        } finally {
            DB::purge('mirror');
        }
    }

    /**
     * Logins, payment methods, roles and permissions, which the platform
     * shares between shops and the shop's computer keeps for itself.
     *
     * @param  array<int, true>  $users  the logins the shop's records name
     */
    private function writeShared($file, $db, $schema, ShopMirror $mirror, array $users): int
    {
        $rows = 0;

        // Logins: those named, and those the shop brought with it.
        $columns = $schema->getColumnListing('users');
        gzwrite($file, $this->line(['table' => 'users', 'columns' => $columns]));

        $query = $db->table('users')->where(fn ($q) => $q->where('mirror_id', $mirror->id)->orWhereIn('id', array_keys($users)))->orderBy('id');

        foreach ($query->cursor() as $user) {
            $user = array_merge((array) $user, array_intersect_key(self::USER_RESET, array_flip($columns)));

            // The platform's own administrator does not travel with a shop's
            // books: no password that works, and no administrator flag.
            if ($user['is_platform_admin']) {
                $user['is_platform_admin'] = false;
                $user['password'] = Hash::make(Str::random(48));
            }

            gzwrite($file, $this->line(['row' => array_values($user)]));
            $rows++;
        }

        // The platform's lists, as they are: the shop's records point at their ids.
        $roles = $db->table('roles')->whereNull('business_id')->pluck('id')->all();

        $lists = [
            'payment_methods' => $db->table('payment_methods'),
            'permissions' => $db->table('permissions'),
            'roles' => $db->table('roles')->whereNull('business_id'),
            'role_has_permissions' => $db->table('role_has_permissions')->whereIn('role_id', $roles),
        ];

        foreach ($lists as $table => $query) {
            $columns = $schema->getColumnListing($table);
            gzwrite($file, $this->line(['table' => $table, 'columns' => $columns]));

            foreach ($query->get() as $row) {
                gzwrite($file, $this->line(['row' => array_values((array) $row)]));
                $rows++;
            }
        }

        return $rows;
    }

    /**
     * A row in the shop's own numbering. Logins it names are noted, so they
     * can be written out with it.
     *
     * @param  array<string, array{0: string, 1: ?string}>  $kinds
     * @param  array<int, true>  $users
     */
    private function homeward(string $table, array $row, array $kinds, int $base, array &$users): array
    {
        $out = [];

        foreach ($row as $column => $value) {
            [$kind, $detail] = $kinds[$column] ?? ['keep', null];

            if ($value === null || $kind === 'keep') {
                $out[$column] = $value;

                continue;
            }

            if ($kind === 'move') {
                $out[$column] = $this->outOfBlock($value, $base, $table, $column);

                continue;
            }

            $target = $kind === 'match' ? (string) $detail : $this->morphTarget($row[$detail] ?? null);

            if ($target === null) {
                $out[$column] = $value;
            } elseif (in_array($target, MergedImporter::matchedTables(), true)) {
                // The platform's own id, written out with its row below.
                $out[$column] = $value;

                if ($target === 'users') {
                    $users[(int) $value] = true;
                }
            } else {
                $out[$column] = $this->outOfBlock($value, $base, $table, $column);
            }
        }

        return $out;
    }

    private function morphTarget(mixed $class): ?string
    {
        return is_string($class) && class_exists($class) ? (new $class)->getTable() : null;
    }

    private function outOfBlock(mixed $value, int $base, string $table, string $column): ?int
    {
        $id = (int) $value;

        if ($id > $base && $id < $base + MergedImporter::BLOCK) {
            return $id - $base;
        }

        if (in_array($column, self::DROPPED, true)) {
            return null;
        }

        throw new RuntimeException("{$table}.{$column} points at {$id}, which is not one of this shop's records. Nothing was downloaded.");
    }

    /** The shop's own address for a business, without what was added to it here. */
    private function ownSlug(string $slug, ShopMirror $mirror): string
    {
        $suffix = '-'.($mirror->slug ?: 'shop-'.$mirror->id);

        return str_ends_with($slug, $suffix) && strlen($slug) > strlen($suffix) ? substr($slug, 0, -strlen($suffix)) : $slug;
    }

    private function line(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    }
}
