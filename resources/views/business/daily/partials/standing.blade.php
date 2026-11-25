{{--
    Business standing: where the business stands at the end of the day.

    A plain card is the day's own figure; a balance card shows where a running
    balance ended, how far it moved since the start of the day, and why. Below
    it, what should be in the drawer, line by line. Counting and closing are
    behind the Close day button at the top of the page.

    Needs: $business, $day, $close (from DailyEntryController::day), $bills,
    $closeState (from day.blade.php).
--}}
@php
    [
        'figures' => $figures, 'closing' => $closing,
        'openingNetPosition' => $openingNetPosition, 'openingCashCorrection' => $openingCashCorrection,
    ] = $close;
    $rs = fn ($m) => ($m->isNegative() ? '−' : '') . $m->absolute()->format();
    $billCount = $bills->count();

    // Whatever moved cash that is not one of the day's lines lands on its own
    // line, so the drawer always adds up.
    $otherCash = $figures['closing_cash']->minus(
        $figures['opening_cash']
            ->plus($figures['cash_sales'])->plus($figures['collections'])
            ->minus($figures['purchases_paid'])->minus($figures['company_payments'])
            ->minus($figures['expenses'])
    )->minus($openingCashCorrection);

    /*
     * The owner takes each day's takings home, so the drawer starts the day
     * empty and holds only the day's own cash. What the owner had from earlier
     * days is theirs to hold; the two together are all the business's cash.
     */
    $carried = $figures['opening_cash']->plus($openingCashCorrection);
    $drawerCash = $figures['closing_cash']->minus($carried);

    $drawer = array_values(array_filter([
        ['+ Cash received on sales', $figures['cash_sales'], false],
        ['+ Collected on earlier credit', $figures['collections'], false],
        ['− Paid for purchases', $figures['purchases_paid'], false],
        ['− Paid to companies (earlier bills)', $figures['company_payments'], false],
        ['− Expenses', $figures['expenses'], false],
        $otherCash->isZero() ? null : ['± Cash count difference and other corrections', $otherCash, false],
        ['Should be in the drawer today', $drawerCash, true],
    ]));

    $owner = array_values(array_filter([
        ['With the owner from earlier days', $figures['opening_cash'], false],
        $openingCashCorrection->isZero() ? null : ['+ Opening cash correction', $openingCashCorrection, false],
        ['+ Today\'s drawer', $drawerCash, false],
        ['Cash the owner should have', $figures['closing_cash'], true],
    ]));

    $gpShare = $figures['sales']->isPositive()
        ? number_format((float) $figures['gross_profit']->toDecimal() / (float) $figures['sales']->toDecimal() * 100, 1) . '% of sales'
        : 'No sales on this day';
    $stockMove = $figures['closing_stock']->minus($figures['opening_stock']);
    $cashMove = $figures['closing_cash']->minus($figures['opening_cash']);
    $netMove = $figures['net_position']->minus($openingNetPosition);

    // Each card: [label, figure, note, move, tone]. move is [amount, words] for
    // a balance; tone colours it by what it means for the business.
    $rows = [
        'Sales' => [
            ['Sales', $figures['sales'], $billCount . ' ' . Str::plural('bill', $billCount) . ' · ' . $rs($figures['cash_sales']) . ' in cash · ' . $rs($figures['credit_sales']) . ' on credit', null, null],
            ['Purchase price of the day\'s sales', $figures['cogs'], 'What the goods sold cost', null, null],
            ['Gross profit', $figures['gross_profit'], $gpShare, null, $figures['gross_profit']->isNegative() ? 'loss' : 'profit'],
            ['Client pending amount', $figures['closing_receivable'],
                'Start of day ' . $rs($figures['opening_receivable']) . ' · + ' . $rs($figures['credit_sales']) . ' sold on credit · − ' . $rs($figures['collections']) . ' collected',
                [$figures['receivable_delta'], 'today'], 'neutral'],
        ],
        'Purchases' => [
            ['Purchases', $figures['purchases'], $rs($figures['purchases_paid']) . ' paid now · ' . $rs($figures['purchases_on_account']) . ' on credit', null, null],
            ['Supplier pending amount', $figures['closing_payable'],
                'Start of day ' . $rs($figures['opening_payable']) . ' · + ' . $rs($figures['purchases_on_account']) . ' bought on credit · − ' . $rs($figures['company_payments']) . ' paid',
                [$figures['payable_delta'], 'today'], 'up-bad'],
            ['Stock', $figures['closing_stock'],
                'Last night ' . $rs($figures['opening_stock']) . ' · + ' . $rs($figures['purchases']) . ' bought · − ' . $rs($figures['cogs']) . ' sold at cost',
                [$stockMove, 'since last night'], 'neutral'],
        ],
        'Expenses' => [
            ['Expenses', $figures['expenses'], 'Spent on running the shop', null, null],
            ['Net profit', $figures['net_profit'], 'Gross profit less expenses', null, $figures['net_profit']->isNegative() ? 'loss' : 'profit'],
        ],
        'Money' => [
            ['Today\'s cash in the drawer', $drawerCash, 'The day\'s own cash: received less paid out. Counted at closing.', null, null],
            ['Cash with the owner', $figures['closing_cash'], 'Earlier days ' . $rs($carried) . ' · + today\'s drawer ' . $rs($drawerCash), [$cashMove, 'today'], 'up-good'],
            ['Net position', $figures['net_position'], 'What the business owns less what it owes', [$netMove, 'today'], 'up-good'],
        ],
    ];

    // Two columns on a laptop, the row's own count on a wide screen.
    $cols = [2 => 'sm:grid-cols-2', 3 => 'sm:grid-cols-2 xl:grid-cols-3', 4 => 'sm:grid-cols-2 xl:grid-cols-4'];
