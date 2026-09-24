<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Stock</h1>
                <p class="mt-1 text-sm text-gray-500">
                    What has come in, and the one independent check on the balance that is an estimate.
                </p>
            </div>

            <div class="flex flex-wrap items-stretch gap-3">
                <div class="rounded-md bg-gray-100 px-3 py-2">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Book value</p>
                    <p class="mt-0.5 font-mono text-sm font-semibold tabular-nums text-gray-900">{{ $bookValue->format() }}</p>
                </div>
                <div class="rounded-md bg-gray-100 px-3 py-2">
                    <p class="text-xs uppercase tracking-wide text-gray-500">In stock</p>
                    <p class="mt-0.5 font-mono text-sm font-semibold tabular-nums text-gray-900">
                        {{ number_format($packsHeld) }} <span class="text-xs font-normal text-gray-500">{{ $business->unit()->many() }}</span>
                    </p>
                </div>
                <div class="rounded-md bg-gray-100 px-3 py-2">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Received · {{ strtolower($period['label']) }}</p>
                    <p class="mt-0.5 font-mono text-sm font-semibold tabular-nums text-gray-900">{{ $receivedValue->format() }}</p>
                </div>

                {{-- Goods arrive both ways: checked off against an order form, or
                     simply delivered because someone rang the company. --}}
                @can('manageOrders', $business)
                    <a href="{{ route('businesses.stock.receive', $business) }}"
                       class="flex items-center rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                        Add stock
                    </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="w-full px-4 py-4 sm:px-6 lg:px-8">
        <x-flash />

        {{-- Goods coming in, two ways: the deliveries themselves, and what they
             added up to per product. Both are "received" — see the note. --}}        <x-panel class="flex min-h-0 flex-col lg:h-[calc(100vh-11.5rem)]" x-data="{ view: 'onhand' }">
            <div class="flex shrink-0 flex-wrap items-center gap-3 border-b border-gray-200 px-4 py-3 sm:px-6">
                <h2 class="text-base font-semibold text-gray-900">Stock</h2>

                <div class="flex gap-1">
                    <button type="button" x-on:click="view = 'onhand'"
                            :class="view === 'onhand' ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                            class="rounded-full px-3 py-1 text-xs font-medium">
                        In stock ({{ number_format($productsHeld) }})
                    </button>
                    <button type="button" x-on:click="view = 'deliveries'"
                            :class="view === 'deliveries' ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                            class="rounded-full px-3 py-1 text-xs font-medium">
                        Deliveries ({{ $deliveries->count() }})
                    </button>
                    <button type="button" x-on:click="view = 'counts'"
                            :class="view === 'counts' ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                            class="rounded-full px-3 py-1 text-xs font-medium">
                        Counts ({{ $verifications->total() }})
                    </button>
                </div>

                <div class="ml-auto flex gap-1">
                    @foreach (['month' => 'This month', 'quarter' => '3 months', 'all' => 'All'] as $key => $label)
                        <a href="{{ route('businesses.stock.index', ['business' => $business, 'period' => $key]) }}"
                           class="rounded-full px-3 py-1 text-xs font-medium {{ $periodKey === $key ? 'bg-emerald-700 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- The deliveries --}}
            <div x-show="view === 'deliveries'" x-cloak class="min-h-0 flex-1 overflow-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="sticky top-0 z-10 bg-gray-50">
                        <tr>
                            @foreach ([['Received','left'],['Reference','left'],['Supplier','left'],['Items','right'],['Value','right'],['Invoice','left'],['Reached the books','left']] as [$h,$align])
                                <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first ? 'sm:px-6' : '' }}">{{ $h }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($deliveries as $delivery)
                            <tr>
                                <td class="whitespace-nowrap px-3 py-2 text-gray-900 sm:px-6">
                                    {{ $delivery->received_at?->format('j M Y') ?? '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    <a href="{{ route('businesses.orders.show', [$business, $delivery]) }}"
                                       class="font-mono font-medium text-gray-900 hover:text-emerald-700">{{ $delivery->reference }}</a>
                                </td>
                                <td class="px-3 py-2 text-gray-900">{{ $delivery->company->name }}</td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-600">{{ $delivery->receiptLines->count() }}</td>
                                <td class="px-3 py-2 text-right font-mono font-semibold tabular-nums text-gray-900">{{ $delivery->receivedTotal()->format() }}</td>
                                <td class="px-3 py-2 font-mono text-xs text-gray-500">{{ $delivery->purchaseLine?->invoice_no ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-xs">
                                    @php($entry = $delivery->purchaseLine?->dailyEntry)
                                    @if ($entry === null)
                                        {{-- Goods with no bill behind them never touch the ledger, so the
                                             book value above does not know about this one. --}}
                                        <span class="text-gray-400">no invoice yet</span>
                                    @elseif ($entry->isEditable())
                                        <a href="{{ route('businesses.daily.show', [$business, $entry]) }}"
                                           class="text-amber-700 hover:text-amber-800">
                                            not yet — {{ $entry->business_date->format('j M') }} is a draft
                                        </a>
                                    @else
                                        <a href="{{ route('businesses.daily.show', [$business, $entry]) }}"
                                           class="text-emerald-700 hover:text-emerald-800">
                                            yes — posted {{ $entry->business_date->format('j M') }}
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-10 text-center text-gray-500">
                                    Nothing received {{ strtolower($period['label']) }}.
                                    Deliveries appear here once an order form is received.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- What is held, product by product — a page at a time, because a
                 whole catalogue in one table is a slow page and an unreadable one. --}}
            <div x-show="view === 'onhand'" class="flex min-h-0 flex-1 flex-col">
                <form method="GET" class="flex shrink-0 flex-wrap items-center gap-2 border-b border-gray-200 px-4 py-2 sm:px-6">
                    <input type="hidden" name="period" value="{{ $periodKey }}">
                    <label for="q" class="sr-only">Search products</label>
                    <input id="q" name="q" type="search" value="{{ $search }}" placeholder="Search a product"
                           class="w-64 rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    <button class="rounded-md bg-gray-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-800">Search</button>
                    @if ($search !== '')
                        <a href="{{ route('businesses.stock.index', [$business, 'period' => $periodKey]) }}"
                           class="text-sm text-gray-500 hover:text-gray-800">Clear</a>
                    @endif
                    <span class="ml-auto text-xs text-gray-500">
                        Showing {{ $onHand->firstItem() ?? 0 }}–{{ $onHand->lastItem() ?? 0 }} of {{ number_format($onHand->total()) }}
                    </span>
                </form>

                <div class="min-h-0 flex-1 overflow-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="sticky top-0 z-10 bg-gray-50">
                            <tr>
                                @foreach ([['Product','left'],['Company','left'],['Pack','left'],['Received ' . strtolower($period['label']),'right'],['In stock','right'],['Value at cost','right']] as [$h,$align])
                                    <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first ? 'sm:px-6' : '' }}">{{ $h }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($onHand as $row)
                                <tr>
                                    <td class="px-3 py-2 sm:px-6">
                                        <a href="{{ route('businesses.products.show', [$business, $row['product']]) }}"
                                           class="font-medium text-gray-900 hover:text-emerald-700">{{ $row['product']->label() }}</a>
                                        @if ($row['product']->generic_name)
                                            <div class="text-xs text-gray-500">{{ $row['product']->generic_name }}</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-gray-600">{{ $row['product']->company?->name ?? '—' }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ $row['product']->pack_size ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums {{ $row['received'] > 0 ? 'text-emerald-700' : 'text-gray-400' }}">
                                        {{ $row['received'] > 0 ? '+' . number_format($row['received']) : '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono text-base font-semibold tabular-nums {{ $row['packs'] < 0 ? 'text-red-700' : 'text-gray-900' }}">
                                        {{ number_format($row['packs']) }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-600">
                                        {{ $row['value']?->format() ?? '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-10 text-center text-gray-500">
                                        Nothing in stock yet. Quantities build up as deliveries are recorded.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($productsHeld > 0)
                            <tfoot class="sticky bottom-0 bg-gray-50 shadow-[0_-1px_0_0_rgb(229,231,235)]">
                                <tr>
                                    <td colspan="4" class="px-3 py-2 text-right text-sm font-semibold text-gray-900 sm:px-6">
                                        {{ number_format($productsHeld) }} products
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono text-base font-semibold tabular-nums text-gray-900">
                                        {{ number_format($packsHeld) }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono font-semibold tabular-nums text-gray-900">
                                        {{ $heldValue->format() }}
                                    </td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>

                @if ($onHand->hasPages())
                    <div class="shrink-0 border-t border-gray-200 px-4 py-2 sm:px-6">{{ $onHand->links() }}</div>
                @endif

                <p class="shrink-0 border-t border-gray-200 px-4 py-2 text-xs text-gray-500 sm:px-6">
                    Quantities are the sum of every movement: deliveries add, adjustments correct. Selling is not
                    yet recorded per product, so until the point of sale is built these do not come down on their
                    own — record breakage, expiry and opening stock as an adjustment on the product.
                    <span class="text-gray-400">Value at cost is each product's current rate, for comparing against
                    the book value of {{ $bookValue->format() }} — the ledger holds the figure that counts.</span>
                </p>
            </div>

            {{-- Counting, and what past counts found. Kept because the book
                 value is derived and only a count can correct it: expiry,
                 breakage and theft reach the figures nowhere else. --}}
            <div x-show="view === 'counts'" x-cloak class="grid min-h-0 flex-1 xl:grid-cols-3">
                <div class="flex min-h-0 flex-col xl:col-span-2">
                        <div class="min-h-0 flex-1 overflow-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="sticky top-0 z-10 bg-gray-50">
                                    <tr>
                                        @foreach (['Date', 'Book value', 'Counted', 'Variance', 'Drift', 'Reason', 'Verified by'] as $h)
                                            <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ in_array($h, ['Date','Reason','Verified by']) ? 'text-left' : 'text-right' }}">{{ $h }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @forelse ($verifications as $v)
                                        <tr>
                                            <td class="whitespace-nowrap px-3 py-2 text-gray-900">{{ $v->business_date->format('d M Y') }}</td>
                                            <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-600">{{ $v->book_value->format() }}</td>
                                            <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-900">{{ $v->counted_value->format() }}</td>
                                            <td class="px-3 py-2 text-right font-mono tabular-nums {{ $v->variance->isNegative() ? 'text-red-700' : 'text-emerald-700' }}">{{ $v->variance->format() }}</td>
                                            <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-600">{{ number_format($v->variance_pct, 2) }}%</td>
                                            <td class="px-3 py-2 text-gray-600">{{ $v->reason ?? '—' }}</td>
                                            <td class="whitespace-nowrap px-3 py-2 text-gray-500">{{ $v->verifier->name }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="7" class="px-6 py-10 text-center text-gray-500">Stock has never been counted.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                </div>

                <div class="min-h-0 overflow-auto border-t border-gray-200 xl:border-l xl:border-t-0">
                    <form method="POST" action="{{ route('businesses.stock.store', $business) }}" class="space-y-4 p-4 sm:p-6">
                        @csrf
                        <div class="rounded-md {{ $confidence['stale'] ? 'bg-amber-50 text-amber-900' : 'bg-gray-50 text-gray-700' }} px-3 py-2 text-sm">
                            {{ $confidence['detail'] }}
                        </div>

                        <dl class="flex justify-between text-sm">
                            <dt class="text-gray-600">Book value now</dt>
                            <dd class="font-mono tabular-nums text-gray-900">{{ $bookValue->format() }}</dd>
                        </dl>

                        <div>
                            <x-input-label for="business_date" value="Count date" />
                            <x-text-input id="business_date" name="business_date" type="date" class="mt-1 block w-full"
                                          :value="old('business_date', $business->today()->toDateString())" required />
                            <x-input-error :messages="$errors->get('business_date')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="counted_value" value="Counted value (at cost)" />
                            <x-text-input id="counted_value" name="counted_value" type="text" inputmode="decimal"
                                          class="mt-1 block w-full text-right font-mono tabular-nums"
                                          placeholder="0.00" required />
                            <x-input-error :messages="$errors->get('counted_value')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="reason" value="Notes" />
                            <x-text-input id="reason" name="reason" type="text" class="mt-1 block w-full"
                                          placeholder="Expiry write-off and breakage found in Godown 2" />
                        </div>

                        <button class="w-full rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                            Record count
                        </button>
                        <p class="text-xs text-gray-500">
                            The difference posts to profit and loss as a real cost. Expiry, breakage and theft are
                            invisible to the derived cost model — this is where they surface.
                        </p>
                    </form>
                </div>
            </div>
        </x-panel>
    </div>
</x-workspace-layout>
