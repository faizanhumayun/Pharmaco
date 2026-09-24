{{--
    A saved bill, on whichever paper this business uses.

    The format comes from the business — a sheet for a distributor, a till roll
    for a counter — and the switch here changes only what is on screen, for the
    time someone needs the other one. Printing prints the slip and nothing else.
--}}
<x-pos-layout :business="$business">
    {{-- The browser is told the paper size, so a till roll is not printed as a
         sheet with the slip stranded in one corner. --}}
    @push('head')
        <style>
            @media print {
                @page { size: {{ $format->pageSize() }}; margin: {{ $format === \App\Enums\ReceiptFormat::A4 ? '14mm' : '3mm' }}; }
                html, body { height: auto !important; overflow: visible !important; background: #fff !important; }

                /* Only the slip, and only once. Everything else is screen
                   furniture whose height and fixed positioning is what made
                   the browser produce page after identical page. */
                body > *:not(#print-sheet) { display: none !important; }
                #print-sheet { display: block !important; }
                #print-sheet .slip { width: 100% !important; max-width: none !important; margin: 0 !important; padding: 0 !important; box-shadow: none !important; }
            }
        </style>
    @endpush

    <header class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 bg-slate-900 px-4 py-3 text-white print:hidden sm:px-6">
        <div class="flex items-center gap-3">
            <span class="rounded bg-emerald-500 px-2 py-1 text-sm font-bold text-slate-900">Rx</span>
            <div>
                <p class="text-base font-semibold leading-tight">{{ $business->name }}</p>
                <p class="text-xs text-slate-400">Bill {{ $bill->reference() }} · sold by {{ $bill->creator->name }}</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            {{-- Two papers, one click apart. The business's own is marked, so
                 switching feels like a borrow rather than a change of setting. --}}
            {{-- Which bill, then which paper. --}}
            @if (\App\Enums\ReceiptFormat::NET_BILL_AVAILABLE)
            <div class="flex items-center rounded-lg bg-slate-800 p-0.5">
                @foreach (['Plain' => false, 'Net ' . \App\Enums\ReceiptFormat::TRADE_DISCOUNT_PERCENT . '%' => true] as $label => $isNet)
                    <a href="{{ route('businesses.pos.receipt', array_filter([
                            'business' => $business, 'posBill' => $bill,
                            'format' => $format->value, 'net' => $isNet ? 1 : null,
                       ])) }}"
                       class="rounded-md px-3 py-1.5 text-sm font-semibold {{ $net === $isNet ? 'bg-white text-slate-900' : 'text-slate-300 hover:text-white' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
            @endif

            <div class="flex items-center rounded-lg bg-slate-800 p-0.5">
                @foreach (\App\Enums\ReceiptFormat::cases() as $option)
                    <a href="{{ route('businesses.pos.receipt', array_filter([
                            'business' => $business, 'posBill' => $bill,
                            'format' => $option->value, 'net' => $net ? 1 : null,
                       ])) }}"
                       class="rounded-md px-3 py-1.5 text-sm font-semibold {{ $format === $option ? 'bg-white text-slate-900' : 'text-slate-300 hover:text-white' }}"
                       title="{{ $option->description() }}">
                        {{ $option->short() }}@if ($business->receiptFormat() === $option)<span class="ms-1 text-xs font-normal {{ $format === $option ? 'text-slate-500' : 'text-slate-500' }}">·&nbsp;usual</span>@endif
                    </a>
                @endforeach
            </div>

            @if ($bill->dailyEntry)
                <a href="{{ route('businesses.daily.show', [$business, $bill->dailyEntry]) }}"
                   class="rounded-md border border-slate-600 px-3 py-1.5 text-sm font-medium text-slate-200 hover:bg-slate-800">
                    That day's entry
                </a>
            @endif
            <button type="button" onclick="window.printSlip()"
                    class="rounded-md bg-white px-3 py-1.5 text-sm font-semibold text-slate-900 hover:bg-slate-100">
                Print {{ $format->short() }}
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

    @include('business.pos.partials.print-portal')

    <div class="flex-1 overflow-y-auto p-6 print:overflow-visible print:p-0">
        <div class="print-area">
            @include('business.pos.slips.' . ($net ? 'net' : $format->value))
        </div>

        {{-- Not on the paper: what the counter made on it. --}}
        <div class="mx-auto mt-4 max-w-md text-center text-xs text-gray-500 print:hidden">
            Cost of these goods Rs. {{ $bill->cost->format() }} · margin
            <span class="{{ $bill->margin()->isNegative() ? 'text-red-700' : 'text-emerald-700' }}">Rs. {{ $bill->margin()->format() }}</span>,
            added to the day's gross profit.
        </div>
    </div>
</x-pos-layout>
