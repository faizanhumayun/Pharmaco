<?php

namespace App\Domain\Mirror;

use App\Models\ShopMirror;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * What changed in a shop's books while the back office had them, written out
 * for the shop's computer to take back.
 *
 * When the shop's last entries arrive and its computer locks, a fingerprint
 * of every one of its rows is kept here ({@see self::keepBaseline()}). When
 * the fix is done, the rows are fingerprinted again: one that differs was
 * changed, one that is new was added, one that is gone was removed. Only
 * those go back — and each is first turned back into the shop's own
 * numbering, the reverse of what {@see MergedImporter} did on the way in.
 *
 * The package ends with what the shop's books must then hold (rows per table,
 * the ledger's totals), so the shop's computer can check itself before it
 * opens ({@see ReturnApplier}).
 *
 * Raw rows without their models, by the same exception as the snapshot.
 */
class ReturnPackage
{
    public const VERSION = 1;

    /** Platform-only rows a returning record may point at: the pointer is dropped. */
    private const DROPPED = ['device_id'];

    /** Columns of a business that belong to this side and stay here. */
    private const OURS = ['businesses' => ['mirror_id', 'slug']];

    public function __construct(private readonly MergedImporter $importer, private readonly Snapshot $snapshot) {}

    /**
     * Remembers the books as the shop left them, and how its logins and
     * payment methods were matched to the platform's, for the way back.
     *
     * @param  array<string, array<int, int>>  $maps  the shop's id → the platform's
     */
    public function keepBaseline(ShopMirror $mirror, array $maps): void
    {
        $prints = [];

        foreach ($this->tables() as $table) {
            $prints[$table] = $this->fingerprint($mirror, $table);
        }

        Storage::disk('local')->put($this->file($mirror), gzencode(json_encode(['maps' => $maps, 'tables' => $prints]), 6));
    }

    public function hasBaseline(ShopMirror $mirror): bool
    {
        return Storage::disk('local')->exists($this->file($mirror));
    }

    public function forgetBaseline(ShopMirror $mirror): void
    {
        Storage::disk('local')->delete($this->file($mirror));
    }

    /**
     * Writes the package of changes to a file.
     *
     * @return array{changed: int, removed: int, tables: int}
     */
    public function write(ShopMirror $mirror, string $path): array
    {
        if (! $this->hasBaseline($mirror)) {
            throw new RuntimeException('The books as the shop left them were not kept, so what changed cannot be worked out.');
        }

        $baseline = json_decode((string) gzdecode((string) Storage::disk('local')->get($this->file($mirror))), true);
        $base = MergedImporter::baseFor($mirror);
        $db = DB::connection('main');

        $this->adoptStrays($mirror);

        // The way home for logins, payment methods and roles: the platform's id → the shop's.
        $home = array_map(fn (array $map) => array_flip($map), $baseline['maps'] ?? []);
        $home['fallback_user'] = $this->fallbackUser($db, $home['users'] ?? []);

        $file = gzopen($path, 'wb6');

        if ($file === false) {
            throw new RuntimeException('The package could not be written.');
        }

        $done = ['changed' => 0, 'removed' => 0, 'tables' => 0];
        $counts = [];
        $ledger = ['debit' => '0', 'credit' => '0'];

        try {
            gzwrite($file, json_encode(['meta' => ['version' => self::VERSION, 'kind' => 'return', 'made_at' => now()->toIso8601String()]])."\n");

            foreach ($this->tables() as $table) {
                $columns = Schema::connection('main')->getColumnListing($table);
                $kinds = $this->importer->columnKinds($table, $columns);
                $before = $baseline['tables'][$table] ?? ['ids' => []];
                $hasId = in_array('id', $columns, true);
                $counts[$table] = 0;
                $opened = false;

                $open = function (string $mode) use (&$opened, $file, $table, &$done) {
                    if (! $opened) {
                        gzwrite($file, json_encode(['table' => $table, 'mode' => $mode])."\n");
                        $opened = true;
                        $done['tables']++;
                    }
                };

                if (! $hasId) {
                    // No id to tell one row from another: the shop's rows of
                    // this table go back whole, when any of them changed.
                    $rows = $this->rows($db, $table, $base, false)->get()->map(fn ($row) => (array) $row)->all();
                    $counts[$table] = count($rows);

                    if ($this->printOfAll($rows) !== ($before['all'] ?? null)) {
                        $open('replace');

                        foreach ($rows as $row) {
                            gzwrite($file, $this->line(['put' => $this->homeward($table, $row, $kinds, $base, $home)]));
                            $done['changed']++;
                        }
                    }

                    continue;
                }

                $seen = [];

                foreach ($this->rows($db, $table, $base, true)->orderBy('id')->cursor() as $row) {
                    $row = (array) $row;
                    $id = (int) $row['id'];
                    $seen[$id] = true;
                    $counts[$table]++;

                    if ($table === 'ledger_entries') {
                        $ledger['debit'] = bcadd($ledger['debit'], (string) ($row['debit'] ?? '0'), 2);
                        $ledger['credit'] = bcadd($ledger['credit'], (string) ($row['credit'] ?? '0'), 2);
                    }

                    if (($before['ids'][$id] ?? null) === $this->printOf($row)) {
                        continue;
                    }

                    $open('rows');
                    gzwrite($file, $this->line(['put' => $this->homeward($table, $row, $kinds, $base, $home)]));
                    $done['changed']++;
                }

                foreach (array_keys($before['ids'] ?? []) as $id) {
                    if (! isset($seen[(int) $id])) {
                        $open('rows');
                        gzwrite($file, json_encode(['del' => (int) $id - $base])."\n");
                        $done['removed']++;
                    }
                }
            }

            if (bccomp($ledger['debit'], $ledger['credit'], 2) !== 0) {
                throw new RuntimeException('The ledger no longer balances (debits '.$ledger['debit'].', credits '.$ledger['credit'].'). Nothing was sent back.');
            }

            gzwrite($file, json_encode(['end' => ['counts' => $counts, 'ledger' => $ledger] + $done])."\n");
        } finally {
            gzclose($file);
        }

        return $done;
    }

