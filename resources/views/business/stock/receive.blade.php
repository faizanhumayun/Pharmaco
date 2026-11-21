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
    ? 'grid-cols-[minmax(0,1fr)_6.5rem_5.5rem_8.5rem_7.5rem_6.5rem_6.5rem_2rem]'
    : 'grid-cols-[minmax(0,1fr)_6.5rem_5.5rem_8.5rem_7.5rem_6.5rem_6.5rem_6.5rem_2rem]')
<x-pos-layout :business="$business" title="Receive stock" mode="receiving">
    <div class="flex h-full flex-col" x-data="delivery({
        products: @js($products),
    })" x-on:keydown.window.f2.prevent="$refs.search?.focus()" x-on:keydown.window.f9.prevent="save()">

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
                                   x-on:keydown.enter.prevent="shown[0] && add(shown[0])" x-on:keydown.escape="query = ''"
                                   placeholder="Search their products by brand or code"
                                   class="block w-full rounded-lg border-2 border-emerald-700 py-3 pl-12 pr-16 text-base placeholder:text-slate-400 focus:border-emerald-700 focus:ring-2 focus:ring-emerald-600/30 disabled:border-slate-300 disabled:bg-slate-50">
                            <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 rounded border border-slate-300 px-1.5 py-0.5 font-mono text-xs text-slate-500">F2</span>
                        </div>

                        <p class="flex items-center justify-between text-sm text-slate-600">
                            <span x-show="companyId" x-cloak>
                                <span x-text="shown.length"></span> of <span x-text="ofCompany.length"></span> products
                            </span>
                            <span x-show="! companyId">Their products</span>
                            <button type="button" x-show="companyId" x-cloak x-on:click="openNew()"
                                    class="font-medium text-emerald-700 hover:text-emerald-800">
                                Not in the list? Add it
                            </button>
                        </p>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <p x-show="! companyId" class="px-4 py-10 text-center text-sm text-slate-500">
                            Choose a company first — the list reads its catalogue.
                        </p>

                        <template x-for="product in shown" :key="product.id">
                            <div class="grid w-full grid-cols-[minmax(0,1fr)_auto_6rem_2.75rem] items-center gap-3 border-b border-slate-200 px-4 py-2.5 last:border-0 hover:bg-emerald-50">
                                <button type="button" x-on:click="add(product)" class="min-w-0 text-left">
                                    <span class="block break-words text-base font-medium leading-snug text-slate-900" x-text="product.name"></span>
                                    <span class="block truncate font-mono text-xs text-slate-500" x-text="product.code || '—'"></span>
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
                        <template x-for="(line, i) in lines" :key="line.id">
                            {{-- The line just added is scrolled to and held in
                                 colour for a moment, as on the till, so a long
                                 delivery never swallows what was just picked. --}}
                            <div :data-line="line.id" :class="flash === line.id ? 'bg-emerald-50' : ''"
                                 class="{{ $cols }} grid items-center gap-3 border-b border-slate-200 px-4 py-2.5 transition-colors duration-500">
                                <span class="min-w-0">
                                    <span class="block break-words text-base font-medium leading-snug text-slate-900" x-text="line.name"></span>
                                    <template x-if="priceMove(line)">
                                        <span class="text-xs font-medium" :class="priceMove(line).up ? 'text-red-700' : 'text-emerald-700'"
                                              x-text="(priceMove(line).up ? '▲ ' : '▼ ') + priceMove(line).percent.toFixed(0) + '% on last cost of ' + priceMove(line).was.toFixed(2)"></span>
                                    </template>
                                    <input type="hidden" :name="`lines[${i}][company_product_id]`" :value="line.id">
                                </span>

                                {{-- The pack on file, to check against the carton
                                     and correct here if it differs. --}}
                                <span>
                                    <input type="text" x-model="line.pack" :name="`lines[${i}][pack_size]`"
                                           placeholder="10/10" maxlength="60" title="Pack size — change it if the carton says otherwise"
                                           class="block w-full rounded-lg border-slate-300 px-2 py-2 text-center font-mono text-sm focus:border-emerald-700 focus:ring-emerald-600">
                                    @if ($business->sellsLoose())
                                        <span class="mt-0.5 block text-center text-[11px] leading-tight text-slate-500"
                                              x-show="piecesFromPack(line.pack) > 1" x-text="piecesFromPack(line.pack) + ' to a pack'"></span>
                                    @endif
                                </span>

                                <input type="text" inputmode="numeric" x-model.number="line.packs" :name="`lines[${i}][packs]`"
                                       class="w-full rounded-lg border-slate-300 py-2 text-center font-mono text-lg font-semibold tabular-nums focus:border-emerald-700 focus:ring-emerald-600">

                                {{-- The date on the carton, taken while it is still in
                                     someone's hands. Blank is an answer: not everything
                                     expires. Under it, the batch number printed beside it. --}}
                                <span class="space-y-1">
                                    <input type="month" x-model="line.expires_on" :name="`lines[${i}][expires_on]`"
                                           :class="line.expires_on && expiresSoon(line.expires_on) ? 'border-amber-400 bg-amber-50' : 'border-slate-300'"
                                           class="block w-full rounded-lg px-2 py-1 text-sm focus:border-emerald-700 focus:ring-emerald-600">
                                    <input type="text" x-model="line.batch_no" :name="`lines[${i}][batch_no]`"
                                           placeholder="Batch no." maxlength="60"
                                           class="block w-full rounded-lg border-slate-300 px-2 py-1 font-mono text-sm uppercase placeholder:font-sans placeholder:normal-case focus:border-emerald-700 focus:ring-emerald-600">
                                </span>

                                <input type="text" inputmode="decimal" x-model.number="line.rate" :name="`lines[${i}][rate]`"
                                       class="w-full rounded-lg border-slate-300 py-2 text-right font-mono text-sm tabular-nums focus:border-emerald-700 focus:ring-emerald-600">

                                {{-- What a distributor sells it for. A pharmacy sells
                                     at the MRP in the next column. --}}
                                @unless ($business->sellsAtMrp())
                                    <input type="text" inputmode="decimal" x-model.number="line.trade" :name="`lines[${i}][trade]`" placeholder="—"
                                           class="w-full rounded-lg border-slate-300 py-2 text-right font-mono text-sm tabular-nums focus:border-emerald-700 focus:ring-emerald-600">
                                @endunless

                                {{-- Printed on the pack. --}}
                                <input type="text" inputmode="decimal" x-model.number="line.mrp" :name="`lines[${i}][mrp]`"
                                       placeholder="{{ $sellRequired ? 'required' : '—' }}"
                                       :class="{{ $sellRequired ? 'true' : 'false' }} && ! (Number(line.mrp) > 0) ? 'border-amber-400 bg-amber-50' : 'border-slate-300'"
                                       class="w-full rounded-lg py-2 text-right font-mono text-sm tabular-nums focus:border-emerald-700 focus:ring-emerald-600">

                                <span class="text-right font-mono text-sm font-bold tabular-nums text-slate-900"
                                      x-text="money((Number(line.packs) || 0) * (Number(line.rate) || 0))"></span>

                                <button type="button" x-on:click="lines.splice(i, 1)"
                                        class="justify-self-end rounded-lg p-1 text-lg leading-none text-slate-500 hover:bg-red-50 hover:text-red-700" title="Remove">&times;</button>
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
             stock count. --}}
        <div x-show="adding" x-cloak x-on:keydown.escape.window="adding = false"
             class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-gray-900/50 p-4">
            <div class="my-auto w-full max-w-md rounded-xl bg-white shadow-xl">
                <div class="border-b border-gray-200 px-5 py-4">
                    <h2 class="text-lg font-semibold text-gray-900">Add a product</h2>
                    <p class="text-sm text-gray-500">
                        It joins this company's catalogue, so it can be counted and sold like any other.
                    </p>
                </div>

                <div class="space-y-4 px-5 py-4">
                    <div>
                        <x-input-label value="Name" />
                        <x-text-input x-model="draft.brand_name" type="text" class="mt-1 block w-full"
                                      placeholder="e.g. PANADOL 500MG Tablet" maxlength="200" />
                        <p class="mt-1 text-xs text-red-600" x-show="draftError" x-cloak x-text="draftError"></p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <x-input-label for="draft_form" value="Type" />
                            <select id="draft_form" x-model="draft.dosage_form"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                <option value="">Choose — tablet, syrup…</option>
                                @foreach ($forms as $form)
                                    <option value="{{ $form }}">{{ $form }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label value="Strength" />
                            <x-text-input x-model="draft.strength" type="text" class="mt-1 block w-full" placeholder="500mg" maxlength="60" />
                        </div>
                    </div>

                    <div>
                        <x-input-label value="Pack size" />
                        <x-text-input x-model="draft.pack_size" type="text" class="mt-1 block w-full" placeholder="10/10" maxlength="60" />
                        <p class="mt-1 text-xs text-gray-500">
                            Strips × tablets, as on the box: “10/10”, “1/14”. Leave it blank for a bottle or a single item.
                            @if ($business->sellsLoose())
                                <span class="font-medium text-gray-700" x-show="piecesFromPack(draft.pack_size) > 1"
                                      x-text="'That is ' + piecesFromPack(draft.pack_size) + ' to a pack.'"></span>
                            @endif
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <x-input-label value="Purchase rate" />
                            <x-text-input x-model="draft.purchase_rate" type="text" inputmode="decimal"
                                          class="mt-1 block w-full text-right tabular-nums" placeholder="0.00" />
                        </div>
                        <div>
                            <x-input-label value="Selling price" />
                            <x-text-input x-model="draft.mrp" type="text" inputmode="decimal"
                                          class="mt-1 block w-full text-right tabular-nums" placeholder="0.00" />
                        </div>
                    </div>

                    {{-- The code is issued here, not asked for: it is ours, it is
                         the next in sequence, and there is nothing to decide about
                         it. Shown before saving so it can be written on the pack
                         while the delivery is still open. --}}
                    <div>
                        <x-input-label value="Code" />
                        <p class="mt-1 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-900"
                           x-text="nextCode"></p>
                        <p class="mt-1 text-xs text-gray-500">Given to this product when it is added.</p>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-gray-200 px-5 py-3">
                    <button type="button" x-on:click="adding = false"
                            class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="button" x-on:click="saveNew()" :disabled="savingNew"
                            class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">
                        <span x-text="savingNew ? 'Adding…' : 'Add and put on delivery'"></span>
                    </button>
                </div>
            </div>
        </div>
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

                    adding: false,
                    savingNew: false,
                    draftError: '',
                    nextCode: @js($nextCode),
                    draft: { brand_name: '', dosage_form: '', pack_size: '', strength: '', purchase_rate: '', mrp: '' },

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

                        return this.ofCompany
                            .filter(p => p.name.toLowerCase().includes(q) || (p.code || '').toLowerCase().includes(q))
                            .slice(0, 300);
                    },

                    /** How many of this one are already on the delivery. */
                    onDelivery(id) {
                        return Number(this.lines.find(l => l.id === id)?.packs) || 0;
                    },

                    openNew() {
                        this.draftError = '';
                        this.draft = { brand_name: this.query.trim(), dosage_form: '', pack_size: '', strength: '', purchase_rate: '', mrp: '' };
                        this.adding = true;
                    },

                    /*
                     * A new product joins the catalogue before it joins the
                     * delivery. A line with no product behind it would reach
                     * the invoice and never the stock count, which is the one
                     * outcome this whole screen exists to avoid.
                     */
                    async saveNew() {
                        if (this.savingNew) return;

                        if (this.draft.brand_name.trim() === '') {
                            this.draftError = 'It needs a name.';
                            return;
                        }

                        if (! this.draft.dosage_form) {
                            this.draftError = 'Choose its type — tablet, syrup, injection…';
                            return;
                        }

                        this.savingNew = true;
                        this.draftError = '';

                        try {
                            const response = await fetch(@js(route('businesses.stock.receive.product', $business)), {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                },
                                body: JSON.stringify({ ...this.draft, company_id: this.companyId }),
                            });

                            if (! response.ok) {
                                this.draftError = 'Could not add that product. Try again.';
                                return;
                            }

                            const product = await response.json();

                            // The one after it, ready for the next product added
                            // without reloading the screen.
                            this.nextCode = product.next_code ?? this.nextCode;

                            // Into the list on screen as well, so it behaves
                            // exactly like one that came from a price list.
                            if (! this.products.some(p => p.id === product.id)) {
                                this.products.push(product);
                            }

                            this.adding = false;
                            this.query = '';
                            this.add(product);
                        } finally {
                            this.savingNew = false;
                        }
                    },

                    add(product) {
                        const line = this.lines.find(l => l.id === product.id);

                        if (line) {
                            line.packs = (Number(line.packs) || 0) + 1;
                        } else {
                            this.lines.push({
                                id: product.id, name: product.name, packs: 1,
                                rate: product.rate,
                                // Confirmed rather than retyped where they exist.
                                mrp: product.mrp ?? '',
                                trade: product.trade ?? '',
                                expires_on: '',
                                batch_no: '',
                                // As on file; sent back only to be compared.
                                pack: product.pack ?? '',
                                // What it cost before this delivery, so a rate
                                // typed differently shows as a change.
                                list_rate: product.rate,
                            });
                        }

                        // Seen arriving: scrolled into view and held in colour briefly.
                        this.flash = product.id;
                        clearTimeout(this.flashTimer);
                        this.flashTimer = setTimeout(() => { this.flash = null; }, 1200);
                        this.$nextTick(() => {
                            this.$refs.delivery
                                ?.querySelector('[data-line="' + product.id + '"]')
                                ?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                        });

                        this.$refs.search?.focus();
                    },

                    flash: null,
                    flashTimer: null,
                    invoiceNo: @js(old('invoice_no', '')),

                    get total() {
                        return this.lines.reduce((n, l) => n + (Number(l.packs) || 0) * (Number(l.rate) || 0), 0);
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
                            return this.lines.filter(l => ! (Number(l.mrp) > 0)).length;
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
                        this.$watch('companyId', () => { this.lines = []; this.query = ''; });

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
