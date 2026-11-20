{{--
    Moving a business in from its old POS: the preview.

    Everything the file would bring across, row by row, before anything is
    written. Each row says whether it is new, already here, or left out and
    why; the totals at the top are for what is new. Importing carries out
    exactly this — it is planned again from the file at that moment.
--}}
@php($t = $plan['totals'])
@php($rs = fn ($v) => $v === null ? '—' : \App\Support\Money::of($v)->format())
@php($badge = [
    'new' => ['New', 'bg-emerald-50 text-emerald-800 ring-emerald-600/20'],
    'exists' => ['Already here', 'bg-gray-100 text-gray-600 ring-gray-500/20'],
    'skipped' => ['Left out', 'bg-amber-50 text-amber-800 ring-amber-600/20'],
])
@php($trade = ! $business->sellsAtMrp())
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Preview — {{ $import->original_name }}</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Into {{ $business->name }} ({{ $business->business_type->label() }}). Nothing has been imported yet.
                </p>
            </div>
            <form method="POST" action="{{ route('admin.businesses.import.destroy', [$business, $import]) }}">
                @csrf
                @method('DELETE')
                <button class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Discard this upload
                </button>
            </form>
        </div>
    </x-slot>

    <div class="w-full space-y-6 px-4 py-8 sm:px-6 lg:px-8" x-data="{ tab: 'products', only: 'all' }">
        <x-flash />

        {{-- What is going to be imported, and the choice of how much of it. --}}
        <form method="POST" action="{{ route('admin.businesses.import.commit', [$business, $import]) }}"
              x-data="{ products: true, sending: false }" x-on:submit="sending = true">
            @csrf
            <x-panel title="This is going to be imported"
                     description="Untick anything you do not want. Rows already in the business are never changed or added twice.">
                <div class="grid gap-px bg-gray-100 sm:grid-cols-2 xl:grid-cols-5">
                    @foreach ([
                        ['companies', 'Companies', number_format($t['companies_new']).' new', $t['companies_exist'] ? number_format($t['companies_exist']).' already here' : 'Each gets its own account', true],
                        ['customers', 'Customers', number_format($t['customers_new']).' new', $t['customers_exist'] ? number_format($t['customers_exist']).' already here' : 'Each gets its own account', true],
                        ['products', 'Products', number_format($t['products_new']).' new', trim(($t['products_exist'] ? number_format($t['products_exist']).' already here' : '').($t['products_skipped'] ? ' · '.number_format($t['products_skipped']).' left out' : ''), ' ·') ?: ($trade ? 'Trade price and MRP' : 'MRP only — a pharmacy has no trade price'), true],
                        ['stock', 'Stock', number_format($t['stock_packs']).' in '.number_format($t['stock_lines']).' products', 'Worth Rs. '.$rs($t['stock_value']).' at cost, with expiry and batch', true],
                        ['balances', 'Balances', number_format($t['customer_balances'] + $t['company_balances']).' accounts', 'Customers owe Rs. '.$rs($t['owed_by_customers']).' · companies are owed Rs. '.$rs($t['owed_to_companies']), $plan['balances_possible']],
                    ] as [$key, $title, $figure, $note, $possible])
                        <label @class(['flex items-start gap-3 bg-white px-4 py-4', 'cursor-pointer' => $possible, 'opacity-60' => ! $possible])>
                            <input type="hidden" name="{{ $key }}" value="0">
                            <input type="checkbox" name="{{ $key }}" value="1" @checked($possible && $key !== 'balances') @disabled(! $possible)
                                   @if ($key === 'products') x-model="products" @endif
                                   @if ($key === 'stock') :disabled="! products" @endif
                                   class="mt-1 h-4 w-4 rounded border-gray-300 text-emerald-700 focus:ring-emerald-600">
                            <span class="min-w-0">
                                <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $title }}</span>
                                <span class="block text-lg font-semibold tabular-nums text-gray-900">{{ $figure }}</span>
                                <span class="block text-xs leading-snug text-gray-500">{{ $note }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="space-y-2 border-t border-gray-200 px-4 py-3 text-sm sm:px-6">
                    @unless ($plan['balances_possible'])
                        <p class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900">
                            <strong>Balances cannot be brought over yet.</strong>
                            This business has no finalised opening balance, so nothing can be posted to its books.
                            Import the lists and stock now, finalise the opening balance, then upload the file again for the balances.
                        </p>
                    @else
                        <p class="text-gray-600">
                            <strong class="text-gray-900">Balances are not ticked to begin with.</strong>
                            Check the two totals above against the customers' and companies' statements first —
                            an old system's running balance is not always what is really owed. Ticked, each balance
                            is moved from “Unallocated” onto that customer's or company's own account; the totals
                            owed, and the net position, stay exactly as they are.
                        </p>
                    @endunless
                    <p class="text-gray-600">
                        Stock comes in as a count only. Its value — Rs. {{ $rs($t['stock_value']) }} at cost — belongs in the opening balance.
                    </p>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-gray-200 px-4 py-3 sm:px-6">
                    <span class="text-xs text-gray-500">The uploaded file is removed from the server once this is done.</span>
                    <button :disabled="sending"
                            class="rounded-md bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-wait disabled:opacity-60">
                        <span x-text="sending ? 'Importing…' : 'Import into {{ $business->name }}'">Import into {{ $business->name }}</span>
                    </button>
                </div>
            </x-panel>
        </form>

        {{-- Row by row. --}}
        <x-panel>
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 sm:px-6">
                <div class="flex gap-1">
                    @foreach (['products' => 'Products · '.number_format(count($plan['products'])), 'companies' => 'Companies · '.number_format(count($plan['companies'])), 'customers' => 'Customers · '.number_format(count($plan['customers']))] as $key => $label)
                        <button type="button" x-on:click="tab = '{{ $key }}'"
                                :class="tab === '{{ $key }}' ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
                                class="rounded-md px-3 py-1.5 text-sm font-medium">{{ $label }}</button>
                    @endforeach
                </div>
                <div class="flex items-center gap-1 text-sm">
                    <span class="mr-1 text-gray-500">Show</span>
                    @foreach (['all' => 'Everything', 'new' => 'New', 'exists' => 'Already here', 'notes' => 'With a note'] as $key => $label)
                        <button type="button" x-on:click="only = '{{ $key }}'"
                                :class="only === '{{ $key }}' ? 'border-emerald-700 text-emerald-800 font-semibold' : 'border-gray-300 text-gray-600 hover:border-gray-400'"
                                class="rounded-md border bg-white px-2.5 py-1 text-xs">{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            {{-- Products --}}
            <div x-show="tab === 'products'" class="max-h-[65vh] overflow-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="sticky top-0 bg-gray-50">
                        <tr>
                            @foreach (array_filter(['Product', 'Barcode', 'Pack', 'Company', 'Purchase', $trade ? 'Trade' : null, 'MRP', 'Stock', 'Expiry · batch', '']) as $h)
                                <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ in_array($h, ['Purchase', 'Trade', 'MRP', 'Stock']) ? 'text-right' : 'text-left' }} {{ $loop->first ? 'sm:px-6' : '' }}">{{ $h }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($plan['products'] as $p)
                            <tr x-show="only === 'all' || only === '{{ $p['status'] }}' || (only === 'notes' && {{ $p['notes'] ? 'true' : 'false' }})">
                                <td class="px-3 py-2 sm:px-6">
                                    <span class="font-medium text-gray-900">{{ $p['name'] ?: '—' }}</span>
                                    @if ($p['form']) <span class="text-xs text-gray-500">{{ $p['form'] }}</span> @endif
                                    @foreach ($p['notes'] as $note)
                                        <span class="block text-xs text-amber-700">{{ $note }}</span>
                                    @endforeach
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-gray-600">{{ $p['code'] ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-gray-600">{{ $p['pack'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $p['company'] }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums text-gray-900">{{ $rs($p['cost']) }}</td>
                                @if ($trade)
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums {{ $p['trade'] === null ? 'text-gray-400' : 'text-gray-900' }}">{{ $p['trade'] === null ? 'left empty' : $rs($p['trade']) }}</td>
                                @endif
                                <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums text-gray-900">{{ $rs($p['mrp']) }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums {{ $p['qty'] > 0 ? 'text-gray-900' : 'text-gray-400' }}">{{ number_format($p['qty']) }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-xs text-gray-600">
                                    {{ $p['expires_on'] ? \Illuminate\Support\Carbon::parse($p['expires_on'])->format('M Y') : '—' }}
                                    @if ($p['batch_no']) · <span class="font-mono">{{ $p['batch_no'] }}</span> @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-right sm:px-6"><x-badge :classes="$badge[$p['status']][1]">{{ $badge[$p['status']][0] }}</x-badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Companies and customers share a shape. --}}
            @foreach (['companies' => 'Owed to them', 'customers' => 'They owe'] as $list => $heading)
                <div x-show="tab === '{{ $list }}'" x-cloak class="max-h-[65vh] overflow-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="sticky top-0 bg-gray-50">
                            <tr>
                                @foreach (['Name', 'Phone', 'Area', $heading, 'Balance', ''] as $h)
                                    <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ $h === $heading ? 'text-right' : 'text-left' }} {{ $loop->first ? 'sm:px-6' : '' }}">{{ $h }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($plan[$list] as $row)
                                <tr x-show="only === 'all' || only === '{{ $row['status'] }}' || (only === 'notes' && {{ $row['balance_note'] ? 'true' : 'false' }})">
                                    <td class="px-3 py-2 font-medium text-gray-900 sm:px-6">{{ $row['name'] }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-gray-600">{{ $row['phone'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ $row['area'] ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums {{ \App\Support\Money::of($row['balance'])->isZero() ? 'text-gray-400' : 'text-gray-900' }}">{{ $rs($row['balance']) }}</td>
                                    <td class="px-3 py-2 text-xs">
                                        @if ($row['balance_importable'])
                                            <span class="font-medium text-emerald-700">Will be brought over</span>
                                        @elseif ($row['balance_note'])
                                            <span class="text-amber-700">{{ $row['balance_note'] }}</span>
                                        @else
                                            <span class="text-gray-400">Nothing owing</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right sm:px-6"><x-badge :classes="$badge[$row['status']][1]">{{ $badge[$row['status']][0] }}</x-badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </x-panel>
    </div>
</x-app-layout>
