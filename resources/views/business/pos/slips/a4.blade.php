{{--
    The A4 bill: a document, not a wider till slip.

    A pharmacy files this, matches it against its own purchases and pays
    against it, so it carries what a document needs and a slip does not — who
    it is addressed to, a numbered line for every item, what is still owed
    after this bill, and somewhere to sign.
--}}
@php($rs = fn ($m) => $m->format())

{{-- The slip is included from more than one screen, and only some of them know
     who the customer is. Missing details are left off, never fatal. --}}
@php($customer = $customer ?? null)
@php($owed = $owed ?? null)

<div class="slip mx-auto w-full max-w-[210mm] bg-white p-10 text-gray-900 shadow-sm ring-1 ring-slate-200 print:m-0 print:max-w-none print:p-0 print:shadow-none print:ring-0">

    <div class="flex items-start justify-between gap-8 border-b-2 border-gray-900 pb-4">
        <div>
            <h1 class="text-2xl font-bold uppercase tracking-tight">{{ $business->name }}</h1>
            @if ($business->address)
                <p class="mt-0.5 text-sm text-gray-600">{{ $business->address }}</p>
            @endif
            <p class="text-sm text-gray-600">
                @if ($business->phone) <span>Ph: {{ $business->phone }}</span> @endif
                @if ($business->email) <span class="ms-3">{{ $business->email }}</span> @endif
            </p>
            @if ($business->ntn)
                <p class="text-sm text-gray-600">NTN: {{ $business->ntn }}</p>
            @endif
        </div>

        <div class="shrink-0 text-right">
            <p class="text-sm font-semibold uppercase tracking-wide text-gray-500">Sales invoice</p>
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
            <p class="mt-0.5 text-base font-semibold">{{ $bill->customer_name ?? 'Cash sale' }}</p>
            @if ($customer?->area)
                <p class="text-sm text-gray-600">{{ $customer->area }}</p>
            @endif
            @if ($customer?->phone)
                <p class="text-sm text-gray-600">{{ $customer->phone }}</p>
            @endif
        </div>
        <div class="text-right text-sm text-gray-600">
            <p>Prepared by <span class="font-medium text-gray-900">{{ $bill->creator->name }}</span></p>
        </div>
    </div>

    <table class="mt-5 w-full text-sm">
        <thead>
            <tr class="border-y border-gray-300 bg-gray-50 text-left">
                <th class="w-10 px-2 py-2 text-center font-semibold">#</th>
                <th class="px-2 py-2 font-semibold">Description</th>
                <th class="w-24 px-2 py-2 text-center font-semibold">{{ Str::ucfirst($business->unit()->many()) }}</th>
                <th class="w-28 px-2 py-2 text-right font-semibold">Rate</th>
                <th class="w-32 px-2 py-2 text-right font-semibold">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($bill->lines as $line)
                {{-- Whole packs and tablets out of a pack print as rows of
                     their own, each at its own rate, so quantity times rate
                     is the amount beside it. --}}
                @foreach ($line->printedRows() as $row)
                    <tr @class(['align-top', 'border-b border-gray-200' => $loop->last])>
                        <td class="px-2 py-2 text-center tabular-nums text-gray-500">{{ $row['first'] ? $loop->parent->iteration : '' }}</td>
                        <td class="px-2 py-2">
                            @if ($row['first']) {{ $line->name }} @endif
                            {{-- What the quantity counts, when it is not whole packs. --}}
                            @if ($row['unit'])
                                <span class="block text-xs text-gray-500">{{ $row['unit'] }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-2 py-2 text-center tabular-nums">{{ $row['qty'] }}</td>
                        <td class="px-2 py-2 text-right tabular-nums">{{ $rs($row['rate']) }}</td>
                        <td class="px-2 py-2 text-right tabular-nums">{{ $rs($row['amount']) }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    <div class="mt-5 flex items-start justify-between gap-8">
        <div class="max-w-sm text-xs leading-relaxed text-gray-500">
            <p>{{ $bill->lines->count() }} {{ Str::plural('item', $bill->lines->count()) }} on this bill.</p>
            {{-- The business's own words, from Settings — the same foot as the
                 till slip — or the usual line when it has written none. Cut
                 down to line breaks, bullets, bold, italic and underline
                 (App\Support\SlipText). --}}
            <div class="mt-1 [&_ul]:list-disc [&_ul]:pl-4 [&_ol]:list-decimal [&_ol]:pl-4">{!! $business->receiptFooter('Goods once sold are checked at the counter. Please quote the invoice number on payment.') !!}</div>
        </div>

        {{-- The money, top to bottom the way it is worked out — the same
             lines as the till slip. --}}
        @php($billTotal = $bill->total->plus($bill->discount))
        <dl class="w-72 shrink-0 text-sm">
            <div class="flex justify-between py-1">
                <dt class="text-gray-600">Bill total</dt>
                <dd class="tabular-nums">{{ $rs($billTotal) }}</dd>
            </div>
            @unless ($bill->discount->isZero())
                <div class="flex justify-between py-1">
                    {{-- The percentage is said only where it is a round one. --}}
                    @php($pct = $billTotal->isPositive() ? round((float) $bill->discount->toDecimal() / (float) $billTotal->toDecimal() * 100, 2) : 0)
                    <dt class="text-gray-600">Discount{{ abs($pct - round($pct, 1)) < 0.006 ? ' (' . rtrim(rtrim(number_format($pct, 1), '0'), '.') . '%)' : '' }}</dt>
                    <dd class="tabular-nums">− {{ $rs($bill->discount) }}</dd>
                </div>
            @endunless
            <div class="mt-1 flex justify-between border-y-2 border-gray-900 py-2 text-lg font-bold">
                <dt>{{ $bill->discount->isZero() ? 'Total' : 'To pay' }}</dt>
                <dd class="tabular-nums">Rs. {{ $rs($bill->total) }}</dd>
            </div>
            <div class="flex justify-between py-1">
                {{-- Says how it was paid. --}}
                <dt class="text-gray-600">Received{{ $bill->received->isPositive() ? ' · ' . ($bill->paymentMethod?->label() ?? 'Cash') : '' }}</dt>
                <dd class="tabular-nums">{{ $rs($bill->received) }}</dd>
            </div>
            @unless ($bill->change()->isZero())
                <div class="flex justify-between py-1">
                    <dt class="text-gray-600">Change given</dt>
                    <dd class="tabular-nums">{{ $rs($bill->change()) }}</dd>
                </div>
            @endunless
            @unless ($bill->credit()->isZero())
                <div class="flex justify-between border-t border-gray-300 py-1 font-semibold">
                    <dt>Balance on this bill</dt>
                    <dd class="tabular-nums">{{ $rs($bill->credit()) }}</dd>
                </div>
            @endunless
            @if ($owed !== null)
                {{-- What the account stands at now, this bill included: the
                     figure the pharmacy is actually being asked to settle. --}}
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
