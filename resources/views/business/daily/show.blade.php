{{-- Two views of the day: the entry itself, and who has changed it. --}}
@php($activeTab = request('tab') === 'history' ? 'history' : 'day')
<x-workspace-layout :business="$business">
    @push('scripts')
        @include('business.collections.partials.script')
    @endpush

    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-xl font-semibold text-gray-900">
                        {{ $entry->business_date->format('D d M Y') }}
                    </h1>
                    <x-badge :classes="$entry->status->badgeClasses()">{{ $entry->status->label() }}</x-badge>
                </div>
                <p class="mt-1 text-sm text-gray-500">
                    Entered by {{ $entry->creator->name }} on {{ $entry->created_at->timezone($business->timezone)->format('d M Y, h:i A') }}
                    @if ($entry->poster) · posted by {{ $entry->poster->name }} @endif
                </p>
            </div>
            <div class="flex gap-2">
                @can('update', $entry)
                    <a href="{{ route('businesses.daily.create', [$business, 'date' => $entry->business_date->toDateString()]) }}"
                       class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        {{ $entry->isEditable() ? 'Edit draft' : 'Edit day' }}
                    </a>
                @else
                    @if ($business->isDayClosed($entry->business_date))
                        <span class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-500"
                              title="Reopen the day, or post an adjustment in the open period">
                            Closed — not editable
                        </span>
                    @endif
                @endcan
                <a href="{{ route('businesses.daily.index', $business) }}"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    All days
                </a>
            </div>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8" x-data="collections()">
        <x-flash />

        @include('business.partials.page-tabs', [
            'tabs' => ['day' => 'Day entry', 'history' => 'Change history'],
            'active' => $activeTab,
            'counts' => ['history' => $history->count()],
        ])

        @if ($activeTab === 'history')
            @include('business.partials.change-history')
        @else
        @foreach ($warnings as $warning)
            <div class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ $warning }}
            </div>
        @endforeach

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <x-panel :title="$entry->isEditable() ? 'Resulting position (preview — nothing posted yet)' : 'Position after this day'">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Balance</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Before</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Change</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">After</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($rows as $row)
                                    <tr>
                                        <td class="px-4 py-2 text-gray-900 sm:px-6">
                                            {{ $row['label'] }}
                                            <span class="ml-1 font-mono text-xs text-gray-400">{{ $row['code'] }}</span>
                                        </td>
                                        <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-500">{{ $row['before']->format() }}</td>
                                        <td class="px-4 py-2 text-right font-mono tabular-nums {{ $row['good'] ? 'text-emerald-700' : 'text-red-700' }}">
                                            {{ $row['delta']->isPositive() ? '▲' : ($row['delta']->isZero() ? '—' : '▼') }}
                                            {{ $row['delta']->absolute()->format() }}
                                        </td>
                                        <td class="px-4 py-2 text-right font-mono font-semibold tabular-nums sm:px-6 {{ $row['after']->isNegative() ? 'text-red-700' : 'text-gray-900' }}">
                                            {{ $row['after']->format() }}
                                        </td>
                                    </tr>
                                @endforeach
                                <tr class="border-t-2 border-gray-300 bg-gray-50 font-semibold">
                                    <td class="px-4 py-2 text-gray-900 sm:px-6">Net position</td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-500">{{ $netBefore->format() }}</td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums {{ $entry->netProfit()->isNegative() ? 'text-red-700' : 'text-emerald-700' }}">
                                        {{ $entry->netProfit()->format() }}
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums sm:px-6 {{ $netAfter->isNegative() ? 'text-red-700' : 'text-gray-900' }}">
                                        {{ $netAfter->format() }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </x-panel>


                @if ($entry->purchaseLines->isNotEmpty() || $entry->saleLines->isNotEmpty() || $entry->expenseLines->isNotEmpty() || $entry->collectionLines->isNotEmpty())
                    {{-- The named detail beneath the day's totals. Absent when the
                         day was entered as plain figures, which is a valid way to
                         enter it, not a gap. --}}
                    <x-panel title="Invoice detail" class="mt-6">
                        @if ($entry->purchaseLines->isNotEmpty())
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            @foreach (['Bought from', 'Invoice', 'Amount', 'Paid', 'Still owed'] as $h)
                                                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ in_array($h, ['Bought from','Invoice']) ? 'text-left' : 'text-right' }} {{ $loop->first || $loop->last ? 'sm:px-6' : '' }}">{{ $h }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($entry->purchaseLines as $line)
                                            <tr>
                                                <td class="px-4 py-2 sm:px-6">
                                                    @if ($line->company)
                                                        <a href="{{ route('businesses.companies.show', [$business, $line->company]) }}"
                                                           class="font-medium text-gray-900 hover:text-emerald-700">{{ $line->company->name }}</a>
                                                    @else
                                                        <span class="text-gray-900">{{ $line->company_name ?? 'Unnamed company' }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-2 font-mono text-xs text-gray-500">{{ $line->invoice_no ?? '—' }}</td>
                                                <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900">{{ $line->amount->format() }}</td>
                                                <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-600">{{ $line->paid->format() }}</td>
                                                @php($owed = $line->pending())
                                                <td class="px-4 py-2 text-right font-mono tabular-nums sm:px-6 {{ $owed->isNegative() ? 'text-emerald-800' : 'text-gray-900' }}">
                                                    {{ $owed->isNegative() ? $owed->absolute()->format() . ' off earlier bills' : $owed->format() }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        @if ($entry->saleLines->isNotEmpty())
                            <div class="overflow-x-auto {{ $entry->purchaseLines->isNotEmpty() ? 'border-t border-gray-200' : '' }}">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            @foreach (['Sold to', 'Invoice', 'Amount', 'Received', 'On credit', ''] as $h)
                                                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ in_array($h, ['Sold to','Invoice']) ? 'text-left' : 'text-right' }} {{ $loop->first || $loop->last ? 'sm:px-6' : '' }}">{{ $h }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($entry->saleLines as $line)
                                            <tr>
                                                <td class="px-4 py-2 sm:px-6">
                                                    @if ($line->pharmacy)
                                                        <a href="{{ route('businesses.pharmacies.show', [$business, $line->pharmacy]) }}"
                                                           class="font-medium text-gray-900 hover:text-emerald-700">{{ $line->pharmacy->name }}</a>
                                                    @else
                                                        <span class="text-gray-900">{{ $line->pharmacy_name ?? 'Unnamed pharmacy' }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-2 font-mono text-xs text-gray-500">{{ $line->invoice_no ?? '—' }}</td>
                                                <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900">{{ $line->amount->format() }}</td>
                                                <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-600">{{ $line->received->format() }}</td>
                                                {{-- Negative means they handed over more than the invoice: a
                                                     recovery against earlier credit, not a bigger sale. --}}
                                                @php($credit = $line->outstanding())
                                                <td class="px-4 py-2 text-right font-mono tabular-nums {{ $credit->isNegative() ? 'text-emerald-800' : 'text-gray-900' }}">
                                                    {{ $credit->isNegative() ? $credit->absolute()->format() . ' off earlier credit' : $credit->format() }}
                                                </td>
                                                {{-- Money that comes back later is a new event on the day it
                                                     arrives, never an edit of this one — so collecting here
                                                     writes today's entry, and this day is left alone. --}}
                                                @php($open = $collectable[$line->invoice_no ?? ''] ?? null)
                                                <td class="px-4 py-2 text-right sm:px-6">
                                                    @if ($credit->isPositive() && $open !== null && $open['owed']->isPositive())
                                                        <button type="button" @disabled($dayClosed)
                                                                x-on:click="collect(@js($open['customer']))"
                                                                class="rounded-md bg-emerald-700 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-40">
                                                            Collect
                                                        </button>
                                                    @elseif ($credit->isPositive() && $open !== null)
                                                        {{-- Paid off since, by a collection on this or a later day. --}}
                                                        <span class="text-xs font-medium text-emerald-700" title="Settled by a later collection">Collected ✓</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        {{-- Where the day's collection figure came from. Recorded on
                             the Collection screen, shown here because this entry is
                             the document those postings belong to. --}}
                        @if ($entry->collectionLines->isNotEmpty())
                            <div class="overflow-x-auto border-t border-gray-200">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Collected from</th>
                                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Against</th>
                                            <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($entry->collectionLines as $line)
                                            <tr>
                                                <td class="px-4 py-2 sm:px-6">
                                                    @if ($line->pharmacy)
                                                        <a href="{{ route('businesses.pharmacies.show', [$business, $line->pharmacy]) }}"
                                                           class="font-medium text-gray-900 hover:text-emerald-700">{{ $line->pharmacy->name }}</a>
                                                    @else
                                                        <span class="text-gray-900">{{ $line->label() }}</span>
                                                    @endif
                                                    @if ($line->note)
                                                        <span class="block text-xs text-gray-500">{{ $line->note }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-2 text-xs text-gray-600">
                                                    @forelse ($line->allocations as $allocation)
                                                        <span class="font-mono">{{ $allocation->bill?->reference() ?? '—' }}</span>
                                                        <span class="tabular-nums text-gray-500">{{ $allocation->amount->format() }}</span>@unless ($loop->last), @endunless
                                                    @empty
                                                        <span class="text-gray-400">on account</span>
                                                    @endforelse
                                                    @unless ($line->onAccount()->isZero())
                                                        <span class="block text-gray-500">{{ $line->onAccount()->format() }} left on account</span>
                                                    @endunless
                                                </td>
                                                <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900 sm:px-6">{{ $line->amount->format() }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        @if ($entry->expenseLines->isNotEmpty())
                            <div class="overflow-x-auto {{ $entry->purchaseLines->isNotEmpty() || $entry->saleLines->isNotEmpty() ? 'border-t border-gray-200' : '' }}">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            @foreach (['Spent on', 'Description', 'Amount'] as $h)
                                                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ $h === 'Amount' ? 'text-right' : 'text-left' }} {{ $loop->first || $loop->last ? 'sm:px-6' : '' }}">{{ $h }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($entry->expenseLines as $line)
                                            <tr>
                                                <td class="px-4 py-2 sm:px-6">
                                                    @if ($line->category)
                                                        <a href="{{ route('businesses.expenses.show', [$business, $line->category]) }}"
                                                           class="font-medium text-gray-900 hover:text-emerald-700">{{ $line->category->name }}</a>
                                                    @else
                                                        <span class="text-gray-900">Uncategorised</span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-2 text-gray-600">{{ $line->description ?? '—' }}</td>
                                                <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900 sm:px-6">{{ $line->amount->format() }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </x-panel>
                @endif
            </div>

            <div class="space-y-6">
                <x-panel title="The day as entered">
                    <dl class="divide-y divide-gray-100 text-sm">
                        @foreach ([
                            'Total purchases' => $entry->totalPurchases(),
                            'Cost added to stock' => $entry->costAddedToStock(),
                            'Total sales' => $entry->totalSales(),
                            // The split the day was entered with, so the cash taken
                            // at the counter is visible here and not only in the
                            // ledger — it is not a "collection", which is earlier credit.
                            '— cash received on these sales' => $entry->sale_cash,
                            '— sold on credit' => $entry->sale_credit,
                            'Sales returns' => $entry->sales_return,
                            'Cost of goods sold' => $entry->costOfGoodsSold(),
                            'Gross profit' => $entry->gross_profit,
                            'Expenses' => $entry->expenses_cash,
                            'Net profit' => $entry->netProfit(),
                            // Split by the date of the bills it actually paid: money
                            // back the same day the goods went out is not "earlier
                            // credit", and saying so made the day read as if it had
                            // sold twice.
                            'Collected on today\'s bills' => $entry->collectedOnSameDay(),
                            'Collected on earlier credit' => $entry->collectedOnEarlier(),
                            // Everything paid to companies today, and the part of it
                            // that settled earlier bills rather than today's buying.
                            // Payments against earlier bills are entered in the
                            // purchases "paid" box now; company_payment_cash is the
                            // older field, still counted for days entered with it.
                            'Paid to companies' => $entry->purchase_paid->plus($entry->company_payment_cash),
                            '— against earlier bills' => $entry->purchaseExcess()->plus($entry->company_payment_cash),
                        ] as $label => $value)
                            <div class="flex justify-between px-4 py-2 sm:px-6 {{ str_contains($label, 'profit') ? 'font-semibold' : '' }}">
                                <dt class="text-gray-600">{{ $label }}</dt>
                                <dd class="font-mono tabular-nums text-gray-900">{{ $value->format() }}</dd>
                            </div>
                        @endforeach
                        @if ($entry->marginPercent() !== null)
                            <div class="flex justify-between px-4 py-2 sm:px-6">
                                <dt class="text-gray-600">Margin</dt>
                                <dd class="font-mono tabular-nums text-gray-900">{{ number_format($entry->marginPercent(), 2) }}%</dd>
                            </div>
                        @endif
                    </dl>
                </x-panel>

                @can('post', $entry)
                    <x-panel title="Post this day">
                        <form method="POST" action="{{ route('businesses.daily.post', [$business, $entry]) }}" class="space-y-3 p-4 sm:p-6">
                            @csrf
                            <p class="text-sm text-gray-600">
                                Posting writes {{ count($rows) }} balances into the ledger. Afterwards the day
                                cannot be edited — only reversed and re-entered.
                            </p>
                            <x-input-error :messages="$errors->get('status')" />
                            <button class="w-full rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                                Post day
                            </button>
                        </form>
                    </x-panel>
                @endcan

                @can('reverse', $entry)
                    <x-panel title="Reverse this day">
                        <form method="POST" action="{{ route('businesses.daily.reverse', [$business, $entry]) }}" class="space-y-3 p-4 sm:p-6">
                            @csrf
                            <x-input-label for="reason" value="Reason" />
                            <x-text-input id="reason" name="reason" type="text" class="block w-full"
                                          placeholder="Entered twice — same figures posted yesterday" required />
                            <x-input-error :messages="$errors->get('reason')" />
                            <button class="w-full rounded-md border border-red-300 bg-white px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">
                                Reverse day
                            </button>
                            <p class="text-xs text-gray-500">
                                Both the original and the reversal stay visible. Nothing is deleted.
                            </p>
                        </form>
                    </x-panel>
                @endcan

                @if ($entry->notes)
                    <x-panel title="Notes">
                        <p class="whitespace-pre-line p-4 text-sm text-gray-700 sm:p-6">{{ $entry->notes }}</p>
                    </x-panel>
                @endif
            </div>
        </div>
        @endif

        {{-- Collecting against an invoice on this day. It writes today's entry,
             never this one — which is why it is a dialog and not a field. --}}
        @include('business.collections.partials.collect')
    </div>
</x-workspace-layout>
