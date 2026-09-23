<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-gray-900">Daily entries</h1>
            <a href="{{ route('businesses.daily.create', $business) }}"
               class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                Enter a day
            </a>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        <x-panel>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach (['Date', 'Status', 'Purchases', 'Sales', 'Cash received', 'Gross profit', 'Recovered (earlier credit)', 'Paid to companies', 'Expenses', 'Net profit', 'Entered by'] as $i => $head)
                                <th class="px-4 py-2 {{ $i >= 2 && $i <= 9 ? 'text-right' : 'text-left' }} text-xs font-semibold uppercase tracking-wide text-gray-500 {{ $loop->first || $loop->last ? 'sm:px-6' : '' }}">
                                    {{ $head }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($entries as $entry)
                            <tr>
                                <td class="px-4 py-2 sm:px-6">
                                    <a href="{{ route('businesses.daily.show', [$business, $entry]) }}"
                                       class="font-medium text-gray-900 hover:text-emerald-700">
                                        {{ $entry->business_date->format('D d M Y') }}
                                    </a>
                                </td>
                                <td class="px-4 py-2">
                                    <x-badge :classes="$entry->status->badgeClasses()">{{ $entry->status->label() }}</x-badge>
                                </td>
                                @foreach ([
                                    $entry->totalPurchases(), $entry->totalSales(), $entry->sale_cash, $entry->gross_profit,
                                    // Paid to companies: the purchases "paid" box, plus
                                    // the older payment field for days entered with it.
                                    $entry->collection_cash, $entry->purchase_paid->plus($entry->company_payment_cash),
                                    $entry->expenses_cash, $entry->netProfit(),
                                ] as $value)
                                    <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-700">{{ $value->format() }}</td>
                                @endforeach
                                <td class="px-4 py-2 text-gray-500 sm:px-6">{{ $entry->creator->name }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="11" class="px-6 py-10 text-center text-gray-500">No days entered yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-panel>

        <div class="mt-4">{{ $entries->links() }}</div>
    </div>
</x-workspace-layout>
