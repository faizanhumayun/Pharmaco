{{--
    Goods coming back against one bill. The bill is not changed; this writes a
    return beside it. How it is settled is decided by what is still owed on the
    bill, not chosen here.
--}}
@php($rs = fn ($m) => $m->format())
<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Take goods back — bill {{ $bill->reference() }}</h1>
            <p class="mt-1 text-sm text-gray-500">
                {{ $bill->business_date->format('l j F Y') }} · {{ $bill->customer_name ?? 'Cash sale' }} · Rs. {{ $rs($bill->total) }}
            </p>
        </div>
    </x-slot>

    <div class="w-full space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        @if ($dayClosed)
            <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Today is closed, so a return cannot be recorded on it. Reopen the day on its closing page first.
            </div>
        @endif

        <form method="POST" action="{{ route('businesses.pos.bills.return.store', [$business, $bill]) }}">
            @csrf

            <x-panel title="What is coming back"
                     description="Enter only what the customer has brought back. It goes into stock again at the cost it went out at.">
                @if ($errors->any())
                    <div class="border-b border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 sm:px-6">
                        @foreach ($errors->all() as $error) <p>{{ $error }}</p> @endforeach
                    </div>
                @endif

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Item</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Price</th>
                                <th class="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">Sold</th>
                                <th class="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">Already back</th>
                                <th class="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">Coming back now</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($bill->lines as $i => $line)
                                @php($size = max(1, $line->units_per_pack))
                                @php($gone = (int) ($back[$line->id] ?? 0))
                                @php($left = $line->pieces() - $gone)
                                <tr>
                                    <td class="px-4 py-2 font-medium text-gray-900 sm:px-6">{{ $line->name }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums text-gray-600">{{ $rs($line->unit_price) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-center tabular-nums text-gray-600">{{ $line->quantityLabel() }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-center tabular-nums text-gray-600">
                                        {{ $gone === 0 ? '—' : ($size > 1 ? intdiv($gone, $size).' + '.($gone % $size).' loose' : $gone) }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-center">
                                        @if ($left > 0)
                                            <input type="hidden" name="items[{{ $i }}][line_id]" value="{{ $line->id }}">
                                            <input type="text" inputmode="numeric" name="items[{{ $i }}][quantity]" value="{{ old("items.$i.quantity") }}" placeholder="0"
                                                   class="w-20 rounded-md border-gray-300 py-1 text-center text-sm tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                            @if ($size > 1)
                                                <span class="text-xs text-gray-400">+</span>
                                                <input type="text" inputmode="numeric" name="items[{{ $i }}][loose]" value="{{ old("items.$i.loose") }}" placeholder="0"
                                                       class="w-16 rounded-md border-gray-300 py-1 text-center text-sm tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                <span class="text-xs text-gray-400">loose</span>
                                            @endif
                                            <span class="ml-1 text-xs text-gray-400">of {{ $size > 1 ? intdiv($left, $size).' + '.($left % $size) : $left }}</span>
                                        @else
                                            <span class="text-xs text-gray-400">all returned</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700 sm:px-6">
                    @if ($owing->isPositive())
                        Rs. {{ $rs($owing) }} is still owed on this bill. The return comes off that first; only anything beyond it is handed back in cash.
                    @else
                        This bill is fully paid, so the value of what comes back is handed back in cash from the drawer.
                    @endif
                    @if ($bill->discount->isPositive())
                        The bill carried a discount of Rs. {{ $rs($bill->discount) }}, so each item is worth its share of what was actually charged.
                    @endif
                </div>

                <div class="flex flex-wrap items-end justify-between gap-4 border-t border-gray-200 px-4 py-3 sm:px-6">
                    <div class="min-w-0 flex-1">
                        <x-input-label for="reason" value="Reason (optional)" />
                        <input id="reason" name="reason" type="text" maxlength="255" value="{{ old('reason') }}"
                               placeholder="Wrong item, not needed, short-dated…"
                               class="mt-1 block w-full max-w-2xl rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('businesses.pos.bills.edit', [$business, $bill]) }}"
                           class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
                        <button @disabled($dayClosed)
                                class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50">
                            Record the return
                        </button>
                    </div>
                </div>
            </x-panel>
        </form>
    </div>
</x-workspace-layout>
