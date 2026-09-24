{{--
    Money still out with the market.

    Grouped by customer rather than by bill, because collection is a round: the
    van visits a pharmacy, not an invoice. Each customer opens to show what
    they owe bill by bill, oldest first, so the collector knows what to ask for.
--}}
@php($rs = fn ($m) => $m->format())

<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Pending collection</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Bills that went out unpaid or part paid. Cash brought back is recorded
                    against the customer on the day it arrives — the bill itself never changes.
                </p>
            </div>
            <form method="GET" class="flex items-center gap-2">
                <input name="q" type="search" value="{{ $search }}" placeholder="Customer or bill number"
                       class="w-56 rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                <button class="rounded-md bg-gray-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-800">Search</button>
                @if ($search !== '')
                    <a href="{{ route('businesses.collections.index', $business) }}" class="text-sm text-gray-500 hover:text-gray-800">Clear</a>
                @endif
            </form>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8" x-data="collections()">
        <x-flash />

        <div class="mb-6 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-md border border-gray-200 bg-white px-4 py-3 text-sm shadow-sm sm:px-6">
            <p>
                <span class="text-base font-semibold text-gray-900">Rs. {{ $rs($total) }}</span>
                <span class="text-gray-500">on {{ $rounds->sum(fn ($r) => $r['bills']->count()) }} bills, {{ $rounds->count() }} {{ Str::plural('customer', $rounds->count()) }}</span>
            </p>
            {{-- The ledger figure is the larger one where a business started
                 with receivables that never belonged to a bill. Saying so here
                 stops the two numbers looking like a contradiction. --}}
            @unless ($marketTotal->minus($total)->isZero())
                <p class="text-gray-500">
                    The market owes <span class="font-medium text-gray-900">Rs. {{ $rs($marketTotal) }}</span> in all —
                    the difference is older credit not tied to a bill.
                </p>
            @endunless
            @if ($dayClosed)
                <p class="rounded bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800">
                    {{ $today->format('j M') }} is closed — reopen it before recording a collection.
                </p>
            @endif
        </div>

        <div class="space-y-3">
            @forelse ($rounds as $round)
                <div class="overflow-hidden rounded-md border border-gray-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
                        <button type="button" x-on:click="open === @js($round['name']) ? open = null : open = @js($round['name'])"
                                class="flex min-w-0 items-center gap-3 text-left">
                            <span class="text-gray-400" x-text="open === @js($round['name']) ? '▾' : '▸'"></span>
                            <span class="min-w-0">
                                <span class="block truncate font-medium text-gray-900">{{ $round['name'] ?? 'Cash sale' }}</span>
                                <span class="block text-xs text-gray-500">
                                    {{ $round['bills']->count() }} {{ Str::plural('bill', $round['bills']->count()) }} ·
                                    oldest {{ $round['waiting'] }} {{ Str::plural('day', $round['waiting']) }}
                                </span>
                            </span>
                        </button>

                        <div class="flex items-center gap-4">
                            <x-badge :classes="match ($round['band']) {
                                'This week' => 'bg-gray-100 text-gray-700 ring-gray-500/20',
                                '8–30 days' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
                                default => 'bg-red-50 text-red-800 ring-red-600/20',
                            }">{{ $round['band'] }}</x-badge>

                            <span class="font-mono text-base font-semibold tabular-nums text-gray-900">
                                Rs. {{ $rs($round['owed']) }}
                            </span>

                            @if ($round['pharmacy'] !== null)
                                <button type="button" @disabled($dayClosed)
                                        x-on:click="collect(@js([
                                            'pharmacy_id' => $round['pharmacy']->id,
                                            'name' => $round['name'],
                                            'owed' => (float) $round['owed']->toDecimal(),
                                            'bills' => $round['bills']->map(fn ($b) => [
                                                'id' => $b['bill']->id,
                                                'ref' => $b['bill']->reference(),
                                                'owed' => (float) $b['owed']->toDecimal(),
                                                'age' => $b['age'],
                                            ])->all(),
                                        ]))"
                                        class="rounded-md bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-40">
                                    Collect
                                </button>
                            @else
                                <span class="text-xs text-gray-400" title="This bill names no customer account">no account</span>
                            @endif
                        </div>
                    </div>

                    <div x-show="open === @js($round['name'])" x-cloak class="border-t border-gray-100 bg-gray-50/60">
                        <table class="min-w-full text-sm">
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($round['bills'] as $row)
                                    <tr>
                                        <td class="px-4 py-2 font-mono text-xs text-gray-600 sm:px-6">{{ $row['bill']->reference() }}</td>
                                        <td class="px-4 py-2 text-gray-600">{{ $row['bill']->business_date->format('j M Y') }}</td>
                                        <td class="px-4 py-2 text-gray-500">
                                            {{ $row['age'] === 0 ? 'today' : $row['age'] . ' ' . Str::plural('day', $row['age']) }}
                                        </td>
                                        <td class="px-4 py-2 text-right text-gray-500">
                                            bill Rs. {{ $rs($row['bill']->total) }}
                                        </td>
                                        <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900">
                                            Rs. {{ $rs($row['owed']) }}
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            <a href="{{ route('businesses.pos.receipt', [$business, $row['bill']]) }}"
                                               class="text-xs font-medium text-emerald-700 hover:text-emerald-800">Bill →</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <div class="rounded-md border border-gray-200 bg-white px-6 py-12 text-center shadow-sm">
                    <p class="text-base font-medium text-gray-900">Nothing outstanding</p>
                    <p class="mt-1 text-sm text-gray-500">Every bill that went out has been paid for.</p>
                </div>
            @endforelse
        </div>

        @include('business.collections.partials.collect')
    </div>

    @push('scripts')
        @include('business.collections.partials.script')
    @endpush
</x-workspace-layout>
