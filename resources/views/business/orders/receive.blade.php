<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Receive {{ $order->reference }}</h1>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $order->company->name }} · ordered {{ $order->business_date->format('j M Y') }}
                    @if ($order->sent_at) · sent {{ $order->sent_at->diffForHumans() }} @endif
                </p>
            </div>

            {{-- What the delivery is worth, beside what was asked for. More
                 than ordered reads green, less reads red; the same reads as
                 neither, because it is not news. --}}
            <div class="flex flex-wrap items-stretch gap-3"
                 x-data="{ value: '0.00', variance: '', direction: 'same' }"
                 x-on:receipt-count.window="value = $event.detail.value;
                                            variance = $event.detail.variance;
                                            direction = $event.detail.direction">
                <div class="rounded-md bg-gray-100 px-3 py-2">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Ordered</p>
                    <p class="mt-0.5 font-mono text-sm font-semibold tabular-nums text-gray-600">
                        {{ $order->total()->format() }}
                    </p>
                </div>

                <div class="rounded-md bg-gray-100 px-3 py-2">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Value received / bill</p>
                    <p class="mt-0.5 font-mono text-sm font-semibold tabular-nums text-gray-900" x-text="value"></p>
                    <p class="text-xs font-medium"
                       :class="direction === 'over' ? 'text-emerald-700'
                           : (direction === 'under' ? 'text-red-700' : 'text-gray-400')"
                       x-text="variance"></p>
                </div>
            </div>

            {{-- The header is rendered outside the form, so the submit button
                 names the form it belongs to rather than sitting inside it. --}}
            <div class="flex flex-wrap items-center justify-end gap-2">
                <a href="{{ route('businesses.orders.show', [$business, $order]) }}"
                   class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </a>
                {{-- The header sits outside the body's Alpine scope, so the
                     count comes across on an event rather than being read
                     directly from it. --}}
                {{-- Opens the confirmation rather than saving outright. The
                     modal itself lives in the body, where it can see the
                     figures; x-modal listens on the window, so the header can
                     open it from outside that scope. --}}
                <button type="button"
                        x-data="{ recorded: 0 }"
                        x-on:receipt-count.window="recorded = $event.detail.count"
                        x-on:click="$dispatch('open-modal', 'confirm-delivery')"
                        :disabled="recorded === 0"
                        :title="recorded === 0 ? 'Tick what arrived first' : ''"
                        class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-gray-300">
                    Record the delivery
                </button>
            </div>
        </div>
    </x-slot>

    <div class="w-full px-4 py-4 sm:px-6 lg:px-8"
         x-effect="$dispatch('receipt-count', {
             count: received.length + extras.length,
             value: fmt(receivedValue),
             variance: receivedVariance,
             direction: receivedValue > num(orderTotal) ? 'over'
                 : (receivedValue < num(orderTotal) ? 'under' : 'same'),
         })"
         x-data="receiveOrder({
             searchUrl: '{{ route('businesses.orders.product-search', $business) }}',
             companyId: {{ $order->company_id }},
             orderTotal: {{ Illuminate\Support\Js::from($order->total()->toDecimal()) }},
             discount: {{ Illuminate\Support\Js::from($order->discount_percent) }},
             outstanding: {{ Illuminate\Support\Js::from($companyOutstanding->toDecimal()) }},
             invoice: {{ Illuminate\Support\Js::from([
                 'invoice_no' => old('invoice_no', $order->purchaseLine?->invoice_no ?? ''),
                 'paid' => old('paid', $order->purchaseLine?->paid?->format() ?? ''),
             ]) }},
             lines: {{ Illuminate\Support\Js::from($order->lines->map(fn ($l) => [
                 'id' => $l->id,
                 'label' => $l->label(),
                 'generic_name' => $l->generic_name,
                 'pack_size' => $l->pack_size,
                 'case_size' => $l->case_size,
                 'ordered_cartons' => $l->cartons,
                 'ordered_packs' => $l->packs,
                 'rate' => $l->rate->toDecimal(),
                 'line_discount' => $l->discount_percent,
                 'cartons' => ($r = $order->receiptLines->firstWhere('order_line_id', $l->id)) ? $r->cartons : $l->cartons,
                 'packs' => $r ? $r->packs : $l->packs,
                 'note' => $r?->note ?? '',
                 'checked' => $r !== null,
                 'changed' => $r !== null && $r->packs !== $l->packs,
             ])->values()) }},
             existingExtras: {{ Illuminate\Support\Js::from($order->receiptLines->filter(fn ($r) => $r->isUnordered())->map(fn ($r) => [
                 'company_product_id' => $r->company_product_id,
                 'label' => $r->label(),
                 'generic_name' => $r->generic_name,
                 'pack_size' => $r->pack_size,
                 'case_size' => $r->case_size,
                 'cartons' => $r->cartons,
                 'packs' => $r->packs,
                 'rate' => $r->rate->toDecimal(),
             ])->values()) }},
         })">
        <x-flash />

        @if ($errors->has('status'))
            <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ $errors->first('status') }}
            </div>
        @endif

        <form id="receive-form" method="POST" action="{{ route('businesses.orders.receive.store', [$business, $order]) }}">
            @csrf

            {{-- Two panes: tick items off on the left as you unpack, and they
                 cross to the right where the quantity is confirmed. Whatever is
                 still on the left when you save simply did not arrive. --}}
            <div class="flex flex-col gap-4 lg:h-[calc(100vh-15rem)] lg:flex-row">

                {{-- Left: still to check --}}
                <x-panel class="flex min-h-0 min-w-0 flex-col lg:flex-1">
                    <div class="flex shrink-0 flex-wrap items-center gap-3 border-b border-gray-200 px-4 py-3 sm:px-6">
                        <h2 class="text-base font-semibold text-gray-900">Ordered</h2>
                        <span class="text-sm" :class="pending.length ? 'text-amber-700' : 'text-gray-500'">
                            <span x-text="pending.length"></span> still to check
                        </span>
                        <span class="text-xs text-gray-400">tick = came as ordered</span>
                        <button type="button" x-on:click="checkAll()" x-show="pending.length" x-cloak
                                class="ml-auto rounded-md border border-gray-300 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                            Check all
                        </button>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto p-2">
                        <template x-if="! pending.length">
                            <p class="py-10 text-center text-sm text-emerald-700">
                                Everything has been checked off.
                            </p>
                        </template>

                        <ul class="divide-y divide-gray-100">
                            <template x-for="line in pending" :key="line.id">
                                <li class="grid grid-cols-[1.25rem_minmax(0,1fr)_3.5rem_4.5rem_7.5rem] items-start gap-2 px-2 py-2 sm:px-4">
                                    {{-- Ticking it means it came as ordered, and
                                         moves it across. Not submitted — the
                                         hidden fields on the other side are. --}}
                                    <input type="checkbox" x-on:change="check(line)"
                                           :aria-label="`Received as ordered: ${line.label}`"
                                           class="mt-0.5 h-4 w-4 cursor-pointer rounded border-gray-300 text-emerald-700 focus:ring-emerald-600">

                                    <div class="min-w-0">
                                        <p class="break-words text-sm font-medium leading-snug text-gray-900" x-text="line.label"></p>
                                        <p class="break-words text-xs leading-snug text-gray-500" x-text="line.generic_name || ''"></p>
                                    </div>

                                    <div class="text-right text-xs leading-snug text-gray-500">
                                        <p class="truncate" x-text="line.pack_size || '—'"></p>
                                    </div>

                                    <div class="text-right leading-snug">
                                        <p class="font-mono text-sm tabular-nums text-gray-900" x-text="fmtInt(line.ordered_packs)"></p>
                                        <p class="text-xs text-gray-400" x-show="line.ordered_cartons" x-cloak>
                                            <span x-text="line.ordered_cartons"></span> ctn
                                        </p>
                                    </div>

                                    <div class="text-right">
                                        {{-- For when it did not come as ordered. --}}
                                        <button type="button" x-on:click="openChange(line)"
                                                class="w-full whitespace-nowrap rounded-md border border-gray-300 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                            Record change
                                        </button>
                                    </div>
                                </li>
                            </template>
                        </ul>
                    </div>

                    <p class="shrink-0 border-t border-gray-200 px-4 py-2 text-xs text-gray-500 sm:px-6">
                        Anything left here counts as not delivered.
                    </p>
                </x-panel>

                {{-- Right: received --}}
                <x-panel class="flex min-h-0 min-w-0 flex-col lg:flex-1">
                    <div class="flex shrink-0 flex-wrap items-center gap-3 border-b border-gray-200 px-4 py-3 sm:px-6">
                        <h2 class="text-base font-semibold text-gray-900">Received</h2>
                        <span class="text-sm text-gray-500">
                            <span x-text="received.length + extras.length"></span> lines
                        </span>
                        <span class="text-sm text-emerald-700" x-show="matching > 0" x-cloak>
                            <span x-text="matching"></span> as ordered
                        </span>
                        <span class="text-sm text-amber-700" x-show="short > 0" x-cloak>
                            <span x-text="short"></span> short
                        </span>
                        <span class="text-sm text-sky-700" x-show="over > 0" x-cloak>
                            <span x-text="over"></span> over
                        </span>
                        <span class="text-sm text-gray-500" x-show="extras.length" x-cloak>
                            <span x-text="extras.length"></span> unordered
                        </span>
                        <div class="ml-auto flex items-center gap-2">
                            <button type="button" x-on:click="uncheckAll()" x-show="received.length" x-cloak
                                    class="rounded-md border border-gray-300 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                Send all back
                            </button>
                            <button type="button" x-on:click="openUnordered()"
                                    class="whitespace-nowrap rounded-md border border-gray-300 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                Sent but not ordered<template x-if="extras.length"><span> (<span x-text="extras.length"></span>)</span></template>
                            </button>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 overflow-auto">
                        <template x-if="! received.length && ! extras.length">
                            <p class="py-10 text-center text-sm text-gray-500">
                                Nothing checked off yet. Work down the order on the left as you unpack.
                            </p>
                        </template>

                        <table class="min-w-full divide-y divide-gray-200 text-sm" x-show="received.length" x-cloak>
                            <thead class="sticky top-0 z-10 bg-gray-50">
                                <tr>
                                    @foreach ([['Product','left'],['Ordered','right'],['Received','right'],['Difference','right'],['Note','left'],['','right']] as [$h,$align])
                                        <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first ? 'sm:px-6' : '' }}">{{ $h }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <template x-for="line in received" :key="line.id">
                                    <tr class="align-top" :class="difference(line) === 0 ? '' : 'bg-amber-50/40'">
                                        <td class="px-3 py-2 sm:px-6">
                                            <div class="flex items-start gap-2">
                                                <input type="checkbox" checked x-on:change="uncheck(line)"
                                                       :aria-label="`Send back to ordered: ${line.label}`"
                                                       class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded border-gray-300 text-emerald-700 focus:ring-emerald-600">
                                                <div class="min-w-0">
                                                    <span class="block max-w-[14rem] break-words font-medium leading-snug text-gray-900" x-text="line.label"></span>
                                                    <span class="block break-words text-xs leading-snug text-gray-500" x-text="line.generic_name || ''"></span>
                                                </div>
                                            </div>
                                        </td>

                                        <td class="px-3 py-2 text-right font-mono text-xs tabular-nums text-gray-500"
                                            x-text="fmtInt(line.ordered_packs)"></td>

                                        <td class="px-3 py-2 text-right">
                                            {{-- Posted from here; altered only through the modal, so
                                                 what crossed over cannot be nudged by accident. --}}
                                            <input type="hidden" :name="`lines[${line.id}][cartons]`" :value="line.cartons ?? ''">
                                            <input type="hidden" :name="`lines[${line.id}][packs]`" :value="line.packs === '' ? '0' : line.packs">
                                            <input type="hidden" :name="`lines[${line.id}][note]`" :value="line.note ?? ''">

                                            <span class="font-mono text-sm tabular-nums text-gray-900" x-text="fmtInt(line.packs)"></span>
                                            <template x-if="line.cartons">
                                                <div class="text-xs text-gray-400"><span x-text="line.cartons"></span> ctn</div>
                                            </template>
                                        </td>

                                        <td class="px-3 py-2 text-right font-mono text-sm tabular-nums" :class="diffClass(line)"
                                            x-text="diffLabel(line)"></td>

                                        <td class="px-3 py-2 text-xs text-gray-600">
                                            <span class="block max-w-[12rem] break-words" x-text="line.note || ''"></span>
                                        </td>

                                        <td class="whitespace-nowrap px-3 py-2 text-right">
                                            <button type="button" x-on:click="openChange(line)"
                                                    class="text-xs font-medium text-emerald-700 hover:text-emerald-800">Change</button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>

                        {{-- Things the company sent that were never asked for. --}}
                    </div>
                </x-panel>
            </div>

        <x-drawer name="unordered-items" maxWidth="2xl"
                  title="Sent but not ordered"
                  subtitle="Anything the company delivered that was never on the order.">
            <div class="space-y-4">
                <div>
                    <x-text-input type="search" class="block w-full"
                                  placeholder="Search, or pick from the list below…"
                                  x-model="query" x-on:input.debounce.250ms="search()"
                                  x-on:keydown.escape="query = ''; results = []" />

                    <div class="mt-1 flex items-baseline justify-between text-xs text-gray-400">
                        <span x-text="query ? 'Matches' : 'Everything this company sells'"></span>
                        <span>
                            <span x-text="query ? results.length : catalogue.length"></span>
                            of <span x-text="catalogueTotal"></span>
                        </span>
                    </div>

                    {{-- The supplier's catalogue, open and scrollable, so an
                         unordered item can be found by reading rather than by
                         guessing at its name. --}}
                    <div class="mt-1 max-h-72 overflow-y-auto rounded-md border border-gray-200"
                         x-on:scroll.debounce.100ms="maybeLoadMore($event.target)">
                        <template x-if="query && ! results.length">
                            <p class="px-3 py-6 text-center text-sm text-gray-500">
                                Nothing matches “<span x-text="query"></span>”.
                            </p>
                        </template>

                        <template x-if="! query && ! catalogue.length && ! loadingCatalogue">
                            <p class="px-3 py-6 text-center text-sm text-gray-500">
                                This company has no products in the catalogue yet.
                            </p>
                        </template>

                        <ul class="divide-y divide-gray-100">
                            <template x-for="p in (query ? results : catalogue)" :key="p.id">
                                <li x-on:click="addExtra(p)" class="cursor-pointer px-3 py-2 hover:bg-emerald-50">
                                    @include('business.orders._product-row')
                                </li>
                            </template>
                        </ul>

                        <p x-show="loadingCatalogue" class="py-3 text-center text-xs text-gray-400">Loading…</p>

                        <button type="button" x-on:click="loadCatalogue()"
                                x-show="! query && catalogueNext !== null && ! loadingCatalogue"
                                class="w-full border-t border-gray-100 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-50">
                            Load more
                        </button>
                    </div>
                </div>

                <table class="min-w-full divide-y divide-gray-200 text-sm" x-show="extras.length" x-cloak>
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach ([['Product','left'],['Cartons','right'],['Packs','right'],['Rate','right'],['','right']] as [$h,$align])
                                <th class="whitespace-nowrap px-2 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }}">{{ $h }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="(extra, i) in extras" :key="i">
                            <tr class="align-top">
                                <td class="py-2 pr-3">
                                    <input type="hidden" :name="`extras[${i}][company_product_id]`" :value="extra.company_product_id ?? ''">
                                    <input type="hidden" :name="`extras[${i}][case_size]`" :value="extra.case_size ?? ''">
                                    <span class="block break-words font-medium leading-snug text-gray-900" x-text="extra.label"></span>
                                    <span class="block text-xs text-gray-500" x-text="extra.generic_name || ''"></span>
                                </td>
                                <td class="py-2 pr-3 text-right">
                                    <input type="number" min="1" :name="`extras[${i}][cartons]`" x-model="extra.cartons"
                                           x-on:input="fromCartons(extra)" :disabled="! extra.case_size" placeholder="ctn"
                                           class="w-20 rounded-md border-gray-300 text-right text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600 disabled:bg-gray-100">
                                </td>
                                <td class="py-2 pr-3 text-right">
                                    <input type="number" min="1" :name="`extras[${i}][packs]`" x-model="extra.packs"
                                           x-on:input="fromPacks(extra)" placeholder="packs"
                                           class="w-24 rounded-md border-gray-300 text-right text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                </td>
                                <td class="py-2 pr-3 text-right">
                                    <input type="text" inputmode="decimal" :name="`extras[${i}][rate]`" x-model="extra.rate"
                                           class="w-24 rounded-md border-gray-300 text-right font-mono text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                </td>
                                <td class="py-2 text-right">
                                    <button type="button" x-on:click="extras.splice(i, 1)"
                                            class="text-sm text-gray-400 hover:text-red-700" aria-label="Remove">✕</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <p class="text-sm text-gray-500" x-show="! extras.length">
                    Nothing so far. Most deliveries have none.
                </p>
            </div>

            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close-drawer', 'unordered-items')"
                        class="w-full rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                    Done
                </button>
            </x-slot>
        </x-drawer>
        </form>

        {{--
            Recording what actually came for one item.

            Opened from either column: from the left when the delivery differs
            from the order, from the right to correct something already checked
            off. The reason is optional but it is the part that is worth having
            in three months' time, when "why were we short 120 packs" is the
            question.
        --}}

        {{--
            The last look before it is recorded.

            Worth a pause because this is the point where a delivery stops being
            a tally and becomes an invoice on a day's entry — so the payment is
            spelled out, and where it is going is named.
        --}}
        <x-modal name="confirm-delivery" maxWidth="lg" focusable>
            <div class="p-6">
                <h2 class="text-lg font-semibold text-gray-900">Record this delivery?</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    {{ $order->reference }} · {{ $order->company->name }}
                </p>

                <dl class="mt-4 space-y-1 border-y border-gray-100 py-3 text-sm">
                    <div class="flex items-baseline justify-between">
                        <dt class="text-gray-500">Checked off</dt>
                        <dd class="text-gray-900">
                            <span class="font-mono font-semibold tabular-nums" x-text="received.length"></span>
                            of <span x-text="lines.length"></span> lines
                        </dd>
                    </div>
                    <div class="flex items-baseline justify-between" x-show="short > 0" x-cloak>
                        <dt class="text-amber-700">Short</dt>
                        <dd class="font-mono font-semibold tabular-nums text-amber-700" x-text="short + ' lines'"></dd>
                    </div>
                    <div class="flex items-baseline justify-between" x-show="over > 0" x-cloak>
                        <dt class="text-sky-700">Over</dt>
                        <dd class="font-mono font-semibold tabular-nums text-sky-700" x-text="over + ' lines'"></dd>
                    </div>
                    <div class="flex items-baseline justify-between" x-show="extras.length" x-cloak>
                        <dt class="text-gray-500">Sent but not ordered</dt>
                        <dd class="font-mono font-semibold tabular-nums text-gray-900" x-text="extras.length + ' lines'"></dd>
                    </div>
                    <div class="flex items-baseline justify-between" x-show="pending.length" x-cloak>
                        <dt class="text-amber-700">Not arrived</dt>
                        <dd class="font-mono font-semibold tabular-nums text-amber-700" x-text="pending.length + ' lines'"></dd>
                    </div>
                </dl>

                {{--
                    The bill is asked for here rather than on the page behind,
                    because this is the moment it matters: the invoice usually
                    comes out of the envelope once the goods are checked in.

                    The inputs name the form they belong to — the modal renders
                    outside it, and an input that does not say so is simply not
                    submitted.
                --}}
                <div class="mt-4 rounded-md bg-gray-50 px-4 py-3 text-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Payment</p>

                    <div class="mt-2 space-y-3">
                        <div class="flex items-baseline justify-between gap-4">
                            <span class="text-gray-500">Bill amount</span>
                            <span class="font-mono font-semibold tabular-nums text-gray-900" x-text="fmt(receivedValue)"></span>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <x-input-label for="invoice_no" value="Invoice no" />
                                <x-text-input id="invoice_no" name="invoice_no" form="receive-form" type="text"
                                              class="mt-1 block w-full" placeholder="INV-4471"
                                              x-model="invoice.invoice_no" />
                            </div>

                            <div>
                                <x-input-label for="paid" value="Paying now" />
                                {{-- Grouped when you leave it, unpicked back to a
                                     plain number while you are typing. --}}
                                <x-text-input id="paid" name="paid" form="receive-form" type="text" inputmode="decimal"
                                              class="mt-1 block w-full text-right font-mono tabular-nums" placeholder="0.00"
                                              x-model="invoice.paid"
                                              x-on:focus="invoice.paid = num(invoice.paid) ? String(num(invoice.paid)) : ''"
                                              x-on:blur="invoice.paid = num(invoice.paid) ? fmt(num(invoice.paid)) : ''" />
                            </div>
                        </div>

                        <div class="flex items-baseline justify-between gap-4 border-t border-gray-200 pt-2">
                            <span class="font-semibold text-gray-900">Pending on this bill</span>
                            <span class="font-mono font-semibold tabular-nums"
                                  :class="pendingAmount > 0 ? 'text-amber-700' : 'text-gray-900'"
                                  x-text="fmt(pendingAmount)"></span>
                        </div>

                        {{-- Paying past the bill is allowed and is not a mistake
                             — it either clears what this supplier was already
                             owed, or runs ahead of them. Which of the two it is
                             depends on their ledger, so it is spelled out. --}}
                        <template x-if="overpaid > 0">
                            <div class="space-y-1 rounded-md bg-white px-3 py-2 ring-1 ring-sky-200">
                                <div class="flex items-baseline justify-between gap-4">
                                    <span class="text-gray-500">Beyond this bill</span>
                                    <span class="font-mono font-semibold tabular-nums text-sky-700" x-text="fmt(overpaid)"></span>
                                </div>

                                <template x-if="settlesEarlier > 0">
                                    <div class="flex items-baseline justify-between gap-4 text-xs">
                                        <span class="text-gray-500">
                                            against earlier bills
                                            <span class="text-gray-400">(owed <span x-text="fmt(num(outstanding))"></span>)</span>
                                        </span>
                                        <span class="font-mono tabular-nums text-gray-700" x-text="fmt(settlesEarlier)"></span>
                                    </div>
                                </template>

                                <template x-if="advance > 0">
                                    <div class="flex items-baseline justify-between gap-4 text-xs">
                                        <span class="text-gray-500">as an advance to this supplier</span>
                                        <span class="font-mono tabular-nums text-gray-700" x-text="fmt(advance)"></span>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                    <x-input-error :messages="$errors->get('paid')" class="mt-2" />
                </div>

                <p class="mt-3 text-xs text-gray-500" x-show="num(invoice.paid) > 0 || invoice.invoice_no" x-cloak>
                    @if ($order->purchaseLine)
                        This updates the invoice already on the daily entry for
                        {{ $order->purchaseLine->dailyEntry->business_date->format('j M Y') }}.
                    @else
                        This puts the invoice on today's daily entry. It posts to the ledger when that day is
                        posted — not now.
                    @endif
                </p>
                <p class="mt-3 text-xs text-gray-500" x-show="! (num(invoice.paid) > 0 || invoice.invoice_no)" x-cloak>
                    No invoice number and no payment, so nothing goes to the daily entry — this records the goods only.
                </p>

                <div class="mt-5 flex items-center justify-end gap-3">
                    <button type="button" x-on:click="$dispatch('close-modal', 'confirm-delivery')"
                            class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Go back
                    </button>
                    <button type="submit" form="receive-form"
                            class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                        Record the delivery
                    </button>
                </div>
            </div>
        </x-modal>

        <x-modal name="record-change" maxWidth="lg" focusable>
            <div class="p-6" x-show="change.line">
                <template x-if="change.line">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900" x-text="change.line.label"></h2>
                        <p class="mt-0.5 text-sm text-gray-500" x-text="change.line.generic_name || ''"></p>

                        <dl class="mt-4 grid grid-cols-2 gap-x-6 border-y border-gray-100 py-3 text-sm">
                            <div>
                                <dt class="text-xs text-gray-500">Ordered</dt>
                                <dd class="font-mono tabular-nums text-gray-900">
                                    <span x-text="fmtInt(change.line.ordered_packs)"></span> packs
                                    <template x-if="change.line.ordered_cartons">
                                        <span class="text-gray-400"> (<span x-text="change.line.ordered_cartons"></span> ctn)</span>
                                    </template>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500">Pack</dt>
                                <dd class="text-gray-900">
                                    <span x-text="change.line.pack_size || '—'"></span>
                                    <template x-if="change.line.case_size">
                                        <span class="text-gray-400"> · case of <span x-text="change.line.case_size"></span></span>
                                    </template>
                                </dd>
                            </div>
                        </dl>

                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="change-cartons" value="Cartons received" />
                                <x-text-input id="change-cartons" type="number" min="0" class="mt-1 block w-full"
                                              x-model="change.cartons" x-on:input="changeFromCartons()"
                                              ::disabled="! change.line.case_size" />
                            </div>
                            <div>
                                <x-input-label for="change-packs" value="Packs received" />
                                <x-text-input id="change-packs" type="number" min="0" class="mt-1 block w-full"
                                              x-model="change.packs" x-on:input="changeFromPacks()" />
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" x-on:click="changeAsOrdered()"
                                    class="rounded-md border border-gray-300 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                As ordered
                            </button>
                            <button type="button" x-on:click="changeNone()"
                                    class="rounded-md border border-gray-300 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                None arrived
                            </button>
                        </div>

                        <div class="mt-4">
                            <x-input-label for="change-note" value="Reason (optional)" />
                            <x-text-input id="change-note" type="text" class="mt-1 block w-full" maxlength="255"
                                          x-model="change.note"
                                          placeholder="Out of stock until next month, two cartons damaged…" />
                        </div>

                        <div class="mt-4 rounded-md bg-gray-50 px-4 py-3 text-sm">
                            <div class="flex items-baseline justify-between">
                                <span class="text-gray-500">Against the order</span>
                                <span class="font-mono font-semibold tabular-nums" :class="changeDiffClass"
                                      x-text="changeDiffLabel"></span>
                            </div>
                        </div>

                        <div class="mt-5 flex items-center justify-end gap-3">
                            <button type="button" x-on:click="$dispatch('close-modal', 'record-change')"
                                    class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                Cancel
                            </button>
                            <button type="button" x-on:click="commitChange()"
                                    class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                                Record change
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </x-modal>
    </div>

    @push('scripts')
        <script>
            function receiveOrder({ searchUrl, companyId, lines, existingExtras, orderTotal, discount, invoice, outstanding }) {
                return {
                    searchUrl,
                    companyId,
                    lines,
                    orderTotal,
                    discount,
                    invoice,
                    outstanding,
                    extras: existingExtras || [],
                    query: '',
                    results: [],

                    catalogue: [],
                    catalogueTotal: 0,
                    catalogueNext: 0,
                    loadingCatalogue: false,

                    openUnordered() {
                        if (! this.catalogue.length) this.loadCatalogue();
                        this.$dispatch('open-drawer', 'unordered-items');
                    },

                    async fetchProducts({ q = '', offset = 0, limit = 25 }) {
                        const params = new URLSearchParams({ company: this.companyId, q, offset, limit });
                        const response = await fetch(`${this.searchUrl}?${params}`, {
                            headers: { 'Accept': 'application/json' },
                        });

                        if (! response.ok) throw new Error('lookup failed');

                        return await response.json();
                    },

                    async loadCatalogue() {
                        if (this.loadingCatalogue || this.catalogueNext === null) return;
                        this.loadingCatalogue = true;
                        try {
                            const data = await this.fetchProducts({ offset: this.catalogueNext, limit: 100 });
                            this.catalogue.push(...data.products);
                            this.catalogueTotal = data.total;
                            this.catalogueNext = data.next;
                        } catch (e) {
                            this.catalogueNext = null;
                        } finally {
                            this.loadingCatalogue = false;
                        }
                    },

                    maybeLoadMore(el) {
                        if (! this.query && el.scrollTop + el.clientHeight >= el.scrollHeight - 120) this.loadCatalogue();
                    },

                    // The two columns are one list read two ways, so a line can
                    // never be in both and never fall out of the order it was
                    // written in.
                    get pending() { return this.lines.filter(l => ! l.checked); },
                    get received() { return this.lines.filter(l => l.checked); },

                    // The item being recorded in the modal.
                    change: { line: null, cartons: '', packs: '', note: '' },

                    /** Received means received as ordered. One click, no form. */
                    check(line) {
                        line.packs = line.ordered_packs;
                        line.cartons = line.ordered_cartons ?? '';
                        line.note = '';
                        line.changed = false;
                        line.checked = true;
                    },
                    uncheck(line) {
                        line.checked = false;
                        line.changed = false;
                        line.note = '';
                    },

                    openChange(line) {
                        this.change = {
                            line,
                            cartons: line.checked ? (line.cartons ?? '') : (line.ordered_cartons ?? ''),
                            packs: line.checked ? line.packs : line.ordered_packs,
                            note: line.note ?? '',
                        };
                        this.$dispatch('open-modal', 'record-change');
                    },

                    changeFromCartons() {
                        const size = this.change.line?.case_size;
                        if (! size) return;
                        const cartons = this.int(this.change.cartons);
                        this.change.packs = cartons > 0 ? cartons * size : 0;
                    },
                    changeFromPacks() {
                        const size = this.change.line?.case_size;
                        if (! size) { this.change.cartons = ''; return; }
                        const packs = this.int(this.change.packs);
                        this.change.cartons = packs > 0 && packs % size === 0 ? packs / size : '';
                    },
                    changeAsOrdered() {
                        this.change.packs = this.change.line.ordered_packs;
                        this.change.cartons = this.change.line.ordered_cartons ?? '';
                    },
                    changeNone() {
                        this.change.packs = 0;
                        this.change.cartons = '';
                    },

                    get changeDifference() {
                        return this.int(this.change.packs) - this.int(this.change.line?.ordered_packs);
                    },
                    get changeDiffLabel() {
                        const d = this.changeDifference;
                        if (d === 0) return 'as ordered';

                        return (d > 0 ? '+' : '') + d.toLocaleString('en-US') + ' packs';
                    },
                    get changeDiffClass() {
                        const d = this.changeDifference;
                        if (d === 0) return 'text-gray-500';

                        return d < 0 ? 'text-amber-700' : 'text-sky-700';
                    },

                    commitChange() {
                        const line = this.change.line;
                        if (! line) return;

                        line.packs = this.int(this.change.packs);
                        line.cartons = this.change.cartons === '' ? '' : this.int(this.change.cartons);
                        line.note = this.change.note;
                        line.changed = this.changeDifference !== 0 || !! this.change.note;
                        line.checked = true;

                        this.$dispatch('close-modal', 'record-change');
                        this.change = { line: null, cartons: '', packs: '', note: '' };
                    },
                    checkAll() { this.lines.forEach(l => { if (! l.checked) this.check(l); }); },
                    uncheckAll() { this.lines.forEach(l => this.uncheck(l)); },

                    // What the delivery is worth at the order's own rates —
                    // the figure to check the supplier's bill against.
                    lineValue(row) {
                        const paisa = Math.round(this.num(row.rate) * 100) * this.int(row.packs);
                        const pct = row.line_discount !== null && row.line_discount !== undefined && row.line_discount !== ''
                            ? this.num(row.line_discount)
                            : this.num(this.discount);

                        return (pct > 0 ? paisa - Math.floor((paisa * pct) / 100 + 0.5) : paisa) / 100;
                    },

                    get receivedValue() {
                        const lines = this.received.reduce((t, l) => t + this.lineValue(l), 0);
                        const extras = this.extras.reduce((t, e) => t + this.lineValue({ ...e, line_discount: '' }), 0);

                        return lines + extras;
                    },

                    get receivedVariance() {
                        const d = this.receivedValue - this.num(this.orderTotal);
                        if (Math.abs(d) < 0.005) return 'matches the order';

                        return (d > 0 ? '+' : '') + this.fmt(d) + ' against the order';
                    },

                    /** What is left on the supplier's bill. Named apart from
                        `pending`, which is the list of lines not yet checked off. */
                    get pendingAmount() {
                        return Math.max(0, this.receivedValue - this.num(this.invoice.paid));
                    },

                    /** Handed over beyond what this bill comes to. */
                    get overpaid() {
                        return Math.max(0, this.num(this.invoice.paid) - this.receivedValue);
                    },

                    /** Of that, what earlier bills can absorb. */
                    get settlesEarlier() {
                        return Math.min(this.overpaid, Math.max(0, this.num(this.outstanding)));
                    },

                    /** And what is left running ahead of the supplier. */
                    get advance() {
                        return this.overpaid - this.settlesEarlier;
                    },

                    int(v) { const n = parseInt(v, 10); return isNaN(n) ? 0 : n; },
                    num(v) { const n = parseFloat(String(v ?? '').replace(/,/g, '')); return isNaN(n) ? 0 : n; },
                    fmtInt(v) { return this.int(v).toLocaleString('en-US'); },
                    fmt(v) { return this.num(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },

                    fromCartons(row) {
                        if (! row.case_size) return;
                        const cartons = this.int(row.cartons);
                        row.packs = cartons > 0 ? cartons * row.case_size : '';
                    },
                    fromPacks(row) {
                        if (! row.case_size) { row.cartons = ''; return; }
                        const packs = this.int(row.packs);
                        row.cartons = packs > 0 && packs % row.case_size === 0 ? packs / row.case_size : '';
                    },

                    difference(line) { return this.int(line.packs) - this.int(line.ordered_packs); },
                    diffLabel(line) {
                        const d = this.difference(line);

                        return d === 0 ? 'as ordered' : (d > 0 ? '+' : '') + d.toLocaleString('en-US');
                    },
                    diffClass(line) {
                        const d = this.difference(line);
                        if (d === 0) return 'text-gray-400';

                        return d < 0 ? 'font-semibold text-amber-700' : 'font-semibold text-sky-700';
                    },

                    get matching() { return this.received.filter(l => this.difference(l) === 0).length; },
                    get short() { return this.received.filter(l => this.difference(l) < 0).length; },
                    get over() { return this.received.filter(l => this.difference(l) > 0).length; },

                    async search() {
                        if (! this.query) { this.results = []; return; }
                        try {
                            this.results = (await this.fetchProducts({ q: this.query, limit: 50 })).products;
                        } catch (e) {
                            this.results = [];
                        }
                    },

                    addExtra(p) {
                        this.extras.push({
                            company_product_id: p.id, label: p.label, generic_name: p.generic_name,
                            pack_size: p.pack_size, case_size: p.case_size,
                            cartons: p.case_size ? 1 : '', packs: p.case_size || 1, rate: p.rate ?? '',
                        });
                        // The list stays put so several can be added in a row.
                    },

                    // The shared picker row expects these; nothing is discounted
                    // on this screen and nothing is already on an order.
                    discountedRate() { return null; },
                    onOrder(p) {
                        const extra = this.extras.find(e => e.company_product_id === p.id);

                        return extra ? this.int(extra.packs) : 0;
                    },
                };
            }
        </script>
    @endpush
</x-workspace-layout>
