@php
    // Days close in sequence, so the one after the lock is the day due. You may
    // still look at any day from the opening to today; only the due one closes.
    $due = $business->locked_through_date?->copy()->addDay()
        ?? $business->opening_date?->copy()->addDay()
        ?? $business->today();
    $earliest = $business->opening_date?->copy()->addDay() ?? $date;

    // Two views of the day: the closing itself, and who has changed it.
    $activeTab = request('tab') === 'history' ? 'history' : 'day';

    /*
     * A closed day is a record, not a form: nothing on it can be acted on until
     * it is reopened. Only the latest closed day can be, and only by someone
     * allowed to — anything else would leave a gap in the chain of closes.
     */
    $isClosed = (bool) $closing?->isFinalized();
    $isLatestClosed = $isClosed && $business->locked_through_date?->equalTo($date);
    $canReopen = $isLatestClosed && auth()->user()->can('reopen', [App\Models\DailyClosing::class, $business]);

    $closedBy = $isClosed && $closing->finalizer
        ? $closing->finalizer->name
            . ($closing->finalizer->isPlatformAdmin() ? '' : ' (' . ($closing->finalizer->roleIn($business)?->label() ?? 'former member') . ')')
        : null;
    $closedAt = $closing?->finalized_at?->timezone($business->timezone)->format('d M Y, h:i A');

    $rs = fn ($m) => ($m->isNegative() ? '−' : '') . $m->absolute()->format();

    // Whatever moved cash that is not one of the day's lines — an opening
    // balance correction is shown on its own; anything else lands here, so the
    // column always adds up.
    $otherCash = $figures['closing_cash']->minus(
        $figures['opening_cash']
            ->plus($figures['cash_sales'])->plus($figures['collections'])
            ->minus($figures['purchases_paid'])->minus($figures['company_payments'])
            ->minus($figures['expenses'])
    )->minus($openingCashCorrection);

    /*
     * The day's figures, in the owner's words. Each row is
     * [label, amount, emphasis, direction]: direction is null for a plain
     * figure (red only when below zero), 'sign' to also show a positive result
     * in green, or 'up-good' / 'up-bad' for a change, which shows ▲/▼ in green
     * when it is good for the business and red when it is not.
     */
    $sections = [
        ['Cash in the drawer', 'What came in and went out of the cash drawer.', array_values(array_filter([
            ['Cash at start of day', $figures['opening_cash'], false, null],
            $openingCashCorrection->isZero() ? null : ['+ Opening cash correction', $openingCashCorrection, false, null],
            ['+ Cash received on sales', $figures['cash_sales'], false, null],
            ['+ Collected on earlier credit', $figures['collections'], false, null],
            ['− Paid for purchases', $figures['purchases_paid'], false, null],
            ['− Paid to companies (earlier bills)', $figures['company_payments'], false, null],
            ['− Expenses', $figures['expenses'], false, null],
            $otherCash->isZero() ? null : ['± Other corrections', $otherCash, false, null],
            ['Should be in the drawer', $figures['closing_cash'], true, null],
        ]))],
        ['Market owes us', 'Credit given to pharmacies, and what they paid back.', [
            ['Owed at start of day', $figures['opening_receivable'], false, null],
            ['+ Sold on credit', $figures['credit_sales'], false, null],
            ['− Collected back', $figures['collections'], false, null],
            ['Owed at end of day', $figures['closing_receivable'], true, null],
            ['Change today', $figures['receivable_delta'], false, 'up-bad'],
        ]],
        ['We owe companies', 'Bought on credit, and what was paid off.', [
            ['Owed at start of day', $figures['opening_payable'], false, null],
            // Only the part left owing reaches the payable; what was paid at
            // the time never touches it.
            ['+ Bought on credit', $figures['purchases_on_account'], false, null],
            ['− Paid to companies', $figures['company_payments'], false, null],
            ['Owed at end of day', $figures['closing_payable'], true, null],
            ['Change today', $figures['payable_delta'], false, 'up-bad'],
        ]],
        ['Medicine in stock', 'Valued at cost. An estimate until stock is physically counted.', [
            ['At start of day', $figures['opening_stock'], false, null],
            ['+ Bought (at cost)', $figures['purchases'], false, null],
            ['− Cost of what was sold', $figures['cogs'], false, null],
            ['At end of day', $figures['closing_stock'], true, null],
        ]],
        ["The day's result", 'What the day earned.', [
            ['Sales', $figures['sales'], false, null],
            ['− Cost of what was sold', $figures['cogs'], false, null],
            ['Gross profit', $figures['gross_profit'], true, 'sign'],
            ['− Expenses', $figures['expenses'], false, null],
            ['After expenses', $figures['net_profit'], true, 'sign'],
            ['Net position (own − owe)', $figures['net_position'], true, 'sign'],
        ]],
    ];

    // What physically arrived. The figures above are money; this is the goods
    // behind them, and the two can disagree when a bill has not turned up yet.
    $goodsSummary = $deliveries->isEmpty()
        ? 'Nothing was delivered on this day.'
        : $deliveries->count() . ' ' . Str::plural('delivery', $deliveries->count())
            . ' · ' . number_format($packsIn) . ' packs into stock';
