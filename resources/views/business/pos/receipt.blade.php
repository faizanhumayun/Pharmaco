{{--
    The receipt. Sized for an 80mm till roll: printing the page prints only the
    slip, and the counter's own chrome stays on screen.
--}}
@php($rs = fn ($m) => $m->format())

<x-pos-layout :business="$business">
    {{-- Same header as the counter, so the till never changes shape. --}}
    <header class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 bg-slate-900 px-4 py-3 text-white sm:px-6">
        <div class="flex items-center gap-3">
            <span class="rounded bg-emerald-500 px-2 py-1 text-sm font-bold text-slate-900">Rx</span>
            <div>
                <p class="text-base font-semibold leading-tight">{{ $business->name }}</p>
                <p class="text-xs text-slate-400">Bill {{ $bill->reference() }} · sold by {{ $bill->creator->name }}</p>
            </div>
        </div>

        <div class="flex items-center gap-3 print:hidden">
            @if ($bill->dailyEntry)
                <a href="{{ route('businesses.daily.show', [$business, $bill->dailyEntry]) }}"
                   class="rounded-md border border-slate-600 px-3 py-1.5 text-sm font-medium text-slate-200 hover:bg-slate-800">
                    That day's entry
                </a>
            @endif
            <button type="button" onclick="window.print()"
                    class="rounded-md bg-white px-3 py-1.5 text-sm font-semibold text-slate-900 hover:bg-slate-100">
                Print
            </button>
            <a href="{{ route('businesses.pos.index', $business) }}"
               class="rounded-md bg-emerald-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700">
                New bill
            </a>
        </div>
    </header>

    @if (session('status'))
        <div class="bg-emerald-600 px-4 py-2 text-center text-sm font-medium text-white print:hidden sm:px-6">{{ session('status') }}</div>
    @endif

    <div class="flex-1 overflow-y-auto p-6 print:overflow-visible print:p-0">

        <div class="mx-auto max-w-sm rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 print:ring-0 print:max-w-none print:p-0 print:shadow-none">
            <div class="text-center">
                <p class="text-base font-semibold text-gray-900">{{ $business->name }}</p>
                @if ($business->address)
                    <p class="text-xs text-gray-500">{{ $business->address }}</p>
                @endif
                @if ($business->phone)
                    <p class="text-xs text-gray-500">{{ $business->phone }}</p>
                @endif
            </div>

            <div class="mt-4 flex justify-between border-y border-dashed border-gray-300 py-2 text-xs text-gray-600">
                <span>{{ $bill->reference() }}</span>
                <span>{{ $bill->created_at->timezone($business->timezone)->format('d M Y, h:i A') }}</span>
            </div>

            @if ($bill->customer_name)
                <p class="mt-2 text-xs text-gray-600">Customer: <span class="text-gray-900">{{ $bill->customer_name }}</span></p>
            @endif

            <table class="mt-3 w-full text-xs">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-500">
                        <th class="py-1 text-left font-medium">Item</th>
                        <th class="py-1 text-center font-medium">Qty</th>
                        <th class="py-1 text-right font-medium">Price</th>
                        <th class="py-1 text-right font-medium">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bill->lines as $line)
                        <tr class="align-top">
                            <td class="py-1 pr-2 text-gray-900">{{ $line->name }}</td>
                            <td class="py-1 text-center tabular-nums text-gray-700">{{ $line->quantity }}</td>
                            <td class="py-1 text-right tabular-nums text-gray-700">{{ $rs($line->unit_price) }}</td>
                            <td class="py-1 text-right tabular-nums text-gray-900">{{ $rs($line->line_total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <dl class="mt-3 space-y-1 border-t border-dashed border-gray-300 pt-2 text-xs">
                @unless ($bill->discount->isZero())
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Discount</dt>
                        <dd class="tabular-nums text-gray-900">− {{ $rs($bill->discount) }}</dd>
                    </div>
                @endunless
                <div class="flex justify-between text-sm font-semibold">
                    <dt class="text-gray-900">Total</dt>
                    <dd class="tabular-nums text-gray-900">Rs. {{ $rs($bill->total) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-600">Cash received</dt>
                    <dd class="tabular-nums text-gray-900">{{ $rs($bill->received) }}</dd>
                </div>
                @unless ($bill->change()->isZero())
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Change</dt>
                        <dd class="tabular-nums text-gray-900">{{ $rs($bill->change()) }}</dd>
                    </div>
                @endunless
                @unless ($bill->credit()->isZero())
                    <div class="flex justify-between font-semibold">
                        <dt class="text-amber-800">On credit</dt>
                        <dd class="tabular-nums text-amber-800">{{ $rs($bill->credit()) }}</dd>
                    </div>
                @endunless
            </dl>

            <p class="mt-4 text-center text-[11px] text-gray-500">Thank you — get well soon.</p>
        </div>

        {{-- Not on the slip: what the counter made on it. --}}
        <div class="mx-auto mt-4 max-w-sm text-center text-xs text-gray-500 print:hidden">
            Cost of these goods Rs. {{ $rs($bill->cost) }} · margin
            <span class="{{ $bill->margin()->isNegative() ? 'text-red-700' : 'text-emerald-700' }}">Rs. {{ $rs($bill->margin()) }}</span>,
            added to the day's gross profit.
        </div>
    </div>
</x-pos-layout>
