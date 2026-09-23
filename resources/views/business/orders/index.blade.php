<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Order forms</h1>
                <p class="mt-1 text-sm text-gray-500">
                    What you have asked companies to supply. An order commits nothing — the purchase is entered in
                    Daily entry when the goods and the invoice arrive.
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
                    {{ $outstanding->count() }} {{ Str::plural('order', $outstanding->count()) }} outstanding
                </span>
                <span class="text-sm text-sky-800">
                    worth <span class="font-mono tabular-nums">{{ $outstandingTotal->format() }}</span>
                </span>
                @php($oldest = $outstanding->sortBy('sent_at')->first())
                @if ($oldest?->sent_at)
                    <span class="text-sm text-sky-800">
                        oldest {{ $oldest->reference }}, sent {{ $oldest->sent_at->diffForHumans() }}
                    </span>
                @endif
                <a href="{{ route('businesses.orders.index', ['business' => $business, 'status' => 'sent']) }}"
                   class="ml-auto text-sm font-semibold text-sky-900 underline">Show only these</a>
            </div>
        @endif

        <form method="GET" class="mb-6 flex flex-wrap items-end gap-3 rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
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
                    <option value="">Any</option>
                    @foreach (App\Enums\OrderStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>

            <button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">Filter</button>

            @if (array_filter($filters))
                <a href="{{ route('businesses.orders.index', $business) }}" class="pb-2 text-sm text-gray-500 hover:text-gray-700">Clear</a>
            @endif
        </form>

        <x-panel>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach ([['Reference','left'],['Date','left'],['Company','left'],['Status','left'],['Delivery','left'],['Items','right'],['Discount','right'],['Total','right'],['By','left']] as [$h,$align])
                                <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first ? 'sm:px-6' : '' }}">{{ $h }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($orders as $order)
                            <tr>
                                <td class="px-3 py-2 sm:px-6">
                                    <a href="{{ route('businesses.orders.show', [$business, $order]) }}"
                                       class="font-mono font-medium text-gray-900 hover:text-emerald-700">{{ $order->reference }}</a>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-gray-600">{{ $order->business_date->format('j M Y') }}</td>
                                <td class="px-3 py-2 text-gray-900">{{ $order->company->name }}</td>
                                <td class="px-3 py-2"><x-badge :classes="$order->status->badgeClasses()">{{ $order->status->label() }}</x-badge></td>
                                <td class="whitespace-nowrap px-3 py-2 text-sm">
                                    @if ($order->isOutstanding())
                                        <span class="text-sky-700">
                                            waiting {{ $order->daysWaiting() }} {{ Str::plural('day', $order->daysWaiting()) }}
                                        </span>
                                    @elseif ($order->status === App\Enums\OrderStatus::Received)
                                        @php($exceptions = $order->deliveryExceptions()->count())
                                        @if ($exceptions === 0)
                                            <span class="text-emerald-700">in full</span>
                                        @else
                                            <span class="text-amber-700">
                                                {{ $exceptions }} {{ Str::plural('difference', $exceptions) }}
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-600">{{ $order->lines->count() }}</td>
                                <td class="px-3 py-2 text-right text-gray-600">
                                    {{ $order->discount_percent ? rtrim(rtrim($order->discount_percent, '0'), '.') . '%' : '—' }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono font-semibold tabular-nums text-gray-900">{{ $order->total()->format() }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $order->creator->name }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-6 py-10 text-center text-gray-500">
                                    @if (array_filter($filters))
                                        No order forms match that.
                                    @else
                                        No order forms yet. Write one and send it as a PDF or an image.
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
