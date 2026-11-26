{{-- One supply from a company, and the one page for it: the bill as it went
     on its day, and the goods it was for — whether they came against an order
     form or simply turned up. Against a form, what was ordered sits beside
     what came. Read only: a supply is corrected on its delivery. --}}
<x-workspace-layout :business="$business">
    <div class="w-full px-4 py-5 sm:px-6 lg:px-8">
        <x-flash />

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-gray-200 px-4 py-2.5 ring-1 ring-gray-300">
            <div class="min-w-0">
                <x-back-link :href="route('businesses.companies.show', [$business, $company])" :label="$company->name.' statement'" />
                <h1 class="flex flex-wrap items-center gap-x-2 text-lg font-semibold text-gray-900">
                    <span class="truncate">Bill {{ $bill->invoice_no ?: 'without an invoice number' }}</span>
                    <span class="text-sm font-normal text-gray-500">{{ $company->name }}</span>
                    @if ($order)
                        <x-badge :classes="$hasForm ? 'bg-sky-50 text-sky-800 ring-sky-600/20' : 'bg-gray-100 text-gray-700 ring-gray-500/20'">
                            {{ $hasForm ? 'Against order '.$order->reference : 'Direct supply · '.$order->reference }}
                        </x-badge>
                    @endif
                </h1>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($bill->dailyEntry)
                    <a href="{{ route('businesses.daily.day', [$business, $bill->dailyEntry->business_date->format('Y-m-d')]) }}"
                       class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50">
                        Daily Log · {{ $bill->dailyEntry->business_date->format('d M Y') }}
                    </a>
                @endif
                @if ($hasForm)
                    <a href="{{ route('businesses.orders.show', [$business, $order, 'form' => 1]) }}"
                       class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50">
                        Order form {{ $order->reference }}
                    </a>
                @endif
                @if ($order)
                    @if ($canManage && $order->deliveryIsEditable())
                        <a href="{{ route('businesses.orders.receive', [$business, $order]) }}"
                           class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                            Edit delivery
                        </a>
                    @elseif ($reason = $order->deliveryLockedReason())
                        <span class="rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-xs text-gray-500" title="{{ $reason }}">
                            Delivery locked
                        </span>
                    @endif
                @endif
            </div>
        </div>

        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat label="Bill date" :value="$bill->dailyEntry?->business_date->format('d M Y') ?? '—'"
                    :hint="$order?->receiver ? 'Received by '.$order->receiver->name : null" />
            <x-stat label="Bill amount" :value="$bill->amount->format()" />
            <x-stat label="Paid at the time" :value="$bill->paid->format()" />
            <x-stat label="Left on account" :value="$bill->pending()->format()"
                    hint="Before any trade discount on the day" />
        </div>

        @php($exceptions = $hasForm ? $rows->filter(fn ($r) => $r['difference'] !== 0)->count() : 0)

        <x-panel>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-gray-200 px-4 py-3 sm:px-6">
                <h2 class="text-base font-semibold text-gray-900">Items on this bill</h2>
                @if ($hasForm)
                    @if ($exceptions === 0)
                        <x-badge classes="bg-emerald-50 text-emerald-800 ring-emerald-600/20">All items received as ordered ✓</x-badge>
                    @else
                        <x-badge classes="bg-amber-50 text-amber-800 ring-amber-600/20">
                            {{ $exceptions }} {{ Str::plural('item', $exceptions) }} not as ordered
                        </x-badge>
                    @endif
                @elseif ($order)
                    <p class="text-sm text-gray-500">Supplied without an order form.</p>
                @endif
            </div>

            @if ($rows->isEmpty())
                <p class="px-6 py-10 text-center text-sm text-gray-500">
                    This bill was entered as an amount only, without a delivery, so it has no items.
                </p>
            @else
                @php($heads = array_values(array_filter([
                    ['#', 'left'], ['Product', 'left'], ['Batch', 'left'], ['Expiry', 'left'],
                    $hasForm ? ['Ordered', 'right'] : null,
                    ['Received', 'right'], ['Rate', 'right'], ['Discount', 'right'], ['Amount', 'right'],
                ])))
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                @foreach ($heads as [$h, $align])
                                    <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first ? 'sm:pl-6' : '' }} {{ $loop->last ? 'sm:pr-6' : '' }}">{{ $h }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($rows as $row)
                                @php($item = $row['receipt'])
                                @php($line = $row['line'])
                                @php($missing = $item === null || $item->isMissing())
                                @php($percent = $item ? ($item->discount_percent ?? $order->discount_percent) : null)
                                <tr class="{{ $hasForm && $row['difference'] !== 0 ? 'bg-amber-50/40' : '' }}">
                                    <td class="px-3 py-2 text-gray-500 sm:pl-6">{{ $loop->iteration }}</td>
                                    <td class="min-w-[14rem] px-3 py-2">
                                        <span class="font-medium {{ $missing ? 'text-gray-500' : 'text-gray-900' }}">{{ $line?->label() ?? $item->label() }}</span>
                                        @if ($hasForm && $line === null)
                                            <x-badge classes="ml-2 bg-sky-50 text-sky-800 ring-sky-600/20">Not ordered</x-badge>
                                        @endif
                                        @if ($generic = $item?->generic_name ?? $line?->generic_name)
                                            <p class="text-xs text-gray-500">{{ $generic }}</p>
                                        @endif
                                        @if ($item?->note)
                                            <p class="text-xs text-amber-700">{{ $item->note }}</p>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-gray-700">{{ $item?->batch_no ?: '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ $item?->expires_on?->format('M Y') ?? '—' }}</td>
                                    @if ($hasForm)
                                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-gray-600">
                                            {{ $line ? number_format($row['ordered']) : '—' }}
                                        </td>
                                    @endif
                                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums {{ $missing ? 'text-gray-400' : 'text-gray-900' }}">
                                        @if ($item === null)
                                            Not checked
                                        @elseif ($item->isMissing())
                                            Not received
                                        @else
                                            {{ $item->quantityLabel() }}
                                        @endif
                                        @if ($hasForm && $line && $item && $row['difference'] !== 0)
                                            <span class="ml-1 font-semibold {{ $row['difference'] < 0 ? 'text-amber-700' : 'text-sky-700' }}">
                                                ({{ $row['difference'] > 0 ? '+' : '' }}{{ number_format($row['difference']) }})
                                            </span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums">{{ $item?->rate->format() ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums text-gray-600">
                                        {{ $percent === null || (float) $percent == 0.0 ? '—' : rtrim(rtrim((string) $percent, '0'), '.').'%' }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono font-semibold tabular-nums text-gray-900 sm:pr-6">
                                        {{ $item ? $item->amount($order->discount_percent)->format() : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="{{ count($heads) - 1 }}" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Items total</td>
                                <td class="px-3 py-2 text-right font-mono font-semibold tabular-nums text-gray-900 sm:pr-6">{{ $order->receivedTotal()->format() }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </x-panel>
    </div>
</x-workspace-layout>
