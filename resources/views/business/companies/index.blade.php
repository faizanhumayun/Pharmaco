<x-workspace-layout :business="$business">
    {{-- No heading above the page: the tab that brought you here already
         says where you are. A heading is kept for screen readers. --}}
    <h1 class="sr-only">Companies</h1>

    <div class="w-full px-4 py-5 sm:px-6 lg:px-8">
        <x-flash />

        {{-- Two lists on a grey band: every company, and those with a
             balance still to pay. Adding one sits at the end of the band. --}}
        @php($carried = array_filter(request()->only(['q', 'status', 'sort', 'from', 'to']), fn ($v) => $v !== null && $v !== ''))
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-gray-200 p-1.5 ring-1 ring-gray-300">
            <nav class="flex flex-wrap gap-1.5" aria-label="Company lists">
                @foreach ([
                    'all' => ['All companies', $allCount, route('businesses.companies.index', [$business] + $carried)],
                    'pending' => ['With pending balance', $pendingCount, route('businesses.companies.index', [$business, 'owing' => 1] + $carried)],
                ] as $key => [$label, $count, $href])
                    @php($here = $owing === ($key === 'pending'))
                    <a href="{{ $href }}"
                       @class([
                           'inline-flex items-center gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-semibold transition',
                           'bg-white text-gray-900 shadow-sm ring-1 ring-gray-300' => $here,
                           'text-gray-600 hover:bg-gray-300 hover:text-gray-900' => ! $here,
                       ])
                       @if ($here) aria-current="page" @endif>
                        {{ $label }}
                        <span @class([
                            'rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums',
                            'bg-amber-500 text-white' => $key === 'pending' && $count > 0,
                            'bg-gray-100 text-gray-600' => $key !== 'pending' || $count === 0,
                        ])>{{ number_format($count) }}</span>
                    </a>
                @endforeach
            </nav>

            @can('configure', $business)
                <button type="button" x-data x-on:click="$dispatch('add-company')"
                        class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    Add a company
                </button>
            @endcan
        </div>

        <x-filter-bar submit="Search" class="mb-4" :active="$search !== '' || $status !== 'all' || $sort !== 'name' || $from || $to"
                      clear="{{ route('businesses.companies.index', array_filter([$business, 'owing' => $owing ? 1 : null])) }}">
            @if ($owing) <input type="hidden" name="owing" value="1"> @endif
            <div>
                <x-input-label for="q" value="Company" />
                <input id="q" name="q" type="search" value="{{ $search }}" placeholder="Name or code"
                       class="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
            </div>

            <div>
                <x-input-label for="status" value="Status" />
                <select id="status" name="status"
                        class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach (['all' => 'All', 'active' => 'Active only', 'inactive' => 'Inactive only'] as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="sort" value="Sort by" />
                <select id="sort" name="sort"
                        class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach (['name' => 'Name', 'owed' => 'Highest pending first', 'purchased' => 'Highest purchases first', 'stock' => 'Highest stock first'] as $value => $label)
                        <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            {{-- The days the purchases are counted over. --}}
            <div>
                <x-input-label for="f_from" value="From" />
                <input id="f_from" name="from" type="date" value="{{ $from }}" max="{{ $business->today()->toDateString() }}"
                       class="mt-1 rounded-md border-gray-300 text-sm shadow-sm">
            </div>
            <div>
                <x-input-label for="f_to" value="To" />
                <input id="f_to" name="to" type="date" value="{{ $to }}" max="{{ $business->today()->toDateString() }}"
                       class="mt-1 rounded-md border-gray-300 text-sm shadow-sm">
            </div>
            <x-slot name="count">{{ number_format($companies->total()) }} {{ Str::plural('company', $companies->total()) }} listed</x-slot>
        </x-filter-bar>

        {{-- The list in four figures, right above it. Purchased and paid
             follow the dates chosen; pending is what is owed as of now. --}}
        @php($period = $from || $to
            ? ($from ? \Illuminate\Support\Carbon::parse($from)->format('j M Y') : 'the start') . ' to ' . ($to ? \Illuminate\Support\Carbon::parse($to)->format('j M Y') : 'today')
            : 'all dates')
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5">
            <div class="rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-gray-400">Companies listed</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ number_format($companies->total()) }}</p>
                <p class="mt-0.5 text-xs text-gray-500">{{ number_format($pendingCount) }} with a pending balance</p>
            </div>

            <div class="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-sky-800">Total purchased</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-sky-900">{{ $listedBilled->format() }}</p>
                <p class="mt-0.5 text-xs text-sky-800">{{ number_format($listedInvoices) }} {{ Str::plural('invoice', $listedInvoices) }} · {{ $period }}</p>
            </div>

            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-emerald-700">Total paid amount</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-emerald-800">{{ $listedPaid->format() }}</p>
                <p class="mt-0.5 text-xs text-emerald-700">paid on these invoices · {{ $period }}</p>
            </div>

            {{-- Their goods on the shelf, at what they were bought at. --}}
            <div class="rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-gray-400">Stock in hand</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-gray-900">{{ $listedStock->format() }}</p>
                <p class="mt-0.5 text-xs text-gray-500">
                    at purchase price · {{ number_format($listedStockProducts) }} {{ Str::plural('product', $listedStockProducts) }}
                    from {{ number_format($listedWithStock) }} {{ Str::plural('company', $listedWithStock) }}
                </p>
            </div>

            <div class="rounded-md border px-4 py-3 shadow-sm {{ $shownOwed->isPositive() ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-white' }}">
                <p class="text-xs uppercase tracking-wide {{ $shownOwed->isPositive() ? 'text-amber-700' : 'text-gray-400' }}">Total pending amount</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums {{ $shownOwed->isPositive() ? 'text-amber-900' : 'text-gray-900' }}">{{ $shownOwed->format() }}</p>
                <p class="mt-0.5 text-xs">
                    <span class="{{ $shownOwed->isPositive() ? 'text-amber-700' : 'text-gray-500' }}">still to be paid, as of today</span>
                    @if ($from || $to)
                        <span class="block text-gray-600">{{ $listedBilled->minus($listedPaid)->format() }} pending on the invoices of these dates</span>
                    @endif
                    <span class="block font-medium {{ $reconciles ? 'text-emerald-700' : 'text-red-700' }}">{{ $reconciles ? 'Adds up across companies ✓' : 'Does not add up across companies' }}</span>
                    @unless ($unallocated->isZero())
                        <span class="block text-gray-500">{{ $unallocated->format() }} not linked to any company</span>
                    @endunless
                </p>
            </div>
        </div>

        <x-panel>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                @foreach ([
                                    ['Company','left'], ['Invoices','right'],
                                    ['Purchased','right'], ['Paid','right'], ['Pending on invoices','right'],
                                    ['Pending balance','right'], ['Stock value','right'], ['Last bill','left'], ['Price list','left'],
                                ] as [$h, $align])
                                    <th class="whitespace-nowrap px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first ? 'sm:px-6' : '' }}">{{ $h }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($companies as $company)
                                <tr>
                                    <td class="px-4 py-2 sm:px-6">
                                        <a href="{{ route('businesses.companies.show', [$business, $company]) }}"
                                           class="font-semibold text-emerald-700 underline decoration-emerald-300 underline-offset-2 hover:text-emerald-900">{{ $company->name }}</a>
                                        <span class="block font-mono text-xs text-gray-400">{{ $company->code }} · Account {{ $company->account?->code ?? '—' }}</span>
                                    </td>
                                    @php($bought = $purchases[$company->id])
                                    <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-500">
                                        {{ $bought['invoices'] === 0 ? '—' : number_format($bought['invoices']) }}
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900">{{ $bought['billed']->format() }}</td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-600">{{ $bought['paid']->format() }}</td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums {{ $bought['outstanding']->isZero() ? 'text-gray-400' : 'text-amber-700' }}">
                                        {{ $bought['outstanding']->format() }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right font-mono tabular-nums">
                                        @if ($balances[$company->id]->isPositive())
                                            <span class="font-semibold text-amber-700">{{ $balances[$company->id]->format() }}</span>
                                        @elseif ($balances[$company->id]->isNegative())
                                            <span class="text-emerald-800" title="Paid in advance">{{ $balances[$company->id]->absolute()->format() }} advance</span>
                                        @else
                                            <span class="font-sans text-xs font-medium text-gray-400">No pending balance</span>
                                        @endif
                                    </td>
                                    {{-- Their goods in hand, at purchase price. --}}
                                    <td class="whitespace-nowrap px-4 py-2 text-right font-mono tabular-nums">
                                        @if ($stock[$company->id]['products'] > 0)
                                            <a href="{{ route('businesses.stock.index', [$business, 'period' => 'all', 'company' => $company->id]) }}"
                                               class="text-gray-900 hover:text-emerald-700" title="{{ $stock[$company->id]['products'] }} {{ Str::plural('product', $stock[$company->id]['products']) }} in stock">{{ $stock[$company->id]['value']->format() }}</a>
                                            <span class="block font-sans text-xs text-gray-500">{{ number_format($stock[$company->id]['products']) }} {{ Str::plural('product', $stock[$company->id]['products']) }}</span>
                                        @else
                                            <span class="font-sans text-xs font-medium text-gray-400">No stock</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2 text-xs text-gray-500">{{ $bought['last'] ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-sm">
                                        @can('importProducts', $business)
                                            <a href="{{ route('businesses.companies.imports.create', [$business, $company]) }}"
                                               class="font-medium text-emerald-700 hover:text-emerald-800">Import</a>
                                            <span class="px-1 text-gray-300">·</span>
                                        @endcan
                                        <a href="{{ route('businesses.products.index', ['business' => $business, 'company' => $company->id]) }}"
                                           class="text-gray-500 hover:text-gray-700">Products</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-6 py-10 text-center text-gray-500">
                                        No companies yet. Until a company is added, payables are tracked as one
                                        total and a price list cannot be imported.
                                        <button type="button" x-data x-on:click="$dispatch('add-company')"
                                                class="font-medium text-emerald-700 hover:text-emerald-800">Add a company</button>.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($companies->isNotEmpty())
                            <tfoot class="bg-gray-50">
                                <tr>
                                    <td colspan="2" class="px-4 py-2 text-right text-sm font-semibold text-gray-900 sm:px-6">Total</td>
                                    <td class="px-4 py-2 text-right font-mono font-semibold tabular-nums text-gray-900">{{ $billedTotal->format() }}</td>
                                    <td class="px-4 py-2 text-right font-mono font-semibold tabular-nums text-gray-600">{{ $paidTotal->format() }}</td>
                                    <td class="px-4 py-2 text-right font-mono font-semibold tabular-nums text-amber-700">{{ $billedTotal->minus($paidTotal)->format() }}</td>
                                    <td class="px-4 py-2 text-right font-mono font-semibold tabular-nums text-gray-900">{{ $shownOwed->format() }}</td>
                                    <td class="px-4 py-2 text-right font-mono font-semibold tabular-nums text-gray-900">{{ $listedStock->format() }}</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>

                {{-- The two right-hand figures answer different questions and are
                     not expected to agree: one adds up the invoices, the other is
                     the company's own ledger. --}}
                <p class="border-t border-gray-200 px-4 py-2 text-xs text-gray-500 sm:px-6">
                    Purchased and Paid are from invoices on posted days. Pending balance is from the company's ledger,
                    which also includes its opening balance and any payment made without an invoice. These two
                    figures may be different.
                </p>
        </x-panel>

        <div class="mt-4">{{ $companies->links() }}</div>
    </div>

</x-workspace-layout>
