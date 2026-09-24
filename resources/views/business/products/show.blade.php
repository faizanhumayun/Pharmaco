<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold text-gray-900">{{ $product->brand_name }}</h1>
            @unless ($product->is_active)
                <x-badge classes="bg-gray-100 text-gray-600 ring-gray-500/20">Withdrawn</x-badge>
            @endunless
        </div>
        <p class="mt-1 text-sm text-gray-500">
            {{ $product->company?->name }}
            @if ($product->generic_name) · {{ $product->generic_name }} @endif
        </p>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        {{-- One row: the three prices, what they leave, and what is held. --}}
        <div class="mb-6 grid gap-4 sm:grid-cols-3 xl:grid-cols-6">
            <x-stat label="MRP" :value="$product->mrp?->format() ?? '—'" />
            <x-stat label="Trade price" :value="$product->trade_price?->format() ?? '—'" />
            <x-stat label="Our rate" :value="$product->purchase_rate?->format() ?? '—'" />
            <x-stat label="Margin per pack"
                    :value="$product->marginPerPack()?->format() ?? '—'"
                    :hint="$product->marginPercent() ? $product->marginPercent() . '% of trade price' : null" />
            <x-stat label="In stock"
                    :value="number_format($onHand) . ' ' . $business->unit()->many()"
                    :hint="$product->case_size ? number_format($onHand / $product->case_size, 1) . ' cartons' : null" />
            <x-stat label="Value at cost"
                    :value="$product->purchase_rate ? $product->purchase_rate->times($onHand)->format() : '—'"
                    hint="At the current rate" />
        </div>

        {{-- Two columns, not three cells: the price and movement panels stack in
             one, so Details starts at the top of its own instead of waiting for
             a row tall enough to hold it. --}}
        <div class="grid items-start gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <x-panel title="Price history"
                         description="One row for every time a figure moved, whether a price list moved it or the product was priced some other way. A list that repeated what was already on file leaves no row — and nothing here is ever rewritten.">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    @foreach ([['Date','left'],['MRP','right'],['Trade price','right'],['Our rate','right'],['Case','right'],['From','left']] as [$heading,$align])
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
                                                <span class="ml-1 text-xs text-emerald-700">current</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-700">{{ $price->mrp?->format() ?? '—' }}</td>
                                        <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-700">{{ $price->trade_price?->format() ?? '—' }}</td>
                                        <td class="px-3 py-2 text-right font-mono tabular-nums font-semibold text-gray-900">{{ $price->purchase_rate?->format() ?? '—' }}</td>
                                        <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-600">{{ $price->case_size ?? '—' }}</td>
                                        <td class="px-3 py-2 text-xs text-gray-500">
                                            @if ($price->import)
                                                <a href="{{ route('businesses.companies.imports.show', [$business, $product->company_id, $price->import]) }}"
                                                   class="text-emerald-700 hover:text-emerald-800">{{ $price->import->original_filename }}</a>
                                            @elseif ($price->source instanceof \App\Models\Order)
                                                {{-- The company charged this on a delivery. --}}
                                                <a href="{{ route('businesses.orders.show', [$business, $price->source]) }}"
                                                   class="text-emerald-700 hover:text-emerald-800">Delivery {{ $price->source->reference }}</a>
                                            @else
                                                {{-- Priced without a list behind it: carried over from an
                                                     older system, or set when the product was added. --}}
                                                <span class="text-gray-400">{{ $loop->last ? 'opening price' : 'entered directly' }}</span>
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

            <x-panel title="Stock movements"
                     description="Every pack in or out. The quantity held is their sum — nothing stores a running count."
                     class="">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                @foreach ([['Date','left'],['What','left'],['In','right'],['Out','right'],['Running','right'],['Note','left'],['By','left']] as [$h,$align])
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
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-emerald-700">
                                        {{ $movement->isIncoming() ? number_format($movement->packs) : '' }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-red-700">
                                        {{ $movement->isIncoming() ? '' : number_format(abs($movement->packs)) }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono font-semibold tabular-nums text-gray-900">{{ number_format($row['running']) }}</td>
                                    <td class="px-3 py-2 text-xs text-gray-600">{{ $movement->note ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-xs text-gray-500">{{ $movement->creator->name }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-10 text-center text-gray-500">
                                        Nothing has moved yet. A recorded delivery adds {{ $business->unit()->many() }} here.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @can('verifyStock', $business)
                    {{-- Corrections are movements too, so the running total and
                         the history stay one thing. --}}
                    <form method="POST" action="{{ route('businesses.products.adjust-stock', [$business, $product]) }}"
                          class="flex flex-wrap items-end gap-3 border-t border-gray-200 px-4 py-3 sm:px-6">
                        @csrf
                        <div>
                            <x-input-label for="type" value="Correction" />
                            <select id="type" name="type"
                                    class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                <option value="adjustment">Adjustment</option>
                                <option value="opening">Opening stock</option>
                            </select>
                        </div>

                        <div>
                            <x-input-label for="packs" :value="ucfirst($business->unit()->many()) . ' (− to remove)'" />
                            <x-text-input id="packs" name="packs" type="number" step="1" required
                                          class="mt-1 block w-32 text-right font-mono" placeholder="e.g. -12" />
                        </div>

                        <div class="min-w-[14rem] flex-1">
                            <x-input-label for="note" value="Reason" />
                            <x-text-input id="note" name="note" type="text" class="mt-1 block w-full"
                                          placeholder="Breakage in Godown 2, expiry write-off, opening count" />
                        </div>

                        <button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                            Record
                        </button>

                        <x-input-error :messages="$errors->get('packs')" class="w-full" />
                    </form>
                @endcan
            </x-panel>
            </div>

            <x-panel title="Details">
                <dl class="divide-y divide-gray-100 text-sm">
                    @foreach ([
                        'Product code' => $product->code,
                        'Brand name' => $product->brand_name,
                        'Generic name' => $product->generic_name,
                        'Strength' => $product->strength,
                        'Dosage form' => $product->dosageForm()?->label() ?? $product->dosage_form,
                        'Pack size' => $product->pack_size,
                        'Pack type' => $product->packType()?->label() ?? $product->pack_type,
                        'Case size' => $product->case_size ? $product->case_size . ' packs per carton' : null,
                        'Cost of a full case' => $product->caseCost()?->format(true),
                        'Company' => $product->company?->name,
                        'Priced on' => $product->priced_on?->format('j M Y'),
                        'Last import' => $product->lastImport?->original_filename,
                    ] as $label => $value)
                        <div class="flex items-baseline justify-between gap-4 px-4 py-2 sm:px-6">
                            <dt class="text-gray-500">{{ $label }}</dt>
                            <dd class="text-right font-medium text-gray-900">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-panel>
        </div>
    </div>
</x-workspace-layout>
