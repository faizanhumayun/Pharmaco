<?php

namespace App\Domain\Mirror;

use App\Models\ShopMirror;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Puts a shop's {@see Snapshot} into the platform's own database, beside the
 * businesses that work online, replacing that shop's previous copy.
 *
 * Every record a shop sends has an id that means something only on the shop's
 * computer, and would collide with the platform's own. So each shop is given a
 * block of ids of its own, and every id — and every reference to one — is
 * moved into that block by adding the same number to it:
 *
 *     the shop's bill 57   →   bill 100,000,057   for the first shop
 *                              bill 200,000,057   for the second
 *
 * Because it is the same number every time, a record keeps its id from one
 * copy to the next, so a page that was open stays the right page.
 *
 * Three things are not moved, because the platform has its own:
 *   - people: matched to the platform's logins by email, and created when new;
 *   - payment methods: matched by name;
 *   - roles and permissions: the platform's own are used.
 *
 * It refuses to guess. A column that looks like a reference and is not one it
 * has been told about stops the import with its name, and before anything is
 * kept the copy is checked against the snapshot: the same number of rows in
 * every table, and the same ledger totals to the paisa. A copy that silently
 * differed from the shop's books would be worse than no copy.
 *
 * All or nothing, in one transaction. Raw rows, without their models, by the
 * same exception to the rule as {@see SnapshotImporter}.
 */
class MergedImporter
{
    /** Ids a single shop may use in any one table. */
    public const BLOCK = 100_000_000;

    /** Shops whose blocks fit under the platform's own new ids. */
    public const MAX_SHOPS = 999;

    /**
     * Where the platform's own new ids continue from once a copy is kept
     * here: above every shop's block, so the two can never meet.
     */
    public const PLATFORM_FLOOR = 100_000_000_000;

    /** Matched to the platform's own rows rather than copied. */
    private const MATCHED = ['users', 'payment_methods', 'roles', 'permissions', 'role_has_permissions'];

    /** Columns that end like a reference and are not one. */
    private const NOT_REFERENCES = ['pos_bill_lines.short_by', 'businesses.mirror_id'];

    /** Columns that name a business with no foreign key to say so. */
    private const BUSINESS_COLUMNS = ['business_id'];

    public static function baseFor(ShopMirror $mirror): int
    {
        if ($mirror->id < 1 || $mirror->id > self::MAX_SHOPS) {
            throw new RuntimeException('Only '.self::MAX_SHOPS.' shops can be kept in the main database. This one needs a database of its own.');
        }

        return $mirror->id * self::BLOCK;
    }

