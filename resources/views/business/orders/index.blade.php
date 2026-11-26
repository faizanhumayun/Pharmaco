<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Order forms &amp; deliveries</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Orders sent to companies, and every supply received — against an order form or without one.
                    An order does not change the accounts; the purchase is entered when the goods and the invoice arrive.
                </p>
            </div>
            @can('manageOrders', $business)
                <a href="{{ route('businesses.orders.create', $business) }}"
                   class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    New order form
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        @if ($outstanding->isNotEmpty())
            <div class="mb-6 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-md border border-sky-200 bg-sky-50 px-4 py-3">
                <span class="text-sm font-semibold text-sky-900">
                    {{ $outstanding->count() }} {{ Str::plural('order', $outstanding->count()) }} pending
                </span>
                <span class="text-sm text-sky-800">
                    Total value <span class="font-mono tabular-nums">{{ $outstandingTotal->format() }}</span>
                </span>
                @php($oldest = $outstanding->sortBy('sent_at')->first())
                @if ($oldest?->sent_at)
                    <span class="text-sm text-sky-800">
                        Oldest: {{ $oldest->reference }}, sent {{ $oldest->sent_at->diffForHumans() }}
                    </span>
                @endif
                <a href="{{ route('businesses.orders.index', ['business' => $business, 'status' => 'sent']) }}"
                   class="ml-auto text-sm font-semibold text-sky-900 underline">Show pending orders</a>
            </div>
        @endif

        <x-filter-bar submit="Filter" :active="array_filter($filters)" clear="{{ route('businesses.orders.index', $business) }}" class="mb-6">
            <x-slot name="count">{{ number_format($orders->total()) }} {{ $orders->total() === 1 ? 'entry' : 'entries' }}</x-slot>

            <div>
                <x-input-label for="q" value="Reference or invoice no." />
                <x-text-input id="q" name="q" type="search" class="mt-1 block" :value="$filters['q']" placeholder="ORD-0009, INV-17173…" />
            </div>

            <div>
                <x-input-label for="company" value="Company" />
                <select id="company" name="company"
                        class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    <option value="">All companies</option>
                    @foreach ($companies as $option)
                        <option value="{{ $option->id }}" @selected((string) $filters['company'] === (string) $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="status" value="Status" />
                <select id="status" name="status"
                        class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    <option value="">All statuses</option>
                    @foreach (App\Enums\OrderStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="kind" value="Type" />
                <select id="kind" name="kind"
                        class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    <option value="">Both</option>
                    <option value="form" @selected($filters['kind'] === 'form')>Against an order form</option>
                    <option value="direct" @selected($filters['kind'] === 'direct')>Direct supply</option>
                </select>
            </div>

            <div>
                <x-input-label for="invoice" value="Invoice" />
                <select id="invoice" name="invoice"
                        class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    <option value="">Any</option>
                    <option value="with" @selected($filters['invoice'] === 'with')>Invoice entered</option>
                    <option value="without" @selected($filters['invoice'] === 'without')>Received, no invoice yet</option>
                </select>
            </div>

            <div>
                <x-input-label for="from" value="From" />
                <x-text-input id="from" name="from" type="date" class="mt-1 block" :value="$filters['from']" />
            </div>

            <div>
                <x-input-label for="to" value="To" />
                <x-text-input id="to" name="to" type="date" class="mt-1 block" :value="$filters['to']" />
            </div>
        </x-filter-bar>

        <x-panel>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach ([['Reference','left'],['Date','left'],['Company','left'],['Status','left'],['Delivery','left'],['Invoice','left'],['Items','right'],['Discount','right'],['Total','right'],['Created by','left']] as [$h,$align])
                                <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first ? 'sm:px-6' : '' }}">{{ $h }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($orders as $order)
                            <tr>
                                @php($hasForm = $order->lines->isNotEmpty())
                                @php($bill = $order->purchaseLine)
                                <td class="whitespace-nowrap px-3 py-2 sm:px-6">
                                    <a href="{{ route('businesses.orders.show', [$business, $order]) }}"
                                       class="font-mono font-medium text-gray-900 hover:text-emerald-700">{{ $order->reference }}</a>
                                    <p class="text-xs text-gray-500">{{ $hasForm ? 'Order form' : 'Direct supply' }}</p>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-gray-600">{{ $order->business_date->format('j M Y') }}</td>
                                <td class="px-3 py-2 text-gray-900">{{ $order->company->name }}</td>
                                <td class="px-3 py-2"><x-badge :classes="$order->status->badgeClasses()">{{ $order->status->label() }}</x-badge></td>
                                <td class="whitespace-nowrap px-3 py-2 text-sm">
                                    @if ($order->isOutstanding())
                                        <span class="text-sky-700">
                                            Waiting {{ $order->daysWaiting() }} {{ Str::plural('day', $order->daysWaiting()) }}
                                        </span>
                                    @elseif ($order->status === App\Enums\OrderStatus::Received)
                                        @php($exceptions = $order->deliveryExceptions()->count())
                                        @if ($exceptions === 0)
                                            <span class="text-emerald-700">Received in full</span>
                                        @else
                                            <span class="text-amber-700">
                                                {{ $exceptions }} {{ Str::plural('difference', $exceptions) }}
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                {{-- The company's invoice for what came, once it is entered. --}}
                                <td class="whitespace-nowrap px-3 py-2">
                                    @if ($bill && $bill->company_id)
                                        <a href="{{ route('businesses.companies.bill', [$business, $bill->company_id, $bill]) }}"
                                           class="font-mono font-medium text-emerald-700 hover:underline">{{ $bill->invoice_no ?: 'No number' }}</a>
                                        <p class="font-mono text-xs tabular-nums text-gray-500">{{ $bill->amount->format() }}</p>
                                    @elseif ($bill)
                                        <span class="font-mono text-gray-900">{{ $bill->invoice_no ?: 'No number' }}</span>
                                    @elseif ($order->status === App\Enums\OrderStatus::Received)
                                        <span class="text-amber-700">Not entered</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-600">{{ $hasForm ? $order->lines->count() : $order->receiptLines->count() }}</td>
                                <td class="px-3 py-2 text-right text-gray-600">
                                    {{ $order->discount_percent ? rtrim(rtrim($order->discount_percent, '0'), '.') . '%' : '—' }}
                                </td>
                                {{-- A direct supply has no form to total, so it is what came. --}}
                                <td class="px-3 py-2 text-right font-mono font-semibold tabular-nums text-gray-900">{{ ($hasForm ? $order->total() : $order->receivedTotal())->format() }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $order->creator->name }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-6 py-10 text-center text-gray-500">
                                    @if (array_filter($filters))
                                        Nothing matches these filters.
                                    @else
                                        No order forms or deliveries yet. Create an order form, or receive stock from Add stock.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($orders->hasPages())
                <div class="border-t border-gray-200 px-4 py-3 sm:px-6">{{ $orders->links() }}</div>
            @endif
        </x-panel>
    </div>
</x-workspace-layout>
