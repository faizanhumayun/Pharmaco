<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Business position</h1>
                <p class="mt-1 text-sm text-gray-500">
                    As at {{ $date->format('D d M Y') }}. All figures are calculated from the ledger.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('businesses.daily.day', $business) }}"
                   class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    Daily Log
                </a>
                <a href="{{ route('businesses.closing.show', $business) }}"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Day closing
                </a>
            </div>
        </div>
    </x-slot>

    <div class="w-full space-y-8 px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        @if (! $integrity['trial']['balanced'] || ! $integrity['positionConsistent'])
            <div class="rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900">
                <strong>The ledger does not reconcile.</strong>
                Until this is resolved, the figures on this page may be incorrect.
            </div>
        @endif

        {{-- The six things worth knowing, side by side: one row, one glance.
             Each is a headline figure with what it is made of beneath, and the
             whole card is the way through to acting on it. --}}
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            @if ($showPosition)
                <a href="{{ route('businesses.ledger', $business) }}"
                   class="flex flex-col rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200 transition hover:ring-emerald-300">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Net position</p>
                    <p class="mt-1 font-mono text-2xl font-bold tabular-nums {{ $position->netPosition()->isNegative() ? 'text-red-700' : 'text-gray-900' }}">
                        {{ $position->netPosition()->format() }}
                    </p>
                    <dl class="mt-3 space-y-1 border-t border-gray-100 pt-2 text-xs">
                        @foreach (['Stock' => $stock, 'Cash' => $cash, 'Receivables' => $receivables, 'Payables' => $payables] as $label => $figure)
                            <div class="flex justify-between gap-2">
                                <dt class="text-gray-400">{{ $label }}@if ($label === 'Stock')<span class="text-amber-700"> · estimated</span>@endif</dt>
                                <dd class="font-mono tabular-nums text-gray-700">{{ $figure->format() }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    {{-- Stock is the one headline figure that is an estimate, and
                         saying so beside it is the whole reason it is marked. --}}
                    <p class="mt-2 text-[11px] leading-snug text-gray-400">{{ $confidence['detail'] }}</p>
                </a>
            @endif

            <a href="{{ route('businesses.daily.index', $business) }}"
               class="flex flex-col rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200 transition hover:ring-emerald-300">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Sales today</p>
                <p class="mt-1 font-mono text-2xl font-bold tabular-nums text-gray-900">{{ $today['sales']->format() }}</p>
                <dl class="mt-3 space-y-1 border-t border-gray-100 pt-2 text-xs">
                    @foreach (['Gross profit' => $today['grossProfit'], 'Expenses' => $today['expenses'], 'Net profit' => $today['netProfit'], 'Credit change' => $today['receivableDelta']] as $label => $figure)
                        <div class="flex justify-between gap-2">
                            <dt class="text-gray-400">{{ $label }}</dt>
                            <dd class="font-mono tabular-nums {{ $figure->isNegative() ? 'text-red-700' : 'text-gray-700' }}">{{ $figure->format() }}</dd>
                        </div>
                    @endforeach
                </dl>
            </a>

            {{-- What the market owes in all, which is not the same as what is
                 sitting on unpaid bills: older credit was carried in before any
                 bill existed. Both are shown, side by side, rather than one
                 being made to stand for the other. --}}
            <a href="{{ route('businesses.pharmacies.index', $business) }}"
               class="flex flex-col rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200 transition hover:ring-emerald-300">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Market credit</p>
                <p class="mt-1 font-mono text-2xl font-bold tabular-nums {{ $receivables->isPositive() ? 'text-amber-800' : 'text-gray-900' }}">
                    {{ $receivables->format() }}
                </p>
                <dl class="mt-3 space-y-1 border-t border-gray-100 pt-2 text-xs">
                    <div class="flex justify-between gap-2">
                        <dt class="text-gray-400">Today</dt>
                        <dd class="font-mono tabular-nums {{ $today['receivableDelta']->isPositive() ? 'text-red-700' : 'text-emerald-700' }}">
                            {{ $today['receivableDelta']->format() }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-gray-400">On unpaid bills</dt>
                        <dd class="font-mono tabular-nums text-gray-700">{{ $watch['collection']['value']->format() }}</dd>
                    </div>
                    @unless ($customerAdvances->isZero())
                        <div class="flex justify-between gap-2">
                            <dt class="text-gray-400">Customer advances</dt>
                            <dd class="font-mono tabular-nums text-gray-700">{{ $customerAdvances->format() }}</dd>
                        </div>
                    @endunless
                </dl>
            </a>

            <a href="{{ route('businesses.collections.index', $business) }}"
               class="flex flex-col rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200 transition hover:ring-emerald-300">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">To collect</p>
                <p class="mt-1 font-mono text-2xl font-bold tabular-nums {{ $watch['collection']['value']->isPositive() ? 'text-amber-800' : 'text-gray-900' }}">
                    {{ $watch['collection']['value']->format() }}
                </p>
                <dl class="mt-3 space-y-1 border-t border-gray-100 pt-2 text-xs">
                    <div class="flex justify-between gap-2">
                        <dt class="text-gray-400">Bills</dt>
                        <dd class="font-mono tabular-nums text-gray-700">{{ $watch['collection']['bills'] }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-gray-400">Customers</dt>
                        <dd class="font-mono tabular-nums text-gray-700">{{ $watch['collection']['customers'] }}</dd>
                    </div>
                </dl>
                <span class="mt-auto pt-2 text-xs font-medium text-emerald-700">Collect →</span>
            </a>

            <a href="{{ route('businesses.stock.expiring', $business) }}"
               class="flex flex-col rounded-lg bg-white p-4 shadow-sm transition ring-1 {{ $watch['expired']['count'] > 0 ? 'ring-red-300 hover:ring-red-400' : 'ring-gray-200 hover:ring-emerald-300' }}">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Near expiry</p>
                <p class="mt-1 font-mono text-2xl font-bold tabular-nums {{ $watch['expiring']['count'] > 0 ? 'text-amber-800' : 'text-gray-900' }}">
                    {{ $watch['expiring']['value']->format() }}
                </p>
                <dl class="mt-3 space-y-1 border-t border-gray-100 pt-2 text-xs">
                    <div class="flex justify-between gap-2">
                        <dt class="text-gray-400">Products</dt>
                        <dd class="font-mono tabular-nums text-gray-700">{{ $watch['expiring']['count'] }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="{{ $watch['expired']['count'] > 0 ? 'text-red-600' : 'text-gray-400' }}">Expired</dt>
                        <dd class="font-mono tabular-nums {{ $watch['expired']['count'] > 0 ? 'font-semibold text-red-800' : 'text-gray-700' }}">
                            {{ $watch['expired']['value']->format() }}
                        </dd>
                    </div>
                </dl>
                @if ($watch['expired']['count'] > 0)
                    <span class="mt-auto pt-2 text-xs font-medium text-red-700">
                        {{ $watch['expired']['count'] }} to remove from stock →
                    </span>
                @endif
            </a>

            {{-- The monthly round: each company's rack counted at least once a
                 month. Every company holding stock is in exactly one line below,
                 so the lines add up to the total. --}}
            @php($round = $watch['audit'])
            <a href="{{ route('businesses.company-audits.index', $business) }}"
               class="flex flex-col rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200 transition hover:ring-emerald-300">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Company audit</p>
                <p class="mt-1 font-mono text-2xl font-bold tabular-nums text-gray-900">
                    {{ number_format($round['audited']) }}<span class="text-base font-normal text-gray-400">/{{ number_format($round['companies']) }}</span>
                </p>
                <p class="text-xs text-gray-500">companies audited this month</p>
                <div class="mt-1.5 h-1.5 rounded-full bg-gray-100">
                    <div class="h-1.5 rounded-full bg-emerald-700" style="width: {{ $round['companies'] ? round($round['audited'] / $round['companies'] * 100) : 0 }}%"></div>
                </div>
                <dl class="mt-3 space-y-1 border-t border-gray-100 pt-2 text-xs">
                    <div class="flex justify-between gap-2">
                        <dt class="text-gray-400">Audited</dt>
                        <dd class="font-mono tabular-nums text-emerald-700">{{ $round['audited'] }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-gray-400">Being counted</dt>
                        <dd class="font-mono tabular-nums text-gray-700">{{ $round['counting'] }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="{{ $round['waiting'] > 0 ? 'text-amber-700' : 'text-gray-400' }}">Waiting for approval</dt>
                        <dd class="font-mono tabular-nums {{ $round['waiting'] > 0 ? 'font-semibold text-amber-800' : 'text-gray-700' }}">{{ $round['waiting'] }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="{{ $round['not_started'] > 0 ? 'text-amber-700' : 'text-gray-400' }}">Not started</dt>
                        <dd class="font-mono tabular-nums {{ $round['not_started'] > 0 ? 'font-semibold text-amber-800' : 'text-gray-700' }}">{{ $round['not_started'] }}</dd>
                    </div>
                </dl>
            </a>
        </section>

        {{-- Today itself, for quick access: the counter's bills, what came in
             from companies, and what was spent. Three cards of one height, each
             scrolling inside, newest first. --}}
        @php($supplies = $todayEntry?->purchaseLines ?? collect())
        @php($spent = $todayEntry?->expenseLines ?? collect())
        @php($sum = fn ($rows, $field) => $rows->reduce(fn ($total, $row) => $total->plus($row->{$field}), \App\Support\Money::zero()))
        @php($canSell = auth()->user()->can('sellAtPos', $business))

        <div>
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">
                Today · {{ $business->today()->format('D d M Y') }}
            </h2>
            <div class="grid gap-4 lg:grid-cols-3">
                {{-- Bills rung up at the counter. --}}
                <section class="flex h-80 flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex shrink-0 items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-4 py-2.5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Bills <span class="font-normal normal-case tracking-normal text-gray-400">· {{ $todayBills->count() }}</span>
                        </p>
                        <span class="flex items-center gap-3">
                        <p class="font-mono text-xs font-semibold tabular-nums text-gray-900">{{ $sum($todayBills, 'total')->format() }}</p>
                        @can('sellAtPos', $business)
                        <a href="{{ route('businesses.pos.index', $business) }}" target="_blank" title="New bill at POS" aria-label="New bill at POS"
                           class="flex h-7 w-7 items-center justify-center rounded-md bg-emerald-700 text-base font-semibold leading-none text-white hover:bg-emerald-800">+</a>
                        @endcan
                    </span>
                    </div>
                    <ul class="min-h-0 flex-1 divide-y divide-gray-100 overflow-y-auto">
                        @forelse ($todayBills as $bill)
                            <li>
                                <a href="{{ $canSell ? route('businesses.pos.bills.edit', [$business, $bill]) : '#' }}"
                                   class="flex items-center justify-between gap-3 px-4 py-2 hover:bg-emerald-50">
                                    <span class="min-w-0">
                                        <span class="block font-mono text-sm font-medium text-gray-900">{{ $bill->reference() }}</span>
                                        <span class="block truncate text-xs text-gray-500">
                                            {{ $bill->customer_name ?? 'Walk-in customer' }} · {{ $bill->lines_count }} {{ Str::plural('line', $bill->lines_count) }} ·
                                            {{ $bill->created_at->timezone($business->timezone)->format('h:i A') }}
                                        </span>
                                    </span>
                                    <span class="shrink-0 font-mono text-sm tabular-nums text-gray-900">{{ $bill->total->format() }}</span>
                                </a>
                            </li>
                        @empty
                            <li class="px-4 py-3 text-sm text-gray-500">No bills today.</li>
                        @endforelse
                    </ul>
                </section>
                {{-- What came in from companies. --}}
                <section class="flex h-80 flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex shrink-0 items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-4 py-2.5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Supplies <span class="font-normal normal-case tracking-normal text-gray-400">· {{ $supplies->count() }}</span>
                        </p>
                        <span class="flex items-center gap-3">
                        <p class="font-mono text-xs font-semibold tabular-nums text-gray-900">{{ $sum($supplies, 'amount')->format() }}</p>
                        @can('manageOrders', $business)
                        <a href="{{ route('businesses.stock.receive', $business) }}" title="Add stock" aria-label="Add stock"
                           class="flex h-7 w-7 items-center justify-center rounded-md bg-emerald-700 text-base font-semibold leading-none text-white hover:bg-emerald-800">+</a>
                        @endcan
                    </span>
                    </div>
                    <ul class="min-h-0 flex-1 divide-y divide-gray-100 overflow-y-auto">
                        @forelse ($supplies->sortByDesc('id') as $supply)
                            @php($to = $supply->order_id
                                ? route('businesses.orders.show', [$business, $supply->order_id])
                                : ($supply->company_id ? route('businesses.companies.show', [$business, $supply->company_id]) : null))
                            <li>
                                <a @if ($to) href="{{ $to }}" @endif class="flex items-center justify-between gap-3 px-4 py-2 hover:bg-emerald-50">
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-medium text-gray-900">{{ $supply->company?->name ?? $supply->company_name ?? 'No company name' }}</span>
                                        <span class="block truncate text-xs text-gray-500">
                                            {{ $supply->invoice_no ?: 'No invoice number' }}
                                            @if ($supply->paid?->isPositive()) · {{ $supply->paid->format() }} paid @endif
                                        </span>
                                    </span>
                                    <span class="shrink-0 font-mono text-sm tabular-nums text-gray-900">{{ $supply->amount->format() }}</span>
                                </a>
                            </li>
                        @empty
                            <li class="px-4 py-3 text-sm text-gray-500">No stock received today.</li>
                        @endforelse
                    </ul>
                </section>
                {{-- What was spent. --}}
                <section class="flex h-80 flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex shrink-0 items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-4 py-2.5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Expenses <span class="font-normal normal-case tracking-normal text-gray-400">· {{ $spent->count() }}</span>
                        </p>
                        <span class="flex items-center gap-3">
                        <p class="font-mono text-xs font-semibold tabular-nums text-gray-900">{{ $sum($spent, 'amount')->format() }}</p>
                        @can('create', [\App\Models\DailyEntry::class, $business])
                        <button type="button" x-data x-on:click="$dispatch('open-drawer', 'add-expense')"
                                title="Add expense" aria-label="Add expense"
                                class="flex h-7 w-7 items-center justify-center rounded-md bg-emerald-700 text-base font-semibold leading-none text-white hover:bg-emerald-800">+</button>
                        @endcan
                    </span>
                    </div>
                    <ul class="min-h-0 flex-1 divide-y divide-gray-100 overflow-y-auto">
                        @forelse ($spent->sortByDesc('id') as $expense)
                            <li>
                                <a @if ($expense->expense_category_id) href="{{ route('businesses.expenses.show', [$business, $expense->expense_category_id]) }}" @endif
                                   class="flex items-center justify-between gap-3 px-4 py-2 hover:bg-emerald-50">
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-medium text-gray-900">{{ $expense->category?->name ?? 'Expense' }}</span>
                                        @if ($expense->description)
                                            <span class="block truncate text-xs text-gray-500">{{ $expense->description }}</span>
                                        @endif
                                    </span>
                                    <span class="shrink-0 font-mono text-sm tabular-nums text-gray-900">{{ $expense->amount->format() }}</span>
                                </a>
                            </li>
                        @empty
                            <li class="px-4 py-3 text-sm text-gray-500">No expenses today.</li>
                        @endforelse
                    </ul>
                </section>
            </div>
        </div>

        {{-- Trends --}}
        @if ($showPosition && count($trend['labels']) > 1)
            <section>
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Last {{ count($trend['labels']) }} closed days
                </h2>
                <x-panel>
                    <div class="p-4 sm:p-6">
                        {{-- The chart fills its container (maintainAspectRatio is
                             off), so the container has to have a height of its
                             own. Without one, each redraw makes the canvas a
                             little taller and the page grows without end. --}}
                        <div class="relative h-72">
                            <canvas id="trendChart"></canvas>
                        </div>
                        <p class="mt-3 text-xs text-gray-500">
                            Compare receivables with sales. If receivables increase while sales stay the same,
                            collections are falling behind.
                        </p>
                    </div>
                </x-panel>
            </section>

            @push('scripts')
                <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
                <script>
                    new Chart(document.getElementById('trendChart'), {
                        type: 'line',
                        data: {
                            labels: @json($trend['labels']),
                            datasets: [
                                @foreach ($trend['series'] as $label => $values)
                                    {
                                        label: @json($label),
                                        data: @json($values),
                                        // From the theme file (tokens.css, --chart-1 to --chart-5).
                                        borderColor: themeColor('chart-{{ $loop->iteration }}'),
                                        backgroundColor: themeColor('chart-{{ $loop->iteration }}', 0.15),
                                        borderWidth: 2,
                                        tension: 0.25,
                                        pointRadius: 2,
                                    },
                                @endforeach
                            ],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: { mode: 'index', intersect: false },
                            scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() } } },
                        },
                    });
                </script>
            @endpush
        @endif
    </div>

    {{-- Opened by the + on the Expenses card. --}}
    @can('create', [\App\Models\DailyEntry::class, $business])
        <x-add-expense-drawer :business="$business" />
    @endcan
</x-workspace-layout>
