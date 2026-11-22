{{--
    One bill after the sale, to read: the bill as it prints, beside the customer's
    standing and what has happened to the bill since. Nothing is changed here — a correction is made at the till, which
    loads the bill back (Edit), and each one is listed below with what changed
    and why.
--}}
@php($rs = fn ($m) => $m->format())
<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Bill {{ $bill->reference() }}</h1>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $bill->business_date->format('l j F Y') }} · {{ $bill->customer_name ?? 'Cash sale' }} ·
                    rung up by {{ $bill->creator->name }}@if ($bill->device) on {{ $bill->device->label() }}@endif
                </p>
            </div>
            <div class="flex items-center gap-2">
                @if ($canEdit && $blocked === null)
                    <a href="{{ route('businesses.pos.index', [$business, 'edit' => $bill->id]) }}"
                       class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Edit at the till</a>
                @endif
                <a href="{{ route('businesses.pos.receipt', [$business, $bill]) }}"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">View / print</a>
                @can('returnAtPos', $business)
                    <a href="{{ route('businesses.pos.bills.return', [$business, $bill]) }}"
                       class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Take goods back</a>
                @endcan
            </div>
        </div>
    </x-slot>

    {{-- Two columns. On the left, this bill and nothing else, drawn the way it
         prints. On the right, what surrounds it: the customer's standing and
         every bill of theirs, then what has happened to this bill since. --}}
    @php($hasCustomer = $customerBills->isNotEmpty())
    <div class="w-full px-4 py-8 sm:px-6 lg:px-8 xl:grid xl:grid-cols-2 xl:items-start xl:gap-6">

        {{-- The bill, as printed. --}}
        <div class="min-w-0 space-y-4 xl:sticky xl:top-4">
            <x-flash />

            @if ($canEdit && $blocked !== null)
                <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $blocked }}</div>
            @endif

            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-gray-50 p-4 sm:p-6">
                @include('business.pos.slips.' . ($net ? 'net' : $format->value))
            </div>
        </div>

        <div class="mt-6 min-w-0 space-y-6 xl:mt-0">
            @if ($hasCustomer)
                @php($stillOwed = $customerBills->reduce(fn ($t, $b) => $t->plus($b->owing), \App\Support\Money::zero()))
                @php($bought = $customerBills->reduce(fn ($t, $b) => $t->plus($b->total), \App\Support\Money::zero()))

                {{-- Where the customer stands, across all their bills. --}}
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-stat label="Total sales till now" :value="'Rs. '.$rs($bought)" :hint="$customerBills->count().' '.Str::plural('bill', $customerBills->count())" />
                    <x-stat label="Paid so far" :value="'Rs. '.$rs($bought->minus($stillOwed))" />
                    {{-- Amber while anything is owing, the way the list below marks it. --}}
                    <div @class(['rounded-lg border px-4 py-4', 'border-amber-300 bg-amber-50' => $stillOwed->isPositive(), 'border-line bg-white' => ! $stillOwed->isPositive()])>
                        <dt @class(['text-xs font-medium uppercase tracking-wide', 'text-amber-800' => $stillOwed->isPositive(), 'text-gray-500' => ! $stillOwed->isPositive()])>On credit now</dt>
                        <dd @class(['mt-1 text-xl font-semibold tabular-nums xl:text-2xl', 'text-amber-700' => $stillOwed->isPositive(), 'text-gray-900' => ! $stillOwed->isPositive()])>Rs. {{ $rs($stillOwed) }}</dd>
                        <p @class(['mt-1 text-xs', 'font-medium text-amber-700' => $stillOwed->isPositive(), 'text-gray-500' => ! $stillOwed->isPositive()])>{{ $stillOwed->isPositive() ? 'Still to collect' : 'Nothing owing' }}</p>
                    </div>
                </div>

            <x-panel>
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 sm:px-6">
                    <div class="min-w-0">
                        <h2 class="truncate text-base font-semibold text-gray-900">{{ $bill->customer_name }}</h2>
                        <p class="text-sm text-gray-500">Every bill of theirs, newest first.</p>
                    </div>
                    @if ($bill->pharmacy_id)
                        <a href="{{ route('businesses.pharmacies.show', [$business, $bill->pharmacy_id]) }}"
                           class="shrink-0 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Their account</a>
                    @endif
                </div>

                {{-- Ten bills high; the rest scroll inside. --}}
                <div class="max-h-[25.25rem] overflow-y-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="sticky top-0 bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Bill</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Day</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Lines</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Total</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Owing</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($customerBills as $other)
                                <tr @class(['bg-emerald-50' => $other->is($bill), 'hover:bg-gray-50' => ! $other->is($bill)])>
                                    <td class="whitespace-nowrap px-4 py-2 sm:px-6">
                                        <a href="{{ route('businesses.pos.bills.edit', [$business, $other]) }}"
                                           class="font-mono font-medium text-gray-900 hover:text-emerald-700">{{ $other->reference() }}</a>
                                        @if ($other->is($bill))
                                            <span class="ml-1 text-xs font-medium text-emerald-700">this bill</span>
                                        @endif
                                        @if ($other->returns->isNotEmpty())
                                            <span class="ml-1 text-xs text-gray-500">returned</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-gray-600">{{ $other->business_date->format('j M Y') }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-gray-600">{{ $other->lines_count }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums text-gray-900">{{ $rs($other->total) }}</td>
                                    <td @class(['whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums sm:px-6', 'font-semibold text-amber-700' => $other->owing->isPositive(), 'text-gray-400' => ! $other->owing->isPositive()])>
                                        {{ $other->owing->isPositive() ? $rs($other->owing) : 'paid' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($customerBills->count() >= 100)
                    <p class="border-t border-gray-200 px-4 py-2 text-xs text-gray-500 sm:px-6">Showing their latest 100 bills.</p>
                @endif
            </x-panel>
            @endif

            {{-- This bill's own money: what was paid then, and since. --}}
            <div>
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">This bill</h2>
                {{-- One strip, not five cards: these are figures to glance at. --}}
                <dl class="grid grid-cols-2 divide-gray-200 rounded-lg border border-line bg-white sm:grid-cols-5 sm:divide-x">
                    @foreach ([
                        ['Total', $bill->total, null, false],
                        ['Received', $bill->received, $bill->paymentMethod?->label() ?? 'Cash', false],
                        ['Collected since', $collected, null, false],
                        ['Returned', $bill->returned(), null, false],
                        ['Still owing', $owing, null, $owing->isPositive()],
                    ] as [$what, $figure, $note, $warn])
                        <div class="px-3 py-2">
                            <dt class="truncate text-[11px] font-medium uppercase tracking-wide text-gray-500">{{ $what }}</dt>
                            <dd @class(['font-mono text-sm font-semibold tabular-nums', 'text-amber-700' => $warn, 'text-gray-900' => ! $warn])>
                                {{ $rs($figure) }}
                                @if ($note) <span class="font-sans text-[11px] font-normal text-gray-500">{{ $note }}</span> @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <x-panel title="History of this bill"
                     description="Every correction, oldest first: what was added, taken off or changed, by whom, and why.">
                <div class="divide-y divide-gray-100">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-3 text-sm sm:px-6">
                        <p><span class="font-medium text-gray-900">Rung up</span> by {{ $bill->creator->name }}</p>
                        <p class="text-xs text-gray-500">{{ $bill->created_at->timezone($business->timezone)->format('D j M Y, h:i A') }}</p>
                    </div>

                    @foreach ($bill->revisions as $revision)
                        <div class="px-4 py-3 sm:px-6">
                            <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                                <p>
                                    <span class="font-medium text-gray-900">Correction {{ $revision->revision_no }}</span>
                                    by {{ $revision->editor->name }} — “{{ $revision->reason }}”
                                </p>
                                <p class="text-xs text-gray-500">{{ $revision->created_at->timezone($business->timezone)->format('D j M Y, h:i A') }}</p>
                            </div>
                            <ul class="mt-2 space-y-1 text-sm">
                                @forelse ($revision->changes as $change)
                                    <li class="flex flex-wrap items-baseline gap-2">
                                        <x-badge :classes="['added' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20', 'removed' => 'bg-red-50 text-red-800 ring-red-600/20', 'changed' => 'bg-amber-50 text-amber-800 ring-amber-600/20'][$change['kind']] ?? 'bg-gray-100 text-gray-700 ring-gray-500/20'">{{ ucfirst($change['kind']) }}</x-badge>
                                        <span class="font-medium text-gray-900">{{ $change['what'] }}</span>
                                        <span class="font-mono text-xs tabular-nums text-gray-600">
                                            @if ($change['kind'] === 'changed') {{ $change['from'] }} → {{ $change['to'] }}
                                            @else {{ $change['to'] ?? $change['from'] }} @endif
                                        </span>
                                    </li>
                                @empty
                                    <li class="text-gray-500">Saved with nothing changed.</li>
                                @endforelse
                            </ul>
                        </div>
                    @endforeach

                    @foreach ($bill->returns as $return)
                        <div class="px-4 py-3 sm:px-6">
                            <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                                <p>
                                    <span class="font-medium text-gray-900">Return {{ $return->reference() }}</span>
                                    taken by {{ $return->creator->name }} — Rs. {{ $rs($return->total) }}
                                    @if ($return->refunded->isPositive()) · Rs. {{ $rs($return->refunded) }} refunded in cash @endif
                                    @if ($return->onAccount()->isPositive()) · Rs. {{ $rs($return->onAccount()) }} off the account @endif
                                    @if ($return->reason) — “{{ $return->reason }}” @endif
                                </p>
                                <p class="text-xs text-gray-500">{{ $return->business_date->format('D j M Y') }}</p>
                            </div>
                            <ul class="mt-2 space-y-1 text-sm text-gray-700">
                                @foreach ($return->lines as $line)
                                    <li>{{ $line->name }} <span class="font-mono text-xs text-gray-500">× {{ $line->quantityLabel() }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach

                    @if ($bill->revisions->isEmpty() && $bill->returns->isEmpty())
                        <p class="px-4 py-3 text-sm text-gray-500 sm:px-6">Unchanged since it was rung up.</p>
                    @endif
                </div>
            </x-panel>
        </div>
    </div>

</x-workspace-layout>
