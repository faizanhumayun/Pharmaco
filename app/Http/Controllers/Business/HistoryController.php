<?php

namespace App\Http\Controllers\Business;

use App\Domain\Reporting\HistoryQuery;
use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class HistoryController extends Controller
{
    /** Ranges an owner actually asks for, rather than a date picker alone. */
    private const PRESETS = [
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        'month' => 'This month',
        'last_month' => 'Last month',
        'year' => 'This year',
        'all' => 'All time',
        'custom' => 'Custom range',
    ];

    private const GRANULARITIES = ['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly'];

    public function __invoke(Request $request, Business $business, HistoryQuery $history): View
    {
        $this->authorize('viewReports', $business);

        $preset = $request->string('preset')->toString() ?: '30d';
        [$from, $to] = $this->range($business, $preset, $request);

        // A daily chart over three years is unreadable; nudge the granularity
        // to something the eye can actually follow.
        $granularity = $request->string('granularity')->toString()
            ?: $this->suggestGranularity($from, $to);

        $data = $history->build($business, $from, $to, $granularity);

        // Only the active pane is rendered: a Chart.js canvas laid out inside a
        // hidden container measures zero and never recovers when shown.
        $view = $request->string('view')->toString();

        return view('business.history', [
            'business' => $business,
            'data' => $data,
            // The listing is the default; the charts are one click away.
            'view' => in_array($view, ['charts', 'table'], true) ? $view : 'table',

            // The founding position, shown whatever the range: the figures the
            // business started from are context for every period after them.
            'startingPoint' => $business->openingBalance()
                ->with(['lines.account', 'corrections.lines.account'])
                ->first(),
            'previous' => $history->comparison($business, $from, $to, $granularity),
            'presets' => self::PRESETS,
            'granularities' => self::GRANULARITIES,
            'preset' => $preset,
            'granularity' => $granularity,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Business $business, string $preset, Request $request): array
    {
        $today = $business->today();

        // Never earlier than the day after the opening date: the opening
        // position is a starting point, not activity. When a business opened
        // today there is no trading history yet, so the range collapses to
        // today rather than pointing at a future day.
        $earliest = $business->opening_date?->copy()->addDay() ?? $today->copy()->subYear();

        if ($earliest->greaterThan($today)) {
            $earliest = $today->copy();
        }

        [$from, $to] = match ($preset) {
            '7d' => [$today->copy()->subDays(6), $today->copy()],
            '90d' => [$today->copy()->subDays(89), $today->copy()],
            'month' => [$today->copy()->startOfMonth(), $today->copy()],
            'last_month' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'year' => [$today->copy()->startOfYear(), $today->copy()],
            'all' => [$earliest->copy(), $today->copy()],
            'custom' => [
                $request->date('from') ?? $today->copy()->subDays(29),
                $request->date('to') ?? $today->copy(),
            ],
            default => [$today->copy()->subDays(29), $today->copy()],
        };

        // Plain calendar dates in one timezone. Some of these come from the
        // business's clock and some from stored dates; mixed, a comparison
        // between them is off by the timezone and the last day falls out.
        $from = Carbon::parse(Carbon::parse($from)->toDateString());
        $to = Carbon::parse(Carbon::parse($to)->toDateString());

        if ($from->lessThan($earliest)) {
            $from = $earliest->copy();
        }

        if ($to->lessThan($from)) {
            $to = $from->copy();
        }

        return [$from, $to];
    }

    private function suggestGranularity(Carbon $from, Carbon $to): string
    {
        $days = $from->diffInDays($to);

        return match (true) {
            $days > 400 => 'month',
            $days > 92 => 'week',
            default => 'day',
        };
    }
}
