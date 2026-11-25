<x-workspace-layout :business="$business">
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">Products</h1>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="mt-1 text-sm text-gray-500">
                Products of each company and their prices, as imported from the company price lists.
            </p>
            @can('importProducts', $business)
                <a href="{{ route('businesses.imports.create', $business) }}"
                   class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    Import a price list
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        {{-- Sold past the count. The counter is allowed to do it — the medicine
             was on the shelf even if the books said otherwise — so this is where
             it surfaces afterwards, rather than being lost in the day. --}}
        @if ($belowZero->isNotEmpty() && ! $filters['short'])
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-300 bg-amber-50 px-4 py-3">
                <p class="text-sm text-amber-900">
                    <span class="font-semibold">{{ $belowZero->count() }} {{ Str::plural('product', $belowZero->count()) }}</span>
                    {{ $belowZero->count() === 1 ? 'has' : 'have' }} negative stock because more was sold than
                    the stock in the records. Enter the missing purchase, or correct the stock.
                </p>
                <a href="{{ route('businesses.products.index', [$business, 'short' => 1]) }}"
                   class="rounded-md bg-amber-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-amber-700">
                    Show products
                </a>
            </div>
        @endif

        @if ($filters['short'])
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-300 bg-amber-50 px-4 py-3">
                <p class="text-sm text-amber-900">
                    Showing only products with negative stock.
                </p>
                <a href="{{ route('businesses.products.index', $business) }}"
                   class="text-sm font-medium text-amber-900 underline hover:text-amber-950">Show all products</a>
            </div>
        @endif

        <x-filter-bar submit="Filter" :active="array_filter($filters)" clear="{{ route('businesses.products.index', $business) }}" class="mb-6">
            @if ($filters['short'])
                <input type="hidden" name="short" value="1">
            @endif
            <div class="min-w-[16rem] flex-1">
                <x-input-label for="q" value="Search" />
                <x-text-input id="q" name="q" type="search" :value="$filters['q']"
                              placeholder="Brand, generic, code, strength or pack" class="mt-1 block w-full" />
            </div>

            <div>
                <x-input-label for="company" value="Company" />
                <select id="company" name="company"
                        class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    <option value="">All companies</option>
                    @foreach ($companies as $option)
                        <option value="{{ $option->id }}" @selected((string) $filters['company'] === (string) $option->id)>
                            {{ $option->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            @if ($forms->isNotEmpty())
                <div>
                    <x-input-label for="form" value="Form" />
                    <select id="form" name="form"
                            class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                        <option value="">All forms</option>
                        @foreach ($forms as $option)
                            <option value="{{ $option }}" @selected($filters['form'] === $option)>{{ ucfirst($option) }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <label class="flex items-center gap-2 pb-2 text-sm text-gray-600">
                <input type="checkbox" name="inactive" value="1" @checked($filters['inactive'])
                       class="rounded border-gray-300 text-emerald-700 focus:ring-emerald-600">
                Include withdrawn
            </label>

            <x-slot name="count">{{ number_format($products->total()) }} of {{ number_format($total) }} products</x-slot>
        </x-filter-bar>

        <x-panel>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach ([
                                ['Product', 'left'], ['Generic', 'left'], ['Pack', 'left'], ['Company', 'left'],
                                ['MRP', 'right'], ...($business->sellsAtMrp() ? [] : [['Trade price', 'right']]), ['Purchase rate', 'right'],
                                ['Price | Margin', 'right'], ['Carton', 'right'], ['Stock', 'left'], ['Price date', 'right'],
                            ] as [$heading, $align])
                                <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ $align === 'right' ? 'text-right' : 'text-left' }} {{ $loop->first ? 'sm:px-6' : '' }}">
                                    {{-- Two figures in one column: each half has a fixed
                                         width, so the bar lines up down the page. --}}
                                    @if ($heading === 'Price | Margin')
                                        <span class="inline-block w-24 text-right">Price</span><span class="px-1 text-gray-300">|</span><span class="inline-block w-14 text-left">Margin</span>
                                    @else
                                        {{ $heading }}
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($products as $product)
                            <tr class="{{ $product->is_active ? '' : 'opacity-60' }}">
                                <td class="px-3 py-2 sm:px-6">
                                    <a href="{{ route('businesses.products.show', [$business, $product]) }}"
                                       class="font-medium text-gray-900 hover:text-emerald-700">{{ $product->brand_name }}</a>
                                    @if ($product->strength || $product->dosage_form)
                                        <div class="text-xs text-gray-500">
                                            {{ trim(($product->strength ?? '') . ' ' . ($product->dosageForm()?->label() ?? $product->dosage_form ?? '')) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-gray-600">{{ $product->generic_name ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">
                                    {{ $product->pack_size ?? '—' }}
                                    @if ($product->pack_type)
                                        <span class="text-xs text-gray-400">{{ $product->packType()?->label() ?? $product->pack_type }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-gray-600">{{ $product->company?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-900">{{ $product->mrp?->format() ?? '—' }}</td>
                                @unless ($business->sellsAtMrp())
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-900">{{ $product->trade_price?->format() ?? '—' }}</td>
                                @endunless
                                <td class="px-3 py-2 text-right font-mono tabular-nums font-semibold text-gray-900">{{ $product->purchase_rate?->format() ?? '—' }}</td>
                                {{-- What it sells at, then what is kept of that. --}}
                                <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums">
                                    @php($sells = $product->sellingPriceFor($business))
                                    @php($keeps = $product->marginPerPack($business))
                                    <span class="inline-block w-24 text-right font-semibold text-emerald-700">{{ $sells?->format() ?? '—' }}</span><span class="px-1 text-gray-300">|</span><span class="inline-block w-14 text-left">
                                        @if ($keeps)
                                            <span class="text-gray-700">{{ $product->marginPercent($business) }}%</span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-600">{{ $product->case_size ?? '—' }}</td>
                                @php($held = (int) ($onHand[$product->id] ?? 0))
                                @php($looseHeld = (int) ($looseOnHand[$product->id] ?? 0))
                                {{-- Whole packs as one chip, an opened pack's pieces as a second. --}}
                                <td class="whitespace-nowrap px-3 py-2 text-left"
                                    @if ($held < 0) title="Negative stock. Enter the missing purchase, or correct the stock." @endif>
                                    @if ($held === 0 && $looseHeld === 0)
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-500">None</span>
                                    @else
                                        <span class="inline-flex items-center gap-1">
                                            @if ($held !== 0)
                                                <span class="inline-flex items-baseline gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {{ $held < 0 ? 'bg-red-50 text-red-700 ring-red-600/20' : 'bg-emerald-50 text-emerald-800 ring-emerald-600/20' }}">
                                                    <span class="font-mono text-sm font-semibold tabular-nums">{{ number_format($held) }}</span>
                                                    {{ abs($held) === 1 ? 'pack' : 'packs' }}
                                                </span>
                                            @endif
                                            @if ($looseHeld !== 0)
                                                <span class="inline-flex items-baseline gap-1 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-500/20">
                                                    <span class="font-mono text-sm font-semibold tabular-nums">{{ number_format($looseHeld) }}</span>
                                                    {{ Str::after($product->stockLabel(0, $looseHeld), ' ') }}
                                                </span>
                                            @endif
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right text-xs text-gray-500">{{ $product->priced_on?->format('j M y') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="px-6 py-10 text-center text-gray-500">
                                    @if (array_filter($filters))
                                        No products found.
                                    @elseif ($companies->isEmpty())
                                        No companies yet. Please
                                        <a href="{{ route('businesses.companies.index', $business) }}"
                                           class="font-medium text-emerald-700 hover:text-emerald-800">add a company</a>
                                        before importing a price list.
                                    @else
                                        No products yet. Open a company and import its price list.
                                        <a href="{{ route('businesses.companies.index', $business) }}"
                                           class="font-medium text-emerald-700 hover:text-emerald-800">Select a company</a>.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($products->hasPages())
                <div class="border-t border-gray-200 px-4 py-3 sm:px-6">{{ $products->links() }}</div>
            @endif
        </x-panel>
    </div>
</x-workspace-layout>
