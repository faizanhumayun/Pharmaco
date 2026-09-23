<x-workspace-layout :business="$business">
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">Products</h1>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="mt-1 text-sm text-gray-500">
                What the companies sell and at what prices — as imported from the price lists they sent.
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

        <form method="GET" class="mb-6 flex flex-wrap items-end gap-3 rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
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
                        <option value="">Any form</option>
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

            <button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">Filter</button>

            @if (array_filter($filters))
                <a href="{{ route('businesses.products.index', $business) }}" class="pb-2 text-sm text-gray-500 hover:text-gray-700">Clear</a>
            @endif

            <span class="ml-auto pb-2 text-sm text-gray-500">
                {{ number_format($products->total()) }} of {{ number_format($total) }} products
            </span>
        </form>

        <x-panel>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach ([
                                ['Product', 'left'], ['Generic', 'left'], ['Pack', 'left'], ['Company', 'left'],
                                ['MRP', 'right'], ['Trade price', 'right'], ['Our rate', 'right'],
                                ['Margin', 'right'], ['Case', 'right'], ['On hand', 'right'], ['Priced', 'right'],
                            ] as [$heading, $align])
                                <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first ? 'sm:px-6' : '' }}">
                                    {{ $heading }}
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
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-900">{{ $product->trade_price?->format() ?? '—' }}</td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums font-semibold text-gray-900">{{ $product->purchase_rate?->format() ?? '—' }}</td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-600">
                                    @if ($product->marginPerPack())
                                        {{ $product->marginPerPack()->format() }}
                                        <span class="text-xs text-gray-400">{{ $product->marginPercent() }}%</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-600">{{ $product->case_size ?? '—' }}</td>
                                @php($held = (int) ($onHand[$product->id] ?? 0))
                                <td class="px-3 py-2 text-right font-mono tabular-nums {{ $held > 0 ? 'font-semibold text-gray-900' : 'text-gray-400' }}">
                                    {{ $held === 0 ? '—' : number_format($held) }}
                                </td>
                                <td class="px-3 py-2 text-right text-xs text-gray-500">{{ $product->priced_on?->format('j M y') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="px-6 py-10 text-center text-gray-500">
                                    @if (array_filter($filters))
                                        Nothing matches that.
                                    @elseif ($companies->isEmpty())
                                        No companies yet. A price list belongs to a company, so
                                        <a href="{{ route('businesses.companies.index', $business) }}"
                                           class="font-medium text-emerald-700 hover:text-emerald-800">add a company</a>
                                        before importing one.
                                    @else
                                        No products yet. Open a company and import the price list it sent —
                                        <a href="{{ route('businesses.companies.index', $business) }}"
                                           class="font-medium text-emerald-700 hover:text-emerald-800">choose one</a>.
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
