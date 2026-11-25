<?php

namespace App\Domain\Reporting;

use App\Enums\TransactionType;
use App\Models\Business;
use App\Models\DailyClosing;
use App\Models\DailyEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Who did what to a day, in the words an owner would use.
 *
 * Read from the audit trail that every action already writes — nothing here
 * records anything. Each item names the person and their role, the time in the
 * business's own timezone, what happened, why when a reason was given, and for
 * an edit, each figure before and after.
 *
 * @phpstan-type Item array{at: Carbon, who: string, role: ?string, action: string, detail: ?string, reason: ?string, changes: array<int, array{0: string, 1: ?string, 2: ?string, 3: ?Money}>, kind: string, amount: ?Money}
 */
class ChangeHistory
{
    /** Field names as the daily form labels them. */
    private const FIELD_LABELS = [
        'purchase_total' => 'Purchases',
        'purchase_paid' => 'Paid to companies',
        'purchase_discount' => 'Purchase discount',
        'purchase_return' => 'Purchase returns',
        'sale_cash' => 'Cash received on sales',
        'sale_credit' => 'Sold on credit',
        'sales_return' => 'Sales returns',
        'gross_profit' => 'Gross profit',
        'collection_cash' => 'Collected on earlier credit',
        'discount_allowed' => 'Discount allowed',
        'bad_debt' => 'Bad debt',
        'company_payment_cash' => 'Company payments (older field)',
        'discount_received' => 'Discount received',
        'expenses_cash' => 'Expenses',
        'owner_drawing' => 'Owner drawings',
        'owner_capital' => 'Owner capital',
    ];

    public const EVENT_LABELS = [
        'pos.sold' => 'Sale',
        'pos.edited' => 'Bill changed',
        'pos.returned' => 'Items returned',
        'delivery.received' => 'Stock received',
        'delivery.corrected' => 'Delivery corrected',
        'daily_entry.posted' => 'Daily entry posted',
        'daily_entry.amended' => 'Daily entry changed',
        'daily_entry.reversed' => 'Daily entry cancelled',
        'closing.cash_counted' => 'Cash counted',
        'closing.finalized' => 'Day closed',
        'closing.reopened' => 'Day reopened',
        'opening_balance.finalized' => 'Opening balance finalized',
        'team.member_added' => 'User added',
        'team.member_updated' => 'User changed',
        'order.sent' => 'Order sent',
        'price_list.applied' => 'Price list imported',
        'product.updated' => 'Product changed',
        'products.pieces_set' => 'Pack sizes updated',
        'stock.adjusted' => 'Stock adjusted',
        'stock.imported' => 'Opening stock imported',
        'business.payment_methods' => 'Payment methods changed',
        'business.imported' => 'Data imported',
        'report.exported' => 'Report downloaded',
        'business.deleted' => 'Business deleted',
    ];

    /** What sort of step each event is — drives its colour on the page. */
    private const EVENT_KINDS = [
        'pos.sold' => 'good',
        'pos.edited' => 'change',
        'pos.returned' => 'bad',
        'delivery.received' => 'info',
        'delivery.corrected' => 'change',
        'product.updated' => 'change',
        'daily_entry.posted' => 'good',
        'daily_entry.amended' => 'change',
        'daily_entry.reversed' => 'bad',
        'closing.cash_counted' => 'info',
        'closing.finalized' => 'good',
        'closing.reopened' => 'change',
        'opening_balance.finalized' => 'good',
        'team.member_added' => 'info',
        'team.member_updated' => 'change',
        'order.sent' => 'good',
        'price_list.applied' => 'change',
        'stock.adjusted' => 'change',
        'stock.imported' => 'good',
        'business.deleted' => 'bad',
    ];

    /** @var array<int, ?string> role label per user, for this business */
    private array $roles = [];

