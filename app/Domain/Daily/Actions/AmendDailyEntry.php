<?php

namespace App\Domain\Daily\Actions;

use App\Domain\Ledger\LedgerPoster;
use App\Enums\DocumentStatus;
use App\Enums\TransactionType;
use App\Exceptions\LedgerException;
use App\Models\DailyEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites a posted day that has not been closed yet.
 *
 * The document is editable; the ledger is not. So an amendment reverses what
 * the day previously posted and posts the new figures, leaving both sets
 * visible. Nothing is mutated in place and nothing disappears — the statement
 * shows the original, its reversal, and the replacement.
 */
class AmendDailyEntry
{
    public function __construct(
        private readonly LedgerPoster $poster,
        private readonly SaveDailyEntry $save,
        private readonly PostDailyEntry $post,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(DailyEntry $entry, array $data, User $by): DailyEntry
    {
        if ($entry->isEditable()) {
            throw new LedgerException('This day is still a draft — save it normally.');
        }

        if ($entry->business->isDayClosed($entry->business_date)) {
            throw new LedgerException(
                'This day is closed and can no longer be edited. Reopen it, or post an '
                .'adjustment in the open period.'
            );
        }

        return DB::transaction(function () use ($entry, $data, $by) {
            $before = collect($entry->only(DailyEntry::MONEY_FIELDS))
                ->map(fn ($m) => (string) $m)->all();

            $reason = 'Amended before the day was closed';

            foreach ($entry->transactions()->where('type', '!=', TransactionType::Reversal)->get() as $transaction) {
                if (! $transaction->isReversed()) {
                    $this->poster->reverse($transaction, $reason, $by);
                }
            }

            // Back to draft so the figures can be written, then posted again.
            // The immutability guard still holds for everything else.
            $entry->forceFill([
                'status' => DocumentStatus::Draft,
                'posted_by' => null,
                'posted_at' => null,
            ])->save();

            $this->save->handle($entry->business, $data, $by);
            $this->post->handle($entry->fresh(), $by);

            $fresh = $entry->fresh();

            activity()
                ->performedOn($fresh)
                ->causedBy($by)
                ->withProperties([
                    'before' => $before,
                    'after' => collect($fresh->only(DailyEntry::MONEY_FIELDS))->map(fn ($m) => (string) $m)->all(),
                ])
                ->event('daily_entry.amended')
                ->log('Daily entry amended');

            return $fresh;
        });
    }
}
