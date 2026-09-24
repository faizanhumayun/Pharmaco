{{--
    The till slip: 80mm, handed over the counter and put in a pocket.

    Narrow enough that nothing can sit side by side, so every figure is on its
    own line and the total is the biggest thing on the paper.
--}}
@php($rs = fn ($m) => $m->format())

<div class="slip mx-auto w-[80mm] max-w-full bg-white p-5 text-gray-900 shadow-sm ring-1 ring-slate-200 print:m-0 print:w-full print:p-0 print:shadow-none print:ring-0">
    <div class="text-center">
        <p class="text-base font-bold uppercase">{{ $business->name }}</p>
        @if ($business->address)
            <p class="text-[11px] leading-snug text-gray-600">{{ $business->address }}</p>
        @endif
        @if ($business->phone)
            <p class="text-[11px] text-gray-600">{{ $business->phone }}</p>
        @endif
    </div>

    <div class="mt-3 border-y border-dashed border-gray-400 py-1.5 text-[11px]">
        <div class="flex justify-between">
            <span>{{ $bill->reference() }}</span>
            <span>{{ $bill->created_at->timezone($business->timezone)->format('d/m/y h:i A') }}</span>
        </div>
        @if ($bill->customer_name)
            <div class="mt-0.5 truncate">{{ $bill->customer_name }}</div>
        @endif
        <div class="mt-0.5 text-gray-600">Served by {{ $bill->creator->name }}</div>
    </div>

    <table class="mt-2 w-full text-[11px]">
        <thead>
            <tr class="border-b border-gray-400 text-left">
                <th class="py-1 font-semibold">Item</th>
                <th class="py-1 text-center font-semibold">Qty</th>
                <th class="py-1 text-right font-semibold">Rate</th>
                <th class="py-1 pl-2 text-right font-semibold">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($bill->lines as $line)
                <tr class="align-top">
                    {{-- A long medicine name wraps onto its own lines rather than
                         squeezing the figures, which must stay readable. --}}
                    <td class="py-1 pr-1.5 leading-snug">{{ $line->name }}</td>
                    <td class="py-1 text-center tabular-nums">{{ $line->quantity }}</td>
                    <td class="py-1 text-right tabular-nums">{{ $rs($line->unit_price) }}</td>
                    <td class="py-1 pl-2 text-right tabular-nums">{{ $rs($line->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <dl class="mt-2 space-y-0.5 border-t border-dashed border-gray-400 pt-2 text-[11px]">
        @unless ($bill->discount->isZero())
            <div class="flex justify-between">
                <dt>Discount</dt>
                <dd class="tabular-nums">− {{ $rs($bill->discount) }}</dd>
            </div>
        @endunless
        <div class="flex items-baseline justify-between border-y border-gray-400 py-1 text-sm font-bold">
            <dt>TOTAL</dt>
            <dd class="tabular-nums">Rs. {{ $rs($bill->total) }}</dd>
        </div>
        <div class="flex justify-between pt-0.5">
            <dt>Cash received</dt>
            <dd class="tabular-nums">{{ $rs($bill->received) }}</dd>
        </div>
        @unless ($bill->change()->isZero())
            <div class="flex justify-between">
                <dt>Change</dt>
                <dd class="tabular-nums">{{ $rs($bill->change()) }}</dd>
            </div>
        @endunless
        @unless ($bill->credit()->isZero())
            <div class="flex justify-between font-bold">
                <dt>ON CREDIT</dt>
                <dd class="tabular-nums">{{ $rs($bill->credit()) }}</dd>
            </div>
        @endunless
    </dl>

    <p class="mt-3 text-center text-[10px] leading-snug text-gray-600">
        {{ $bill->lines->count() }} {{ Str::plural('item', $bill->lines->count()) }} ·
        {{ $bill->lines->sum('quantity') }} {{ $business->unit()->many() }}
    </p>
    <p class="mt-1 text-center text-[11px] font-medium">Thank you — get well soon.</p>
</div>
