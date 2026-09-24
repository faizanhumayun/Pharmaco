@php
    $canManage = auth()->user()->can('manageOrders', $business);
    $filename = $order->reference . '-' . Str::slug($order->company->name);
    // #, Product, Pack, Qty, Rate, [Disc], Amount — the totals label spans
    // everything but the amount column.
    $labelSpan = $order->hasDiscount() ? 6 : 5;
@endphp

<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-xl font-semibold text-gray-900">{{ $order->reference }}</h1>
                    <x-badge :classes="$order->status->badgeClasses()">{{ $order->status->label() }}</x-badge>
                </div>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $order->company->name }} · {{ $order->business_date->format('j M Y') }} ·
                    written by {{ $order->creator->name }}
                    @if ($order->sent_at) · sent {{ $order->sent_at->diffForHumans() }} @endif
                </p>
            </div>

            {{--
                The actions, beside the order they act on.

                They sit in the layout's header slot, which is a different part
                of the page from the body — so the two that need JavaScript ask
                for it by event rather than reaching into the body's Alpine
                scope, which they cannot see. The body listens on the window.
            --}}
            <div class="flex flex-wrap items-center justify-end gap-2 print:hidden" x-data>
                {{-- Nothing to send, print or photograph when no order form was
                     ever written: these all act on that document. --}}
                @if ($order->lines->isNotEmpty())
                    <a href="{{ route('businesses.orders.pdf', [$business, $order]) }}"
                       class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                        Download PDF
                    </a>

                    <button type="button" x-on:click="$dispatch('order-save-image', { button: $el })"
                            class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Save as image
                    </button>

                    <button type="button" x-on:click="$dispatch('order-print')"
                            class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Print
                    </button>
                @endif

                @if ($canManage && $order->deliveryIsEditable())
                    <a href="{{ route('businesses.orders.receive', [$business, $order]) }}"
                       class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Edit delivery
                    </a>
                @elseif ($reason = $order->deliveryLockedReason())
                    <span class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-500"
                          title="{{ $reason }}">
                        Delivery locked
                    </span>
                @endif

                @if ($canManage && $order->isOutstanding())
                    <a href="{{ route('businesses.orders.receive', [$business, $order]) }}"
                       class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                        Record the delivery
                    </a>
                @endif

                @if ($canManage && $order->isEditable())
                    <a href="{{ route('businesses.orders.edit', [$business, $order]) }}"
                       class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Edit
                    </a>

                    <form method="POST" action="{{ route('businesses.orders.send', [$business, $order]) }}">
                        @csrf
                        <button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                            Mark as sent
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </x-slot>

    {{-- A delivery recorded without an order form has no document behind it —
         the order was placed on the phone. There is nothing to show, print or
         photograph, so the form and its tab are left out entirely and the
         delivery is simply the page. --}}
    @php($hasForm = $order->lines->isNotEmpty())

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8"
         x-data="{
             tab: @js($hasForm ? 'order' : 'delivery'),
             /* The document cannot be photographed or printed while it is
                hidden, so both switch to it first and put the tab back after. */
             onDocument(run) {
                 const previous = this.tab;
                 this.tab = 'order';
                 this.$nextTick(() => Promise.resolve(run()).finally(() => { this.tab = previous; }));
             },
         }"
         x-on:order-save-image.window="onDocument(() => window.saveAsImage('#order-document', '{{ $filename }}.png', $event.detail.button))"
         x-on:order-print.window="onDocument(() => window.print())">
        <x-flash />

        @if ($errors->has('status'))
            <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ $errors->first('status') }}
            </div>
        @endif

        {{-- Everything you can do with it, none of which moves a figure. --}}

        @if ($order->isOutstanding())
            <p class="mb-6 text-sm text-gray-500 print:hidden">
                Sent {{ $order->sent_at?->diffForHumans() }} and still outstanding. When the delivery arrives,
                record what actually came — then enter the invoice in Daily entry.
            </p>
        @endif

        {{-- Two views of the same order: the document that goes to the company,
             and what actually turned up. The second only exists once it has. --}}
        @if ($order->status === App\Enums\OrderStatus::Received)
            @php($exceptionCount = $order->deliveryExceptions()->count())
            <div class="mb-6 flex gap-1 border-b border-gray-200 print:hidden">
                @if ($hasForm)
                <button type="button" x-on:click="tab = 'order'"
                        :class="tab === 'order'
                            ? 'border-emerald-700 text-emerald-800'
                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                        class="border-b-2 px-4 py-2 text-sm font-medium transition">
                    Order form
                </button>
                @endif
                <button type="button" x-on:click="tab = 'delivery'"
                        :class="tab === 'delivery'
                            ? 'border-emerald-700 text-emerald-800'
                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                        class="flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium transition">
                    Delivery
                    @if ($exceptionCount > 0)
                        <span class="rounded-full bg-amber-100 px-1.5 py-0.5 text-xs font-semibold text-amber-800">
                            {{ $exceptionCount }}
                        </span>
                    @else
                        <span class="text-xs text-emerald-700">✓</span>
                    @endif
                </button>
            </div>
        @endif
        {{-- The delivery audit: the order against what turned up. Shown only
             here, never on the document the company receives. --}}
        <div x-show="tab === 'delivery'" x-cloak class="print:hidden">
        {{-- The delivery audit: the order against what turned up. Shown only
             here, never on the document the company receives. --}}
        @if ($order->status === App\Enums\OrderStatus::Received)
            @php($rows = $order->delivery())
            @php($exceptions = $order->deliveryExceptions())

            <x-panel class="mb-6 print:hidden"
                     title="Delivery"
                     :description="$order->received_at?->format('j M Y') . ' · recorded by ' . ($order->receiver?->name ?? '—')">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 border-b border-gray-200 px-4 py-3 sm:px-6">
                    @if (! $hasForm)
                        {{-- Nothing was ordered on paper, so every line is
                             "unordered" and calling that a difference would be
                             counting the whole delivery as an exception. --}}
                        <x-badge classes="bg-gray-100 text-gray-700 ring-gray-500/20">
                            Recorded without an order form
                        </x-badge>
                    @elseif ($exceptions->isEmpty())
                        <x-badge classes="bg-emerald-50 text-emerald-800 ring-emerald-600/20">Everything arrived as ordered ✓</x-badge>
                    @else
                        <x-badge classes="bg-amber-50 text-amber-800 ring-amber-600/20">
                            {{ $exceptions->count() }} {{ Str::plural('difference', $exceptions->count()) }} against the order
                        </x-badge>
                    @endif

                    <span class="text-sm text-gray-500">
                        ordered <span class="font-mono tabular-nums">{{ $order->total()->format() }}</span>
                        · received <span class="font-mono font-semibold tabular-nums text-gray-900">{{ $order->receivedTotal()->format() }}</span>
                    </span>
                </div>

                @if ($order->purchaseLine)
                    @php($bill = $order->purchaseLine)
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2 border-b border-gray-200 bg-gray-50 px-4 py-3 text-sm sm:px-6">
                        <span class="text-gray-500">
                            Invoice <span class="font-medium text-gray-900">{{ $bill->invoice_no ?? '—' }}</span>
                        </span>
                        <span class="text-gray-500">
                            billed <span class="font-mono font-semibold tabular-nums text-gray-900">{{ $bill->amount->format() }}</span>
                        </span>
                        <span class="text-gray-500">
                            paid <span class="font-mono tabular-nums text-gray-900">{{ $bill->paid->format() }}</span>
                        </span>
                        @php($owed = $bill->pending())
                        {{-- A negative is not a negative debt: it is money paid
                             past this bill, which the ledger puts against what
                             the supplier was already owed. Said the same way the
                             daily entry says it. --}}
                        @if ($owed->isNegative())
                            <span class="text-sky-700">
                                <span class="font-mono font-semibold tabular-nums">{{ $owed->absolute()->format() }}</span>
                                paid beyond this bill
                            </span>
                        @else
                            <span class="{{ $owed->isZero() ? 'text-gray-500' : 'text-amber-700' }}">
                                pending <span class="font-mono font-semibold tabular-nums">{{ $owed->format() }}</span>
                            </span>
                        @endif

                        {{-- Where the money actually is: on a day's entry, and
                             only in the ledger once that day is posted. --}}
                        <a href="{{ route('businesses.daily.show', [$business, $bill->dailyEntry]) }}"
                           class="ml-auto text-sm font-medium text-emerald-700 hover:text-emerald-800">
                            On the daily entry for {{ $bill->dailyEntry->business_date->format('j M Y') }}
                            ({{ strtolower($bill->dailyEntry->status->label()) }}) →
                        </a>
                    </div>

                    {{-- Goods and money move at different moments, and saying
                         "stock moves when the day is posted" ran the two together:
                         the count moved the moment the delivery was recorded — the
                         packs are on the shelf and can be sold — while what the
                         books say they are worth waits for the posting. --}}
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-900 sm:px-6">
                        <span>
                            <span class="font-semibold">{{ number_format($order->receiptLines->sum('packs')) }}
                            {{ Str::plural($business->unit()->one(), $order->receiptLines->sum('packs')) }}</span>
                            are already counted in stock and can be sold.
                        </span>
                        <a href="{{ route('businesses.stock.index', $business) }}"
                           class="ml-auto font-medium underline">See stock</a>
                    </div>

                    @if ($bill->dailyEntry->isEditable())
                        <div class="flex flex-wrap items-center gap-3 border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900 sm:px-6">
                            <span>
                                The money has not reached the ledger yet — what the stock is
                                worth, the payable to {{ $order->company->name }} and the cash paid
                                all move when the daily entry for
                                {{ $bill->dailyEntry->business_date->format('j M Y') }} is posted.
                            </span>
                            <a href="{{ route('businesses.daily.show', [$business, $bill->dailyEntry]) }}"
                               class="ml-auto font-semibold underline">Post that day</a>
                        </div>
                    @endif
                @endif

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                @foreach ([['Product','left'],['Ordered','right'],['Received','right'],['Difference','right'],['Reason','left']] as [$h,$align])
                                    <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first ? 'sm:px-6' : '' }}">{{ $h }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($rows as $row)
                                <tr class="{{ $row['difference'] === 0 ? '' : 'bg-amber-50/40' }}">
                                    <td class="px-3 py-2 sm:px-6">
                                        <span class="font-medium text-gray-900">{{ $row['line']?->label() ?? $row['receipt']->label() }}</span>
                                        @if ($row['line'] === null)
                                            <x-badge classes="ml-2 bg-sky-50 text-sky-800 ring-sky-600/20">not ordered</x-badge>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-600">
                                        {{ $row['ordered'] === 0 ? '—' : number_format($row['ordered']) }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-900">
                                        {{ $row['received'] === 0 ? '—' : number_format($row['received']) }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums {{ $row['difference'] === 0 ? 'text-gray-400' : ($row['difference'] < 0 ? 'font-semibold text-amber-700' : 'font-semibold text-sky-700') }}">
                                        @if ($row['difference'] === 0)
                                            as ordered
                                        @else
                                            {{ $row['difference'] > 0 ? '+' : '' }}{{ number_format($row['difference']) }}
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-xs text-gray-600">
                                        @if ($row['receipt']?->note)
                                            {{ $row['receipt']->note }}
                                        @elseif ($row['receipt'] === null && $row['line'] !== null)
                                            {{-- Distinct from a recorded zero: this one was never
                                                 checked off at all, so nobody said why. --}}
                                            <span class="text-gray-400">not checked off</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-panel>
        @endif
        </div>

        @if ($order->isEditable())
            <p class="mb-6 text-sm text-gray-500 print:hidden">
                This is still a draft. Send the PDF or the image to the company, then mark it sent — after that it
                is fixed, so their copy and yours cannot disagree.
            </p>
        @endif

        @if ($hasForm && ! $business->address && ! $business->phone && ! $business->email && ! $business->ntn)
            <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 print:hidden">
                This order goes out with only your business name on it — no address, phone or NTN.
                @can('update', $business)
                    <a href="{{ route('admin.businesses.edit', $business) }}" class="font-semibold underline">Add them</a>
                    and every order form from now on carries them.
                @else
                    Ask the App Owner to add them.
                @endcan
            </div>
        @endif

        {{-- The document itself. This is what gets photographed and printed,
             so it is plain, white, and free of anything that only makes sense
             on a screen. Left out altogether when no order was written: an
             empty form with a zero total is not a document. --}}
        @if ($hasForm)
        <div id="order-document" x-show="tab === 'order'"
             class="mx-auto max-w-4xl bg-white p-8 shadow-sm ring-1 ring-gray-200 print:shadow-none print:ring-0">
            <div class="flex flex-wrap items-start justify-between gap-6 border-b-2 border-gray-900 pb-4">
                {{-- The letterhead: who this order is from, and how to reach
                     them. A company receiving it should not have to look the
                     sender up. --}}
                <div class="min-w-0">
                    <h2 class="text-2xl font-bold text-gray-900">{{ $business->name }}</h2>
                    @if ($business->address)
                        <p class="mt-1 text-sm text-gray-600">{{ $business->address }}</p>
                    @endif
                    @php($contact = array_filter([$business->phone, $business->email]))
                    @if ($contact)
                        <p class="text-sm text-gray-600">{{ implode(' · ', $contact) }}</p>
                    @endif
                    @if ($business->ntn)
                        <p class="text-sm text-gray-600">NTN {{ $business->ntn }}</p>
                    @endif
                </div>

                <div class="text-right text-sm">
                    <p class="text-xs font-semibold uppercase tracking-widest text-gray-500">Order form</p>
                    <p class="mt-1 text-lg font-bold text-gray-900">{{ $order->reference }}</p>
                    <p class="text-gray-600">{{ $order->business_date->format('j F Y') }}</p>
                </div>
            </div>

            <div class="flex flex-wrap justify-between gap-6 py-4">
                <div class="text-sm">
                    <p class="font-semibold uppercase tracking-wide text-gray-500">To</p>
                    <p class="mt-1 text-base font-semibold text-gray-900">{{ $order->company->name }}</p>
                    @if ($order->company->contact)
                        <p class="text-gray-600">{{ $order->company->contact }}</p>
                    @endif
                    @if ($order->company->phone)
                        <p class="text-gray-600">{{ $order->company->phone }}</p>
                    @endif
                </div>

                @if ($order->discount_percent)
                    <div class="text-right text-sm">
                        <p class="font-semibold uppercase tracking-wide text-gray-500">Agreed discount</p>
                        <p class="mt-1 text-base font-semibold text-gray-900">{{ rtrim(rtrim($order->discount_percent, '0'), '.') }}% on all items</p>
                    </div>
                @endif
            </div>

            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="border-y border-gray-300 bg-gray-50">
                        <th class="px-2 py-2 text-left font-semibold text-gray-700">#</th>
                        <th class="px-2 py-2 text-left font-semibold text-gray-700">Product</th>
                        <th class="px-2 py-2 text-left font-semibold text-gray-700">Pack</th>
                        <th class="px-2 py-2 text-right font-semibold text-gray-700">Qty</th>
                        <th class="px-2 py-2 text-right font-semibold text-gray-700">Rate</th>
                        @if ($order->hasDiscount())
                            <th class="px-2 py-2 text-right font-semibold text-gray-700">Disc</th>
                        @endif
                        <th class="px-2 py-2 text-right font-semibold text-gray-700">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->lines as $line)
                        <tr class="border-b border-gray-200">
                            <td class="px-2 py-2 text-gray-500">{{ $loop->iteration }}</td>
                            <td class="px-2 py-2">
                                <span class="font-medium text-gray-900">{{ $line->label() }}</span>
                                @if ($line->generic_name)
                                    <div class="text-xs text-gray-500">{{ $line->generic_name }}</div>
                                @endif
                            </td>
                            <td class="px-2 py-2 text-gray-600">{{ $line->pack_size ?? '—' }}</td>
                            <td class="px-2 py-2 text-right text-gray-900">{{ $line->quantityLabel() }}</td>
                            <td class="px-2 py-2 text-right font-mono tabular-nums text-gray-900">{{ $line->rate->format() }}</td>
                            @if ($order->hasDiscount())
                                <td class="px-2 py-2 text-right text-gray-600">
                                    @if ($line->discountPercent($order->discount_percent))
                                        {{ rtrim(rtrim($line->discountPercent($order->discount_percent), '0'), '.') }}%
                                    @else
                                        —
                                    @endif
                                </td>
                            @endif
                            <td class="px-2 py-2 text-right font-mono tabular-nums text-gray-900">
                                {{ $line->amount($order->discount_percent)->format() }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    @if ($order->hasDiscount())
                        <tr>
                            <td colspan="{{ $labelSpan }}" class="px-2 pt-3 text-right text-gray-600">Before discount</td>
                            <td class="px-2 pt-3 text-right font-mono tabular-nums text-gray-700">{{ $order->gross()->format() }}</td>
                        </tr>
                        <tr>
                            <td colspan="{{ $labelSpan }}" class="px-2 py-1 text-right text-gray-600">Discount</td>
                            <td class="px-2 py-1 text-right font-mono tabular-nums text-gray-700">− {{ $order->discountTotal()->format() }}</td>
                        </tr>
                    @endif
                    <tr class="border-t-2 border-gray-900">
                        <td colspan="{{ $labelSpan }}" class="px-2 pt-2 text-right font-semibold text-gray-900">Total</td>
                        <td class="px-2 pt-2 text-right font-mono text-base font-bold tabular-nums text-gray-900">
                            Rs. {{ $order->total()->format() }}
                        </td>
                    </tr>
                </tfoot>
            </table>

            <div class="mt-4 flex flex-wrap justify-between gap-6 text-sm text-gray-600">
                <p>{{ number_format($order->totalPacks()) }} packs across {{ $order->lines->count() }} {{ Str::plural('item', $order->lines->count()) }}</p>
                <p>Prepared by {{ $order->creator->name }}</p>
            </div>

            @if ($order->notes)
                <div class="mt-4 border-t border-gray-200 pt-3 text-sm">
                    <p class="font-semibold text-gray-700">Note</p>
                    <p class="mt-1 whitespace-pre-line text-gray-600">{{ $order->notes }}</p>
                </div>
            @endif

            <p class="mt-6 border-t border-gray-200 pt-3 text-xs text-gray-400">
                This is an order, not an invoice. Prices are as quoted and subject to the company's confirmation.
            </p>
        </div>
        @endif

    </div>
</x-workspace-layout>