@endphp

@include('business.daily.partials.closing-notice')

<section aria-labelledby="stands-h" class="space-y-5">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="stands-h" class="text-base font-semibold text-gray-900">Where the business stands {{ $day->isSameDay($business->today()) ? 'tonight' : 'at the end of ' . $day->format('d M') }}</h2>
        <p class="text-xs text-gray-500">Balances from the start of the day to its end · ▲ up · ▼ down</p>
    </div>

    @foreach ($rows as $rowTitle => $cards)
        <div>
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $rowTitle }}</h3>
            <dl class="grid gap-3 {{ $cols[count($cards)] }}">
                @foreach ($cards as [$label, $figure, $note, $move, $tone])
                    @php
                        $isResult = in_array($tone, ['profit', 'loss'], true);
                        $box = match ($tone) {
                            'profit' => 'border-emerald-200 bg-emerald-50',
                            'loss' => 'border-red-200 bg-red-50',
                            default => 'border-line bg-white',
                        };
                        $ink = match ($tone) { 'profit' => 'text-emerald-800', 'loss' => 'text-red-800', default => 'text-gray-500' };
                        $amount = $move ? $move[0] : null;
                        $pill = match (true) {
                            $amount === null || $amount->isZero() => 'bg-gray-100 text-gray-600',
                            $tone === 'neutral' => 'bg-gray-100 text-gray-700',
                            ($tone === 'up-good') === $amount->isPositive() => 'bg-emerald-50 text-emerald-800',
                            default => 'bg-red-50 text-red-800',
                        };
                    @endphp
                    <div class="flex min-h-[9rem] flex-col gap-1.5 rounded-lg border px-4 py-4 {{ $box }}">
                        <dt class="text-xs font-medium uppercase tracking-wide {{ $ink }}">{{ $tone === 'loss' ? str_replace('profit', 'loss', $label) : $label }}</dt>
                        <dd class="font-mono text-xl font-semibold tabular-nums xl:text-2xl {{ $isResult ? $ink : ($figure->isNegative() ? 'text-red-700' : 'text-gray-900') }}">{{ $rs($figure) }}</dd>
                        @if ($move)
                            <span class="self-start rounded-full px-2 py-0.5 text-xs font-semibold {{ $pill }}">
                                @if ($amount->isZero())
                                    = no change {{ $move[1] }}
                                @else
                                    {{ $amount->isPositive() ? '▲' : '▼' }} {{ $amount->absolute()->format() }} {{ $move[1] }}
                                @endif
                            </span>
                        @endif
                        <p class="mt-auto text-xs leading-4 {{ $isResult ? $ink : 'text-gray-500' }}">{{ $note }}</p>
                    </div>
                @endforeach
            </dl>
        </div>
    @endforeach