    /**
     * @return array{tables: int, rows: int, made_at: ?string, businesses: int}
     */
    public function import(string $path, ShopMirror $mirror): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('The snapshot file is not there.');
        }

        $base = self::baseFor($mirror);
        $db = DB::connection('main');
        $schema = Schema::connection('main');
        $skip = config('mirror.skip_tables', []);

        // First reading: the snapshot's own lists of people, payment methods
        // and roles, which every other table refers to.
        $lookups = $this->readLookups($path);

        $done = ['tables' => 0, 'rows' => 0, 'made_at' => $lookups['made_at'], 'businesses' => 0];

        // The platform's own new ids are moved above every shop's block
        // before the first copy goes in. Outside the transaction: changing
        // where a table counts from cannot be undone by one.
        $this->raisePlatformFloor($db, $schema, $path, $skip);

        $db->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $db->transaction(function () use ($db, $schema, $skip, $path, $mirror, $base, $lookups, &$done) {
                $users = $this->matchUsers($db, $lookups['users'], $mirror);
                $methods = $this->matchPaymentMethods($db, $lookups['payment_methods']);
                $roles = $this->matchByName($db, 'roles', $lookups['roles']);
                $permissions = $this->matchByName($db, 'permissions', $lookups['permissions']);

                $foreign = $this->foreignKeys($db);
                $maps = ['users' => $users, 'payment_methods' => $methods, 'roles' => $roles, 'permissions' => $permissions];

                $file = gzopen($path, 'rb');
                $table = null;
                $columns = [];
                $plan = [];
                $batch = [];
                $counts = [];
                $ledger = ['debit' => '0', 'credit' => '0'];

                $flush = function () use (&$batch, &$table, $db) {
                    if ($batch !== [] && $table !== null) {
                        $db->table($table)->insert($batch);
                    }

                    $batch = [];
                };

                try {
                    while (($line = gzgets($file)) !== false) {
                        $data = json_decode($line, true);

                        if (! is_array($data)) {
                            throw new RuntimeException('The snapshot is damaged: a line could not be read.');
                        }

                        if (isset($data['meta'])) {
                            continue;
                        }

                        if (isset($data['table'])) {
                            $flush();

                            $table = (string) $data['table'];

                            if (in_array($table, $skip, true) || in_array($table, self::MATCHED, true) || ! $schema->hasTable($table)) {
                                $table = null;

                                continue;
                            }

                            $here = array_flip($schema->getColumnListing($table));
                            $columns = array_values(array_filter($data['columns'] ?? [], fn ($c) => isset($here[$c])));
                            $positions = array_flip($data['columns'] ?? []);
                            $plan = $this->plan($table, $columns, $positions, $foreign, $schema);

                            // The shop's previous copy of this table goes first.
                            $this->clear($db, $table, $base, isset($here['id']), isset($here['business_id']));

                            $counts[$table] = 0;
                            $done['tables']++;

                            continue;
                        }

                        if ($table === null || ! isset($data['row'])) {
                            continue;
                        }

                        $row = [];

                        foreach ($plan as $column => [$position, $kind, $detail]) {
                            $value = $data['row'][$position] ?? null;
                            $row[$column] = $this->moved($value, $kind, $detail, $data['row'], $base, $maps, $table, $column);
                        }

                        if ($table === 'businesses') {
                            $row['mirror_id'] = $mirror->id;
                            $row['slug'] = $this->freeSlug($db, (string) $row['slug'], (int) $row['id'], $mirror);
                            $done['businesses']++;
                        }

                        if ($table === 'ledger_entries') {
                            $ledger['debit'] = bcadd($ledger['debit'], (string) ($row['debit'] ?? '0'), 2);
                            $ledger['credit'] = bcadd($ledger['credit'], (string) ($row['credit'] ?? '0'), 2);
                        }

                        $batch[] = $row;
                        $counts[$table]++;
                        $done['rows']++;

                        if (count($batch) >= 400) {
                            $flush();
                        }
                    }

                    $flush();
                } finally {
                    gzclose($file);
                }

                if ($done['tables'] === 0) {
                    throw new RuntimeException('The snapshot held no tables.');
                }

                $this->verify($db, $schema, $base, $counts, $ledger);
            });
        } finally {
            $db->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // What each login may do is remembered; the copy may have changed it.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $done;
    }

    /** Removes everything a shop's copy put here. Used when the shop is unregistered. */
    public function purge(ShopMirror $mirror): void
    {
        $base = self::baseFor($mirror);
        $db = DB::connection('main');
        $schema = Schema::connection('main');

        $db->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $db->transaction(function () use ($db, $schema, $base, $mirror) {
                foreach (app(Snapshot::class)->tables('main') as $table) {
                    if (in_array($table, self::MATCHED, true)) {
                        continue;
                    }

                    $columns = array_flip($schema->getColumnListing($table));
                    $this->clear($db, $table, $base, isset($columns['id']), isset($columns['business_id']));
                }

                // Logins that came with the shop and belong to nothing else.
                $db->table('users')->where('mirror_id', $mirror->id)
                    ->whereNotExists(fn ($q) => $q->from('business_user')->whereColumn('business_user.user_id', 'users.id'))
                    ->delete();
            });
        } finally {
            $db->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    // ---------------------------------------------------------------------

    /**
     * @return array{made_at: ?string, users: list<array<string, mixed>>, payment_methods: list<array<string, mixed>>, roles: list<array<string, mixed>>, permissions: list<array<string, mixed>>}
     */
    private function readLookups(string $path): array
    {
        $found = ['made_at' => null, 'users' => [], 'payment_methods' => [], 'roles' => [], 'permissions' => []];
        $file = gzopen($path, 'rb');

        if ($file === false) {
            throw new RuntimeException('The snapshot file could not be opened.');
        }

        $table = null;
        $columns = [];

        while (($line = gzgets($file)) !== false) {
            $data = json_decode($line, true);

            if (isset($data['meta'])) {
                if (($data['meta']['version'] ?? null) !== Snapshot::VERSION) {
                    gzclose($file);

                    throw new RuntimeException('This snapshot was made by a different version and cannot be read.');
                }

                $found['made_at'] = $data['meta']['made_at'] ?? null;
            } elseif (isset($data['table'])) {
                $table = array_key_exists($data['table'], $found) ? $data['table'] : null;
                $columns = $data['columns'] ?? [];
            } elseif ($table !== null && isset($data['row'])) {
                $found[$table][] = array_combine($columns, $data['row']);
            }
        }

        gzclose($file);

        return $found;
    }

    /**
     * The shop's people, as the platform's logins. Matched by email. A new
     * one is created with the shop's own name and password, and never as a
     * platform administrator — a shop cannot make itself one.
     *
     * @return array<int, int> the shop's user id → the platform's
     */
    private function matchUsers($db, array $users, ShopMirror $mirror): array
    {
        $map = [];
        $existing = $db->table('users')->whereIn('email', array_column($users, 'email'))->get()->keyBy(fn ($u) => mb_strtolower($u->email));

        foreach ($users as $user) {
            $here = $existing->get(mb_strtolower((string) $user['email']));

            if ($here === null) {
                $map[(int) $user['id']] = (int) $db->table('users')->insertGetId([
                    'mirror_id' => $mirror->id,
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'email_verified_at' => $user['email_verified_at'] ?? null,
                    'password' => $user['password'],
                    'is_platform_admin' => false,
                    'is_active' => (bool) ($user['is_active'] ?? true),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                continue;
            }

            // A login this shop brought is kept in step with the shop. One
            // the platform made, or another shop brought, is left alone.
            if ((int) $here->mirror_id === $mirror->id) {
                $db->table('users')->where('id', $here->id)->update([
                    'name' => $user['name'],
                    'password' => $user['password'],
                    'is_active' => (bool) ($user['is_active'] ?? true),
                    'updated_at' => now(),
                ]);
            }

            $map[(int) $user['id']] = (int) $here->id;
        }

        return $map;
    }

    /** @return array<int, int> */
    private function matchPaymentMethods($db, array $methods): array
    {
        $map = [];
        $existing = $db->table('payment_methods')->get()->keyBy(fn ($m) => mb_strtolower($m->name));

        foreach ($methods as $method) {
            $here = $existing->get(mb_strtolower((string) $method['name']));

            $map[(int) $method['id']] = $here !== null ? (int) $here->id : (int) $db->table('payment_methods')->insertGetId([
                'name' => $method['name'],
                'kind' => $method['kind'],
                'is_active' => (bool) ($method['is_active'] ?? true),
                'sort_order' => (int) ($method['sort_order'] ?? 0),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $map;
    }

    /** @return array<int, int> */
    private function matchByName($db, string $table, array $rows): array
    {
        $existing = $db->table($table)->pluck('id', 'name');
        $map = [];

        foreach ($rows as $row) {
            if (isset($existing[$row['name']])) {
                $map[(int) $row['id']] = (int) $existing[$row['name']];
            }
        }

        return $map;
    }

    /** @return array<string, string> "table.column" → the table it points at */
    private function foreignKeys($db): array
    {
        return collect($db->select(
            'select table_name as t, column_name as c, referenced_table_name as r from information_schema.key_column_usage
             where table_schema = ? and referenced_table_name is not null',
            [$db->getDatabaseName()]
        ))->mapWithKeys(fn ($row) => [$row->t.'.'.$row->c => (string) $row->r])->all();
    }

    /**
     * What is done to each column of a table on its way in.
     *
     * @return array<string, array{0: int, 1: string, 2: mixed}> column → [position in the row, kind, detail]
     */
    private function plan(string $table, array $columns, array $positions, array $foreign, $schema): array
    {
        $plan = [];

        foreach ($columns as $column) {
            $position = $positions[$column];
            $target = $foreign[$table.'.'.$column] ?? null;
            $typeColumn = preg_replace('/_id$/', '_type', $column);

            $plan[$column] = match (true) {
                $column === 'id' => [$position, 'move', null],
                $target !== null && in_array($target, self::MATCHED, true) => [$position, 'match', $target],
                $target !== null => [$position, 'move', null],
                in_array($column, self::BUSINESS_COLUMNS, true) => [$position, 'move', null],
                in_array($table.'.'.$column, self::NOT_REFERENCES, true) => [$position, 'keep', null],
                // A reference whose kind is written beside it: source_type / source_id.
                str_ends_with($column, '_id') && isset($positions[$typeColumn]) => [$position, 'morph', $positions[$typeColumn]],
                // It looks like a reference and nothing says what to. Stop
                // rather than copy a number that points at the wrong record.
                str_ends_with($column, '_id') || str_ends_with($column, '_by') => throw new RuntimeException(
                    "The column {$table}.{$column} looks like a reference, and the copy has not been told what it refers to. Nothing was changed."
                ),
                default => [$position, 'keep', null],
            };
        }

        return $plan;
    }

    private function moved(mixed $value, string $kind, mixed $detail, array $row, int $base, array $maps, string $table, string $column): mixed
    {
        if ($value === null || $kind === 'keep') {
            return $value;
        }

        if ($kind === 'move') {
            return $this->intoBlock($value, $base, $table, $column);
        }

        if ($kind === 'match') {
            // Someone the snapshot does not list (a login removed from the
            // shop since): the reference is dropped rather than misdirected.
            return $maps[$detail][(int) $value] ?? null;
        }

        // A reference with its kind beside it: follow the kind to its table.
        $class = $row[$detail] ?? null;

        if (! is_string($class) || ! class_exists($class)) {
            return $value;
        }

        $target = (new $class)->getTable();

        return in_array($target, self::MATCHED, true)
            ? ($maps[$target][(int) $value] ?? null)
            : $this->intoBlock($value, $base, $table, $column);
    }

    private function intoBlock(mixed $value, int $base, string $table, string $column): int
    {
        $id = (int) $value;

        if ($id < 1 || $id >= self::BLOCK) {
            throw new RuntimeException("{$table}.{$column} holds {$id}, which is outside what one shop's copy can hold.");
        }

        return $base + $id;
    }

    private function clear($db, string $table, int $base, bool $hasId, bool $hasBusinessId): void
    {
        $column = $hasId ? 'id' : ($hasBusinessId ? 'business_id' : null);

        if ($column === null) {
            throw new RuntimeException("The table {$table} has neither an id nor a business, so its rows cannot be told apart by shop.");
        }

        $db->table($table)->where($column, '>=', $base)->where($column, '<', $base + self::BLOCK)->delete();
    }

    /** The shop's own address for the business if it is free here; otherwise one that is. */
    private function freeSlug($db, string $slug, int $id, ShopMirror $mirror): string
    {
        $taken = fn (string $candidate) => $db->table('businesses')->where('slug', $candidate)->where('id', '!=', $id)->exists();

        if (! $taken($slug)) {
            return $slug;
        }

        $candidate = Str::limit($slug, 150, '').'-'.($mirror->slug ?: 'shop-'.$mirror->id);

        return $taken($candidate) ? $candidate.'-'.$id : $candidate;
    }

    /**
     * The copy against the snapshot, before any of it is kept: every table
     * holds as many of this shop's rows as were sent, and its ledger adds up
     * to what the shop's did — and balances.
     */
    private function verify($db, $schema, int $base, array $counts, array $ledger): void
    {
        foreach ($counts as $table => $sent) {
            $column = $schema->hasColumn($table, 'id') ? 'id' : 'business_id';
            $kept = $db->table($table)->where($column, '>=', $base)->where($column, '<', $base + self::BLOCK)->count();

            if ($kept !== $sent) {
                throw new RuntimeException("The copy of {$table} holds {$kept} rows and the shop sent {$sent}. Nothing was changed.");
            }
        }

        if (isset($counts['ledger_entries'])) {
            $sums = $db->table('ledger_entries')->where('id', '>=', $base)->where('id', '<', $base + self::BLOCK)
                ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();

            if (bccomp((string) $sums->d, $ledger['debit'], 2) !== 0 || bccomp((string) $sums->c, $ledger['credit'], 2) !== 0) {
                throw new RuntimeException('The copied ledger does not add up to the shop\'s. Nothing was changed.');
            }

            if (bccomp($ledger['debit'], $ledger['credit'], 2) !== 0) {
                throw new RuntimeException('The shop\'s own ledger does not balance (debits '.$ledger['debit'].', credits '.$ledger['credit'].'). Nothing was changed.');
            }
        }
    }

    /**
     * Before the first copy goes into a table, the platform's own new ids in
     * it are set to continue above every shop's block. Rows the platform
     * already has keep their ids; only new ones are affected.
     */
    private function raisePlatformFloor($db, $schema, string $path, array $skip): void
    {
        foreach (app(Snapshot::class)->tables('main') as $table) {
            if (in_array($table, $skip, true) || in_array($table, self::MATCHED, true) || ! $schema->hasColumn($table, 'id')) {
                continue;
            }

            $highest = (int) $db->table($table)->max('id');

            if ($highest >= self::PLATFORM_FLOOR) {
                continue;
            }

            // The platform's own rows must all sit below the first shop's block.
            $own = (int) $db->table($table)->where('id', '<', self::BLOCK)->max('id');
            $between = $db->table($table)->where('id', '>=', self::BLOCK * (self::MAX_SHOPS + 1))->where('id', '<', self::PLATFORM_FLOOR)->exists();

            if ($own >= self::BLOCK - 1 || $between) {
                throw new RuntimeException("The table {$table} already uses ids a shop's copy would need. This platform needs separate databases for its shops.");
            }

            $db->statement('ALTER TABLE `'.$table.'` AUTO_INCREMENT = '.self::PLATFORM_FLOOR);
        }
    }
}
