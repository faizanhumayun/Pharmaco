{{--
    One product, on one screen.

    The page does not scroll: it is the height of the window, and each panel
    scrolls inside itself. So the figures, the supplier and the two ledgers are
    always in view together, however long any one list becomes. (On a narrow
    screen there is no room for that, and the page scrolls as usual.)
--}}
<x-workspace-layout :business="$business" :fill="true">
    <x-slot name="header">
        {{-- One line: the name, who makes it, any flags, and Edit. The
             negative margin takes back half the header's padding, so the
             figures below start higher up the page. --}}
        <x-back-link :href="route('businesses.products.index', $business)" :label="'All products'" />
        <div class="-my-2 flex flex-wrap items-center gap-x-3 gap-y-1">
            {{-- Its code, then its name — nothing else: a product may come from
                 more than one supplier, and its pack, generic and stock are in
                 the cards and tabs below. --}}
            <x-badge classes="bg-gray-100 text-gray-800 ring-gray-500/20 font-mono font-semibold" title="Product code">{{ $product->code }}</x-badge>
            <h1 class="text-xl font-semibold text-gray-900">{{ $product->brand_name }}</h1>
            @unless ($product->is_active)
                <x-badge classes="bg-gray-100 text-gray-600 ring-gray-500/20">Withdrawn</x-badge>
            @endunless
            @if ($product->reorder_level > 0 && $onHand <= $product->reorder_level)
                <x-badge classes="bg-amber-50 text-amber-800 ring-amber-600/20">At reorder level</x-badge>
            @endif
            @can('editProducts', $business)
                <a href="{{ route('businesses.products.edit', [$business, $product]) }}"
                   class="ml-auto rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Edit product</a>
            @endcan
        </div>
    </x-slot>

    <div class="h-full w-full overflow-auto px-4 py-4 sm:px-6 lg:px-8 xl:flex xl:flex-col xl:overflow-hidden">
        <x-flash />

        {{-- Two columns, each filling the height that is left. --}}
        <div class="space-y-4 xl:grid xl:min-h-0 xl:flex-1 xl:grid-cols-3 xl:grid-rows-[minmax(0,1fr)] xl:gap-4 xl:space-y-0">
            <div class="space-y-4 xl:col-span-2 xl:flex xl:min-h-0 xl:flex-col xl:gap-4 xl:space-y-0">
                {{-- Which batches the stock on the shelf is made of. Worked out,
                     not stored: what came in, oldest expiry first, less what
                     has gone out. Only batches that arrived with a date. --}}
                @if ($batches->isNotEmpty())
                    <x-panel title="Batches in stock" class="xl:shrink-0"
                             description="Stock in hand, by batch. The oldest expiry is sold first.">
                        <div class="max-h-48 overflow-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="sticky top-0 bg-gray-50">
                                    <tr>
                                        @foreach ([['Batch','left'],['Expires','left'],['In stock','right'],['At cost','right'],['','right']] as [$h,$align])
                                            <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first ? 'sm:px-6' : '' }} {{ $loop->last ? 'sm:px-6' : '' }}">{{ $h }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($batches as $batch)
                                        <tr @class(['bg-red-50/60' => $batch['state'] === 'expired', 'bg-amber-50/50' => $batch['state'] === 'expiring'])>
                                            <td class="whitespace-nowrap px-3 py-2 font-mono text-gray-900 sm:px-6">{{ $batch['batch_no'] ?: '—' }}</td>
                                            <td class="whitespace-nowrap px-3 py-2 text-gray-900">{{ $batch['expires_on']->format('M Y') }}</td>
                                            <td class="whitespace-nowrap px-3 py-2 text-right text-gray-900">{{ $product->stockLabel($batch['packs'], $batch['loose']) }}</td>
                                            <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums text-gray-900">{{ $batch['value']->format() }}</td>
                                            <td class="whitespace-nowrap px-3 py-2 text-right sm:px-6">
                                                @if ($batch['state'] === 'expired')
                                                    <x-badge classes="bg-red-600 text-white ring-red-700/20">Expired</x-badge>
                                                @elseif ($batch['state'] === 'expiring')
                                                    <x-badge classes="bg-amber-500 text-white ring-amber-600/20">Near expiry</x-badge>
                                                @else
                                                    <x-badge classes="bg-gray-100 text-gray-700 ring-gray-500/20">OK</x-badge>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-panel>
                @endif

                {{-- As tall as its rows, up to a third of the column; the
                     movements below take whatever is left. --}}
                <x-panel title="Price history" description="All price changes, newest first."
                         class="xl:flex xl:max-h-[36%] xl:shrink-0 xl:flex-col">
                    <div class="max-h-56 overflow-auto xl:max-h-none xl:min-h-0 xl:flex-1">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="sticky top-0 z-10 bg-gray-50">
                                <tr>
                                    @foreach ([['Date','left'],['MRP','right'],...($business->sellsAtMrp() ? [] : [['Trade price','right']]),['Purchase rate','right'],['Carton','right'],['Source','left']] as [$heading,$align])
                                        <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first ? 'sm:px-6' : '' }}">{{ $heading }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($history as $price)
                                    <tr>
                                        <td class="whitespace-nowrap px-3 py-2 text-gray-900 sm:px-6">
                                            {{ $price->business_date->format('j M Y') }}
                                            @if ($loop->first)
                                                <span class="ml-1 text-xs text-emerald-700">Current</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-700">{{ $price->mrp?->format() ?? '—' }}</td>
                                        @unless ($business->sellsAtMrp())
                                            <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-700">{{ $price->trade_price?->format() ?? '—' }}</td>
                                        @endunless
                                        <td class="px-3 py-2 text-right font-mono tabular-nums font-semibold text-gray-900">{{ $price->purchase_rate?->format() ?? '—' }}</td>
                                        <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-600">{{ $price->case_size ?? '—' }}</td>
                                        <td class="px-3 py-2 text-xs text-gray-500">
                                            @if ($price->import)
                                                <a href="{{ route('businesses.companies.imports.show', [$business, $product->company_id, $price->import]) }}"
                                                   class="text-emerald-700 hover:text-emerald-800">{{ $price->import->original_filename }}</a>
                                                <span class="text-gray-500">({{ $price->import->company?->name ?? $product->company?->name }})</span>
                                            @elseif ($price->source instanceof \App\Models\Order)
                                                {{-- The company charged this on a delivery. --}}
                                                <a href="{{ route('businesses.orders.show', [$business, $price->source]) }}"
                                                   class="font-mono text-emerald-700 hover:text-emerald-800">{{ $price->source->reference }}</a>
                                                <span class="text-gray-500">({{ $price->source->company?->name ?? '—' }})</span>
                                            @else
                                                {{-- Priced without a list behind it: carried over from an
                                                     older system, or set when the product was added. --}}
                                                <span class="text-gray-400">{{ $loop->last ? 'Opening price' : 'Entered manually' }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="px-6 py-10 text-center text-gray-500">No price history.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-panel>

            <x-panel title="Stock movements" description="All stock received and removed. Stock in hand is the total of these."
                     class="xl:flex xl:min-h-0 xl:flex-1 xl:flex-col">
                <div class="max-h-96 overflow-auto xl:max-h-none xl:min-h-0 xl:flex-1">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="sticky top-0 z-10 bg-gray-50">
                            <tr>
                                @foreach ([['Date','left'],['Type','left'],['In','right'],['Out','right'],['Balance','right'],['Note','left'],['Entered by','left']] as [$h,$align])
                                    <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first ? 'sm:px-6' : '' }}">{{ $h }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($movements as $row)
                                @php($movement = $row['movement'])
                                <tr>
                                    <td class="whitespace-nowrap px-3 py-2 text-gray-900 sm:px-6">{{ $movement->business_date->format('j M Y') }}</td>
                                    <td class="px-3 py-2">
                                        <x-badge :classes="$movement->type->badgeClasses()">{{ $movement->type->label() }}</x-badge>
                                        @if ($movement->source instanceof App\Models\Order)
                                            <a href="{{ route('businesses.orders.show', [$business, $movement->source]) }}"
                                               class="ml-1 font-mono text-xs text-emerald-700 hover:text-emerald-800">{{ $movement->source->reference }}</a>
                                            <span class="text-xs text-gray-500">({{ $movement->source->company?->name ?? '—' }})</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-emerald-700">
                                        {{ $movement->isIncoming() ? number_format($movement->packs) . ($movement->loose !== 0 ? ' + ' . abs($movement->loose) . ' loose' : '') : '' }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-red-700">
                                        {{ $movement->isIncoming() ? '' : number_format(abs($movement->packs)) . ($movement->loose !== 0 ? ' + ' . abs($movement->loose) . ' loose' : '') }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono font-semibold tabular-nums text-gray-900">{{ number_format($row['running']) }}{{ $row['running_loose'] > 0 ? ' + ' . $row['running_loose'] : '' }}</td>
                                    <td class="px-3 py-2 text-xs text-gray-600">
                                        {{ $movement->note ?? '—' }}
                                        @if ($movement->batch_no) <span class="ml-1 font-mono text-gray-500">Batch {{ $movement->batch_no }}</span> @endif
                                        @if ($movement->expires_on) <span class="ml-1 text-gray-500">Exp. {{ $movement->expires_on->format('M Y') }}</span> @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-xs text-gray-500">{{ $movement->creator->name }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-10 text-center text-gray-500">
                                        No stock movements yet. A saved delivery adds {{ $business->unit()->many() }} here.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @can('verifyStock', $business)
                    {{-- Corrections are movements too, so the running total and
                         the history stay one thing. --}}
                    {{-- What is added says when it expires, like a delivery. What
                         is removed has no date to give, so the two fields only
                         show while the quantity is a plus. --}}
                    <form method="POST" action="{{ route('businesses.products.adjust-stock', [$business, $product]) }}"
                          x-data="{ packs: @js(old('packs', '')) }"
                          class="flex shrink-0 flex-wrap items-end gap-3 border-t border-gray-200 px-4 py-3 sm:px-6">
                        @csrf
                        <div>
                            <x-input-label for="type" value="Manual stock adjustment" />
                            <select id="type" name="type"
                                    class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                <option value="adjustment">Adjustment</option>
                                <option value="opening">Opening stock</option>
                            </select>
                        </div>

                        <div>
                            <x-input-label for="packs" :value="ucfirst($business->unit()->many()) . ' (− to remove)'" />
                            <x-text-input id="packs" name="packs" type="number" step="1" required x-model="packs"
                                          class="mt-1 block w-32 text-right font-mono" placeholder="e.g. -12" />
                        </div>

                        <div x-show="Number(packs) > 0" x-cloak>
                            <x-input-label value="Expiry (required)" />
                            <input type="month" data-must name="expires_on" value="{{ old('expires_on') }}"
                                   :disabled="! (Number(packs) > 0)"
                                   class="mt-1 block w-36 rounded-md border-gray-300 py-2 text-sm">
                        </div>

                        <div x-show="Number(packs) > 0" x-cloak>
                            <x-input-label for="batch_no" value="Batch no." />
                            <x-text-input id="batch_no" name="batch_no" type="text" maxlength="60" :value="old('batch_no')"
                                          x-bind:disabled="! (Number(packs) > 0)"
                                          class="mt-1 block w-32 font-mono uppercase" />
                        </div>

                        <div class="min-w-[14rem] flex-1">
                            <x-input-label for="note" value="Reason" />
                            <x-text-input id="note" name="note" type="text" class="mt-1 block w-full"
                                          placeholder="e.g. Breakage, expiry write-off, opening count" :value="old('note')" />
                        </div>

                        <button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                            Save
                        </button>

                        <x-input-error :messages="$errors->get('packs')" class="w-full" />
                        <x-input-error :messages="$errors->get('expires_on')" class="w-full" />
                    </form>
                @endcan
            </x-panel>
            </div>

            {{-- The right column: one card, three tabs. Suppliers, details and
                 the change log are each read on their own, never compared, so
                 they take turns at the full height instead of sharing it three
                 ways. Each tab says how many it holds, so nothing is hidden
                 behind a label that gives no sign of it. --}}
                @php($details = array_filter([
                    'Product code' => $product->code,
                    'Generic name' => $product->generic_name,
                    'Strength' => $product->strength,
                    'Dosage form' => $product->dosageForm()?->label() ?? $product->dosage_form,
                    'Pack size' => $product->pack_size,
                    'Pack type' => $product->packType()?->label() ?? $product->pack_type,
                    'Carton size' => $product->case_size ? $product->case_size . ' packs per carton' : null,
                    'Cost of a full carton' => $product->caseCost()?->format(true),
                    'Reorder level' => $product->reorder_level > 0 ? number_format($product->reorder_level) . ' ' . $business->unit()->many() : 'Not set',
                    'Sold loose' => $product->sellsLoose() ? $product->units_per_pack . ' pieces in one ' . $business->unit()->one() : 'No. Sold whole only',
                    'Price date' => $product->priced_on?->format('j M Y'),
                    'Last import' => $product->lastImport?->original_filename,
                ]))
            <x-panel class="xl:flex xl:min-h-0 xl:flex-col"
                     x-data="{ tab: 'details' }">
                <div class="flex shrink-0 border-b border-gray-200" role="tablist">
                    @foreach ([
                        'details' => ['Details', null],
                        'suppliers' => ['Suppliers', $suppliers->count()],
                        'changes' => ['Changes', $changes->count()],
                    ] as $key => [$label, $count])
                        <button type="button" role="tab" x-on:click="tab = '{{ $key }}'"
                                :aria-selected="tab === '{{ $key }}'"
                                :class="tab === '{{ $key }}'
                                    ? 'border-emerald-700 text-gray-900'
                                    : 'border-transparent text-gray-500 hover:text-gray-800'"
                                class="-mb-px flex flex-1 items-center justify-center gap-1.5 border-b-2 px-3 py-3 text-sm font-semibold focus:outline-none focus-visible:bg-gray-50">
                            {{ $label }}
                            @if ($count !== null)
                                <span class="rounded-full bg-gray-100 px-1.5 py-0.5 font-mono text-xs font-medium text-gray-600">{{ $count }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>

                {{-- Suppliers: everyone it has come from, cheapest first. --}}
                <div x-show="tab === 'suppliers'" x-cloak role="tabpanel"
                     class="max-h-96 divide-y divide-gray-100 overflow-auto xl:max-h-none xl:min-h-0 xl:flex-1">
                        @forelse ($suppliers as $supplier)
                            <div class="flex items-start justify-between gap-3 px-4 py-2.5 sm:px-6">
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                        @if ($supplier['link'])
                                            <a href="{{ route('businesses.companies.show', [$business, $supplier['company']]) }}"
                                               class="truncate text-sm font-semibold text-emerald-700 hover:underline">{{ $supplier['company']->name }}</a>
                                        @else
                                            <span class="truncate text-sm font-semibold text-gray-900">{{ $supplier['company']->name }}</span>
                                        @endif
                                        @if ($supplier['best'])
                                            <x-badge classes="bg-emerald-50 text-emerald-800 ring-emerald-600/20">Best rate</x-badge>
                                        @endif
                                    </p>
                                    <p class="mt-0.5 text-xs text-gray-500">
                                        @if ($supplier['last_on'])
                                            Last delivery {{ $supplier['last_on']->format('j M Y') }} · {{ number_format($supplier['received']) }} received
                                        @else
                                            No deliveries yet
                                        @endif
                                        @if ($supplier['owed'] !== null && ! $supplier['owed']->isZero())
                                            · Pending {{ $supplier['owed']->format() }}
                                        @endif
                                    </p>
                                </div>
                                <p class="shrink-0 text-right font-mono text-sm font-semibold text-gray-900">
                                    {{ $supplier['rate']?->format() ?? '—' }}
                                </p>
                            </div>
                        @empty
                            <p class="px-4 py-3 text-sm text-gray-500 sm:px-6">No supplier found for this product.</p>
                        @endforelse
                </div>

                {{-- Details: only what is known, one fact to a line. --}}
                <div x-show="tab === 'details'" role="tabpanel"
                     class="max-h-96 overflow-auto xl:max-h-none xl:min-h-0 xl:flex-1">
                    {{-- The figures first: what it sells for, what it costs,
                         what that leaves, and what is held. Two to a row, each
                         in the mono like every number from the books. --}}
                    @php($margin = $product->marginPerPack($business))
                    @php($figures = array_filter([
                        'MRP' => [$product->mrp?->format() ?? '—', null],
                        // A pharmacy sells at the MRP; only a distributor has a trade price.
                        'Trade price' => $business->sellsAtMrp() ? null : [$product->trade_price?->format() ?? '—', null],
                        'Purchase rate' => [$product->purchase_rate?->format() ?? '—', null],
                        'Margin per pack' => [$margin?->format() ?? '—', $product->marginPercent($business) ? $product->marginPercent($business) . ($business->sellsAtMrp() ? '% of MRP' : '% of trade price') : null],
                        'In stock' => [$product->stockLabel($onHand, $looseOnHand), implode(' · ', array_filter([$product->pack_size ? 'Pack ' . $product->pack_size : null, $product->case_size ? number_format($onHand / $product->case_size, 1) . ' cartons' : null])) ?: null],
                        'Value at cost' => [$product->purchase_rate ? $product->purchase_rate->times($onHand)->format() : '—', 'At the current rate'],
                    ]))
                    <dl class="grid grid-cols-2 gap-px border-b border-gray-200 bg-gray-100">
                        @foreach ($figures as $label => [$value, $hint])
                            <div class="bg-white px-4 py-3 sm:px-6 {{ $loop->last && $loop->odd ? 'col-span-2' : '' }}">
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</dt>
                                <dd class="mt-0.5 font-mono text-lg font-semibold text-gray-900">{{ $value }}</dd>
                                @if ($hint)
                                    <p class="text-xs text-gray-500">{{ $hint }}</p>
                                @endif
                            </div>
                        @endforeach
                    </dl>

                    <dl class="divide-y divide-gray-100 text-sm">
                        @foreach ($details as $label => $value)
                            <div class="flex items-baseline justify-between gap-4 px-4 py-2 sm:px-6">
                                <dt class="text-gray-500">{{ $label }}</dt>
                                <dd class="text-right font-medium text-gray-900">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                {{-- Changes made by hand: who edited the product, newest first.
                     Price lists and deliveries have their own record in the
                     price history; this is the rest. --}}
                <div x-show="tab === 'changes'" x-cloak role="tabpanel"
                     class="max-h-96 divide-y divide-gray-100 overflow-auto text-sm xl:max-h-none xl:min-h-0 xl:flex-1">
                        @forelse ($changes as $change)
                            <div class="px-4 py-3 sm:px-6">
                                <p class="text-xs text-gray-500">
                                    {{ $change->causer?->name ?? 'System' }} ·
                                    {{ $change->created_at->timezone($business->timezone)->format('D j M Y, h:i A') }}
                                </p>
                                <ul class="mt-1 space-y-0.5">
                                    @foreach ($change->properties['after'] ?? [] as $field => $to)
                                        <li>
                                            <span class="font-medium text-gray-900">{{ Str::headline($field) }}</span>:
                                            <span class="font-mono text-xs text-gray-500">{{ ($change->properties['before'][$field] ?? '') === '' ? '—' : $change->properties['before'][$field] }}</span>
                                            →
                                            <span class="font-mono text-xs text-gray-900">{{ $to === '' ? '—' : $to }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @empty
                            <p class="px-4 py-4 text-gray-500 sm:px-6">No manual changes.</p>
                        @endforelse
                </div>
            </x-panel>
        </div>

    </div>
</x-workspace-layout>