    // ---------------------------------------------------------------------

    /** @return list<string> the tables a shop's copy lives in */
    private function tables(): array
    {
        $schema = Schema::connection('main');

        return array_values(array_filter(
            $this->snapshot->tables('main'),
            fn (string $table) => ! in_array($table, MergedImporter::matchedTables(), true)
                && ($schema->hasColumn($table, 'id') || $schema->hasColumn($table, 'business_id'))
        ));
    }

    private function rows($db, string $table, int $base, bool $hasId)
    {
        $column = $hasId ? 'id' : 'business_id';

        return $db->table($table)->where($column, '>=', $base)->where($column, '<', $base + MergedImporter::BLOCK);
    }

    /** @return array{ids?: array<int, string>, all?: string} */
    private function fingerprint(ShopMirror $mirror, string $table): array
    {
        $base = MergedImporter::baseFor($mirror);
        $db = DB::connection('main');

        if (! Schema::connection('main')->hasColumn($table, 'id')) {
            return ['all' => $this->printOfAll($this->rows($db, $table, $base, false)->get()->map(fn ($row) => (array) $row)->all())];
        }

        $ids = [];

        foreach ($this->rows($db, $table, $base, true)->orderBy('id')->cursor() as $row) {
            $ids[(int) $row->id] = $this->printOf((array) $row);
        }

        return ['ids' => $ids];
    }

