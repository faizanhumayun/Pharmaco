<?php

namespace App\Domain\Daily\Actions;

use App\Domain\Ledger\LedgerPoster;
use App\Enums\TransactionType;
use App\Exceptions\LedgerException;
use App\Models\DailyEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reverses a posted day so it can be entered again.
 *
 * Both the original entry and its reversal stay visible. The date slot is
 * freed by marking the document reversed rather than deleting it, because the
 * audit trail needs to show that the day was entered, corrected, and re-entered.
 */
class ReverseDailyEntry
{
    public function __construct(private readonly LedgerPoster $poster) {}

    public function handle(DailyEntry $entry, string $reason, User $by): DailyEntry
    {
        if ($entry->isEditable()) {
            throw new LedgerException('This day has not been posted, so there is nothing to reverse.');
        }

        if (trim($reason) === '') {
            throw new LedgerException('A reversal must say why.');
        }

        return DB::transaction(function () use ($entry, $reason, $by) {
            foreach ($entry->transactions()->where('type', '!=', TransactionType::Reversal)->get() as $transaction) {
                if (! $transaction->isReversed()) {
                    $this->poster->reverse($transaction, $reason, $by);
                }
            }

            $entry->forceFill(['notes' => trim(($entry->notes ? $entry->notes."\n" : '')
                .'Reversed: '.$reason)])->save();

            activity()
                ->performedOn($entry)
                ->causedBy($by)
                ->withProperties(['reason' => $reason])
                ->event('daily_entry.reversed')
                ->log('Daily entry reversed');

            return $entry->fresh();
        });
    }
}
