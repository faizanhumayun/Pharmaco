<?php

namespace App\Domain\Businesses\Actions;

use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Removes a business and everything belonging to it.
 *
 * This is the one deliberate exception to the rule that financial records are
 * never destroyed. Every other path in the system reverses, adjusts or
 * archives; this one is a platform-level teardown of an entire tenant, guarded
 * by the App Owner check and by having to type the business name.
 *
 * It uses query-builder deletes rather than Eloquent on purpose: the models
 * refuse to delete posted records, and that guard is right everywhere except
 * here. Keeping the exception in a single, clearly-named class is what stops it
 * becoming a general-purpose escape hatch.
 */
class DeleteBusiness
{
    public function handle(Business $business, User $by): array
    {
        $id = $business->id;

        $summary = [
            'name' => $business->name,
            'slug' => $business->slug,
            'had_history' => $business->hasFinancialHistory(),
            'transactions' => DB::table('transactions')->where('business_id', $id)->count(),
            'entries' => DB::table('daily_entries')->where('business_id', $id)->count(),
            'closings' => DB::table('daily_closings')->where('business_id', $id)->count(),
        ];

        // Foreign-key checks are a setting of the connection, not of the
        // transaction, so they are put back whatever happens.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            DB::transaction(function () use ($id, $by, $summary) {
                // Logged before anything goes, so the platform keeps a record that
                // this tenant existed and who removed it.
                activity()
                    ->causedBy($by)
                    ->withProperties(['business' => $summary])
                    ->event('business.deleted')
                    ->log("Business deleted: {$summary['name']}");

                // The audit trail survives the business it described.
                Activity::where('business_id', $id)->update(['business_id' => null]);

                /*
                 * Everything else that belongs to the business goes, found from
                 * the schema rather than from a list kept here: a list has to be
                 * remembered every time a table is added, and the first one
                 * forgotten is the one that stops the delete half way.
                 *
                 * Two kinds of table hold a tenant's rows. Those with a
                 * business_id are emptied by it. Those without one — the lines
                 * of a day, of an order, of an import — belong to a row that
                 * has one, and are followed down from it first.
                 */
                $owned = $this->tablesWithBusinessId();

                foreach ($owned as $table) {
                    $this->purgeChildren($table, fn () => DB::table($table)->where('business_id', $id)->select('id'), $owned);
                }

                foreach ($owned as $table) {
                    DB::table($table)->where('business_id', $id)->delete();
                }

                DB::table('businesses')->where('id', $id)->delete();
            });
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return $summary;
    }

    /**
     * Every table that holds rows for a business by naming it. The audit trail
     * is left out — it is kept — and so is the business's own row, which goes last.
     *
     * @return list<string>
     */
    private function tablesWithBusinessId(): array
    {
        return collect(DB::select(
            'select table_name as t from information_schema.columns where table_schema = ? and column_name = ?',
            [DB::getDatabaseName(), 'business_id']
        ))->pluck('t')->reject(fn ($t) => in_array($t, ['activity_log', 'businesses'], true))->values()->all();
    }

    /**
     * Removes the rows that hang off a set of parent rows through a foreign
     * key, in tables that carry no business_id of their own — and, before
     * each, whatever hangs off those in turn.
     *
     * @param  \Closure(): \Illuminate\Database\Query\Builder  $parentIds  a query selecting the parents' ids
     * @param  list<string>  $owned  tables emptied by business_id, never followed into
     */
    private function purgeChildren(string $parent, \Closure $parentIds, array $owned, int $depth = 0): void
    {
        // The schema is a few levels deep; this only guards against a loop in it.
        if ($depth > 6) {
            return;
        }

        $children = DB::select(
            'select table_name as t, column_name as c from information_schema.key_column_usage
             where table_schema = ? and referenced_table_name = ? and referenced_column_name = ?',
            [DB::getDatabaseName(), $parent, 'id']
        );

        foreach ($children as $child) {
            if (in_array($child->t, $owned, true) || $child->t === $parent || ! $this->hasId($child->t)) {
                // Owned tables are emptied by business_id; a table with no id
                // of its own has nothing below it to follow.
                if (! in_array($child->t, $owned, true) && $child->t !== $parent) {
                    DB::table($child->t)->whereIn($child->c, $parentIds())->delete();
                }

                continue;
            }

            $this->purgeChildren(
                $child->t,
                fn () => DB::table($child->t)->whereIn($child->c, $parentIds())->select('id'),
                $owned,
                $depth + 1,
            );

            DB::table($child->t)->whereIn($child->c, $parentIds())->delete();
        }
    }

    private function hasId(string $table): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn($table, 'id');
    }
}