    /** @return Collection<int, Item> newest first */
    public function forDailyEntry(DailyEntry $entry): Collection
    {
        $business = $entry->business;

        $logged = Activity::query()
            ->where('subject_type', $entry->getMorphClass())
            ->where('subject_id', $entry->id)
            ->with('causer')
            ->get()
            ->map(fn (Activity $a) => $this->fromActivity($business, $a));

        // Being entered is not an audit event; it is the record's own origin.
        $created = [$this->item(
            $business,
            $entry->created_at,
            $entry->creator,
            'Daily entry created',
            kind: 'info',
        )];

        return $this->foldEdits(collect($created)->concat($logged))
            ->sortByDesc(fn ($i) => $i['at'])->values();
    }

    /**
     * An edit to a posted day re-posts it in the same moment, so the trail has
     * "changed" and "posted" side by side for one act. Shown as one step.
     *
     * @param  Collection<int, Item>  $items
     * @return Collection<int, Item>
     */
    private function foldEdits(Collection $items): Collection
    {
        $edits = $items->filter(fn ($i) => $i['action'] === self::EVENT_LABELS['daily_entry.amended']);

        return $items
            ->reject(fn ($i) => $i['action'] === self::EVENT_LABELS['daily_entry.posted']
                && $edits->contains(fn ($e) => $e['who'] === $i['who'] && $e['at']->equalTo($i['at'])))
            ->map(fn ($i) => match (true) {
                $i['action'] !== self::EVENT_LABELS['daily_entry.amended'] => $i,
                $i['changes'] === [] => [...$i, 'action' => 'Saved without change', 'kind' => 'neutral',
                    'detail' => 'No figures changed.'],
                default => [...$i, 'action' => self::EVENT_LABELS['daily_entry.amended']],
            })
            ->values();
    }

    /** @return Collection<int, Item> newest first */
    public function forClosingDay(Business $business, Carbon $date): Collection
    {
        $closing = DailyClosing::forBusiness($business)
            ->where('business_date', $date->toDateString())
            ->first();

        $activities = $closing
            ? Activity::query()
                ->where('subject_type', $closing->getMorphClass())
                ->where('subject_id', $closing->id)
                ->with('causer')
                ->oldest()
                ->oldest('id')
                ->get()
            : collect();

        /*
         * Each close is shown like an edit to the day: the first lists the
         * figures it closed on; every later one lists what differs from the
         * close before it. Walked oldest first so each knows its predecessor.
         */
        $previousFigures = null;

        $logged = $activities->map(function (Activity $a) use ($business, &$previousFigures) {
            $item = $this->fromActivity($business, $a);

            if ($a->event === 'closing.finalized' && $a->properties->has('figures')) {
                $figures = (array) $a->properties->get('figures');
                $item['changes'] = $this->closeChanges($figures, $previousFigures);
                $item['detail'] = $previousFigures !== null && $item['changes'] === []
                    ? 'No figures changed since the last close.'
                    : null;
                $previousFigures = $figures;
            }

            return $item;
        });

        // Corrections posted into this day that no daily entry made: opening
        // cash corrections, cash-count differences, and their reversals. They
        // move the day's cash, so who posted them belongs beside the close.
        $corrections = Transaction::forBusiness($business)
            ->whereDate('business_date', $date->toDateString())
            ->whereIn('type', [TransactionType::Adjustment->value, TransactionType::Reversal->value])
            ->where(fn ($q) => $q->whereNull('source_type')
                ->orWhere('source_type', '!=', (new DailyEntry)->getMorphClass()))
            ->with(['creator', 'lines.account'])
            ->get()
            ->map(fn (Transaction $t) => $this->item(
                $business,
                $t->created_at,
                $t->creator,
                $t->type === TransactionType::Reversal ? 'Cancelled a correction' : 'Posted a correction',
                $t->narration,
                $t->correction_reason,
                // What it did to the drawer, in the same table as an edit.
                [['Cash in hand', null, null, $this->cashEffect($t)]],
                $t->type === TransactionType::Reversal ? 'bad' : 'correction',
            ));

        return $logged->concat($corrections)->sortByDesc(fn ($i) => $i['at'])->values();
    }

