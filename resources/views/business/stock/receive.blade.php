{{--
    Recording a delivery that never had an order form.

    Pick who it came from, tick through what was in the van, and put the
    invoice on it. Behind the scenes this is an ordinary order marked sent and
    received at once — the order was placed, just on the telephone — so the
    goods, the invoice and the trail are identical to every other delivery.
--}}
{{-- Laid out like the till — a screen stood at and worked through — but in
     blue (mode="receiving"), so stock coming in never looks like a sale. --}}
@php($label = 'font-mono text-[11px] uppercase tracking-widest')
@php($many = $business->sellsLoose() ? 'packs' : $business->unit()->many())
@php($cols = $business->sellsAtMrp()
    ? 'grid-cols-[minmax(0,1fr)_6.5rem_5.5rem_10.5rem_7.5rem_6.5rem_6.5rem_2rem]'
    : 'grid-cols-[minmax(0,1fr)_6.5rem_5.5rem_10.5rem_7.5rem_6.5rem_6.5rem_6.5rem_2rem]')
<x-pos-layout :business="$business" title="Receive stock" mode="receiving">
    <div class="flex h-full flex-col" x-data="delivery({
        products: @js($products),
    })" x-on:keydown.window.f2.prevent="$refs.search?.focus()" x-on:keydown.window.f9.prevent="save()"
       x-on:product-added.window="productAdded($event.detail)">

        <header class="flex shrink-0 flex-wrap items-center justify-between gap-x-6 gap-y-2 bg-slate-900 px-4 py-3 text-white sm:px-6">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-600 text-sm font-bold text-white">In</span>
                <div>
                    <p class="text-lg font-semibold leading-tight">{{ $business->name }}</p>
                    <p class="text-xs text-slate-400">Receiving stock · {{ auth()->user()->name }}</p>
                </div>
            </div>

            <div class="text-center">
                <p class="{{ $label }} text-slate-400">{{ $today->format('D d M Y') }}</p>
                <p class="font-mono text-xl font-semibold leading-tight tabular-nums" x-text="clock">&nbsp;</p>
            </div>

            <div class="text-center">
                <p class="{{ $label }} text-slate-400">On this delivery</p>
                <p class="font-mono text-xl font-semibold leading-tight tabular-nums"
                   x-text="lines.length + (lines.length === 1 ? ' line' : ' lines') + ' · ' + totalPacks + ' {{ $many }}'"></p>
            </div>

            <p class="hidden max-w-sm text-xs leading-snug text-slate-400 xl:block">
                Goods that arrived without an order form. They go into stock, and onto today's entry as an invoice.
            </p>

            <div class="flex items-center gap-3">
                <a href="{{ route('businesses.stock.index', $business) }}"
                   class="rounded-lg border border-slate-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">
                    Back to stock
                </a>
                <a href="{{ route('businesses.show', $business) }}"
                   class="rounded-lg border border-slate-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">
                    Leave
                </a>
            </div>
        </header>

        @if ($dayClosed)
            <div class="shrink-0 bg-red-600 px-4 py-2 text-center text-sm font-medium text-white sm:px-6">
                {{ $today->format('l j F Y') }} is closed, so an invoice cannot be put on it. Reopen the day on its closing page first.
            </div>
        @endif

        {{-- "Put into stock", or why it was refused, as a toast. --}}
        <x-flash any />

        <form method="POST" action="{{ route('businesses.stock.receive.store', $business) }}"
              class="flex min-h-0 flex-1 flex-col" x-ref="form" x-on:submit="if (! canSave) { $event.preventDefault(); tried = true }">
            @csrf

            <div class="grid min-h-0 flex-1 grid-cols-1 gap-4 p-4 lg:grid-cols-[minmax(0,4fr)_minmax(0,7.5fr)] sm:px-6">

                {{-- Their catalogue, browsable the way the order form's is: you
                     check a delivery against a list, not by remembering names. --}}
                <section class="flex min-h-0 flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                    <div class="shrink-0 space-y-3 border-b border-slate-200 p-4">
                        {{-- Who it came from: the list below is their catalogue. --}}
                        <label class="flex items-center gap-3">
                            <span class="{{ $label }} shrink-0 text-slate-500">Company</span>
                            <select id="company_id" name="company_id" x-model="companyId" required data-search
                                    class="block w-full rounded-lg border-slate-300 py-2.5 text-base font-semibold text-slate-900 focus:border-emerald-700 focus:ring-emerald-600">
                                <option value="">Choose the company…</option>
                                @foreach ($companies as $company)
                                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <div class="relative">
                            <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-500"
                                 viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="9" cy="9" r="6" /><path d="M14 14l4 4" stroke-linecap="round" />
                            </svg>
                            <input x-ref="search" x-model="query" type="text" autocomplete="off" :disabled="! companyId"
                                   x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)"
                                   x-on:keydown.enter.prevent="addHighlighted()" x-on:keydown.escape="query = ''"
                                   placeholder="Search by brand or code — theirs first, then every product"
                                   class="block w-full rounded-lg border-2 border-emerald-700 py-3 pl-12 pr-16 text-base placeholder:text-slate-400 focus:border-emerald-700 focus:ring-2 focus:ring-emerald-600/30 disabled:border-slate-300 disabled:bg-slate-50">
                            <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 rounded border border-slate-300 px-1.5 py-0.5 font-mono text-xs text-slate-500">F2</span>
                        </div>

                        <p class="flex items-center justify-between text-sm text-slate-600">
                            <span x-show="companyId" x-cloak>
                                <span x-text="shown.length"></span> of <span x-text="ofCompany.length"></span> products
                                <span x-show="fromOthers > 0" x-text="'· ' + fromOthers + ' from other companies'"></span>
                            </span>
                            <span x-show="! companyId">Their products</span>
                            <button type="button" x-show="companyId" x-cloak x-on:click="openNew()"
                                    class="font-medium text-emerald-700 hover:text-emerald-800">
                                Not in the list? Add it
                            </button>
                        </p>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto" x-ref="list">
                        <p x-show="! companyId" class="px-4 py-10 text-center text-sm text-slate-500">
                            Choose a company first — the list reads its catalogue.
                        </p>

                        {{-- Up and Down in the search box move the lit row; Enter
                             puts it on the delivery, as on the till. --}}
                        <template x-for="(product, i) in shown" :key="product.id">
                            <div data-row x-on:mouseenter="highlight = i" :class="highlight === i ? 'bg-emerald-50' : ''"
                                 class="grid w-full grid-cols-[minmax(0,1fr)_auto_6rem_2.75rem] items-center gap-3 border-b border-slate-200 px-4 py-2.5 last:border-0">
                                <button type="button" x-on:click="add(product)" class="min-w-0 text-left">
                                    <span class="block break-words text-base font-medium leading-snug text-slate-900" x-text="product.name"></span>
                                    <span class="block truncate font-mono text-xs text-slate-500" x-text="product.code || '—'"></span>
                                    {{-- Found by the search in another company's
                                         catalogue: the same goods, bought here this time. --}}
                                    <span x-show="! ownProduct(product)" x-cloak
                                          class="mt-0.5 inline-block rounded-md bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20"
                                          x-text="'Usually from ' + (product.company || 'another company')"></span>
                                </button>
                                <span class="{{ $label }} font-semibold text-emerald-700" x-show="onDelivery(product.id)" x-cloak
                                      x-text="onDelivery(product.id) + ' on delivery'"></span>
                                <span x-show="! onDelivery(product.id)"></span>
                                <button type="button" x-on:click="add(product)" title="What it cost last time"
                                        class="text-right font-mono text-sm font-semibold tabular-nums text-slate-900"
                                        x-text="'Rs. ' + money(product.rate)"></button>
                                <button type="button" x-on:click="add(product)" :title="'Add one of ' + product.name"
                                        class="flex h-11 w-11 items-center justify-center justify-self-end rounded-lg bg-emerald-700 text-xl font-semibold leading-none text-white hover:bg-emerald-800">+</button>
                            </div>
                        </template>

                        <p x-show="companyId && shown.length === 0" x-cloak class="px-4 py-10 text-center text-sm text-slate-500">
                            Nothing of theirs matches that.
                            <button type="button" x-on:click="openNew()"
                                    class="font-medium text-emerald-700 hover:text-emerald-800">Add it as a new product</button>
                        </p>
                    </div>
                </section>

                {{-- What is going into stock. --}}
                <section class="flex min-h-0 flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                    {{-- The invoice it arrived on. --}}
                    <div class="flex shrink-0 flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                        <p class="flex items-baseline gap-3">
                            <span class="{{ $label }} text-slate-500">Delivery</span>
                            <span class="font-mono text-xl font-semibold tabular-nums text-emerald-700">{{ $today->format('d M Y') }}</span>
                        </p>
                        <label class="ml-auto flex items-center gap-3">
                            <span class="{{ $label }} text-slate-500">Invoice no.</span>
                            <input id="invoice_no" name="invoice_no" type="text" x-model="invoiceNo" x-ref="invoice" required
                                   placeholder="Required — as printed on their bill" maxlength="60"
                                   :class="tried && ! invoiceNo.trim() ? 'border-red-500 bg-red-50' : (lines.length > 0 && ! invoiceNo.trim() ? 'border-amber-400 bg-amber-50' : 'border-slate-300')"
                                   :aria-invalid="tried && ! invoiceNo.trim()"
                                   class="block w-64 rounded-lg py-2 font-mono text-sm placeholder:font-sans focus:border-emerald-700 focus:ring-emerald-600">
                        </label>
                        <label class="flex items-center gap-3">
                            <span class="{{ $label }} text-slate-500">Note</span>
                            <input id="notes" name="notes" type="text" placeholder="Ordered by phone on Tuesday" maxlength="255"
                                   class="block w-64 rounded-lg border-slate-300 py-2 text-sm focus:border-emerald-700 focus:ring-emerald-600">
                        </label>
                    </div>

                    <div class="{{ $label }} {{ $cols }} grid shrink-0 items-center gap-3 border-y border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-600">
                        <span>On this delivery</span>
                        <span>Pack size</span>
                        <span class="text-center">Qty</span>
                        <span>Expiry · batch</span>
                        <span class="text-right">Cost</span>
                        @unless ($business->sellsAtMrp())
                            <span class="text-right">{{ $sellLabel }}</span>
                        @endunless
                        <span class="text-right">MRP</span>
                        <span class="text-right">Total</span>
                        <span></span>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto" x-ref="delivery">
                        {{-- One row per batch. A product that came in two batches
                             has two rows: the first carries its name, pack and
                             prices; the ones under it carry only their own
                             quantity, expiry and batch number, and take the rest
                             from the first. --}}
                        <template x-for="(line, i) in lines" :key="line.key">
                            {{-- The line just added is scrolled to and held in
                                 colour for a moment, as on the till, so a long
                                 delivery never swallows what was just picked. --}}
                            <div :data-line="line.key" :class="[flash === line.key ? 'bg-emerald-50' : '', line.extra ? 'bg-slate-50/70' : '']"
                                 class="{{ $cols }} grid items-center gap-3 border-b border-slate-200 px-4 py-2.5 transition-colors duration-500">
                                <span class="min-w-0">
                                    <template x-if="! line.extra">
                                        <span>
                                            <span class="block break-words text-base font-medium leading-snug text-slate-900" x-text="line.name"></span>
                                            <span x-show="line.other" x-cloak class="block text-xs text-slate-500" x-text="'Usually from ' + line.other"></span>
                                            <template x-if="priceMove(line)">
                                                <span class="text-xs font-medium" :class="priceMove(line).up ? 'text-red-700' : 'text-emerald-700'"
                                                      x-text="(priceMove(line).up ? '▲ ' : '▼ ') + priceMove(line).percent.toFixed(0) + '% on last cost of ' + priceMove(line).was.toFixed(2)"></span>
                                            </template>
                                        </span>
                                    </template>
                                    <template x-if="line.extra">
                                        <span class="block pl-4 text-sm text-slate-500">↳ another batch of the same</span>
                                    </template>
                                    <input type="hidden" :name="`lines[${i}][company_product_id]`" :value="line.id">
                                </span>

                                {{-- The pack on file, to check against the carton
                                     and correct here if it differs. --}}
                                <span>
                                    <template x-if="! line.extra">
                                        <span>
                                            <input type="text" x-model="line.pack" :name="`lines[${i}][pack_size]`"
                                                   placeholder="10/10" maxlength="60" title="Pack size — change it if the carton says otherwise"
                                                   class="block w-full rounded-lg border-slate-300 px-2 py-2 text-center font-mono text-sm focus:border-emerald-700 focus:ring-emerald-600">
                                            @if ($business->sellsLoose())
                                                <span class="mt-0.5 block text-center text-[11px] leading-tight text-slate-500"
                                                      x-show="piecesFromPack(line.pack) > 1" x-text="piecesFromPack(line.pack) + ' to a pack'"></span>
                                            @endif
                                        </span>
                                    </template>
                                    <template x-if="line.extra">
                                        <input type="hidden" :name="`lines[${i}][pack_size]`" :value="mainOf(line).pack">
                                    </template>
                                </span>

                                <input type="text" inputmode="numeric" x-model.number="line.packs" :name="`lines[${i}][packs]`"
                                       class="w-full rounded-lg border-slate-300 py-2 text-center font-mono text-lg font-semibold tabular-nums focus:border-emerald-700 focus:ring-emerald-600">

                                {{-- The date on the carton, taken while it is still in
                                     someone's hands. Blank is an answer: not everything
                                     expires. Under it, the batch number printed beside it.
                                     The + adds a row for another batch of the same product. --}}
                                <span class="flex items-center gap-1.5">
                                    <span class="min-w-0 flex-1 space-y-1">
                                        <input type="month" x-model="line.expires_on" :name="`lines[${i}][expires_on]`"
                                               :class="line.expires_on && expiresSoon(line.expires_on) ? 'border-amber-400 bg-amber-50' : 'border-slate-300'"
                                               class="block w-full rounded-lg px-2 py-1 text-sm focus:border-emerald-700 focus:ring-emerald-600">
                                        <input type="text" x-model="line.batch_no" :name="`lines[${i}][batch_no]`"
                                               placeholder="Batch no." maxlength="60"
                                               class="block w-full rounded-lg border-slate-300 px-2 py-1 font-mono text-sm uppercase placeholder:font-sans placeholder:normal-case focus:border-emerald-700 focus:ring-emerald-600">
                                    </span>
                                    <button type="button" x-on:click="addBatch(line)" title="Another batch of this product, with its own expiry"
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-emerald-700 text-lg font-semibold leading-none text-emerald-700 hover:bg-emerald-50">+</button>
                                </span>

                                <span>
                                    <template x-if="! line.extra">
                                        <input type="text" inputmode="decimal" x-model.number="line.rate" :name="`lines[${i}][rate]`"
                                               class="w-full rounded-lg border-slate-300 py-2 text-right font-mono text-sm tabular-nums focus:border-emerald-700 focus:ring-emerald-600">
                                    </template>
                                    <template x-if="line.extra">
                                        <span class="block text-right font-mono text-sm tabular-nums text-slate-400">
                                            <span x-text="money(mainOf(line).rate)"></span>
                                            <input type="hidden" :name="`lines[${i}][rate]`" :value="mainOf(line).rate">
                                        </span>
                                    </template>
                                </span>

                                {{-- What a distributor sells it for. A pharmacy sells
                                     at the MRP in the next column. --}}
                                @unless ($business->sellsAtMrp())
                                    <span>
                                        <template x-if="! line.extra">
                                            <input type="text" inputmode="decimal" x-model.number="line.trade" :name="`lines[${i}][trade]`" placeholder="—"
                                                   class="w-full rounded-lg border-slate-300 py-2 text-right font-mono text-sm tabular-nums focus:border-emerald-700 focus:ring-emerald-600">
                                        </template>
                                        <template x-if="line.extra">
                                            <input type="hidden" :name="`lines[${i}][trade]`" :value="mainOf(line).trade">
                                        </template>
                                    </span>
                                @endunless

                                {{-- Printed on the pack. --}}
                                <span>
                                    <template x-if="! line.extra">
                                        <input type="text" inputmode="decimal" x-model.number="line.mrp" :name="`lines[${i}][mrp]`"
                                               placeholder="{{ $sellRequired ? 'required' : '—' }}"
                                               :class="{{ $sellRequired ? 'true' : 'false' }} && ! (Number(line.mrp) > 0) ? 'border-amber-400 bg-amber-50' : 'border-slate-300'"
                                               class="w-full rounded-lg py-2 text-right font-mono text-sm tabular-nums focus:border-emerald-700 focus:ring-emerald-600">
                                    </template>
                                    <template x-if="line.extra">
                                        <span class="block text-right font-mono text-sm tabular-nums text-slate-400">
                                            <span x-text="Number(mainOf(line).mrp) > 0 ? money(mainOf(line).mrp) : '—'"></span>
                                            <input type="hidden" :name="`lines[${i}][mrp]`" :value="mainOf(line).mrp">
                                        </span>
                                    </template>
                                </span>

                                <span class="text-right font-mono text-sm font-bold tabular-nums text-slate-900"
                                      x-text="money((Number(line.packs) || 0) * (Number(mainOf(line).rate) || 0))"></span>

                                <button type="button" x-on:click="remove(line)"
                                        class="justify-self-end rounded-lg p-1 text-lg leading-none text-slate-500 hover:bg-red-50 hover:text-red-700"
                                        :title="line.extra ? 'Remove this batch' : 'Remove this product'">&times;</button>
                            </div>
                        </template>

                        <div x-show="lines.length === 0" class="flex h-full flex-col items-center justify-center gap-1 py-16 text-center">
                            <p class="text-lg font-medium text-slate-400">Nothing on the delivery yet</p>
                            <p class="text-sm text-slate-400">Choose the company, then pick from their products on the left.</p>
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center justify-between gap-x-6 gap-y-2 border-t border-slate-200 px-4 py-3">
                        <p class="flex items-center gap-3 text-sm text-slate-600">
                            <span x-text="lines.length + (lines.length === 1 ? ' line' : ' lines') + ' · ' + totalPacks + ' {{ $many }}'"></span>
                            <button type="button" x-on:click="lines = []" x-show="lines.length > 0" x-cloak
                                    class="font-medium text-slate-500 underline hover:text-red-700">Clear</button>
                        </p>
                        <p class="text-sm" :class="missingSellPrice > 0 ? 'font-medium text-amber-700' : 'text-slate-500'"
                           x-text="lines.length > 0 && ! invoiceNo.trim() ? 'The invoice number is needed' : missingSellPrice > 0
                               ? missingSellPrice + ' ' + (missingSellPrice === 1 ? 'line needs' : 'lines need') + ' an MRP'
                               : (missingExpiry > 0
                                   ? missingExpiry + ' ' + (missingExpiry === 1 ? 'line has' : 'lines have') + ' no expiry date'
                                   : @js('Recorded on ' . $today->format('j M Y')))"></p>
                    </div>
                </section>
            </div>

            {{-- The foot of the screen: what their bill comes to, what is paid
                 now, what is left on their account, and the button that
                 finishes it. --}}
            <footer class="flex shrink-0 flex-wrap items-end justify-between gap-x-8 gap-y-4 border-t border-slate-300 bg-white px-4 py-5 sm:px-6">
                <div>
                    <p class="{{ $label }} text-slate-500">Bill comes to</p>
                    <p class="mt-1 whitespace-nowrap font-mono text-5xl font-bold leading-none tabular-nums text-slate-900" x-text="'Rs. ' + money(total)"></p>
                </div>

                <div>
                    <p class="{{ $label }} text-slate-500">Paid now</p>
                    <div class="mt-1 flex flex-wrap items-stretch gap-2">
                        <input id="paid" name="paid" x-model="paid" type="text" inputmode="decimal" placeholder="0.00"
                               class="block w-44 rounded-lg border-2 border-emerald-700 py-2 text-right font-mono text-2xl font-semibold tabular-nums focus:border-emerald-700 focus:ring-2 focus:ring-emerald-600/30">
                        <button type="button" x-on:click="paid = total.toFixed(2)"
                                class="rounded-lg bg-slate-100 px-4 font-mono text-sm font-semibold text-slate-900 hover:bg-slate-200">In full</button>
                        <button type="button" x-on:click="paid = ''"
                                class="rounded-lg bg-amber-100 px-4 font-mono text-sm font-semibold text-amber-800 hover:bg-amber-200"
                                title="Nothing paid now — the whole bill goes on their account">On account</button>
                    </div>
                </div>

                <div>
                    <p class="{{ $label }}" :class="onAccount > 0 ? 'text-amber-700' : 'text-slate-500'">On their account</p>
                    <p class="mt-1 whitespace-nowrap font-mono text-3xl font-semibold leading-tight tabular-nums"
                       :class="onAccount > 0 ? 'text-amber-700' : 'text-slate-300'"
                       x-text="'Rs. ' + money(onAccount)"></p>
                </div>

                {{-- Always there to be pressed. If something is missing it
                     says what, rather than sitting greyed out with no reason. --}}
                <div class="flex flex-col items-end gap-2">
                    <p x-show="tried && problem" x-cloak x-text="problem" role="alert"
                       class="rounded-md bg-red-50 px-3 py-1.5 text-sm font-semibold text-red-700"></p>
                    <button type="button" x-on:click="save()"
                            class="flex items-center gap-3 rounded-xl bg-emerald-700 px-10 py-4 text-xl font-bold text-white shadow-sm hover:bg-emerald-800">
                        Put it into stock
                        <span class="rounded border border-white/60 px-1.5 py-0.5 font-mono text-xs font-semibold">F9</span>
                    </button>
                </div>
            </footer>
        </form>

        {{-- A product the price list never had. It joins the catalogue, because
             a line with no product behind it reaches the invoice but never the
             stock count. The dialog is the one every screen uses; here what it
             adds goes straight onto the delivery. --}}
        <x-add-product :business="$business" :reload="false" />
    </div>

    @push('scripts')
        <script>
            /* How many pieces a printed pack size means. The same rule as
               App\Support\PackSize and the product form; keep them in step. */
            function piecesFromPack(text) {
                const t = String(text || '').trim().toLowerCase();
                let m = t.match(/^(\d+)\s*[\/\\x×*]\s*(\d+)/);
                if (m) return Math.max(1, Number(m[1]) * Number(m[2]));
                m = t.match(/^(\d+)\s*('s|s\b|tab|cap|pcs|pieces?)/);
                if (m) return Math.max(1, Number(m[1]));
                return 1;
            }

            function delivery({ products }) {
                return {
                    products,
                    companyId: '',
                    query: '',
                    paid: '',
                    lines: [],


                    /* Only the chosen company's goods: a delivery comes from one
                       supplier, and their invoice is what is being recorded. */
                    get ofCompany() {
                        if (! this.companyId) return [];

                        return this.products.filter(p => String(p.company_id) === String(this.companyId));
                    },

                    /* The whole catalogue by default, narrowed by the search —
                       one list, the way the order form does it, because a
                       delivery is checked against a list rather than recalled. */
                    get shown() {
                        const q = this.query.trim().toLowerCase();

                        if (q === '') return this.ofCompany.slice(0, 300);

                        /* A search looks past this company's catalogue: the
                           same medicine is bought wherever it is in stock, so
                           a product filed under one company can arrive from
                           another. Theirs first, then everyone else's. */
                        const hit = p => p.name.toLowerCase().includes(q) || (p.code || '').toLowerCase().includes(q);
                        const theirs = this.ofCompany.filter(hit);
                        const others = this.companyId
                            ? this.products.filter(p => ! this.ownProduct(p) && hit(p))
                            : [];

                        return theirs.concat(others).slice(0, 300);
                    },

                    /** Whether a product sits in the chosen company's own catalogue. */
                    ownProduct(product) {
                        return String(product.company_id) === String(this.companyId);
                    },

                    /** How many of the rows on show came from other companies' catalogues. */
                    get fromOthers() {
                        return this.shown.filter(p => ! this.ownProduct(p)).length;
                    },

                    // The row the arrow keys are on; Enter adds it.
                    highlight: 0,
                    move(step) {
                        if (this.shown.length === 0) return;

                        this.highlight = (this.highlight + step + this.shown.length) % this.shown.length;
                        this.$refs.list.querySelectorAll('[data-row]')[this.highlight]?.scrollIntoView({ block: 'nearest' });
                    },
                    addHighlighted() {
                        const product = this.shown[this.highlight] ?? this.shown[0];

                        if (product) this.add(product);
                    },

                    /** How many of this one are already on the delivery. */
                    onDelivery(id) {
                        return this.lines.filter(l => l.id === id).reduce((n, l) => n + (Number(l.packs) || 0), 0);
                    },

                    /** The first row of a product: the one that holds its pack and prices. */
                    mainOf(line) {
                        return line.extra ? (this.lines.find(l => l.id === line.id && ! l.extra) ?? line) : line;
                    },

                    /** Another batch of the same product, under the rows it already has. */
                    addBatch(line) {
                        const last = this.lines.map(l => l.id).lastIndexOf(line.id);
                        const batch = {
                            key: line.id + '-' + (++this.batchSeq), id: line.id, name: line.name, extra: true,
                            packs: 1, expires_on: '', batch_no: '',
                        };

                        this.lines.splice(last + 1, 0, batch);
                        this.flashOn(batch.key);
                    },
                    batchSeq: 0,

                    /** Taking a product off takes its other batches with it; a batch goes alone. */
                    remove(line) {
                        this.lines = line.extra
                            ? this.lines.filter(l => l !== line)
                            : this.lines.filter(l => l.id !== line.id);
                    },

                    flashOn(key) {
                        this.flash = key;
                        clearTimeout(this.flashTimer);
                        this.flashTimer = setTimeout(() => { this.flash = null; }, 1200);
                        this.$nextTick(() => {
                            this.$refs.delivery
                                ?.querySelector('[data-line="' + key + '"]')
                                ?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                        });
                    },

                    openNew() {
                        this.$dispatch('add-product', { company_id: this.companyId, name: this.query.trim() });
                    },

                    /*
                     * A new product joins the catalogue before it joins the
                     * delivery. A line with no product behind it would reach
                     * the invoice and never the stock count, which is the one
                     * outcome this whole screen exists to avoid.
                     */
                    productAdded(product) {
                        // Into the list on screen as well, so it behaves
                        // exactly like one that came from a price list.
                        if (! this.products.some(p => p.id === product.id)) {
                            this.products.push(product);
                        }

                        this.query = '';
                        this.add(product);
                    },

                    add(product) {
                        const line = this.lines.find(l => l.id === product.id && ! l.extra);

                        if (line) {
                            line.packs = (Number(line.packs) || 0) + 1;
                        } else {
                            this.lines.push({
                                key: String(product.id), id: product.id, name: product.name, packs: 1, extra: false,
                                rate: product.rate,
                                // Confirmed rather than retyped where they exist.
                                mrp: product.mrp ?? '',
                                trade: product.trade ?? '',
                                expires_on: '',
                                batch_no: '',
                                // As on file; sent back only to be compared.
                                pack: product.pack ?? '',
                                // Whose catalogue it sits in, when not this company's.
                                other: this.ownProduct(product) ? '' : (product.company || 'another company'),
                                // What it cost before this delivery, so a rate
                                // typed differently shows as a change.
                                list_rate: product.rate,
                            });
                        }

                        // Seen arriving: scrolled into view and held in colour briefly.
                        this.flashOn(String(product.id));

                        this.$refs.search?.focus();
                    },

                    flash: null,
                    flashTimer: null,
                    invoiceNo: @js(old('invoice_no', '')),

                    get total() {
                        return this.lines.reduce((n, l) => n + (Number(l.packs) || 0) * (Number(this.mainOf(l).rate) || 0), 0);
                    },

                    get totalPacks() {
                        return this.lines.reduce((n, l) => n + (Number(l.packs) || 0), 0);
                    },

                    /**
                     * This delivery's rate against what the product cost before.
                     * Read from the buyer's side: paying less is the good way,
                     * so it is green and points down.
                     */
                    priceMove(line) {
                        const was = Number(line.list_rate);
                        const now = Number(line.rate);

                        if (! was || ! now || was === now) return null;

                        const delta = now - was;

                        return { up: delta > 0, delta: Math.abs(delta), percent: Math.abs(delta / was) * 100, was };
                    },

                    /** Lines a pharmacy may not save without an MRP — the price it sells at. */
                    get missingSellPrice() {
                        @if ($sellRequired)
                            return this.lines.filter(l => ! l.extra && ! (Number(l.mrp) > 0)).length;
                        @else
                            return 0;
                        @endif
                    },

                    /** Within six months, which is when it stops being worth buying. */
                    expiresSoon(month) {
                        if (! month) return false;

                        const [y, m] = month.split('-').map(Number);
                        const limit = new Date();
                        limit.setMonth(limit.getMonth() + 6);

                        return new Date(y, m - 1, 1) <= limit;
                    },

                    /** Lines where nobody has said when the goods go out of date. */
                    get missingExpiry() {
                        return this.lines.filter(l => ! l.expires_on).length;
                    },

                    get onAccount() {
                        return Math.max(0, this.total - (Number(this.paid) || 0));
                    },

                    money(v) {
                        return (Number(v) || 0).toLocaleString('en-US', {
                            minimumFractionDigits: 2, maximumFractionDigits: 2,
                        });
                    },

                    // Changing company empties the list: the lines belonged to
                    // the old one, and silently keeping them would file another
                    // company's goods under this invoice.
                    init() {
                        this.$watch('companyId', () => { this.lines = []; this.query = ''; this.highlight = 0; });
                        // A new search starts from the top of what it found.
                        this.$watch('query', () => { this.highlight = 0; });

                        // The screen's own clock, in the business's timezone.
                        const tick = () => {
                            this.clock = new Date().toLocaleTimeString('en-US', {
                                timeZone: @js($business->timezone),
                                hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true,
                            });
                        };
                        tick();
                        setInterval(tick, 1000);
                    },

                    clock: '',

                    /** Whether the delivery can be put into stock as it stands. */
                    get canSave() {
                        return this.problem === '';
                    },

                    /** The first thing standing in the way of saving, in words; empty when nothing is. */
                    get problem() {
                        if (@js($dayClosed)) return 'Today is closed. Reopen the day before recording a delivery.';
                        if (! this.companyId) return 'Choose the company first.';
                        if (this.lines.length === 0) return 'Add at least one product.';
                        if (! this.invoiceNo.trim()) return 'Invoice number is required.';
                        if (this.missingSellPrice > 0) {
                            return this.missingSellPrice + ' ' + (this.missingSellPrice === 1 ? 'line needs' : 'lines need') + ' an MRP.';
                        }

                        return '';
                    },

                    // Whether saving has been asked for: what is missing is
                    // only pointed out once somebody has tried.
                    tried: false,

                    save() {
                        this.tried = true;

                        if (this.problem) {
                            if (this.companyId && this.lines.length > 0 && ! this.invoiceNo.trim()) this.$refs.invoice.focus();

                            return;
                        }

                        this.$refs.form.requestSubmit();
                    },
                };
            }
        </script>
    @endpush
</x-pos-layout>
