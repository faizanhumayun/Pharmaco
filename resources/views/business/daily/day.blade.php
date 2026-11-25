{{--
    The Daily Log: one day, read only.

    Down the side, the day's sections: Totals first, then each list on its own.
    Nothing is typed here — every bill, delivery, collection, payment and
    expense wrote itself into the day as it happened. The section chosen is in
    the link (?section=…), so a reload or a shared link opens the same one.
--}}
@php
    $rs = fn ($m) => $m->format();
    $th = 'whitespace-nowrap px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500';
    $num = 'whitespace-nowrap px-4 py-2 text-right font-mono tabular-nums';
    $link = 'font-mono text-sm font-semibold text-emerald-700 underline decoration-emerald-300 underline-offset-2 hover:text-emerald-900';
    $go = fn (string $key) => route('businesses.daily.day', [$business, $day->toDateString()]) . ($key === 'totals' ? '' : '?section=' . $key);

    $sections = [
        'totals' => ['Totals', null],
        'standing' => $seesClosing ? ['Business standing', null] : null,
        'sales' => ['Sales', $bills->count()],
        'purchases' => ['Purchases', $purchases->count()],
        'collections' => ['Pending collections', $collections->count()],
        'payments' => ['Supplier payments', $payments->count() + ($entry?->company_payment_cash?->isPositive() ? 1 : 0)],
        'expenses' => ['Expenses', $expenses->count()],
        'history' => ['Change history', $history->count() + ($close ? $close['history']->count() : 0)],
    ];
    $sections = array_filter($sections);

    /*
     * The closing of this day. Days close in order: the one after the lock is
     * the one due, and the opening day itself is never closed. A closed day
     * can be reopened only if it is the latest, by someone allowed to.
     */
    $closeState = null;
    if ($close) {
        $closingRow = $close['closing'];
        $isClosed = (bool) $closingRow?->isFinalized();
        $isLatestClosed = $isClosed && $business->locked_through_date?->toDateString() === $day->toDateString();
        $finalizer = $isClosed ? $closingRow->finalizer : null;
        $closeState = [
            // Compared as plain dates: the day is in the business's timezone.
            'due' => $business->locked_through_date?->copy()->addDay()
                ?? $business->opening_date?->copy()->addDay()
                ?? $business->today(),
            'closable' => $business->opening_date === null || $day->toDateString() > $business->opening_date->toDateString(),
            'isClosed' => $isClosed,
            'isLatestClosed' => $isLatestClosed,
            'canReopen' => $isLatestClosed && auth()->user()->can('reopen', [App\Models\DailyClosing::class, $business]),
            'closedBy' => $finalizer
                ? $finalizer->name . ($finalizer->isPlatformAdmin() ? '' : ' (' . ($finalizer->roleIn($business)?->label() ?? 'former member') . ')')
                : null,
            'dueLater' => false,
            'closedAt' => $closingRow?->finalized_at?->timezone($business->timezone)->format('d M Y, h:i A'),
        ];
        // This day waits on an earlier one still open.
        $closeState['dueLater'] = $day->toDateString() > $closeState['due']->toDateString();
    }

    $previous = $day->copy()->subDay();
    $next = $day->copy()->addDay();
    $isToday = $day->isSameDay($today);
@endphp