    /**
     * One line of the business-wide activity feed: the same item the day pages
     * show, plus what it was about and where to find it. A close is compared
     * with the close of the same day before it, exactly as on the day's page.
     *
     * @return Item&array{about: ?string, link: ?string}
     */
    public function feedItem(Business $business, Activity $activity): array
    {
        $item = $this->fromActivity($business, $activity);

        if ($activity->event === 'closing.finalized' && $activity->properties->has('figures')) {
            $previous = Activity::query()
                ->where('subject_type', $activity->subject_type)
                ->where('subject_id', $activity->subject_id)
                ->where('event', 'closing.finalized')
                ->where('id', '<', $activity->id)
                ->latest('id')
                ->first();

            $before = $previous ? (array) $previous->properties->get('figures') : null;
            $item['changes'] = $this->closeChanges((array) $activity->properties->get('figures'), $before);
            $item['detail'] = $before !== null && $item['changes'] === [] ? 'No figures changed since the last close.' : null;
        }

        if ($activity->event === 'daily_entry.amended' && $item['changes'] === []) {
            $item = [...$item, 'action' => 'Saved without change', 'kind' => 'neutral', 'detail' => 'No figures changed.'];
        } elseif ($activity->event === 'daily_entry.amended') {
            $item['action'] = self::EVENT_LABELS['daily_entry.amended'];
        }

        // A payment taken from a customer is entered through the day, and
        // that is the only trace of it: said as what it was.
        if ($activity->event === 'daily_entry.amended') {
            $moved = collect((array) $activity->properties->get('before', []))
                ->filter(fn ($before, $field) => (string) $before !== (string) data_get($activity->properties, "after.{$field}"))
                ->keys()->all();

            if ($moved === ['collection_cash']) {
                $item = [...$item, 'action' => 'Payment received from customer', 'kind' => 'good'];
            } elseif ($moved === ['expenses_cash']) {
                $item = [...$item, 'action' => 'Expense recorded', 'kind' => 'change'];
            }
        }

        [$about, $link] = $this->about($business, $activity);

        return [...$item, 'about' => $about, 'link' => $link,
            // For the feed to fold a run of sales into one line.
            'event' => $activity->event,
            'value' => $activity->event === 'pos.sold' ? Money::of($activity->properties->get('total', 0)) : null,
        ];
    }

    /** @return array{0: ?string, 1: ?string} what the entry was about, and a link to it */
    private function about(Business $business, Activity $activity): array
    {
        $subject = $activity->subject;

        return match (true) {
            $subject instanceof DailyEntry => [
                'Daily entry · ' . $subject->business_date->format('D d M Y'),
                route('businesses.daily.show', [$business, $subject]),
            ],
            $subject instanceof DailyClosing => [
                'Closing · ' . $subject->business_date->format('D d M Y'),
                route('businesses.closing.show', [$business, $subject->business_date->toDateString()]),
            ],
            $subject instanceof \App\Models\PosBill => [
                'Bill ' . $subject->reference(),
                auth()->user()?->can('sellAtPos', $business) ? route('businesses.pos.bills.edit', [$business, $subject]) : null,
            ],
            $subject instanceof \App\Models\Order && str_starts_with((string) $activity->event, 'delivery.') => [
                'Delivery ' . ($subject->reference ?? '#' . $subject->id),
                auth()->user()?->can('viewOrders', $business) ? route('businesses.orders.show', [$business, $subject]) : null,
            ],
            $subject instanceof \App\Models\OpeningBalance => ['Opening balance', null],
            $subject instanceof \App\Models\Order => [
                'Order form ' . ($subject->reference ?? '#' . $subject->id),
                route('businesses.orders.show', [$business, $subject]),
            ],
            str_starts_with((string) $activity->event, 'team.') => [
                'Team',
                auth()->user()?->can('manageMembers', $business) ? route('businesses.team.index', $business) : null,
            ],
            $subject instanceof \App\Models\CompanyProduct => [
                $subject->label(),
                auth()->user()?->can('viewProducts', $business) ? route('businesses.products.show', [$business, $subject]) : null,
            ],
            str_starts_with((string) $activity->event, 'stock.') => ['Stock', null],
            str_starts_with((string) $activity->event, 'price_list.') => ['Price list', null],
            default => [null, null],
        };
    }

