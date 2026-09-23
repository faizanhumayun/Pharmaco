<?php

namespace App\Domain\Closing\Actions;

use App\Domain\Closing\ClosingGuard;
use App\Domain\Closing\DailyClosingCalculator;
use App\Exceptions\LedgerException;
use App\Models\Business;
use App\Models\DailyClosing;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinalizeDailyClosing
{
    public function __construct(
        private readonly DailyClosingCalculator $calculator,
        private readonly ClosingGuard $guard,
    ) {}

    public function handle(Business $business, Carbon $date, User $by): DailyClosing
    {
        $draft = DailyClosing::forBusiness($business)
            ->where('business_date', $date->toDateString())
            ->first();

        if ($draft?->isFinalized()) {
            throw new LedgerException('This day is already closed.');
        }

        $blockers = $this->guard->blockers($business, $date, $draft);

        if ($blockers !== []) {
            throw new LedgerException(implode(' ', $blockers));
        }

        return DB::transaction(function () use ($business, $date, $by) {
            $figures = $this->calculator->compute($business, $date);

            $closing = DailyClosing::updateOrCreate(
                ['business_id' => $business->id, 'business_date' => $date->toDateString()],
                [
                    ...array_map(fn (Money $m) => $m->toDecimal(), $figures),
                    'status' => 'finalized',
                    'finalized_by' => $by->id,
                    'finalized_at' => now(),
                    'built_at' => now(),
                ]
            );

            // From here nothing may post on or before this date.
            $business->forceFill(['locked_through_date' => $date->toDateString()])->save();

            activity()
                ->performedOn($closing)
                ->causedBy($by)
                ->withProperties(['figures' => array_map(fn (Money $m) => $m->toDecimal(), $figures)])
                ->event('closing.finalized')
                ->log('Day closed');

            return $closing;
        });
    }
}