<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <x-back-link :href="route('businesses.daily.index', $business)" label="All days" />
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-xl font-semibold text-gray-900">Daily Log · {{ $day->format('D d M Y') }}</h1>
                    @if ($closed)
                        <x-badge classes="bg-emerald-50 text-emerald-800 ring-emerald-600/20">Closed</x-badge>
                    @else
                        <x-badge classes="bg-amber-50 text-amber-800 ring-amber-600/20">Open · not closed yet</x-badge>
                    @endif
                </div>
                <p class="mt-1 text-sm text-gray-500">
                    Filled in from the till, deliveries, collections, payments and expenses. Nothing is typed here.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2"
                 x-data="{ go(d) { if (d) window.location = '{{ route('businesses.daily.day', [$business, 'DATE']) }}'.replace('DATE', d); } }">
                <a href="{{ route('businesses.daily.day', [$business, $previous->toDateString()]) }}"
                   class="rounded-md border border-gray-300 bg-white px-2.5 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"
                   aria-label="Previous day" title="{{ $previous->format('D d M') }}">←</a>
                <input type="date" value="{{ $day->toDateString() }}" max="{{ $today->toDateString() }}"
                       aria-label="Day" x-on:change="go($event.target.value)"
                       class="rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                @if ($isToday)
                    <span class="rounded-md border border-gray-200 bg-white px-2.5 py-2 text-sm font-medium text-gray-300" aria-disabled="true">→</span>
                @else
                    <a href="{{ route('businesses.daily.day', [$business, $next->toDateString()]) }}"
                       class="rounded-md border border-gray-300 bg-white px-2.5 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"
                       aria-label="Next day" title="{{ $next->format('D d M') }}">→</a>
                @endif
                @if ($closeState)
                    @if ($closeState['canReopen'])
                        <button type="button" x-on:click="$dispatch('open-modal', 'reopen-day')"
                                class="rounded-md border border-amber-300 bg-white px-3 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-50">Reopen day</button>
                    @elseif (! $closeState['isClosed'] && $closeState['closable'])
                        <button type="button" x-on:click="$dispatch('open-modal', 'close-day')"
                                class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Close day</button>
                    @endif
                @endif
            </div>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        <div class="flex flex-wrap items-start gap-6">
            {{-- The day's sections. On a narrow screen they sit above the content. --}}
            <nav aria-label="Sections of this day"
                 class="flex w-full flex-col gap-0.5 rounded-lg border border-line bg-white p-2 lg:w-60 lg:shrink-0">
                @foreach ($sections as $key => [$label, $count])
                    <a href="{{ $go($key) }}" @if ($section === $key) aria-current="page" @endif
                       @class([
                           'flex items-center justify-between gap-3 rounded-md px-3 py-2 text-sm',
                           'bg-emerald-50 font-semibold text-emerald-800' => $section === $key,
                           'text-gray-700 hover:bg-gray-50' => $section !== $key,
                       ])>
                        <span>{{ $label }}</span>
                        @if ($count !== null)
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs tabular-nums',
                                'bg-emerald-100 text-emerald-800' => $section === $key,
                                'bg-gray-100 text-gray-600' => $section !== $key,
                            ])>{{ $count }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>

            <div class="min-w-0 flex-1 basis-[36rem] space-y-4">
                @switch($section)

                    {{-- ===================== Totals ===================== --}}
                    @case('totals')
                        @php
                            $card = fn (bool $bad) => $bad
                                ? ['border-red-200 bg-red-50', 'text-red-800']
                                : ['border-emerald-200 bg-emerald-50', 'text-emerald-800'];
                        @endphp
                        {{-- One row per kind of money: selling, buying, spending. Each
                             row is four columns wide so the cards line up down the page. --}}
                        @php
                            $row = 'grid gap-3 sm:grid-cols-2 xl:grid-cols-4';
                            $rowTitle = 'mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500';
                            $profitCard = function (string $label, $figure, string $hint) use ($card, $rs) {
                                [$box, $ink] = $card($figure->isNegative());

                                return compact('label', 'figure', 'hint', 'box', 'ink');
                            };
                        @endphp

                        {{-- Sales: what came in, what it earned, and money in on earlier bills. --}}
                        <section aria-label="Sales">
                            <h2 class="{{ $rowTitle }}">Sales</h2>
                            <dl class="{{ $row }}">
                                <x-stat label="Sales" :value="$rs($totals['sales'])"
                                        :hint="$bills->count() . ' ' . Str::plural('bill', $bills->count()) . ' · ' . $rs($totals['sales_received']) . ' received'" />
                                @if ($seesProfit)
                                    @php($c = $profitCard('Gross profit', $totals['gross_profit'], ($totals['margin'] !== null ? number_format($totals['margin'], 1) . '% · ' : '') . 'cost of goods ' . $rs($totals['cogs'])))
                                    <div class="rounded-lg border px-4 py-4 {{ $c['box'] }}">
                                        <dt class="text-xs font-medium uppercase tracking-wide {{ $c['ink'] }}">{{ $c['figure']->isNegative() ? 'Gross loss' : 'Gross profit' }}</dt>
                                        <dd class="mt-1 font-mono text-xl font-semibold tabular-nums xl:text-2xl {{ $c['ink'] }}">{{ $rs($c['figure']) }}</dd>
                                        <p class="mt-1 text-xs {{ $c['ink'] }}">{{ $c['hint'] }}</p>
                                    </div>
                                @endif
                                <x-stat label="Collected on pending bills" :value="$rs($totals['collected'])"
                                        :hint="$collections->isEmpty() ? 'No collections on this day' : $collections->count() . ' ' . Str::plural('collection', $collections->count())" />
                            </dl>
                        </section>

                        {{-- Purchases: today's supply, paid for it now, still owed on it, and earlier bills paid. --}}
                        <section aria-label="Purchases">
                            <h2 class="{{ $rowTitle }}">Purchases</h2>
                            <dl class="{{ $row }}">
                                <x-stat label="Purchases" :value="$rs($totals['purchases'])"
                                        :hint="$purchases->count() . ' ' . Str::plural('invoice', $purchases->count()) . ' received today'" />
                                <x-stat label="Paid for today's supply" :value="$rs($totals['purchases_paid'])" hint="Paid on today's invoices" />
                                <div @class(['rounded-lg border px-4 py-4', 'border-amber-200 bg-amber-50' => $totals['purchases_pending']->isPositive(), 'border-line bg-white' => ! $totals['purchases_pending']->isPositive()])>
                                    <dt @class(['text-xs font-medium uppercase tracking-wide', 'text-amber-800' => $totals['purchases_pending']->isPositive(), 'text-gray-500' => ! $totals['purchases_pending']->isPositive()])>Pending on today's supply</dt>
                                    <dd @class(['mt-1 font-mono text-xl font-semibold tabular-nums xl:text-2xl', 'text-amber-900' => $totals['purchases_pending']->isPositive(), 'text-gray-900' => ! $totals['purchases_pending']->isPositive()])>{{ $rs($totals['purchases_pending']) }}</dd>
                                    <p @class(['mt-1 text-xs', 'text-amber-800' => $totals['purchases_pending']->isPositive(), 'text-gray-500' => ! $totals['purchases_pending']->isPositive()])>{{ $totals['purchases_pending']->isPositive() ? 'Still owed to companies' : 'Nothing owed on today\'s invoices' }}</p>
                                </div>
                                <x-stat label="Paid to suppliers" :value="$rs($totals['paid_to_suppliers'])" hint="Against earlier bills" />
                            </dl>
                        </section>

                        {{-- Expenses: the day's spending, and what is left after it. --}}
                        <section aria-label="Expenses">
                            <h2 class="{{ $rowTitle }}">Expenses</h2>
                            <dl class="{{ $row }}">
                                <x-stat label="Expenses" :value="$rs($totals['expenses'])"
                                        :hint="$expenses->count() . ' ' . Str::plural('expense', $expenses->count())" />
                                @if ($seesProfit)
                                    @php($c = $profitCard('Net profit', $totals['net_profit'], 'Gross profit less expenses'))
                                    <div class="rounded-lg border px-4 py-4 {{ $c['box'] }}">
                                        <dt class="text-xs font-medium uppercase tracking-wide {{ $c['ink'] }}">{{ $c['figure']->isNegative() ? 'Net loss' : 'Net profit' }}</dt>
                                        <dd class="mt-1 font-mono text-xl font-semibold tabular-nums xl:text-2xl {{ $c['ink'] }}">{{ $rs($c['figure']) }}</dd>
                                        <p class="mt-1 text-xs {{ $c['ink'] }}">{{ $c['hint'] }}</p>
                                    </div>
                                @endif
                            </dl>
                        </section>

                        @if ($seesProfit)
                            <x-panel title="Result for the day">
                                <dl class="divide-y divide-gray-100 text-sm">
                                    @foreach ([
                                        ['Sales', $totals['sales'], false],
                                        ['− Returns', $totals['returns'], false],
                                        ['− Cost of goods sold', $totals['cogs'], false],
                                        ['Gross profit', $totals['gross_profit'], true],
                                        ['− Expenses', $totals['expenses'], false],
                                        ['Net profit', $totals['net_profit'], true],
                                    ] as [$label, $figure, $strong])
                                        @continue($label === '− Returns' && $figure->isZero())
                                        <div @class(['flex justify-between px-4 py-2 sm:px-6', 'bg-gray-50 font-semibold' => $loop->last, 'font-semibold' => $strong])>
                                            <dt class="{{ $strong ? 'text-gray-900' : 'text-gray-600' }}">{{ $label }}</dt>
                                            <dd @class(['font-mono tabular-nums', 'text-emerald-800' => $strong && ! $figure->isNegative(), 'text-red-700' => $strong && $figure->isNegative(), 'text-gray-900' => ! $strong])>{{ $rs($figure) }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </x-panel>
                        @endif

                        @if ($entry === null)
                            <p class="text-sm text-gray-500">Nothing was recorded on this day.</p>
                        @endif
                        @break

                    {{-- ===================== Business standing ===================== --}}
                    @case('standing')
                        @include('business.daily.partials.standing')
                        @break

                    {{-- ===================== Sales ===================== --}}
                    @case('sales')
                        <x-panel title="Sales" description="Every bill rung up at the till. Open a bill to see its items, edit it or take a return.">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="{{ $th }} text-left sm:px-6">Bill</th>
                                            <th class="{{ $th }} text-left">Time</th>
                                            <th class="{{ $th }} text-left">Customer</th>
                                            <th class="{{ $th }} text-right">Items</th>
                                            <th class="{{ $th }} text-right">Total</th>
                                            <th class="{{ $th }} text-right">Received</th>
                                            <th class="{{ $th }} text-right {{ $seesProfit ? '' : 'sm:px-6' }}">Pending</th>
                                            @if ($seesProfit)<th class="{{ $th }} text-right sm:px-6">Profit</th>@endif
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @forelse ($bills as $bill)
                                            <tr class="hover:bg-gray-50">
                                                <td class="whitespace-nowrap px-4 py-2 sm:px-6">
                                                    <a href="{{ route('businesses.pos.bills.edit', [$business, $bill]) }}" class="{{ $link }}" title="Open this bill">{{ $bill->reference() }}</a>
                                                </td>
                                                <td class="whitespace-nowrap px-4 py-2 text-gray-500">{{ $bill->created_at->timezone($business->timezone)->format('h:i A') }}</td>
                                                <td class="px-4 py-2 text-gray-900">{{ $bill->customer_name ?? 'Walk-in customer' }}</td>
                                                <td class="{{ $num }} text-gray-600">{{ $bill->lines_count }}</td>
                                                <td class="{{ $num }} text-gray-900">{{ $rs($bill->total) }}</td>
                                                <td class="{{ $num }} text-gray-600">{{ $rs($bill->received) }}</td>
                                                <td class="{{ $num }} {{ $seesProfit ? '' : 'sm:px-6' }}">
                                                    @if ($bill->owing->isPositive())
                                                        <span class="font-semibold text-amber-700">{{ $rs($bill->owing) }}</span>
                                                    @else
                                                        <span class="font-sans text-xs font-medium text-gray-400">Paid</span>
                                                    @endif
                                                </td>
                                                @if ($seesProfit)
                                                    @php($gp = $bill->grossProfit()['profit'])
                                                    <td class="{{ $num }} font-semibold sm:px-6 {{ $gp->isNegative() ? 'text-red-700' : 'text-emerald-700' }}">{{ $rs($gp) }}</td>
                                                @endif
                                            </tr>
                                        @empty
                                            <tr><td colspan="8" class="px-6 py-10 text-center text-gray-500">No bills on this day.</td></tr>
                                        @endforelse
                                    </tbody>
                                    @if ($bills->isNotEmpty())
                                        <tfoot class="border-t border-gray-200 bg-gray-50 font-semibold">
                                            <tr>
                                                <td colspan="4" class="px-4 py-2 text-gray-900 sm:px-6">{{ $bills->count() }} {{ Str::plural('bill', $bills->count()) }}</td>
                                                <td class="{{ $num }}">{{ $rs(\App\Support\Money::sum($bills->pluck('total'))) }}</td>
                                                <td class="{{ $num }}">{{ $rs(\App\Support\Money::sum($bills->pluck('received'))) }}</td>
                                                <td class="{{ $num }} {{ $seesProfit ? '' : 'sm:px-6' }}">{{ $rs(\App\Support\Money::sum($bills->pluck('owing'))) }}</td>
                                                @if ($seesProfit)
                                                    <td class="{{ $num }} text-emerald-800 sm:px-6">{{ $rs(\App\Support\Money::sum($bills->map(fn ($b) => $b->grossProfit()['profit']))) }}</td>
                                                @endif
                                            </tr>
                                        </tfoot>
                                    @endif
                                </table>
                            </div>
                        </x-panel>

                        <x-panel title="Returns" :description="$returns->isEmpty() ? 'No items were returned on this day.' : 'Items brought back on this day, against the bill they were sold on.'">
                            @if ($returns->isNotEmpty())
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="{{ $th }} text-left sm:px-6">Return</th>
                                                <th class="{{ $th }} text-left">Against bill</th>
                                                <th class="{{ $th }} text-left">Reason</th>
                                                <th class="{{ $th }} text-right">Value</th>
                                                <th class="{{ $th }} text-right sm:px-6">Refunded</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            @foreach ($returns as $return)
                                                <tr>
                                                    <td class="whitespace-nowrap px-4 py-2 font-mono text-xs text-gray-600 sm:px-6">{{ $return->return_no }}</td>
                                                    <td class="whitespace-nowrap px-4 py-2">
                                                        @if ($return->bill)
                                                            <a href="{{ route('businesses.pos.bills.edit', [$business, $return->bill]) }}" class="{{ $link }}">{{ $return->bill->reference() }}</a>
                                                        @else — @endif
                                                    </td>
                                                    <td class="px-4 py-2 text-gray-600">{{ $return->reason ?? '—' }}</td>
                                                    <td class="{{ $num }} text-gray-900">{{ $rs($return->total) }}</td>
                                                    <td class="{{ $num }} text-gray-600 sm:px-6">{{ $rs($return->refunded) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </x-panel>
                        @break

                    {{-- ===================== Purchases ===================== --}}
                    @case('purchases')
                        <x-panel title="Purchases" description="Supplier invoices for goods received. Open one to see the delivery.">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="{{ $th }} text-left sm:px-6">Company</th>
                                            <th class="{{ $th }} text-left">Invoice</th>
                                            <th class="{{ $th }} text-right">Amount</th>
                                            <th class="{{ $th }} text-right">Paid now</th>
                                            <th class="{{ $th }} text-right sm:px-6">Left to pay</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @forelse ($purchases as $line)
                                            @php($left = $line->pending())
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-4 py-2 sm:px-6">
                                                    @if ($line->company)
                                                        <a href="{{ route('businesses.companies.show', [$business, $line->company]) }}" class="font-medium text-gray-900 hover:text-emerald-700">{{ $line->company->name }}</a>
                                                    @else
                                                        <span class="text-gray-900">{{ $line->label() }}</span>
                                                    @endif
                                                </td>
                                                <td class="whitespace-nowrap px-4 py-2">
                                                    @if ($line->order_id && auth()->user()->can('viewOrders', $business))
                                                        <a href="{{ route('businesses.orders.show', [$business, $line->order_id]) }}" class="{{ $link }}" title="Open this delivery">{{ $line->invoice_no ?? 'Open' }}</a>
                                                    @else
                                                        <span class="font-mono text-xs text-gray-500">{{ $line->invoice_no ?? '—' }}</span>
                                                    @endif
                                                </td>
                                                <td class="{{ $num }} text-gray-900">{{ $rs($line->amount) }}</td>
                                                <td class="{{ $num }} text-gray-600">{{ $rs($line->paid) }}</td>
                                                <td class="{{ $num }} sm:px-6 {{ $left->isPositive() ? 'font-semibold text-amber-700' : 'text-gray-400' }}">
                                                    {{ $left->isNegative() ? '0.00' : $rs($left) }}
                                                </td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="5" class="px-6 py-10 text-center text-gray-500">No purchases on this day.</td></tr>
                                        @endforelse
                                    </tbody>
                                    @if ($purchases->isNotEmpty())
                                        <tfoot class="border-t border-gray-200 bg-gray-50 font-semibold">
                                            <tr>
                                                <td colspan="2" class="px-4 py-2 text-gray-900 sm:px-6">{{ $purchases->count() }} {{ Str::plural('invoice', $purchases->count()) }}</td>
                                                <td class="{{ $num }}">{{ $rs($totals['purchases']) }}</td>
                                                <td class="{{ $num }}">{{ $rs($totals['purchases_paid']) }}</td>
                                                <td class="{{ $num }} sm:px-6">{{ $rs(\App\Support\Money::sum($purchases->map(fn ($l) => $l->pending()->isNegative() ? \App\Support\Money::zero() : $l->pending()))) }}</td>
                                            </tr>
                                        </tfoot>
                                    @endif
                                </table>
                            </div>
                        </x-panel>
                        @break

                    {{-- ===================== Pending collections ===================== --}}
                    @case('collections')
                        <x-panel title="Pending collections" description="Money received on this day from customers against bills they still owed.">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="{{ $th }} text-left sm:px-6">Customer</th>
                                            <th class="{{ $th }} text-left">Against bills</th>
                                            <th class="{{ $th }} text-right sm:px-6">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @forelse ($collections as $line)
                                            <tr>
                                                <td class="px-4 py-2 sm:px-6">
                                                    @if ($line->pharmacy)
                                                        <a href="{{ route('businesses.pharmacies.show', [$business, $line->pharmacy]) }}" class="font-medium text-gray-900 hover:text-emerald-700">{{ $line->pharmacy->name }}</a>
                                                    @else
                                                        <span class="text-gray-900">{{ $line->label() }}</span>
                                                    @endif
                                                    @if ($line->note)<span class="block text-xs text-gray-500">{{ $line->note }}</span>@endif
                                                </td>
                                                <td class="px-4 py-2 text-xs text-gray-600">
                                                    @forelse ($line->allocations as $allocation)
                                                        @if ($allocation->bill)
                                                            <a href="{{ route('businesses.pos.bills.edit', [$business, $allocation->bill]) }}" class="{{ $link }} text-xs">{{ $allocation->bill->reference() }}</a>
                                                        @else — @endif
                                                        <span class="tabular-nums text-gray-500">{{ $rs($allocation->amount) }}</span>@unless ($loop->last), @endunless
                                                    @empty
                                                        <span class="text-gray-400">On account</span>
                                                    @endforelse
                                                    @unless ($line->onAccount()->isZero())
                                                        <span class="block text-gray-500">{{ $rs($line->onAccount()) }} kept on account</span>
                                                    @endunless
                                                </td>
                                                <td class="{{ $num }} text-gray-900 sm:px-6">{{ $rs($line->amount) }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="3" class="px-6 py-10 text-center text-gray-500">Nothing collected against pending bills on this day.</td></tr>
                                        @endforelse
                                    </tbody>
                                    @if ($collections->isNotEmpty())
                                        <tfoot class="border-t border-gray-200 bg-gray-50 font-semibold">
                                            <tr>
                                                <td colspan="2" class="px-4 py-2 text-gray-900 sm:px-6">{{ $collections->count() }} {{ Str::plural('collection', $collections->count()) }}</td>
                                                <td class="{{ $num }} sm:px-6">{{ $rs($totals['collected']) }}</td>
                                            </tr>
                                        </tfoot>
                                    @endif
                                </table>
                            </div>
                        </x-panel>
                        @break

                    {{-- ===================== Supplier payments ===================== --}}
                    @case('payments')
                        <x-panel title="Supplier payments" description="Money paid on this day to companies against their earlier bills.">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="{{ $th }} text-left sm:px-6">Company</th>
                                            <th class="{{ $th }} text-left">Reference</th>
                                            <th class="{{ $th }} text-right sm:px-6">Paid</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($payments as $line)
                                            <tr>
                                                <td class="px-4 py-2 sm:px-6">
                                                    @if ($line->company)
                                                        <a href="{{ route('businesses.companies.show', [$business, $line->company]) }}" class="font-medium text-gray-900 hover:text-emerald-700">{{ $line->company->name }}</a>
                                                    @else
                                                        <span class="text-gray-900">{{ $line->label() }}</span>
                                                    @endif
                                                </td>
                                                <td class="whitespace-nowrap px-4 py-2 font-mono text-xs text-gray-500">{{ $line->invoice_no ?? '—' }}</td>
                                                <td class="{{ $num }} text-gray-900 sm:px-6">{{ $rs($line->paid) }}</td>
                                            </tr>
                                        @endforeach
                                        {{-- Days recorded before payments had a line of their own. --}}
                                        @if ($entry?->company_payment_cash?->isPositive())
                                            <tr>
                                                <td class="px-4 py-2 text-gray-900 sm:px-6">Paid to companies</td>
                                                <td class="px-4 py-2 text-xs text-gray-500">{{ $entry->company_note ?? 'Recorded as one figure' }}</td>
                                                <td class="{{ $num }} text-gray-900 sm:px-6">{{ $rs($entry->company_payment_cash) }}</td>
                                            </tr>
                                        @endif
                                        @if ($payments->isEmpty() && ! $entry?->company_payment_cash?->isPositive())
                                            <tr><td colspan="3" class="px-6 py-10 text-center text-gray-500">No supplier bills paid on this day.</td></tr>
                                        @endif
                                    </tbody>
                                </table>
                            </div>
                        </x-panel>
                        @break

                    {{-- ===================== Expenses ===================== --}}
                    @case('expenses')
                        <div class="overflow-hidden border-y border-line bg-white sm:rounded-lg sm:border">
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-4 sm:px-6">
                                <h2 class="text-base font-semibold text-gray-900">Expenses</h2>
                                @if (! $closed && Route::has('businesses.expenses.index'))
                                    <a href="{{ route('businesses.expenses.index', $business) }}"
                                       class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Add an expense</a>
                                @endif
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="{{ $th }} text-left sm:px-6">Expense head</th>
                                            <th class="{{ $th }} text-left">Description</th>
                                            <th class="{{ $th }} text-right sm:px-6">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @forelse ($expenses as $line)
                                            <tr>
                                                <td class="px-4 py-2 sm:px-6">
                                                    @if ($line->category)
                                                        <a href="{{ route('businesses.expenses.show', [$business, $line->category]) }}" class="font-medium text-gray-900 hover:text-emerald-700">{{ $line->category->name }}</a>
                                                    @else
                                                        <span class="text-gray-900">Uncategorised</span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-2 text-gray-600">{{ $line->description ?? '—' }}</td>
                                                <td class="{{ $num }} text-gray-900 sm:px-6">{{ $rs($line->amount) }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="3" class="px-6 py-10 text-center text-gray-500">No expenses on this day.</td></tr>
                                        @endforelse
                                    </tbody>
                                    @if ($expenses->isNotEmpty())
                                        <tfoot class="border-t border-gray-200 bg-gray-50 font-semibold">
                                            <tr>
                                                <td colspan="2" class="px-4 py-2 text-gray-900 sm:px-6">{{ $expenses->count() }} {{ Str::plural('expense', $expenses->count()) }}</td>
                                                <td class="{{ $num }} sm:px-6">{{ $rs($totals['expenses']) }}</td>
                                            </tr>
                                        </tfoot>
                                    @endif
                                </table>
                            </div>
                        </div>
                        @break

                    {{-- ===================== Change history ===================== --}}
                    @case('history')
                        @include('business.partials.change-history', [
                            'panelDescription' => 'Who added what to this day, and when.',
                        ])
                        @if ($close)
                            @include('business.partials.change-history', [
                                'history' => $close['history'],
                                'panelTitle' => 'Cash count and closing',
                                'panelDescription' => 'Who counted, closed, reopened or corrected this day, and why.',
                            ])
                        @endif
                        @break
                @endswitch
            </div>
        </div>
    </div>

    @if ($closeState)
        @include('business.daily.partials.close-day')
    @endif
</x-workspace-layout>