@endphp

<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-xl font-semibold text-gray-900">
                        Closing {{ $date->format('D d M Y') }}
                    </h1>
                    @if ($isClosed)
                        <x-badge classes="bg-emerald-50 text-emerald-800 ring-emerald-600/20">Closed</x-badge>
                    @elseif ($closing?->status === 'superseded')
                        <x-badge classes="bg-amber-50 text-amber-800 ring-amber-600/20">Reopened</x-badge>
                    @elseif ($date->isSameDay($due))
                        <x-badge classes="bg-amber-50 text-amber-800 ring-amber-600/20">Next day due</x-badge>
                    @elseif ($date->greaterThan($due))
                        <x-badge classes="bg-gray-100 text-gray-600 ring-gray-500/20">
                            Not yet — {{ $due->format('d M') }} closes first
                        </x-badge>
                    @endif
                </div>
                <p class="mt-1 text-sm text-gray-500">
                    @if ($isClosed)
                        Closed by <span class="font-medium text-gray-700">{{ $closedBy ?? '—' }}</span>
                        on {{ $closedAt }}. Locked — reopen it to change anything.
                    @else
                        Every figure is read from the books; only the cash count is typed.
                    @endif
                </p>
            </div>

            {{-- Pick a day, and — on a closed day — reopen it. --}}
            <div class="flex flex-wrap items-center gap-2"
                 x-data="{ go(d) { if (d) window.location = '{{ route('businesses.closing.show', [$business, 'DATE']) }}'.replace('DATE', d); } }">
                @if ($canReopen)
                    <button type="button" x-on:click="$dispatch('open-modal', 'reopen-day')"
                            class="rounded-md border border-amber-300 bg-white px-3 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-50">
                        Reopen day
                    </button>
                @elseif ($isClosed && ! $isLatestClosed)
                    <span class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-500"
                          title="Days reopen newest first, so the chain of closed days has no gaps.">
                        Reopen {{ $business->locked_through_date?->format('d M') }} first
                    </span>
                @endif

                <a href="{{ route('businesses.closing.show', [$business, $date->copy()->subDay()->toDateString()]) }}"
                   class="rounded-md border border-gray-300 bg-white px-2 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"
                   aria-label="Previous day" title="Previous day">←</a>

                <input type="date" value="{{ $date->toDateString() }}"
                       min="{{ $earliest->toDateString() }}"
                       max="{{ $business->today()->toDateString() }}"
                       x-on:change="go($event.target.value)"
                       class="rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">

                <a href="{{ route('businesses.closing.show', [$business, $date->copy()->addDay()->toDateString()]) }}"
                   class="rounded-md border border-gray-300 bg-white px-2 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 {{ $date->greaterThanOrEqualTo($business->today()) ? 'pointer-events-none opacity-40' : '' }}"
                   aria-label="Next day" title="Next day">→</a>

                @unless ($date->isSameDay($due))
                    <a href="{{ route('businesses.closing.show', [$business, $due->toDateString()]) }}"
                       class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                        Go to {{ $due->format('d M') }}
                    </a>
                @endunless

                <a href="{{ route('businesses.closing.index', $business) }}"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Closed days
                </a>
            </div>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />
        <x-input-error :messages="$errors->get('status')" class="mb-4" />

        @include('business.partials.page-tabs', [
            'tabs' => ['day' => 'Day closing', 'history' => 'Change history'],
            'active' => $activeTab,
            'counts' => ['history' => $history->count()],
        ])

        @if ($activeTab === 'history')
            @include('business.partials.change-history')
        @else
        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                @if ($isClosed)
                    {{-- A closed day has nothing left to check. --}}
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 sm:px-6">
                        <p class="font-semibold">This day is closed.</p>
                        <p class="mt-0.5">
                            Its figures are final and nothing can be posted on or before {{ $date->format('d M Y') }}.
                            @if ($canReopen)
                                Use <strong>Reopen day</strong> at the top to change it.
                            @elseif (! $isLatestClosed)
                                Later days are closed too; they have to be reopened first, newest first.
                            @endif
                        </p>
                    </div>
                @else
                    <x-panel title="Before closing"
                             description="Every one of these has to be ticked. Closing a day with something missing locks the mistake in.">
                        <ul class="divide-y divide-gray-100 text-sm">
                            @foreach ($checks as $check)
                                {{-- A failed advisory is worth reading but does not
                                     hold the day; a failed blocker does. --}}
                                <li class="flex items-start gap-3 px-4 py-3 sm:px-6">
                                    <span class="mt-0.5 font-semibold {{ $check['ok'] ? 'text-emerald-600' : ($check['blocking'] ? 'text-red-600' : 'text-sky-600') }}">
                                        {{ $check['ok'] ? '✓' : ($check['blocking'] ? '✗' : 'i') }}
                                    </span>
                                    <span>
                                        <span class="flex flex-wrap items-center gap-2">
                                            <span class="font-medium text-gray-900">{{ $check['label'] }}</span>
                                            @if (! $check['blocking'] && ! $check['ok'])
                                                <x-badge classes="bg-sky-50 text-sky-800 ring-sky-600/20">worth knowing, not blocking</x-badge>
                                            @endif
                                        </span>
                                        <span class="block text-gray-500">{{ $check['detail'] }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </x-panel>
                @endif

                <div class="grid gap-6 md:grid-cols-2">
                    @foreach ($sections as [$title, $description, $rows])
                        <x-panel :title="$title" :description="$description">
                            <dl class="divide-y divide-gray-100 text-sm">
                                @foreach ($rows as [$label, $value, $strong, $direction])
                                    <div class="flex justify-between gap-4 px-4 py-2 sm:px-6 {{ $strong ? 'font-semibold' : '' }}">
                                        <dt class="text-gray-600">{{ $label }}</dt>
                                        @if (in_array($direction, ['up-good', 'up-bad'], true))
                                            @if ($value->isZero())
                                                <dd class="tabular-nums text-gray-400">no change</dd>
                                            @else
                                                <dd class="whitespace-nowrap tabular-nums {{ $value->isPositive() === ($direction === 'up-good') ? 'text-emerald-700' : 'text-red-700' }}">
                                                    {{ $value->isPositive() ? '▲ +' : '▼ −' }}{{ $value->absolute()->format() }}
                                                </dd>
                                            @endif
                                        @else
                                            <dd class="whitespace-nowrap tabular-nums {{ $value->isNegative() ? 'text-red-700' : ($direction === 'sign' && $value->isPositive() ? 'text-emerald-700' : 'text-gray-900') }}">
                                                {{ $rs($value) }}
                                            </dd>
                                        @endif
                                    </div>
                                @endforeach
                            </dl>
                        </x-panel>
                    @endforeach
                </div>

                <x-panel title="Goods received" :description="$goodsSummary">
                    @if ($deliveries->isNotEmpty())
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        @foreach ([['Reference','left'],['Supplier','left'],['Items','right'],['Value','right'],['Invoice','left'],['In the books','left']] as [$h,$align])
                                            <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first ? 'sm:px-6' : '' }}">{{ $h }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($deliveries as $delivery)
                                        <tr>
                                            <td class="px-3 py-2 sm:px-6">
                                                <a href="{{ route('businesses.orders.show', [$business, $delivery]) }}"
                                                   class="font-medium text-gray-900 hover:text-emerald-700">{{ $delivery->reference }}</a>
                                            </td>
                                            <td class="px-3 py-2 text-gray-900">{{ $delivery->company->name }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums text-gray-600">{{ $delivery->receiptLines->count() }}</td>
                                            <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-900">{{ $delivery->receivedTotal()->format() }}</td>
                                            <td class="px-3 py-2 text-xs text-gray-500">{{ $delivery->purchaseLine?->invoice_no ?? '—' }}</td>
                                            <td class="whitespace-nowrap px-3 py-2 text-xs">
                                                @if ($delivery->purchaseLine === null)
                                                    <span class="text-sky-700">no bill yet — packs only</span>
                                                @elseif ($delivery->purchaseLine->dailyEntry->isEditable())
                                                    <span class="text-amber-700">waiting on this day being posted</span>
                                                @else
                                                    <span class="text-emerald-700">posted</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-gray-50">
                                    <tr>
                                        <td colspan="3" class="px-3 py-2 text-right text-sm font-semibold text-gray-900 sm:px-6">
                                            {{ number_format($packsIn) }} packs
                                        </td>
                                        <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-900">{{ $deliveriesValue->format() }}</td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif
                </x-panel>
            </div>

            <div class="space-y-6">
                @if ($isClosed)
                    {{-- The count as it was agreed. Nothing to change here. --}}
                    <x-panel title="Cash count" description="Agreed when the day was closed.">
                        <dl class="divide-y divide-gray-100 text-sm">
                            <div class="flex justify-between px-4 py-2 sm:px-6">
                                <dt class="text-gray-600">Should have been in the drawer</dt>
                                <dd class="tabular-nums text-gray-900">{{ $rs($closing->counted_cash !== null ? $closing->counted_cash->minus($closing->cash_variance ?? \App\Support\Money::zero()) : $figures['closing_cash']) }}</dd>
                            </div>
                            <div class="flex justify-between px-4 py-2 font-semibold sm:px-6">
                                <dt class="text-gray-600">Counted</dt>
                                <dd class="tabular-nums {{ $closing->counted_cash?->isNegative() ? 'text-red-700' : 'text-gray-900' }}">
                                    {{ $closing->counted_cash !== null ? $rs($closing->counted_cash) : 'not counted' }}
                                </dd>
                            </div>
                            <div class="flex justify-between px-4 py-2 sm:px-6">
                                <dt class="text-gray-600">Difference</dt>
                                <dd class="tabular-nums">
                                    @if (! $closing->hasCashVariance())
                                        <span class="text-emerald-700">✓ matched</span>
                                    @else
                                        <span class="{{ $closing->cash_variance->isNegative() ? 'text-red-700' : 'text-emerald-700' }}">
                                            {{ $closing->cash_variance->isNegative() ? '▼ short ' : '▲ over ' }}{{ $closing->cash_variance->absolute()->format() }}
                                        </span>
                                    @endif
                                </dd>
                            </div>
                            @if ($closing->variance_reason)
                                <div class="px-4 py-2 text-gray-600 sm:px-6">
                                    Reason: <span class="italic">“{{ $closing->variance_reason }}”</span>
                                </div>
                            @endif
                        </dl>
                    </x-panel>
                @else
                    {{--
                        The one figure the app cannot work out for itself: what is
                        actually in the drawer. It shows what should be there and asks
                        for the count, so a missed entry or missing money shows up as a
                        difference. "It matches" only fills in the box — someone still
                        counts and presses save.
                    --}}
                    <x-panel title="Count the cash"
                             description="The app has worked out how much cash should be in the drawer. Count the actual notes and coins and enter what you find — if the two agree, the day's cash entries are right.">
                        <form method="POST" action="{{ route('businesses.closing.reconcile', [$business, $date->toDateString()]) }}"
                              class="space-y-4 p-4 sm:p-6"
                              x-data="{
                                  expected: {{ $figures['closing_cash']->toDecimal() }},
                                  counted: @js(old('counted_cash', $closing?->counted_cash?->toDecimal() ?? '')),
                                  n(v) { const p = parseFloat(String(v ?? '').replace(/,/g, '')); return isNaN(p) ? null : p; },
                                  get diff() { const c = this.n(this.counted); return c === null ? null : Math.round((c - this.expected) * 100) / 100; },
                                  fmt(v) { return Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                              }">
                            @csrf

                            <div class="rounded-md bg-gray-50 px-4 py-3">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Should be in the drawer</p>
                                <p class="mt-1 text-2xl font-semibold tabular-nums {{ $figures['closing_cash']->isNegative() ? 'text-red-700' : 'text-gray-900' }}">
                                    Rs. {{ $rs($figures['closing_cash']) }}
                                </p>
                                <p class="mt-1 text-xs text-gray-500">
                                    Cash at the start of the day, plus cash received and collected, minus what was paid
                                    out and spent — the "Cash in the drawer" panel shows each line.
                                    @unless ($openingCashCorrection->isZero())
                                        Includes the <strong>Rs. {{ $rs($openingCashCorrection) }}</strong> opening cash correction.
                                    @endunless
                                </p>
                            </div>

                            @if ($figures['closing_cash']->isNegative())
                                <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                    <strong>The drawer is below zero.</strong>
                                    More cash went out than came in. You can still close the day — enter the count with a minus sign.
                                </div>
                            @endif

                            <div>
                                <x-input-label for="counted_cash" value="You counted" />
                                <div class="mt-1 flex gap-2">
                                    <x-text-input id="counted_cash" name="counted_cash" type="text" inputmode="decimal"
                                                  class="block w-full text-right tabular-nums"
                                                  x-model="counted" placeholder="0.00" required />
                                    <button type="button" x-on:click="counted = expected.toFixed(2)"
                                            class="shrink-0 whitespace-nowrap rounded-md border border-emerald-600 px-3 text-sm font-medium text-emerald-800 hover:bg-emerald-50"
                                            title="Only after counting — fills in the expected amount">
                                        I counted — it matches
                                    </button>
                                </div>
                                <x-input-error :messages="$errors->get('counted_cash')" class="mt-2" />

                                <p class="mt-2 text-sm" x-show="diff !== null" x-cloak>
                                    <span x-show="diff === 0" class="font-medium text-emerald-700">✓ Matches exactly.</span>
                                    <span x-show="diff < 0" class="font-medium text-red-700">
                                        ▼ Short by Rs. <span x-text="fmt(diff)"></span> — less cash than there should be.
                                    </span>
                                    <span x-show="diff > 0" class="font-medium text-amber-700">
                                        ▲ Over by Rs. <span x-text="fmt(diff)"></span> — more cash than there should be.
                                    </span>
                                </p>
                            </div>

                            @if ($closing?->counted_cash !== null)
                                <div class="rounded-md {{ $closing->hasCashVariance() ? 'bg-amber-50 text-amber-900' : 'bg-emerald-50 text-emerald-900' }} px-3 py-2 text-sm">
                                    Last saved count: Rs. {{ $rs($closing->counted_cash) }}
                                    {{ $closing->hasCashVariance() ? '— difference ' . $rs($closing->cash_variance) : '✓ matched' }}
                                </div>
                            @endif

                            {{-- Only asked for when there is something to explain. --}}
                            <div x-show="diff !== null && diff !== 0" x-cloak>
                                <x-input-label for="variance_reason" value="Why is it different?" />
                                <x-text-input id="variance_reason" name="variance_reason" type="text"
                                              class="mt-1 block w-full"
                                              :value="old('variance_reason', $closing?->variance_reason)"
                                              placeholder="Owner took 2,000 for fuel" />
                                <x-input-error :messages="$errors->get('variance_reason')" class="mt-2" />
                            </div>

                            <button class="w-full rounded-md border border-emerald-700 bg-white px-3 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-50">
                                Save cash count
                            </button>
                        </form>
                    </x-panel>

                    @can('finalize', [App\Models\DailyClosing::class, $business])
                        <x-panel title="Close the day">
                            <div class="space-y-3 p-4 sm:p-6">
                                @if ($canClose)
                                    <form method="POST" action="{{ route('businesses.closing.finalize', [$business, $date->toDateString()]) }}">
                                        @csrf
                                        <p class="mb-3 text-sm text-gray-600">
                                            Closing makes these figures final. Nothing can be posted on or before
                                            {{ $date->format('d M Y') }} afterwards unless the day is reopened.
                                        </p>
                                        <button class="w-full rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                                            Close the day
                                        </button>
                                    </form>
                                @else
                                    <p class="text-sm text-red-700">
                                        Not yet — clear the ✗ items under "Before closing" first.
                                    </p>
                                @endif
                            </div>
                        </x-panel>
                    @endcan
                @endif
            </div>
        </div>
        @endif
    </div>

    {{-- Reopening is deliberate: it asks why, and says what it will do. --}}
    @if ($canReopen)
        <x-modal name="reopen-day" :show="$errors->has('reason')" maxWidth="md">
            <form method="POST" action="{{ route('businesses.closing.reopen', [$business, $date->toDateString()]) }}" class="p-6">
                @csrf
                <h2 class="text-lg font-semibold text-gray-900">Reopen {{ $date->format('D d M Y') }}?</h2>
                <p class="mt-2 text-sm text-gray-600">
                    The day unlocks so its entries can be changed. Its figures stop being final until it is
                    closed again, which means counting the cash again. The closing it replaces, and the reason
                    you give, stay on the record.
                </p>

                <div class="mt-4">
                    <x-input-label for="reason" value="Why are you reopening it?" />
                    <x-text-input id="reason" name="reason" type="text" class="mt-1 block w-full"
                                  :value="old('reason')" placeholder="Company invoice for this day arrived late"
                                  required minlength="5" />
                    <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" x-on:click="$dispatch('close')"
                            class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button class="rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-700">
                        Reopen day
                    </button>
                </div>
            </form>
        </x-modal>
    @endif
</x-workspace-layout>
