{{--
    One customer's account.

    Three questions in order: what do they owe, what is it made of, and how did
    it get there. The statement at the bottom is the ledger itself — every entry
    on their own account, never a summary of one.
--}}
@php($rs = fn ($m) => $m->format())

<x-workspace-layout :business="$business">
    @push('scripts')
        @include('business.collections.partials.script')
    @endpush

    <x-slot name="header">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <x-back-link :href="route('businesses.pharmacies.index', $business)" :label="'All customers'" />
                <h1 class="text-xl font-semibold text-gray-900">{{ $pharmacy->name }}</h1>
                <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-500">
                    <span class="font-mono text-xs">{{ $pharmacy->account?->code ?? 'No ledger' }}</span>
                    @if ($pharmacy->area) <span>{{ $pharmacy->area }}</span> @endif
                    @if ($pharmacy->phone) <span>{{ $pharmacy->phone }}</span> @endif
                    @if ($pharmacy->contact_person) <span>{{ $pharmacy->contact_person }}</span> @endif
                    <span>{{ $pharmacy->credit_days ? $pharmacy->credit_days . ' days credit' : 'No credit days set' }}</span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                @if ($open->isNotEmpty())
                    <button type="button" @disabled($dayClosed) x-data
                            x-on:click="$dispatch('collect-from', @js($collectable))"
                            class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-40">
                        Collect
                    </button>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8" x-data="collections()"
         x-on:collect-from.window="collect($event.detail)">
        <x-flash />

        {{-- Where they stand: what they have bought, what has come back in,
             what is still out — amber while anything is — and how much of
             that is late. --}}
        @php($owes = $balance->isPositive())
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <x-stat label="Total sales" :value="'Rs. '.$rs($bought)" :hint="$bills->count().' '.Str::plural('bill', $bills->count())" />
            <x-stat label="Total paid" :value="'Rs. '.$rs($bought->minus($owingOnBills))" hint="Against this customer's bills" />

            <div @class(['rounded-lg border px-4 py-4', 'border-amber-300 bg-amber-50' => $owes, 'border-line bg-white' => ! $owes])>
                <dt @class(['text-xs font-medium uppercase tracking-wide', 'text-amber-800' => $owes, 'text-gray-500' => ! $owes])>Pending amount</dt>
                <dd @class(['mt-1 text-xl font-semibold tabular-nums xl:text-2xl', 'text-amber-700' => $owes, 'text-emerald-800' => $balance->isNegative(), 'text-gray-900' => $balance->isZero()])>Rs. {{ $rs($balance) }}</dd>
                <p @class(['mt-1 text-xs', 'font-medium text-amber-700' => $owes, 'text-gray-500' => ! $owes])>
                    @if ($balance->isNegative())
                        Paid in advance. This is credit for the customer.
                    @elseif ($open->isEmpty())
                        {{ $owes ? 'Older credit, not linked to a bill' : 'No pending amount' }}
                    @else
                        {{ $open->count() }} unpaid {{ Str::plural('bill', $open->count()) }} · oldest {{ $open->max('age') }} {{ Str::plural('day', $open->max('age')) }}
                    @endif
                </p>
            </div>

            <div @class(['rounded-lg border px-4 py-4', 'border-red-200 bg-red-50' => $overdue->isPositive(), 'border-line bg-white' => ! $overdue->isPositive()])>
                <dt @class(['text-xs font-medium uppercase tracking-wide', 'text-red-700' => $overdue->isPositive(), 'text-gray-500' => ! $overdue->isPositive()])>Overdue</dt>
                <dd @class(['mt-1 text-xl font-semibold tabular-nums xl:text-2xl', 'text-red-800' => $overdue->isPositive(), 'text-gray-400' => ! $overdue->isPositive()])>Rs. {{ $rs($overdue) }}</dd>
                <p @class(['mt-1 text-xs', 'text-red-700' => $overdue->isPositive(), 'text-gray-500' => ! $overdue->isPositive()])>
                    Past {{ $pharmacy->credit_days ? $pharmacy->credit_days . ' days' : 'the date of sale' }}
                </p>
            </div>

            <x-stat label="Last payment" :value="$lastPaid ? 'Rs. '.$rs($lastPaid->credit) : '—'"
                    :hint="$lastPaid ? $lastPaid->business_date->format('j M Y') : 'No payment yet'" />
        </div>

        <div class="grid gap-6 xl:grid-cols-5">
            {{-- What the balance is made of. --}}
            <div class="xl:col-span-2">
                <x-panel title="This customer's bills" description="All bills, newest first. Pending amounts are shown in amber.">
                    @if ($ageing->isNotEmpty())
                        <div class="flex flex-wrap gap-x-6 gap-y-2 border-b border-gray-100 px-4 py-3 text-sm sm:px-6">
                            @foreach (['This week', '8–30 days', 'Over 30 days'] as $band)
                                @if ($ageing->has($band))
                                    <div>
                                        <p class="text-xs uppercase tracking-wide {{ $band === 'Over 30 days' ? 'text-red-600' : 'text-gray-400' }}">{{ $band }}</p>
                                        <p class="font-mono text-sm font-semibold tabular-nums text-gray-900">{{ $rs($ageing[$band]) }}</p>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    {{-- Ten bills high; the rest scroll inside. --}}
                    <div class="max-h-[25.25rem] overflow-y-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="sticky top-0 bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Bill</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Date</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Items</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Total</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Pending</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse ($bills as $bill)
                                    <tr class="hover:bg-gray-50">
                                        <td class="whitespace-nowrap px-4 py-2 sm:px-6">
                                            <a href="{{ route('businesses.pos.bills.edit', [$business, $bill]) }}"
                                               class="font-mono font-medium text-gray-900 hover:text-emerald-700">{{ $bill->reference() }}</a>
                                            @if ($bill->returns->isNotEmpty())
                                                <span class="ml-1 text-xs text-gray-500">returned</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2 text-gray-600">{{ $bill->business_date->format('j M Y') }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums text-gray-600">{{ $bill->lines_count }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums text-gray-900">{{ $rs($bill->total) }}</td>
                                        <td @class(['whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums sm:px-6', 'font-semibold text-amber-700' => $bill->owing->isPositive(), 'text-gray-400' => ! $bill->owing->isPositive()])>
                                            {{ $bill->owing->isPositive() ? $rs($bill->owing) : 'Paid' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500">
                                            No bills yet.
                                            @unless ($balance->isZero())
                                                <span class="mt-1 block text-xs">
                                                    The {{ $rs($balance) }} balance is older credit that is not linked to a bill.
                                                </span>
                                            @endunless
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-panel>
            </div>

            {{-- And how it got there. --}}
            <div class="xl:col-span-3">
                <x-panel>
                    {{-- The heading on its own line, the dates under it across
                         the whole width: side by side they crushed each other.
                         From and To become one calendar, as on every list. --}}
                    <div class="border-b border-gray-100 px-4 py-3 sm:px-6">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <h2 class="text-sm font-semibold text-gray-900">Statement</h2>
                            <p class="whitespace-nowrap text-sm text-gray-600">
                                {{ $period === 'all' ? 'All entries, ' : '' }}{{ $from->format('j M Y') }} to {{ $to->format('j M Y') }}
                            </p>
                        </div>

                        <x-filter-bar submit="Show" class="mt-3">
                            <div>
                                <x-input-label for="statement_from" value="From" />
                                <input id="statement_from" type="date" name="from" value="{{ $from->toDateString() }}"
                                       class="mt-1 rounded-md border-gray-300 text-sm shadow-sm">
                            </div>
                            <div>
                                <x-input-label for="statement_to" value="To" />
                                <input id="statement_to" type="date" name="to" value="{{ $to->toDateString() }}"
                                       class="mt-1 rounded-md border-gray-300 text-sm shadow-sm">
                            </div>
                            <div class="flex !min-w-0 !flex-none items-center gap-2">
                                <a href="{{ route('businesses.pharmacies.show', [$business, $pharmacy, 'period' => 'all']) }}"
                                   class="rounded-md border px-3 py-2 text-sm font-medium {{ $period === 'all' ? 'border-emerald-700 bg-emerald-50 text-emerald-800' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">All entries</a>
                                {{-- Corrections are hidden, not deleted: a day amended five
                                     times would otherwise show the same sale five times. --}}
                                <a href="{{ route('businesses.pharmacies.show', array_filter([
                                        'business' => $business, 'pharmacy' => $pharmacy,
                                        'from' => $from->toDateString(), 'to' => $to->toDateString(),
                                        'period' => $period, 'corrections' => $showReversals ? null : 1,
                                   ])) }}"
                                   class="rounded-md border px-3 py-2 text-sm font-medium {{ $showReversals ? 'border-emerald-700 bg-emerald-50 text-emerald-800' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                                    {{ $showReversals ? 'Hide corrections' : 'Show corrections' }}
                                </a>
                            </div>
                        </x-filter-bar>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    @foreach (['Date', 'Details', 'Charged', 'Paid', 'Balance'] as $h)
                                        <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ in_array($h, ['Date','Details']) ? 'text-left' : 'text-right' }} {{ $loop->first || $loop->last ? 'sm:px-6' : '' }}">{{ $h }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                {{-- Everything before this period, in one line. Without it a
                                     statement of one month reads as the whole account. --}}
                                <tr class="bg-gray-50/70">
                                    <td class="whitespace-nowrap px-4 py-2 text-gray-500 sm:px-6">{{ $from->copy()->subDay()->format('d M Y') }}</td>
                                    <td class="px-4 py-2 font-medium text-gray-600">Balance brought forward</td>
                                    <td class="px-4 py-2"></td>
                                    <td class="px-4 py-2"></td>
                                    <td class="px-4 py-2 text-right font-mono font-semibold tabular-nums text-gray-700 sm:px-6">{{ $rs($broughtForward) }}</td>
                                </tr>

                                @forelse ($rows as $row)
                                    @php($entry = $row['entry'])
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-2 text-gray-600 sm:px-6">{{ $entry->business_date->format('d M Y') }}</td>
                                        <td class="px-4 py-2">
                                            <span class="font-medium text-gray-900">
                                                {{ $entry->transaction->narration ?: $entry->transaction->type->label() }}
                                            </span>
                                            <p class="text-xs text-gray-500">
                                                {{ $entry->transaction->type->label() }} · #{{ $entry->transaction->id }}
                                                · {{ $entry->transaction->creator->name }}
                                                @if ($entry->transaction->reversal_of_id)
                                                    <span class="font-medium text-red-700">· reversal</span>
                                                @elseif ($entry->transaction->reversed_by_id)
                                                    <span class="font-medium text-amber-700">· later reversed</span>
                                                @endif
                                            </p>
                                        </td>
                                        {{-- Charged and Paid rather than Debit and Credit: the
                                             people reading this are shopkeepers, not bookkeepers. --}}
                                        <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900">{{ $entry->debit->isZero() ? '' : $rs($entry->debit) }}</td>
                                        <td class="px-4 py-2 text-right font-mono tabular-nums text-emerald-800">{{ $entry->credit->isZero() ? '' : $rs($entry->credit) }}</td>
                                        <td class="px-4 py-2 text-right font-mono font-semibold tabular-nums sm:px-6">{{ $rs($row['running']) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                                            No entries on this account between these dates.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($entries?->hasPages())
                        <div class="border-t border-gray-100 px-4 py-3 sm:px-6">{{ $entries->links() }}</div>
                    @endif
                </x-panel>
            </div>
        </div>

        @include('business.collections.partials.collect')
    </div>
</x-workspace-layout>
