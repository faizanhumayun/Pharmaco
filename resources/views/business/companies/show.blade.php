<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">{{ $company->name }}</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Account {{ $company->account?->code }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('businesses.products.index', ['business' => $business, 'company' => $company->id]) }}"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Catalogue ({{ number_format($productCount) }})
                </a>
                <a href="{{ route('businesses.companies.imports.index', [$business, $company]) }}"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Price lists
                </a>
                @can('importProducts', $business)
                    <a href="{{ route('businesses.companies.imports.create', [$business, $company]) }}"
                       class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                        Import price list
                    </a>
                @endcan
                <a href="{{ route('businesses.companies.index', $business) }}"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    All companies
                </a>
            </div>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        @if ($draftImport)
            <div class="mb-6 flex flex-wrap items-center gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <span>
                    <span class="font-semibold">{{ $draftImport->original_filename }}</span>
                    was read {{ $draftImport->created_at->diffForHumans() }} and is still waiting to be reviewed.
                    Nothing from it has reached the catalogue.
                </span>
                <a href="{{ route('businesses.companies.imports.show', [$business, $company, $draftImport]) }}"
                   class="font-semibold underline">Review it</a>
            </div>
        @endif

        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <x-stat label="Invoices" :value="number_format($purchases['invoices'])"
                    :hint="$purchases['last'] ? 'last ' . $purchases['last'] : 'none yet'" />
            <x-stat label="Purchased" :value="$purchases['billed']->format()" hint="On posted days" />
            <x-stat label="Paid" :value="$purchases['paid']->format()" />
            <x-stat label="Unpaid on invoices" :value="$purchases['outstanding']->format()" />
            <x-stat label="Balance owed" :value="$balance->format()" hint="From this company's ledger" />
        </div>

        {{-- Their goods, not just their bills. A supplier is two things: what
             you owe them, and what of theirs is sitting in the godown. --}}
        <div class="mb-6 grid gap-6 xl:grid-cols-3">
            <div class="xl:col-span-2">
                <x-panel title="Their goods in stock">
                    <div class="flex flex-wrap gap-x-8 gap-y-2 border-b border-gray-100 px-4 py-3 sm:px-6">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-400">Held</p>
                            <p class="font-mono text-lg font-semibold tabular-nums text-gray-900">
                                {{ number_format($stock['packs']) }}
                                <span class="text-xs font-normal text-gray-500">{{ $business->unit()->many() }}</span>
                            </p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-400">Worth at cost</p>
                            <p class="font-mono text-lg font-semibold tabular-nums text-gray-900">{{ $stock['value']->format() }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-400">Products</p>
                            <p class="font-mono text-lg font-semibold tabular-nums text-gray-900">{{ number_format($stock['products']) }}</p>
                            <p class="text-xs text-gray-500">{{ $stock['out'] }} out of stock</p>
                        </div>
                        @if ($stock['below'] > 0)
                            <div>
                                <p class="text-xs uppercase tracking-wide text-red-600">Sold past the count</p>
                                <p class="font-mono text-lg font-semibold tabular-nums text-red-700">{{ $stock['below'] }}</p>
                                <a href="{{ route('businesses.products.index', [$business, 'short' => 1]) }}"
                                   class="text-xs font-medium text-red-700 underline">Put right</a>
                            </div>
                        @endif
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    @foreach (['Product', Str::ucfirst($business->unit()->many()), 'At cost', 'Worth'] as $h)
                                        <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ $loop->first ? 'text-left sm:px-6' : 'text-right' }} {{ $loop->last ? 'sm:px-6' : '' }}">{{ $h }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($stock['rows'] as $row)
                                    <tr>
                                        <td class="px-4 py-2 text-gray-900 sm:px-6">{{ $row['product']->label() }}</td>
                                        <td class="px-4 py-2 text-right font-mono tabular-nums {{ $row['packs'] < 0 ? 'font-semibold text-red-700' : ($row['packs'] === 0 ? 'text-gray-400' : 'text-gray-900') }}">
                                            {{ number_format($row['packs']) }}
                                        </td>
                                        <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-600">
                                            {{ ($row['product']->purchase_rate ?? $row['product']->trade_price)?->format() ?? '—' }}
                                        </td>
                                        <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900 sm:px-6">{{ $row['value']->format() }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500">
                                            No products in the catalogue for this company yet.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($stock['products'] > $stock['rows']->count())
                        <div class="border-t border-gray-100 px-4 py-3 text-sm sm:px-6">
                            <a href="{{ route('businesses.products.index', ['business' => $business, 'company' => $company->id]) }}"
                               class="font-medium text-emerald-700 hover:text-emerald-800">
                                All {{ number_format($stock['products']) }} of their products →
                            </a>
                        </div>
                    @endif
                </x-panel>
            </div>

            <div class="xl:col-span-1">
                <x-panel title="What is owed against it">
                    <dl class="divide-y divide-gray-100 text-sm">
                        @foreach ([
                            'Stock of theirs held' => $stock['value'],
                            'Owed to them' => $balance,
                        ] as $label => $figure)
                            <div class="flex items-baseline justify-between px-4 py-3 sm:px-6">
                                <dt class="text-gray-600">{{ $label }}</dt>
                                <dd class="font-mono tabular-nums text-gray-900">{{ $figure->format() }}</dd>
                            </div>
                        @endforeach
                        {{-- Not a rule, a reading: stock worth less than the bill for
                             it usually means the goods have been sold and the money
                             has not gone back yet. --}}
                        <div class="flex items-baseline justify-between bg-gray-50 px-4 py-3 sm:px-6">
                            <dt class="font-medium text-gray-700">Difference</dt>
                            <dd class="font-mono font-semibold tabular-nums {{ $stock['value']->minus($balance)->isNegative() ? 'text-amber-800' : 'text-gray-900' }}">
                                {{ $stock['value']->minus($balance)->format() }}
                            </dd>
                        </div>
                    </dl>
                    <p class="px-4 py-3 text-xs leading-relaxed text-gray-500 sm:px-6">
                        Stock is valued at what it cost. A negative difference means you owe
                        more than you are still holding of theirs — the goods have moved on.
                    </p>
                </x-panel>
            </div>
        </div>

        <x-panel :title="'Statement — balance owed ' . $balance->format()">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach (['Date', 'Description', 'Debit', 'Credit', 'Balance'] as $h)
                                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ in_array($h, ['Date','Description']) ? 'text-left' : 'text-right' }} {{ $loop->first || $loop->last ? 'sm:px-6' : '' }}">{{ $h }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            @php($entry = $row['entry'])
                            <tr>
                                <td class="whitespace-nowrap px-4 py-2 text-gray-600 sm:px-6">{{ $entry->business_date->format('d M Y') }}</td>
                                <td class="px-4 py-2">
                                    <span class="font-medium text-gray-900">{{ $entry->transaction->type->label() }}</span>
                                    <p class="text-xs text-gray-500">
                                        #{{ $entry->transaction->id }}
                                        @if ($entry->transaction->narration) · {{ $entry->transaction->narration }} @endif
                                        · {{ $entry->transaction->creator->name }}
                                    </p>
                                </td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums">{{ $entry->debit->isZero() ? '' : $entry->debit->format() }}</td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums">{{ $entry->credit->isZero() ? '' : $entry->credit->format() }}</td>
                                <td class="px-4 py-2 text-right font-mono font-semibold tabular-nums sm:px-6">{{ $row['running']->format() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-10 text-center text-gray-500">No activity on this company yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-panel>
    </div>
</x-workspace-layout>
