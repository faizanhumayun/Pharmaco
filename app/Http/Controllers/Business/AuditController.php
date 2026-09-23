<?php

namespace App\Http\Controllers\Business;

use App\Domain\Reporting\ChangeHistory;
use App\Models\User;
use Illuminate\Support\Carbon;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\CompanyProductImport;
use App\Models\DailyClosing;
use App\Models\DailyEntry;
use App\Models\OpeningBalance;
use App\Models\Order;
use App\Models\StockMovement;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit trail.
 *
 * Append-only: the application has no route that updates or deletes an activity
 * record, and this is the only one that reads them.
 */
class AuditController extends Controller
{
    public function __invoke(Request $request, Business $business, ChangeHistory $history): View
    {
        $this->authorize('viewAudit', $business);

        $subjects = [
            OpeningBalance::class => 'Opening balance',
            DailyEntry::class => 'Daily entry',
            DailyClosing::class => 'Daily closing',
            Order::class => 'Order form',
            StockMovement::class => 'Stock',
            CompanyProductImport::class => 'Price list',
        ];

        [$range, $from, $to] = $this->range($business, $request);

        $scoped = Activity::query()
            ->where(function ($q) use ($business, $subjects) {
                $q->where('business_id', $business->id);

                // Older records predate the business_id column, so fall back to
                // matching the subjects that belong to this business.
                foreach ($subjects as $type => $label) {
                    $q->orWhere(fn ($w) => $w
                        ->where('subject_type', $type)
                        ->whereIn('subject_id', $type::forBusiness($business)->pluck('id')));
                }
            });

        // Who appears in this business's trail — the choices for "who".
        $people = User::query()
            ->whereIn('id', (clone $scoped)->whereNotNull('causer_id')->distinct()->pluck('causer_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        $event = array_key_exists($request->string('event')->toString(), ChangeHistory::EVENT_LABELS)
            ? $request->string('event')->toString()
            : null;
        $who = $people->firstWhere('id', $request->integer('who'));

        $activities = (clone $scoped)
            // Saving an edit re-posts the day in the same moment; the edit is
            // the step, so the automatic re-post is not listed beside it.
            ->whereNot(fn ($q) => $q->where('event', 'daily_entry.posted')
                ->whereExists(fn ($e) => $e->selectRaw('1')
                    ->from('activity_log as edit')
                    ->where('edit.event', 'daily_entry.amended')
                    ->whereColumn('edit.subject_type', 'activity_log.subject_type')
                    ->whereColumn('edit.subject_id', 'activity_log.subject_id')
                    ->whereColumn('edit.causer_id', 'activity_log.causer_id')
                    ->whereColumn('edit.created_at', 'activity_log.created_at')))
            ->when($event, fn ($q) => $q->where('event', $event))
            ->when($who, fn ($q) => $q->where('causer_id', $who->id))
            // Periods are the business's own days; the log is stored in UTC.
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from->copy()->shiftTimezone($business->timezone)->startOfDay()->utc()))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to->copy()->shiftTimezone($business->timezone)->endOfDay()->utc()))
            ->with(['causer', 'subject'])
            ->latest()
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Activity $a) => $history->feedItem($business, $a));

        return view('business.audit', [
            'business' => $business,
            'activities' => $activities,
            // Only the kinds of step this business has, in plain words.
            'events' => collect(ChangeHistory::EVENT_LABELS)
                ->only((clone $scoped)->whereNotNull('event')->distinct()->pluck('event')->all())
                ->all(),
            'people' => $people,
            'event' => $event,
            'who' => $who,
            'range' => $range,
            'ranges' => self::RANGES,
            'from' => $from,
            'to' => $to,
        ]);
    }

    private const RANGES = [
        'all' => 'All time',
        'today' => 'Today',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'month' => 'This month',
        'custom' => 'Custom dates',
    ];

    /** @return array{0: string, 1: ?Carbon, 2: ?Carbon} business-local dates */
    private function range(Business $business, Request $request): array
    {
        $range = array_key_exists($request->string('range')->toString(), self::RANGES)
            ? $request->string('range')->toString()
            : 'all';

        $today = Carbon::parse($business->today()->toDateString());

        [$from, $to] = match ($range) {
            'today' => [$today->copy(), $today->copy()],
            '7d' => [$today->copy()->subDays(6), $today->copy()],
            '30d' => [$today->copy()->subDays(29), $today->copy()],
            'month' => [$today->copy()->startOfMonth(), $today->copy()],
            'custom' => [
                $request->date('from') ? Carbon::parse($request->date('from')->toDateString()) : null,
                $request->date('to') ? Carbon::parse($request->date('to')->toDateString()) : null,
            ],
            default => [null, null],
        };

        return [$range, $from, $to];
    }
}
