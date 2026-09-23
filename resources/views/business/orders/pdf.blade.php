@php
    $labelSpan = $order->hasDiscount() ? 6 : 5;

    // Helvetica is one of the fourteen fonts every PDF reader already has, so
    // nothing is embedded and the file comes out at a few kilobytes — which
    // matters, because these get sent over WhatsApp. It only covers Latin-1
    // though, so a document carrying anything beyond that falls back to DejaVu.
    // That costs the best part of a megabyte, and is still the right trade
    // against a company's own name rendering as a row of boxes.
    $text = collect([
            $business->name, $business->address, $business->phone, $business->email, $business->ntn,
            $order->company->name, $order->company->contact, $order->company->phone,
            $order->notes, $order->creator->name, $order->reference,
        ])
        ->concat($order->lines->pluck('brand_name'))
        ->concat($order->lines->pluck('generic_name'))
        ->concat($order->lines->pluck('pack_size'))
        ->filter()
        ->implode(' ');

    $font = preg_match('/[^\x20-\x7E]/', $text) ? 'DejaVu Sans' : 'Helvetica, Arial';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $order->reference }} - {{ $order->company->name }}</title>
    {{--
        Plain CSS on purpose: dompdf reads a small, old subset of it, so this
        page is styled the way a 2005 page was. Anything cleverer silently
        renders as nothing.
    --}}
    <style>
        @page { margin: 18mm 14mm; }
        body { font-family: {{ $font }}, sans-serif; font-size: 10px; color: #111827; }
        .head { border-bottom: 2px solid #111827; padding-bottom: 8px; }
        .head td { vertical-align: top; }
        .business { font-size: 18px; font-weight: bold; }
        .sub { color: #4b5563; font-size: 10px; }
        .ref { font-size: 13px; font-weight: bold; }
        .meta { width: 100%; margin: 10px 0 12px 0; }
        .meta td { vertical-align: top; }
        .muted { color: #6b7280; font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; }
        .name { font-size: 12px; font-weight: bold; }
        table.lines { width: 100%; border-collapse: collapse; }
        table.lines th { background: #f3f4f6; border-top: 1px solid #d1d5db; border-bottom: 1px solid #d1d5db;
                         padding: 5px 4px; text-align: left; font-size: 9px; }
        table.lines td { border-bottom: 1px solid #e5e7eb; padding: 5px 4px; }
        .r { text-align: right; }
        .generic { color: #6b7280; font-size: 9px; }
        .totals td { padding: 4px; }
        .grand td { border-top: 2px solid #111827; font-size: 12px; font-weight: bold; padding-top: 6px; }
        .foot { margin-top: 14px; color: #4b5563; }
        .note { margin-top: 10px; border-top: 1px solid #e5e7eb; padding-top: 6px; }
        .disclaimer { margin-top: 16px; border-top: 1px solid #e5e7eb; padding-top: 6px;
                      color: #9ca3af; font-size: 8px; }
    </style>
</head>
<body>
    {{-- The letterhead: who this order is from, and how to reach them. --}}
    <table class="head" width="100%">
        <tr>
            <td>
                <div class="business">{{ $business->name }}</div>
                @if ($business->address)<div class="sub">{{ $business->address }}</div>@endif
                @php($contact = array_filter([$business->phone, $business->email]))
                @if ($contact)<div class="sub">{{ implode(' - ', $contact) }}</div>@endif
                @if ($business->ntn)<div class="sub">NTN {{ $business->ntn }}</div>@endif
            </td>
            <td class="r">
                <div class="muted">Order form</div>
                <div class="ref">{{ $order->reference }}</div>
                <div class="sub">{{ $order->business_date->format('j F Y') }}</div>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td>
                <div class="muted">To</div>
                <div class="name">{{ $order->company->name }}</div>
                @if ($order->company->contact)<div class="sub">{{ $order->company->contact }}</div>@endif
                @if ($order->company->phone)<div class="sub">{{ $order->company->phone }}</div>@endif
            </td>
            @if ($order->discount_percent)
                <td class="r">
                    <div class="muted">Agreed discount</div>
                    <div class="name">{{ rtrim(rtrim($order->discount_percent, '0'), '.') }}% on all items</div>
                </td>
            @endif
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th width="4%">#</th>
                <th width="{{ $order->hasDiscount() ? '34%' : '40%' }}">Product</th>
                <th width="12%">Pack</th>
                <th class="r" width="16%">Qty</th>
                <th class="r" width="12%">Rate</th>
                @if ($order->hasDiscount())<th class="r" width="8%">Disc</th>@endif
                <th class="r" width="14%">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->lines as $line)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>
                        {{ $line->label() }}
                        @if ($line->generic_name)<div class="generic">{{ $line->generic_name }}</div>@endif
                    </td>
                    <td>{{ $line->pack_size ?? '-' }}</td>
                    <td class="r">{{ $line->quantityLabel() }}</td>
                    <td class="r">{{ $line->rate->format() }}</td>
                    @if ($order->hasDiscount())
                        <td class="r">
                            @if ($p = $line->discountPercent($order->discount_percent)){{ rtrim(rtrim($p, '0'), '.') }}%@else-@endif
                        </td>
                    @endif
                    <td class="r">{{ $line->amount($order->discount_percent)->format() }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot class="totals">
            @if ($order->hasDiscount())
                <tr>
                    <td colspan="{{ $labelSpan }}" class="r">Before discount</td>
                    <td class="r">{{ $order->gross()->format() }}</td>
                </tr>
                <tr>
                    <td colspan="{{ $labelSpan }}" class="r">Discount</td>
                    <td class="r">less {{ $order->discountTotal()->format() }}</td>
                </tr>
            @endif
            <tr class="grand">
                <td colspan="{{ $labelSpan }}" class="r">Total</td>
                <td class="r">Rs. {{ $order->total()->format() }}</td>
            </tr>
        </tfoot>
    </table>

    <table class="foot" width="100%">
        <tr>
            <td>{{ number_format($order->totalPacks()) }} packs across {{ $order->lines->count() }} {{ Str::plural('item', $order->lines->count()) }}</td>
            <td class="r">Prepared by {{ $order->creator->name }}</td>
        </tr>
    </table>

    @if ($order->notes)
        <div class="note">
            <strong>Note</strong>
            <div>{{ $order->notes }}</div>
        </div>
    @endif

    <div class="disclaimer">
        This is an order, not an invoice. Prices are as quoted and subject to the company's confirmation.
    </div>
</body>
</html>
