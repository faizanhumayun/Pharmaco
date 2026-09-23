<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Companies</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Who you buy from. Each one keeps its own ledger of what is owed to it.
                </p>
            </div>
            @can('configure', $business)
                <button type="button" x-data x-on:click="$dispatch('open-drawer', 'add-company')"
                        class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    Add a company
                </button>
            @endcan
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        {{-- Summary and filters on one line. --}}
        <div class="mb-6 flex flex-wrap items-center justify-between gap-x-6 gap-y-3 rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm sm:px-6">
            <p class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-base font-semibold text-gray-900">{{ $companies->count() }} {{ Str::plural('company', $companies->count()) }}</span>
                <span class="text-gray-500">· owed</span>
                <span class="font-semibold tabular-nums text-gray-900">Rs. {{ $shownOwed->format() }}</span>
                @if ($shownOwed->toDecimal() !== $total->toDecimal())
                    <span class="text-gray-500">of Rs. {{ $total->format() }} in total</span>
                @endif
                <x-badge :classes="$reconciles ? 'bg-emerald-50 text-emerald-800 ring-emerald-600/20' : 'bg-red-50 text-red-800 ring-red-600/20'">
                    {{ $reconciles ? 'Adds up across companies ✓' : 'Does not add up across companies' }}
                </x-badge>
                @unless ($unallocated->isZero())
                    <span class="text-gray-500">· <span class="tabular-nums">{{ $unallocated->format() }}</span> not under any company</span>
                @endunless
            </p>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label for="q" class="sr-only">Search</label>
                <input id="q" name="q" type="search" value="{{ $search }}" placeholder="Search name or code"
                       class="w-48 rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">

                <label for="status" class="sr-only">Status</label>
                <select id="status" name="status"
                        class="rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach (['all' => 'Active and not', 'active' => 'Active only', 'inactive' => 'Inactive only'] as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>

                <label for="sort" class="sr-only">Order</label>
                <select id="sort" name="sort"
                        class="rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach (['name' => 'By name', 'owed' => 'Most owed first', 'purchased' => 'Most bought first'] as $value => $label)
                        <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                    @endforeach
                </select>

                <label class="flex items-center gap-1.5 text-sm text-gray-600">
                    <input type="checkbox" name="owing" value="1" @checked($owing)
                           class="rounded border-gray-300 text-emerald-700 focus:ring-emerald-600">
                    Owing only
                </label>

                <button class="rounded-md bg-gray-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-800">Apply</button>
                @if ($search !== '' || $status !== 'all' || $owing || $sort !== 'name')
                    <a href="{{ route('businesses.companies.index', $business) }}" class="text-sm text-gray-500 hover:text-gray-800">Clear</a>
                @endif
            </form>
        </div>

        <x-panel title="Company ledgers">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                @foreach ([
                                    ['Company','left'], ['Account','left'], ['Invoices','right'],
                                    ['Purchased','right'], ['Paid','right'], ['Unpaid on invoices','right'],
                                    ['Balance owed','right'], ['Last bill','left'], ['Price list','left'],
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
                                           class="font-medium text-gray-900 hover:text-emerald-700">{{ $company->name }}</a>
                                    </td>
                                    <td class="px-4 py-2 font-mono text-xs text-gray-500">{{ $company->account?->code ?? '—' }}</td>
                                    @php($bought = $purchases[$company->id])
                                    <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-500">
                                        {{ $bought['invoices'] === 0 ? '—' : number_format($bought['invoices']) }}
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900">{{ $bought['billed']->format() }}</td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-600">{{ $bought['paid']->format() }}</td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums {{ $bought['outstanding']->isZero() ? 'text-gray-400' : 'text-amber-700' }}">
                                        {{ $bought['outstanding']->format() }}
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono font-semibold tabular-nums text-gray-900">{{ $balances[$company->id]->format() }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-xs text-gray-500">{{ $bought['last'] ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-sm">
                                        @can('importProducts', $business)
                                            <a href="{{ route('businesses.companies.imports.create', [$business, $company]) }}"
                                               class="font-medium text-emerald-700 hover:text-emerald-800">Import</a>
                                            <span class="px-1 text-gray-300">·</span>
                                        @endcan
                                        <a href="{{ route('businesses.products.index', ['business' => $business, 'company' => $company->id]) }}"
                                           class="text-gray-500 hover:text-gray-700">Catalogue</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-6 py-10 text-center text-gray-500">
                                        No companies yet. Until one exists, payables are tracked as a single
                                        total — and there is nowhere to import a price list to, since a price
                                        list belongs to a company.
                                        <button type="button" x-data x-on:click="$dispatch('open-drawer', 'add-company')"
                                                class="font-medium text-emerald-700 hover:text-emerald-800">Add one now</button>.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($companies->isNotEmpty())
                            <tfoot class="bg-gray-50">
                                <tr>
                                    <td colspan="3" class="px-4 py-2 text-right text-sm font-semibold text-gray-900 sm:px-6">Total</td>
                                    <td class="px-4 py-2 text-right font-mono font-semibold tabular-nums text-gray-900">{{ $billedTotal->format() }}</td>
                                    <td class="px-4 py-2 text-right font-mono font-semibold tabular-nums text-gray-600">{{ $paidTotal->format() }}</td>
                                    <td class="px-4 py-2 text-right font-mono font-semibold tabular-nums text-amber-700">{{ $billedTotal->minus($paidTotal)->format() }}</td>
                                    <td class="px-4 py-2 text-right font-mono font-semibold tabular-nums text-gray-900">{{ $total->format() }}</td>
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
                    Purchased and paid are the invoices on posted days. Balance owed is the company's ledger, which
                    also carries its opening balance and any payment made without an invoice against it — so the two
                    need not match.
                </p>
        </x-panel>
    </div>

    @can('configure', $business)
        <x-add-company-drawer :business="$business" />
    @endcan
</x-workspace-layout>
