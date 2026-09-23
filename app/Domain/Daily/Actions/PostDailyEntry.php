<?php

namespace App\Domain\Daily\Actions;

use App\Domain\Daily\DailyEntryPostingService;
use App\Enums\DocumentStatus;
use App\Exceptions\LedgerException;
use App\Models\DailyEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PostDailyEntry
{
    public function __construct(private readonly DailyEntryPostingService $posting) {}

    public function handle(DailyEntry $entry, User $by): DailyEntry
    {
        if (! $entry->isEditable()) {
            throw new LedgerException('This day has already been posted.');
        }

        if ($entry->isEmpty()) {
            throw new LedgerException('There is nothing to post for this day.');
        }

        if (! $entry->business->acceptsTransactions()) {
            throw new LedgerException(
                'This business cannot record activity until its opening balance is finalized.'
            );
        }

        // Everything financial inside one transaction, synchronously. A queued
        // posting that fails leaves a day with no ledger effect and no error.
        return DB::transaction(function () use ($entry, $by) {
            $this->posting->post($entry, $by);

            $entry->forceFill([
                'status' => DocumentStatus::Posted,
                'posted_by' => $by->id,
                'posted_at' => now(),
            ])->save();

            activity()
                ->performedOn($entry)
                ->causedBy($by)
                ->withProperties(['figures' => $entry->only(DailyEntry::MONEY_FIELDS)])
                ->event('daily_entry.posted')
                ->log('Daily entry posted');

            return $entry->fresh();
        });
    }
}
