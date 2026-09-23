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

        DB::transaction(function () use ($business, $id, $by, $summary) {
            // Logged before anything goes, so the platform keeps a record that
            // this tenant existed and who removed it.
            activity()
                ->causedBy($by)
                ->withProperties(['business' => $summary])
                ->event('business.deleted')
                ->log("Business deleted: {$summary['name']}");

            // The audit trail survives the business it described.
            Activity::where('business_id', $id)->update(['business_id' => null]);

            // Self-references and cross-references first, or the deletes below
            // trip over their own foreign keys.
            DB::table('transactions')->where('business_id', $id)
                ->update(['reversal_of_id' => null, 'reversed_by_id' => null]);
            DB::table('opening_balances')->where('business_id', $id)->update(['transaction_id' => null]);
            DB::table('stock_verifications')->where('business_id', $id)->update(['transaction_id' => null]);
            DB::table('companies')->where('business_id', $id)->update(['account_id' => null]);

            DB::table('ledger_entries')->where('business_id', $id)->delete();
            DB::table('transactions')->where('business_id', $id)->delete();

            $entryIds = DB::table('daily_entries')->where('business_id', $id)->pluck('id');
            DB::table('expense_lines')->whereIn('daily_entry_id', $entryIds)->delete();
            DB::table('daily_entries')->where('business_id', $id)->delete();

            $openingIds = DB::table('opening_balances')->where('business_id', $id)->pluck('id');
            DB::table('opening_balance_lines')->whereIn('opening_balance_id', $openingIds)->delete();
            DB::table('opening_balances')->where('business_id', $id)->delete();

            DB::table('daily_closings')->where('business_id', $id)->delete();
            DB::table('stock_verifications')->where('business_id', $id)->delete();
            DB::table('companies')->where('business_id', $id)->delete();
            DB::table('expense_categories')->where('business_id', $id)->delete();

            // Children before parents: accounts reference each other.
            DB::table('accounts')->where('business_id', $id)->whereNotNull('parent_id')->delete();
            DB::table('accounts')->where('business_id', $id)->delete();

            DB::table('business_user')->where('business_id', $id)->delete();
            DB::table('businesses')->where('id', $id)->delete();
        });

        return $summary;
    }
}
