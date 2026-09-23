<?php

namespace App\Domain\Closing\Actions;

use App\Exceptions\LedgerException;
use App\Models\Business;
use App\Models\DailyClosing;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reopens the most recently closed day.
 *
 * Only the latest, and only in sequence — reopening an older day would leave
 * the chain broken, with closed days sitting after an open one. The superseded
 * closing is kept so the audit trail shows both what was reported and what it
 * became.
 */
class ReopenDailyClosing
{
    public function handle(Business $business, Carbon $date, string $reason, User $by): DailyClosing
    {
        if (trim($reason) === '') {
            throw new LedgerException('Reopening a closed day must say why.');
        }

        $closing = DailyClosing::forBusiness($business)
            ->where('business_date', $date->toDateString())
            ->finalized()
            ->first();

        if ($closing === null) {
            throw new LedgerException('That day is not closed.');
        }

        if (! $business->locked_through_date?->equalTo($date)) {
            throw new LedgerException(
                'Only the most recently closed day can be reopened. Reopen the later days first.'
            );
        }

        return DB::transaction(function () use ($business, $closing, $date, $reason, $by) {
            $closing->forceFill([
                'status' => 'superseded',
                'reopen_reason' => $reason,
            ])->save();

            $previous = DailyClosing::forBusiness($business)
                ->where('business_date', '<', $date->toDateString())
                ->finalized()
                ->orderByDesc('business_date')
                ->first();

            $business->forceFill([
                'locked_through_date' => $previous?->business_date?->toDateString(),
            ])->save();

            activity()
                ->performedOn($closing)
                ->causedBy($by)
                ->withProperties(['reason' => $reason, 'date' => $date->toDateString()])
                ->event('closing.reopened')
                ->log('Day reopened');

            return $closing->fresh();
        });
    }
}
