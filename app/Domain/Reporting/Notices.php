<?php

namespace App\Domain\Reporting;

use App\Domain\Ledger\BalanceService;
use App\Domain\Stock\StockConfidence;
use App\Enums\AccountCode;
use App\Models\Business;
use App\Models\User;

/**
 * What sits behind the bell in the top bar of every workspace page.
 *
 * Whether the figures can be trusted, how far through the day it is, and
 * anything that needs attention — the same things the dashboard used to spell
 * out, now one click away from anywhere. Read fresh on each page: an alert
 * that is a minute stale is an alert about a minute ago.
 */
class Notices
{
    public function __construct(
        private readonly BalanceService $balances,
        private readonly StockConfidence $confidence,
        private readonly AlertEngine $alerts,
        private readonly DayChecklist $checklist,
    ) {}

    /**
     * @return array{checks: list<array{0: string, 1: string, 2: string}>, unexplained: ?\App\Support\Money, day: array<string, mixed>, alerts: array<int, array<string, mixed>>, count: int, level: string}
     */
    public function for(Business $business, User $user): array
    {
        /*
         * The bell is on every page, and working it out reads the whole
         * ledger: for a business with a year of bills that is most of a
         * second per page. So it is kept, keyed on the newest change to
         * everything it reads — anything posted, entered, closed, sold,
         * received or counted makes a new key and it is worked out afresh at
         * once. The few minutes' limit is only a backstop. Nothing here is a
         * stored balance: it is what the page last showed, never a figure
         * anything else reads.
         */
        return \Illuminate\Support\Facades\Cache::remember(
            'notices:'.$business->id.':'.$user->id.':'.$this->fingerprint($business),
            now()->addMinutes(5),
            fn () => $this->compute($business, $user),
        );
    }

    private function fingerprint(Business $business): string
    {
        $latest = fn (string $model, string $column = 'updated_at') => (string) $model::forBusiness($business)->max($column);

        return md5(implode('|', [
            $business->today()->toDateString(),
            (string) $business->updated_at,
            $latest(\App\Models\Transaction::class, 'id'),
            $latest(\App\Models\StockMovement::class, 'id'),
            $latest(\App\Models\DailyEntry::class),
            $latest(\App\Models\DailyClosing::class),
            $latest(\App\Models\PosBill::class),
            $latest(\App\Models\Order::class),
            $latest(\App\Models\CompanyAudit::class),
            $latest(\App\Models\StockVerification::class),
        ]));
    }

    private function compute(Business $business, User $user): array
    {
        $today = $business->today();
        $trial = $this->balances->trialBalance($business, $today);
        $position = $this->balances->position($business, $today);
        $openDays = $this->alerts->unclosedDays($business);
        $confidence = $this->confidence->for($business);
        $alerts = $this->alerts->for($business);
        $day = $this->checklist->for($business, $user);

        $checks = [
            ['Trial balance', $trial['balanced'] ? 'Balanced' : 'Out by '.$trial['difference']->format(), $trial['balanced'] ? 'ok' : 'bad'],
            ['Position derivations', $position->isConsistent() ? 'Agree' : 'Disagree by '.$position->discrepancy()->format(), $position->isConsistent() ? 'ok' : 'bad'],
            ['Days', $openDays === 0 ? 'All closed' : $openDays.' open', $openDays === 0 ? 'ok' : 'warn'],
            ['Stock', ucfirst($confidence['label']), $confidence['stale'] ? 'warn' : 'ok'],
        ];

        // Operators do not see the business's financial standing, so equity
        // nobody has explained is not theirs to be told about.
        $equity = $user->can('viewReports', $business)
            ? $this->balances->asAt($business, AccountCode::OpeningBalanceEquity, $today)
            : null;
        $unexplained = $equity !== null && ! $equity->isZero() ? $equity : null;

        $broken = collect($alerts)->contains('level', 'critical') || collect($checks)->contains(2, 'bad');

        return [
            'checks' => $checks,
            'unexplained' => $unexplained,
            'day' => $day,
            'alerts' => $alerts,
            'count' => collect($checks)->where(2, '!=', 'ok')->count()
                + count($alerts) + ($unexplained ? 1 : 0) + ($day['complete'] ? 0 : 1),
            'level' => $broken ? 'critical' : 'warn',
        ];
    }
}