    private function printOf(array $row): string
    {
        // As text, so a number read back as a number or as its digits is the same row.
        return md5(json_encode(array_map(fn ($value) => $value === null ? null : (string) $value, array_values($row)), JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function printOfAll(array $rows): string
    {
        $prints = array_map(fn (array $row) => $this->printOf($row), $rows);
        sort($prints);

        return md5(implode('', $prints));
    }

    private function line(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    }

    /**
     * A row in the shop's own numbering.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, array{0: string, 1: ?string}>  $kinds
     * @return array<string, mixed>
     */
    private function homeward(string $table, array $row, array $kinds, int $base, array $home): array
    {
        $out = [];

        foreach ($row as $column => $value) {
            if (in_array($column, self::OURS[$table] ?? [], true)) {
                continue;
            }

            [$kind, $detail] = $kinds[$column] ?? ['keep', null];

            $out[$column] = match (true) {
                $value === null || $kind === 'keep' => $value,
                $kind === 'move' => $this->outOfBlock($value, $base, $table, $column),
                $kind === 'match' => $this->matched((string) $detail, (int) $value, $home, $table, $column),
                default => $this->morph($row[$detail] ?? null, $value, $base, $home, $table, $column),
            };
        }

        return $out;
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

        throw new RuntimeException("{$table}.{$column} points at {$id}, which is not one of this shop's records. Nothing was sent back.");
    }

    private function matched(string $target, int $id, array $home, string $table, string $column): int
    {
        if (isset($home[$target][$id])) {
            return (int) $home[$target][$id];
        }

        // Someone from the back office the shop's computer has no login for:
        // the work is put under the back-office login it does have.
        if ($target === 'users' && $home['fallback_user'] !== null) {
            return $home['fallback_user'];
        }

        throw new RuntimeException("{$table}.{$column} names a record in {$target} that the shop's computer does not have. Nothing was sent back.");
    }

    private function morph(mixed $class, mixed $value, int $base, array $home, string $table, string $column): mixed
    {
        if (! is_string($class) || ! class_exists($class)) {
            return $value;
        }

        $target = (new $class)->getTable();

        return in_array($target, MergedImporter::matchedTables(), true)
            ? $this->matched($target, (int) $value, $home, $table, $column)
            : $this->outOfBlock($value, $base, $table, $column);
    }

    /** The shop's own id for a back-office login it has, if it has one. */
    private function fallbackUser($db, array $home): ?int
    {
        if ($home === []) {
            return null;
        }

        $admin = $db->table('users')->where('is_platform_admin', true)->whereIn('id', array_keys($home))->orderBy('id')->value('id');

        return $admin !== null ? (int) $home[(int) $admin] : null;
    }

    /**
     * Rows that belong to this shop but were given one of the platform's own
     * ids — written by something that does not go through a model, so
     * {@see \App\Support\BlockIds} never saw them. Where nothing else can
     * point at such a row it is simply renumbered into the block. Where
     * something could, it stops: a moved id would leave a pointer behind.
     */
    private function adoptStrays(ShopMirror $mirror): void
    {
        $base = MergedImporter::baseFor($mirror);
        $top = $base + MergedImporter::BLOCK;
        $db = DB::connection('main');
        $schema = Schema::connection('main');

        $pointedAt = collect($db->select(
            'select distinct referenced_table_name as r from information_schema.key_column_usage where table_schema = ? and referenced_table_name is not null',
            [$db->getDatabaseName()]
        ))->pluck('r')->flip();

        foreach ($this->tables() as $table) {
            $columns = $schema->getColumnListing($table);

            if (! in_array('id', $columns, true)) {
                continue;
            }

            $own = array_keys(array_filter(
                $this->importer->columnKinds($table, $columns),
                fn (array $kind, string $column) => $kind[0] === 'move' && $column !== 'id' && ! in_array($column, self::DROPPED, true),
                ARRAY_FILTER_USE_BOTH
            ));

            if ($own === []) {
                continue;
            }

            $strays = $db->table($table)->where('id', '>=', MergedImporter::PLATFORM_FLOOR)
                ->where(function ($query) use ($own, $base, $top) {
                    foreach ($own as $column) {
                        $query->orWhere(fn ($q) => $q->where($column, '>=', $base)->where($column, '<', $top));
                    }
                })
                ->orderBy('id')->pluck('id');

            if ($strays->isEmpty()) {
                continue;
            }

            if (isset($pointedAt[$table])) {
                throw new RuntimeException("{$strays->count()} new rows in {$table} were numbered outside this shop's block, and other records may point at them. Nothing was sent back.");
            }

            $next = max((int) $db->table($table)->where('id', '>=', $base)->where('id', '<', $top)->max('id'), $base);

            foreach ($strays as $id) {
                $db->table($table)->where('id', $id)->update(['id' => ++$next]);
            }
        }
    }

    private function file(ShopMirror $mirror): string
    {
        return 'mirror/baseline/'.$mirror->id.'.json.gz';
    }
}
