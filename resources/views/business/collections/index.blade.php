{{--
    Money still out with the market.

    Grouped by customer rather than by bill, because collection is a round: the
    van visits a pharmacy, not an invoice. Each customer opens to show what
    they owe bill by bill, oldest first, so the collector knows what to ask for.
--}}
@php($rs = fn ($m) => $m->format())

<x-workspace-layout :business="$business">
    {{-- No heading above the page: the tab that brought you here already
         says where you are, and the room is better spent on the list. A
         heading is kept for screen readers. --}}
    <h1 class="sr-only">Bills and collection</h1>
    <div class="w-full px-4 py-5 sm:px-6 lg:px-8" x-data="collections()">
        <x-flash />

        {{-- Two lists: what is still to come in, and every bill there is.
             What was searched for is carried from one to the other. --}}
        @php($carried = array_filter(request()->only(['invoice', 'customer', 'from', 'to']), fn ($v) => $v !== null && $v !== ''))
        {{-- On a grey band of their own, so the two lists are the first
             thing seen and which one is open cannot be missed. --}}
        <nav class="mb-4 flex flex-wrap gap-1.5 rounded-lg bg-gray-200 p-1.5 ring-1 ring-gray-300" aria-label="Bill lists">
            @foreach ([
                'pending' => ['Pending bills', $pendingCount, route('businesses.collections.index', [$business] + $carried)],
                'all' => ['All bills', null, route('businesses.collections.index', [$business, 'show' => 'all'] + $carried)],
            ] as $key => [$label, $count, $href])
                @php($here = $show === $key)
                <a href="{{ $href }}"
                   @class([
                       'inline-flex items-center gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-semibold transition',
                       'bg-white text-gray-900 shadow-sm ring-1 ring-gray-300' => $here,
                       'text-gray-600 hover:bg-gray-300 hover:text-gray-900' => ! $here,
                   ])
                   @if ($here) aria-current="page" @endif>
                    {{ $label }}
                    @if ($count !== null)
                        <span @class([
                            'rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums',
                            'bg-amber-500 text-white' => $count > 0,
                            'bg-gray-100 text-gray-600' => $count === 0,
                        ])>{{ $count }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

        <x-filter-bar submit="Search" :active="$filtered" class="mb-4"
                      clear="{{ route('businesses.collections.index', array_filter([$business, 'show' => $show === 'all' ? 'all' : null])) }}">
            @if ($show === 'all') <input type="hidden" name="show" value="all"> @endif
            <div>
                <x-input-label for="f_invoice" value="Invoice number" />
                <input id="f_invoice" name="invoice" type="search" value="{{ request('invoice') }}" placeholder="e.g. 2411260007"
                       class="mt-1 rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <x-input-label for="f_customer" value="Customer name" />
                <input id="f_customer" name="customer" type="search" value="{{ request('customer') }}" placeholder="Any part of the name"
                       class="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <x-input-label for="f_from" value="From" />
                <input id="f_from" name="from" type="date" value="{{ request('from') }}" max="{{ $today->toDateString() }}"
                       class="mt-1 rounded-md border-gray-300 text-sm shadow-sm">
            </div>
            <div>
                <x-input-label for="f_to" value="To" />
                <input id="f_to" name="to" type="date" value="{{ request('to') }}" max="{{ $today->toDateString() }}"
                       class="mt-1 rounded-md border-gray-300 text-sm shadow-sm">
            </div>
            <x-slot name="count">
                @if ($show === 'all')
                    {{ number_format($allBills->total()) }} {{ Str::plural('bill', $allBills->total()) }}@if ($allSince) since {{ $allSince->format('j M Y') }}. Search or select dates to see older bills. @endif
                @else
                    {{ number_format($rounds->sum(fn ($r) => $r['bills']->count())) }} of {{ number_format($pendingCount) }} pending {{ Str::plural('bill', $pendingCount) }}
                @endif
            </x-slot>
        </x-filter-bar>

        {{-- The list on screen in three figures, right above it. --}}
        @php($billCount = $show === 'all' ? $allBills->total() : $rounds->sum(fn ($r) => $r['bills']->count()))
        @php($pendingNow = $show === 'all' ? $allPending : $total)
        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-gray-400">{{ $show === 'all' ? 'Bills listed' : 'Pending bills' }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ number_format($billCount) }}</p>
                <p class="mt-0.5 text-xs text-gray-500">
                    @if ($show === 'all')
                        {{ $allSince ? 'since ' . $allSince->format('j M Y') : 'matching the search' }}
                    @else
                        from {{ $rounds->count() }} {{ Str::plural('customer', $rounds->count()) }}
                    @endif
                </p>
            </div>

            @if ($show === 'all')
                <div class="rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
                    <p class="text-xs uppercase tracking-wide text-gray-400">Total of these bills</p>
                    <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-gray-900">{{ $rs($allTotal) }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">Sum of the bill totals</p>
                </div>
            @else
                <div class="rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
                    <p class="text-xs uppercase tracking-wide text-gray-400">Total pending in market</p>
                    <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-gray-900">{{ $rs($marketTotal) }}</p>
                    {{-- The ledger figure is the larger one where a business
                         started with receivables that never belonged to a bill. --}}
                    <p class="mt-0.5 text-xs text-gray-500">
                        {{ $filtered || $marketTotal->minus($total)->isZero() ? 'As per the ledger' : 'As per the ledger. The difference is older credit not linked to a bill.' }}
                    </p>
                </div>
            @endif

            <div class="rounded-md border px-4 py-3 shadow-sm {{ $pendingNow->isPositive() ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50' }}">
                <p class="text-xs uppercase tracking-wide {{ $pendingNow->isPositive() ? 'text-amber-700' : 'text-emerald-700' }}">Still pending</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums {{ $pendingNow->isPositive() ? 'text-amber-900' : 'text-emerald-800' }}">{{ $rs($pendingNow) }}</p>
                <p class="mt-0.5 text-xs {{ $pendingNow->isPositive() ? 'text-amber-700' : 'text-emerald-700' }}">
                    {{ $pendingNow->isPositive() ? 'To be collected on the listed bills' : 'All paid. No pending amount.' }}
                </p>
            </div>
        </div>

        @if ($dayClosed)
            <p class="mb-4 rounded-md bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">
                {{ $today->format('j M') }} is closed. Reopen it before recording a collection.
            </p>
        @endif

        @if ($show === 'all')
            <div class="overflow-x-auto rounded-md border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach ([['Invoice','left'],['Date','left'],['Customer','left'],['Bill total','right'],['Paid at sale','right'],['Pending','right'],['','right']] as [$h,$align])
                                <th class="whitespace-nowrap px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first || $loop->last ? 'sm:px-6' : '' }}">{{ $h }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($allBills as $row)
                            @php($bill = $row['bill'])
                            @php($account = $customersByName[mb_strtolower((string) $bill->customer_name)] ?? null)
                            <tr class="hover:bg-gray-50">
                                <td class="whitespace-nowrap px-4 py-2 font-mono text-xs sm:px-6">
                                    <a href="{{ route('businesses.pos.bills.edit', [$business, $bill]) }}" title="Open this bill"
                                       class="font-semibold text-emerald-700 underline decoration-emerald-300 underline-offset-2 hover:text-emerald-900">{{ $bill->reference() }}</a>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-gray-600">{{ $bill->business_date->format('j M Y') }}</td>
                                <td class="px-4 py-2 text-gray-900">
                                    @if ($account && auth()->user()->can('configure', $business))
                                        <a href="{{ route('businesses.pharmacies.show', [$business, $account]) }}" class="font-medium hover:text-emerald-700">{{ $bill->customer_name }}</a>
                                    @else
                                        {{ $bill->customer_name ?? 'Walk-in customer' }}
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-right font-mono tabular-nums text-gray-900">{{ $rs($bill->total) }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right font-mono tabular-nums text-gray-600">{{ $rs($bill->received) }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right font-mono tabular-nums">
                                    @if ($row['owed']->isPositive())
                                        <span class="font-semibold text-amber-700">{{ $rs($row['owed']) }}</span>
                                    @elseif ($bill->credit()->isPositive())
                                        <span class="font-sans text-xs font-medium text-emerald-700" title="Unpaid at the time of sale, received later">Received ✓</span>
                                    @else
                                        <span class="font-sans text-xs font-medium text-gray-400">Paid</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-right sm:px-6">
                                    @if ($row['owed']->isPositive() && $account)
                                        <button type="button" @disabled($dayClosed)
                                                x-on:click="collect(@js([
                                                    'pharmacy_id' => $account->id,
                                                    'name' => $bill->customer_name,
                                                    'owed' => (float) $row['owed']->toDecimal(),
                                                    'bills' => [[
                                                        'id' => $bill->id,
                                                        'ref' => $bill->reference(),
                                                        'owed' => (float) $row['owed']->toDecimal(),
                                                        'age' => (int) $bill->business_date->diffInDays($today),
                                                    ]],
                                                ]))"
                                                class="rounded-md bg-emerald-700 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-40">
                                            Collect
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center">
                                    <p class="text-base font-medium text-gray-900">No bills found</p>
                                    <p class="mt-1 text-sm text-gray-500">{{ $filtered ? 'No bills match the search.' : 'No bills in the last thirty days.' }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $allBills->links() }}</div>
        @else
        <div class="space-y-3">
            @forelse ($rounds as $round)
                <div class="overflow-hidden rounded-md border border-gray-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
                        <button type="button" x-on:click="open === @js($round['name']) ? open = null : open = @js($round['name'])"
                                class="flex min-w-0 items-center gap-3 text-left">
                            <span class="text-gray-400" x-text="open === @js($round['name']) ? '▾' : '▸'"></span>
                            <span class="min-w-0">
                                <span class="block truncate font-medium text-gray-900">{{ $round['name'] ?? 'Walk-in customer' }}</span>
                                <span class="block text-xs text-gray-500">
                                    {{ $round['bills']->count() }} {{ Str::plural('bill', $round['bills']->count()) }} ·
                                    oldest {{ $round['waiting'] }} {{ Str::plural('day', $round['waiting']) }}
                                </span>
                            </span>
                        </button>

                        <div class="flex items-center gap-4">
                            <x-badge :classes="match ($round['band']) {
                                'This week' => 'bg-gray-100 text-gray-700 ring-gray-500/20',
                                '8–30 days' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
                                default => 'bg-red-50 text-red-800 ring-red-600/20',
                            }">{{ $round['band'] }}</x-badge>

                            <span class="font-mono text-base font-semibold tabular-nums text-gray-900">
                                Rs. {{ $rs($round['owed']) }}
                            </span>

                            @if ($round['pharmacy'] !== null)
                                <button type="button" @disabled($dayClosed)
                                        x-on:click="collect(@js([
                                            'pharmacy_id' => $round['pharmacy']->id,
                                            'name' => $round['name'],
                                            'owed' => (float) $round['owed']->toDecimal(),
                                            'bills' => $round['bills']->map(fn ($b) => [
                                                'id' => $b['bill']->id,
                                                'ref' => $b['bill']->reference(),
                                                'owed' => (float) $b['owed']->toDecimal(),
                                                'age' => $b['age'],
                                            ])->all(),
                                        ]))"
                                        class="rounded-md bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-40">
                                    Collect
                                </button>
                            @else
                                <span class="text-xs text-gray-400" title="This bill has no customer account">No account</span>
                            @endif
                        </div>
                    </div>

                    <div x-show="open === @js($round['name'])" x-cloak class="border-t border-gray-100 bg-gray-50/60">
                        <table class="min-w-full text-sm">
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($round['bills'] as $row)
                                    <tr>
                                        <td class="px-4 py-2 font-mono text-xs text-gray-600 sm:px-6">{{ $row['bill']->reference() }}</td>
                                        <td class="px-4 py-2 text-gray-600">{{ $row['bill']->business_date->format('j M Y') }}</td>
                                        <td class="px-4 py-2 text-gray-500">
                                            {{ $row['age'] === 0 ? 'today' : $row['age'] . ' ' . Str::plural('day', $row['age']) }}
                                        </td>
                                        <td class="px-4 py-2 text-right text-gray-500">
                                            bill Rs. {{ $rs($row['bill']->total) }}
                                        </td>
                                        <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900">
                                            Rs. {{ $rs($row['owed']) }}
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            <a href="{{ route('businesses.pos.receipt', [$business, $row['bill']]) }}"
                                               class="text-xs font-medium text-emerald-700 hover:text-emerald-800">Bill →</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <div class="rounded-md border border-gray-200 bg-white px-6 py-12 text-center shadow-sm">
                    <p class="text-base font-medium text-gray-900">{{ $filtered ? 'No pending bills found' : 'No pending amount' }}</p>
                    <p class="mt-1 text-sm text-gray-500">{{ $filtered ? 'No pending bills match the search. The bill may be under All bills.' : 'All bills have been paid.' }}</p>
                </div>
            @endforelse
        </div>
        @endif

        @include('business.collections.partials.collect')
    </div>

    @push('scripts')
        @include('business.collections.partials.script')
    @endpush
</x-workspace-layout>