</section>

<div class="grid gap-4 xl:grid-cols-2">
    {{-- Today's drawer, line by line; then all the cash, with the owner. --}}
    <div class="space-y-4">
        @foreach ([
            ['Cash in the drawer today', 'Only the day\'s own cash. The drawer starts each day empty.', $drawer],
            ['Cash with the owner', 'The daily takings the owner has taken home, and today\'s drawer.', $owner],
        ] as [$panelTitle, $panelText, $panelRows])
            <x-panel :title="$panelTitle" :description="$panelText">
                <dl class="divide-y divide-gray-100 text-sm">
                    @foreach ($panelRows as [$label, $value, $strong])
                        <div class="flex justify-between gap-4 px-4 py-2 sm:px-6 {{ $strong ? 'bg-gray-50 font-semibold' : '' }}">
                            <dt class="{{ $strong ? 'text-gray-900' : 'text-gray-600' }}">{{ $label }}</dt>
                            <dd class="whitespace-nowrap font-mono tabular-nums {{ $value->isNegative() ? 'text-red-700' : 'text-gray-900' }}">{{ $rs($value) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-panel>
        @endforeach
    </div>

    {{-- The count: as it was agreed on a closed day, as last saved on an open one. --}}
    <x-panel title="Cash count" :description="$closeState['isClosed'] ? 'Confirmed when the day was closed.' : 'Counted when the day is closed. Use Close day at the top.'">
        <dl class="divide-y divide-gray-100 text-sm">
            {{-- Stored as all the cash; shown as the drawer, less what the owner already had. --}}
            @php($drawerCounted = $closing?->counted_cash?->minus($carried))
            <div class="flex justify-between px-4 py-2 sm:px-6">
                <dt class="text-gray-600">Should be in the drawer today</dt>
                <dd class="font-mono tabular-nums text-gray-900">{{ $rs($drawerCounted !== null ? $drawerCounted->minus($closing->cash_variance ?? \App\Support\Money::zero()) : $drawerCash) }}</dd>
            </div>
            <div class="flex justify-between px-4 py-2 font-semibold sm:px-6">
                <dt class="text-gray-600">Counted in the drawer</dt>
                <dd class="font-mono tabular-nums {{ $drawerCounted?->isNegative() ? 'text-red-700' : 'text-gray-900' }}">
                    {{ $drawerCounted !== null ? $rs($drawerCounted) : 'Not counted yet' }}
                </dd>
            </div>
            @if ($closing?->counted_cash !== null)
                <div class="flex justify-between px-4 py-2 sm:px-6">
                    <dt class="text-gray-600">Difference</dt>
                    <dd class="tabular-nums">
                        @if (! $closing->hasCashVariance())
                            <span class="text-emerald-700">✓ Matched</span>
                        @else
                            <span class="{{ $closing->cash_variance->isNegative() ? 'text-red-700' : 'text-emerald-700' }}">
                                {{ $closing->cash_variance->isNegative() ? '▼ Short ' : '▲ Over ' }}{{ $closing->cash_variance->absolute()->format() }}
                            </span>
                        @endif
                    </dd>
                </div>
                @if ($closing->variance_reason)
                    <div class="px-4 py-2 text-gray-600 sm:px-6">
                        Reason: <span class="italic">“{{ $closing->variance_reason }}”</span>
                    </div>
                @endif
            @endif
        </dl>
    </x-panel>
</div>
