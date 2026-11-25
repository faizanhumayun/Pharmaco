{{--
    The same bill, quoted the way the trade quotes it.

    A pharmacy is used to reading a rate with a percentage off it, so this
    prints the rate that, less the trade discount, comes to exactly what is
    being charged. Nothing about the sale changes — not the total, not what
    was recorded, not a figure in the ledger. Only the arithmetic on the paper
    runs the other way round.

    The net at the foot is the same number as the plain bill's total, always.
--}}
{{-- Everything set up in one block. A run of inline php directives followed
     by a block of them is mis-parsed, and every variable below comes out
     undefined — and naming that directive inside a comment breaks it too. --}}
@php
    $rs = fn ($m) => $m->format();

    /*
     * A price written the way a code is: five figures wide, zeros in front.
     * 100.00 reads 00100, 83.00 reads 00083. Paisa are kept when there are
     * any, because dropping them would print a price nobody was charged.
     */
    $padded = function ($money) {
        [$rupees, $paisa] = explode('.', $money->toDecimal());

        return str_pad($rupees, 5, '0', STR_PAD_LEFT).($paisa === '00' ? '' : '.'.$paisa);
    };
    $customer = $customer ?? null;
    $owed = $owed ?? null;
    $percent = \App\Enums\ReceiptFormat::TRADE_DISCOUNT_PERCENT;

    /*
     * Worked out per line and summed, rather than a percentage taken off the
     * total: the rates are what the customer checks, so they have to be the
     * figures the amounts came from. The discount is then whatever makes the
     * net come out right, which is why the net can never drift from the sale.
     */
    $rows = $bill->lines->map(function ($line) use ($percent) {
        $mrp = $line->product?->mrp ?? \App\Support\Money::zero();
        $gross = $mrp->times($line->quantity);
        $off = $gross->percent($percent);

        return [
            'line' => $line,
            'mrp' => $mrp,
            'gross' => $gross,
            'off' => $off,
            'amount' => $gross->minus($off),
        ];
    });

    $gross = \App\Support\Money::sum($rows->pluck('gross'));
    $off = \App\Support\Money::sum($rows->pluck('off'));
    $after = \App\Support\Money::sum($rows->pluck('amount'));
@endphp