    /** @return Item */
    private function fromActivity(Business $business, Activity $activity): array
    {
        $props = $activity->properties;
        $changes = [];

        // An edit carries every figure before and after; list only what moved.
        foreach ((array) $props->get('before', []) as $field => $before) {
            $after = data_get($props, "after.{$field}");

            if ((string) $before !== (string) $after && isset(self::FIELD_LABELS[$field])) {
                $changes[] = [
                    self::FIELD_LABELS[$field],
                    Money::of($before ?? 0)->format(),
                    Money::of($after ?? 0)->format(),
                    Money::of($after ?? 0)->minus(Money::of($before ?? 0)),
                ];
            }
        }

        $roleLabel = fn ($v) => \App\Enums\BusinessRole::tryFrom((string) $v)?->label() ?? (string) $v;

        $detail = match ($activity->event) {
            'team.member_added' => $props->get('member') . ' as ' . $roleLabel($props->get('role'))
                . ($props->get('login') === 'created' ? ' · new login' : ' · existing login'),
            'team.member_updated' => $props->get('member') . ': '
                . $roleLabel(data_get($props, 'before.role')) . (data_get($props, 'before.active') ? '' : ' (off)')
                . ' → ' . $roleLabel(data_get($props, 'after.role')) . (data_get($props, 'after.active') ? '' : ' (switched off)')
                . (data_get($props, 'before') == data_get($props, 'after') ? ' · nothing changed' : ''),
            'stock.imported' => number_format((int) $props->get('products')) . ' products from ' . $props->get('source')
                . ' · opening stock on ' . number_format((int) $props->get('stocked')) . ' ('
                . number_format((int) $props->get('units')) . ' units, Rs. ' . Money::of($props->get('value_at_cost', 0))->format() . ' at cost)'
                . (count((array) $props->get('negative_set_to_zero', [])) ? ' · ' . count((array) $props->get('negative_set_to_zero')) . ' negative quantities set to 0' : ''),
            'pos.sold' => (int) $props->get('items') . ' ' . \Illuminate\Support\Str::plural('item', (int) $props->get('items'))
                . ' · Rs. ' . Money::of($props->get('total', 0))->format()
                . (Money::of($props->get('received', 0))->lessThan(Money::of($props->get('total', 0)))
                    ? ' · Rs. ' . Money::of($props->get('total', 0))->minus(Money::of($props->get('received', 0)))->format() . ' pending'
                    : ' · paid in full'),
            'pos.returned' => 'Return ' . $props->get('return') . ' on bill ' . $props->get('bill')
                . ' · Rs. ' . Money::of($props->get('total', 0))->format()
                . ' · refunded Rs. ' . Money::of($props->get('refunded', 0))->format(),
            'pos.edited' => 'Bill ' . $props->get('bill') . ' · correction ' . $props->get('revision'),
            'delivery.received', 'delivery.corrected' => 'From ' . $props->get('company')
                . ($props->get('invoice_no') ? ' · invoice ' . $props->get('invoice_no') : '')
                . ' · ' . (int) $props->get('lines_received') . ' ' . \Illuminate\Support\Str::plural('product', (int) $props->get('lines_received'))
                . ' · Rs. ' . Money::of($props->get('received', 0))->format()
                . (Money::of($props->get('paid', 0))->isZero() ? ' · not paid yet' : ' · paid Rs. ' . Money::of($props->get('paid', 0))->format()),
            'stock.adjusted' => $props->get('product') . ' · ' . $props->get('kind') . ' · '
                . ((int) $props->get('packs') > 0 ? '+' : '') . (int) $props->get('packs')
                . ((int) $props->get('loose') !== 0 ? ' and ' . (int) $props->get('loose') . ' loose' : ''),
            'product.updated' => (string) $props->get('product'),
            'closing.cash_counted' => 'Counted Rs. ' . Money::of($props->get('counted', 0))->format()
                . ' · expected Rs. ' . Money::of($props->get('expected', 0))->format()
                . ($props->get('difference') && ! Money::of($props->get('difference'))->isZero()
                    ? ' · difference Rs. ' . Money::of($props->get('difference'))->format()
                    : ' · matched'),
            default => null,
        };

        return $this->item(
            $business,
            $activity->created_at,
            $activity->causer,
            self::EVENT_LABELS[$activity->event] ?? ($activity->description ?: $activity->event),
            $detail,
            $activity->reason ?? $props->get('reason'),
            $changes,
            self::EVENT_KINDS[$activity->event] ?? 'neutral',
        ) + [
            // Which computer it was done on, as it was called at the time.
            'device' => $activity->device_name,
        ];
    }

