<?php

namespace App\Domain\Businesses\Actions;

use App\Domain\Ledger\ChartOfAccounts;
use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
 *
 * {@see self::clear()} is the same teardown stopped one step short: everything
 * recorded in the business goes, and the business itself — its name, its
 * settings and the people who work in it — stays, back where a new business
 * starts. It exists for a business whose first import or opening figures were
 * wrong, so it can be started again without being set up again.
 */
class DeleteBusiness
{
    /** What a cleared business keeps: who works there, and in what role. */
    private const KEPT_ON_CLEAR = ['business_user', 'model_has_roles', 'model_has_permissions', 'roles'];

    /**
     * Empties a business and leaves it standing.
     *
     * Kept: the business and its settings, its members and their roles.
     * Gone: products, stock, companies, customers, days, bills, orders,
     * imports, the opening balance and every ledger entry. The chart of
     * accounts is laid down again, the opening date is taken off, and the
     * business goes back to setup — exactly where a new one begins.
     *
     * @return array<string, mixed>
     */
    public function clear(Business $business, User $by): array
    {
        $id = $business->id;

        $summary = [
            'name' => $business->name,
            'slug' => $business->slug,
            'transactions' => DB::table('transactions')->where('business_id', $id)->count(),
            'entries' => DB::table('daily_entries')->where('business_id', $id)->count(),
            'closings' => DB::table('daily_closings')->where('business_id', $id)->count(),
            'products' => DB::table('company_products')->where('business_id', $id)->count(),
            'members_kept' => DB::table('business_user')->where('business_id', $id)->count(),
        ];

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            DB::transaction(function () use ($business, $id, $by, $summary) {
                // The audit trail of what is being removed stays with the
                // platform, as it does when a business is deleted outright.
                Activity::where('business_id', $id)->update(['business_id' => null]);

                $this->purge($id, self::KEPT_ON_CLEAR);

                // Back to the state a new business is created in.
                DB::table('businesses')->where('id', $id)->update([
                    'opening_date' => null,
                    'locked_through_date' => null,
                    'status' => $business->status === BusinessStatus::Active ? BusinessStatus::Setup->value : $business->status->value,
                    'updated_at' => now(),
                ]);

                app(ChartOfAccounts::class)->seed($business->fresh());

                activity()
                    ->performedOn($business)
                    ->causedBy($by)
                    ->withProperties(['business' => $summary])
                    ->event('business.data_deleted')
                    ->log("All data deleted from {$summary['name']}; the business was kept");
            });
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // Files uploaded for it: old POS databases and price lists.
        Storage::disk('local')->deleteDirectory('imports/'.$id);
        Storage::disk('local')->deleteDirectory('price-lists/'.$id);

        return $summary;
    }

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

                $this->purge($id);

                DB::table('businesses')->where('id', $id)->delete();
            });
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return $summary;
    }

    /**
     * Removes every row that belongs to a business, found from the schema
     * rather than from a list kept here: a list has to be remembered every
     * time a table is added, and the first one forgotten is the one that
     * stops the delete half way.
     *
     * Two kinds of table hold a tenant's rows. Those with a business_id are
     * emptied by it. Those without one — the lines of a day, of an order, of
     * an import — belong to a row that has one, and are followed down from it
     * first.
     *
     * @param  list<string>  $keep  tables with a business_id to leave as they are
     */
    private function purge(int $id, array $keep = []): void
    {
        $owned = $this->tablesWithBusinessId();
        $going = array_values(array_diff($owned, $keep));

        foreach ($going as $table) {
            $this->purgeChildren($table, fn () => DB::table($table)->where('business_id', $id)->select('id'), $owned);
        }

        foreach ($going as $table) {
            DB::table($table)->where('business_id', $id)->delete();
        }
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