<div class="slip mx-auto w-full max-w-[210mm] bg-white p-8 text-gray-900 shadow-sm ring-1 ring-slate-200 print:m-0 print:max-w-none print:p-0 print:shadow-none print:ring-0">

    <div class="flex items-start justify-between gap-8 border-b-2 border-gray-900 pb-4">
        <div>
            <h1 class="text-2xl font-bold uppercase tracking-tight">{{ $business->name }}</h1>
            @if ($business->address)
                <p class="mt-0.5 text-sm text-gray-600">{{ $business->address }}</p>
            @endif
            <p class="text-sm text-gray-600">
                @if ($business->phone) <span>Ph: {{ $business->phone }}</span> @endif
                @if ($business->ntn) <span class="ms-3">NTN: {{ $business->ntn }}</span> @endif
            </p>
        </div>

        <div class="shrink-0 text-right">
            <p class="text-sm font-semibold uppercase tracking-wide text-gray-500">Net bill</p>
            <p class="text-2xl font-bold tabular-nums">{{ $bill->reference() }}</p>
            <p class="mt-1 text-sm text-gray-600">
                {{ $bill->business_date->format('d F Y') }}<br>
                {{ $bill->created_at->timezone($business->timezone)->format('h:i A') }}
            </p>
        </div>
    </div>

    <div class="mt-5 flex items-start justify-between gap-8">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Billed to</p>
            <p class="mt-0.5 text-base font-semibold">{{ $bill->customer_name ?? 'Walk-in customer' }}</p>
            @if ($customer?->area)
                <p class="text-sm text-gray-600">{{ $customer->area }}</p>
            @endif
        </div>
        <div class="text-right text-sm text-gray-600">
            <p>Prepared by <span class="font-medium text-gray-900">{{ $bill->creator->name }}</span></p>
            <p>Trade discount <span class="font-medium text-gray-900">{{ $percent }}%</span></p>
        </div>
    </div>

    <table class="mt-5 w-full text-sm">
        <thead>
            <tr class="border-y border-gray-300 bg-gray-50 text-left">
                <th class="w-44 px-2 py-2 font-semibold">Code · price</th>
                <th class="px-2 py-2 font-semibold">Product</th>
                <th class="w-16 px-2 py-2 text-center font-semibold">{{ Str::ucfirst($business->unit()->many()) }}</th>
                <th class="w-28 px-2 py-2 text-right font-semibold">MRP</th>
                <th class="w-28 px-2 py-2 text-right font-semibold">Less {{ $percent }}%</th>
                <th class="w-32 px-2 py-2 text-right font-semibold">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr class="border-b border-gray-200 align-top">
                    {{-- The code, and under it the price the goods are actually
                         being sold at — the rate column opposite is that price
                         quoted before the trade discount. --}}
                    {{-- Code and price run together as one figure, the price
                         padded to a fixed width so the join always falls in the
                         same place: 8512402291389 and 100.00 read as
                         851240229138900100. --}}
                    <td class="whitespace-nowrap px-2 py-2 font-mono text-xs text-gray-900">{{ ($row['line']->product?->code ?? '') . $padded($row['line']->unit_price) }}</td>
                    <td class="px-2 py-2">{{ $row['line']->name }}</td>
                    <td class="px-2 py-2 text-center tabular-nums">{{ $row['line']->quantityLabel() }}</td>
                    <td class="px-2 py-2 text-right tabular-nums">{{ $rs($row['mrp']) }}</td>
                    <td class="px-2 py-2 text-right tabular-nums text-gray-600">− {{ $rs($row['off']) }}</td>
                    <td class="px-2 py-2 text-right tabular-nums">{{ $rs($row['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>

        {{-- The line the customer settles against: how many items, and the one
             figure that actually changes hands. It sits with the items rather
             than only in the totals block, because that is where the eye goes
             when checking a delivery off against the bill. --}}
        <tfoot>
            <tr class="border-t-2 border-gray-900">
                <td class="px-2 py-2.5" colspan="3">
                    <span class="font-semibold">Total items {{ $bill->lines->count() }}</span>
                    <span class="font-normal text-gray-500">· {{ $bill->lines->sum('quantity') }} {{ $business->unit()->many() }} ·</span>
                    {{-- The figure that changes hands, beside the count. --}}
                    <span class="font-bold tabular-nums">{{ $rs($bill->total) }}</span>
                </td>
                <td class="px-2 py-2.5"></td>
                <td class="px-2 py-2.5 text-right font-semibold">Total amount</td>
                <td class="px-2 py-2.5 text-right text-base font-bold tabular-nums">{{ $rs($gross) }}</td>
            </tr>
            <tr>
                <td class="px-2 pb-2 text-xs text-gray-500" colspan="6">
                    Total amount is before the {{ $percent }}% trade discount. The amount next to the
                    item count is the amount receivable.
                </td>
            </tr>
        </tfoot>
    </table>

    <div class="mt-5 flex items-start justify-between gap-8">
        <p class="max-w-xs text-xs leading-relaxed text-gray-500">
            Rates are shown before the trade discount. Please
            mention the bill number when paying.
        </p>

        <dl class="w-72 shrink-0 text-sm">
            <div class="flex justify-between py-1">
                <dt class="text-gray-600">MRP total</dt>
                <dd class="tabular-nums">{{ $rs($gross) }}</dd>
            </div>
            <div class="flex justify-between py-1">
                <dt class="text-gray-600">Less {{ $percent }}%</dt>
                <dd class="tabular-nums">− {{ $rs($off) }}</dd>
            </div>
            <div class="mt-1 flex justify-between border-y-2 border-gray-900 py-2 text-lg font-bold">
                <dt>Amount</dt>
                <dd class="tabular-nums">Rs. {{ $rs($after) }}</dd>
            </div>
            @unless ($bill->discount->isZero())
                <div class="flex justify-between py-1">
                    <dt class="text-gray-600">Further discount</dt>
                    <dd class="tabular-nums">− {{ $rs($bill->discount) }}</dd>
                </div>
            @endunless
            @if ($owed !== null)
                <div class="flex justify-between py-1 text-gray-600">
                    <dt>Account balance</dt>
                    <dd class="tabular-nums">{{ $owed->format() }}</dd>
                </div>
            @endif
        </dl>
    </div>

    <div class="mt-12 flex items-end justify-between gap-8 text-xs text-gray-500">
        <div class="w-56 border-t border-gray-400 pt-1 text-center">Received the goods</div>
        <div class="w-56 border-t border-gray-400 pt-1 text-center">For {{ $business->name }}</div>
    </div>
</div>