    /** @return Item */
    private function item(
        Business $business,
        ?Carbon $at,
        ?User $who,
        string $action,
        ?string $detail = null,
        ?string $reason = null,
        array $changes = [],
        string $kind = 'neutral',
        ?Money $amount = null,
    ): array {
        return [
            'at' => ($at ?? now())->copy()->timezone($business->timezone),
            'who' => $who?->name ?? 'Unknown',
            'role' => $who ? $this->roleOf($business, $who) : null,
            'action' => $action,
            'detail' => $detail,
            'reason' => $reason ?: null,
            'changes' => $changes,
            'kind' => $kind,
            // Signed: what the step did to the drawer, where that is the point.
            'amount' => $amount,
        ];
    }

    /** The figures a close is judged by, in the words the closing page uses. */
    private const CLOSE_FIGURES = [
        'closing_cash' => 'Cash in hand',
        'closing_receivable' => 'Pending from market',
        'closing_payable' => 'Pending to companies',
        'gross_profit' => 'Gross profit',
        'expenses' => 'Expenses',
        'net_profit' => 'Profit after expenses',
        'net_position' => 'Net position',
    ];

    /**
     * Rows for a close: every key figure the first time, only what moved on a
     * later close of the same day.
     *
     * @param  array<string, string>  $figures
     * @param  ?array<string, string>  $previous
     * @return array<int, array{0: string, 1: ?string, 2: ?string, 3: ?Money}>
     */
    private function closeChanges(array $figures, ?array $previous): array
    {
        $rows = [];

        foreach (self::CLOSE_FIGURES as $key => $label) {
            if (! array_key_exists($key, $figures)) {
                continue;
            }

            $after = Money::of($figures[$key]);

            if ($previous === null) {
                $rows[] = [$label, null, $after->format(), null];

                continue;
            }

            $before = Money::of($previous[$key] ?? 0);

            if (! $before->equals($after)) {
                $rows[] = [$label, $before->format(), $after->format(), $after->minus($before)];
            }
        }

        return $rows;
    }

    /**
     * What a correction did to cash: in (+) or out (−). Falls back to the
     * transaction's size when it did not touch cash at all.
     */
    private function cashEffect(Transaction $transaction): Money
    {
        $cash = $transaction->lines->filter(fn ($l) => $l->account->code === \App\Enums\AccountCode::Cash->value);

        return $cash->isEmpty()
            ? Money::of($transaction->amount)
            : Money::sum($cash->map(fn ($l) => $l->debit->minus($l->credit)));
    }

    private function roleOf(Business $business, User $user): ?string
    {
        return $this->roles[$user->id] ??= $user->isPlatformAdmin()
            ? 'App Owner'
            : $user->roleIn($business)?->label();
    }
}
