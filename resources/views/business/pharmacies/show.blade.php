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
                <h1 class="text-xl font-semibold text-gray-900">{{ $pharmacy->name }}</h1>
                <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-500">
                    <span class="font-mono text-xs">{{ $pharmacy->account?->code ?? 'no ledger' }}</span>
                    @if ($pharmacy->area) <span>{{ $pharmacy->area }}</span> @endif
                    @if ($pharmacy->phone) <span>{{ $pharmacy->phone }}</span> @endif
                    @if ($pharmacy->contact_person) <span>{{ $pharmacy->contact_person }}</span> @endif
                    <span>{{ $pharmacy->credit_days ? $pharmacy->credit_days . ' days credit' : 'no agreed credit days' }}</span>
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
                <a href="{{ route('businesses.pharmacies.index', $business) }}"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    All customers
                </a>
            </div>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8" x-data="collections()"
         x-on:collect-from.window="collect($event.detail)">
        <x-flash />

        {{-- What they owe, and how much of it is late. --}}
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-gray-400">Owes now</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums {{ $balance->isNegative() ? 'text-emerald-800' : 'text-gray-900' }}">
                    {{ $rs($balance) }}
                </p>
                @if ($balance->isNegative())
                    <p class="mt-0.5 text-xs text-emerald-700">paid ahead — this is credit in their favour</p>
                @endif
            </div>

            <div class="rounded-md border px-4 py-3 shadow-sm {{ $overdue->isPositive() ? 'border-red-200 bg-red-50' : 'border-gray-200 bg-white' }}">
                <p class="text-xs uppercase tracking-wide {{ $overdue->isPositive() ? 'text-red-700' : 'text-gray-400' }}">Overdue</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums {{ $overdue->isPositive() ? 'text-red-800' : 'text-gray-400' }}">
                    {{ $rs($overdue) }}
                </p>
                <p class="mt-0.5 text-xs {{ $overdue->isPositive() ? 'text-red-700' : 'text-gray-400' }}">
                    past {{ $pharmacy->credit_days ? $pharmacy->credit_days . ' days' : 'the day of sale' }}
                </p>
            </div>

            <div class="rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-gray-400">Unpaid bills</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $open->count() }}</p>
                <p class="mt-0.5 text-xs text-gray-500">
                    {{ $open->isEmpty() ? 'nothing outstanding' : 'oldest ' . $open->max('age') . ' ' . Str::plural('day', $open->max('age')) }}
                </p>
            </div>

            <div class="rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-gray-400">Last payment</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-gray-900">
                    {{ $lastPaid ? $rs($lastPaid->credit) : '—' }}
                </p>
                <p class="mt-0.5 text-xs text-gray-500">
                    {{ $lastPaid ? $lastPaid->business_date->format('j M Y') : 'never paid' }}
                </p>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            {{-- What the balance is made of. --}}
            <div class="xl:col-span-1">
                <x-panel title="Still owed, bill by bill">
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

                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($open as $row)
                                <tr>
                                    <td class="px-4 py-2 sm:px-6">
                                        <a href="{{ route('businesses.pos.receipt', [$business, $row['bill']]) }}"
                                           class="font-mono text-xs font-medium text-gray-900 hover:text-emerald-700">{{ $row['bill']->reference() }}</a>
                                        <p class="text-xs text-gray-500">
                                            {{ $row['bill']->business_date->format('j M Y') }} ·
                                            {{ $row['age'] === 0 ? 'today' : $row['age'] . ' ' . Str::plural('day', $row['age']) }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900 sm:px-6">
                                        {{ $rs($row['owed']) }}
                                        @unless ($row['owed']->equals($row['bill']->total))
                                            <span class="block text-xs font-normal text-gray-400">of {{ $rs($row['bill']->total) }}</span>
                                        @endunless
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="px-6 py-8 text-center text-sm text-gray-500">
                                        Nothing outstanding on a bill.
                                        @unless ($balance->isZero())
                                            <span class="mt-1 block text-xs">
                                                The {{ $rs($balance) }} balance is older credit that was never tied to a bill.
                                            </span>
                                        @endunless
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-panel>
            </div>

            {{-- And how it got there. --}}
            <div class="xl:col-span-2">
                <x-panel>
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 sm:px-6">
                        <div>
                            <h2 class="text-sm font-semibold text-gray-900">Statement</h2>
                            <p class="text-xs text-gray-500">
                                {{ $from->format('j M Y') }} to {{ $to->format('j M Y') }}
                            </p>
                        </div>

                        <form method="GET" class="flex flex-wrap items-center gap-2 text-sm">
                            <input type="date" name="from" value="{{ $from->toDateString() }}"
                                   class="rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                            <span class="text-gray-400">to</span>
                            <input type="date" name="to" value="{{ $to->toDateString() }}"
                                   class="rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                            <button class="rounded-md bg-gray-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-800">Show</button>
                            <a href="{{ route('businesses.pharmacies.show', [$business, $pharmacy, 'period' => 'all']) }}"
                               class="text-sm {{ $period === 'all' ? 'font-semibold text-emerald-700' : 'text-gray-500 hover:text-gray-800' }}">Everything</a>
                            {{-- Corrections are hidden, not deleted: a day amended five
                                 times would otherwise show the same sale five times. --}}
                            <a href="{{ route('businesses.pharmacies.show', array_filter([
                                    'business' => $business, 'pharmacy' => $pharmacy,
                                    'from' => $from->toDateString(), 'to' => $to->toDateString(),
                                    'period' => $period, 'corrections' => $showReversals ? null : 1,
                               ])) }}"
                               class="text-sm {{ $showReversals ? 'font-semibold text-emerald-700' : 'text-gray-500 hover:text-gray-800' }}">
                                {{ $showReversals ? 'Hide corrections' : 'Show corrections' }}
                            </a>
                        </form>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    @foreach (['Date', 'What happened', 'Charged', 'Paid', 'Balance'] as $h)
                                        <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ in_array($h, ['Date','What happened']) ? 'text-left' : 'text-right' }} {{ $loop->first || $loop->last ? 'sm:px-6' : '' }}">{{ $h }}</th>
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
                                            Nothing on this account between those dates.
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
