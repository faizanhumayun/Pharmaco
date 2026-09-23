@php
    $action = $order
        ? route('businesses.orders.update', [$business, $order])
        : route('businesses.orders.store', $business);
@endphp

<x-workspace-layout :business="$business">
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">
            {{ $order ? "Edit {$order->reference}" : 'New order form' }}
        </h1>
    </x-slot>

    <div class="w-full px-4 py-4 sm:px-6 lg:px-8"
         x-data="orderForm({
             searchUrl: '{{ route('businesses.orders.product-search', $business) }}',
             companyId: '{{ old('company_id', $company?->id) }}',
             lines: {{ Illuminate\Support\Js::from(old('lines') ? array_values(old('lines')) : $lines) }},
         })">
        <x-flash />

        @if ($errors->has('status'))
            <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ $errors->first('status') }}
            </div>
        @endif

        <form method="POST" action="{{ $action }}" x-ref="form">
            @csrf
            @if ($order) @method('PUT') @endif

            {{-- Two panes, each with its own scrollbar: what you are adding on
                 the left, what is already on the order on the right. The page
                 itself does not scroll, so the totals and the Save button stay
                 in view however long the order gets. --}}
            <div class="flex flex-col gap-4 lg:h-[calc(100vh-11rem)] lg:flex-row">

                {{-- Left pane --}}
                <div class="flex min-h-0 flex-col gap-4 lg:w-[38%]">

                    {{-- Who it is for, when, and what they committed to. --}}
                    <div class="grid shrink-0 gap-3 rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm sm:grid-cols-2">
                        <div>
                            <x-input-label for="company_id" value="Company" />
                            <select id="company_id" name="company_id" required
                                    x-model="companyId" x-on:change="companyChanged()"
                                    @disabled($order !== null)
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600 disabled:bg-gray-100">
                                <option value="">— choose a company —</option>
                                @foreach ($companies as $option)
                                    <option value="{{ $option->id }}" @selected(old('company_id', $company?->id) == $option->id)>
                                        {{ $option->name }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('company_id')" class="mt-2" />
                            @if ($order)
                                <input type="hidden" name="company_id" value="{{ $order->company_id }}">
                            @endif
                        </div>

                        <div>
                            <x-input-label for="business_date" value="Date" />
                            <x-text-input id="business_date" name="business_date" type="date" class="mt-1 block w-full"
                                          :value="old('business_date', ($order?->business_date ?? $business->today())->toDateString())"
                                          required />
                            <x-input-error :messages="$errors->get('business_date')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="discount_percent" value="Discount on everything (%)" />
                            <x-text-input id="discount_percent" name="discount_percent" type="number"
                                          step="0.001" min="0" max="100" x-model="discount"
                                          class="mt-1 block w-full" placeholder="e.g. 13"
                                          :value="old('discount_percent', $order?->discount_percent)" />
                            <x-input-error :messages="$errors->get('discount_percent')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="notes" value="Note to the company" />
                            <x-text-input id="notes" name="notes" type="text" class="mt-1 block w-full"
                                          :value="old('notes', $order?->notes)" placeholder="Delivery instructions" />
                        </div>
                    </div>

                    {{-- The catalogue, with the search narrowing the same list
                         rather than sitting beside a second one. --}}
                    <x-panel class="flex min-h-0 flex-1 flex-col">
                        <div class="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-1 border-b border-gray-200 px-4 py-3 sm:px-6">
                            <h2 class="text-base font-semibold text-gray-900">Add products</h2>
                            <span class="text-sm text-gray-500" x-show="companyId" x-cloak>
                                <span x-text="query ? results.length : catalogue.length"></span>
                                of <span x-text="catalogueTotal"></span>
                            </span>
                            <span class="ml-auto text-xs text-emerald-700" x-show="companyId && orderDiscount > 0" x-cloak>
                                rates after <span x-text="trimPercent(discount)"></span>%
                            </span>
                        </div>

                        <div x-show="! companyId" class="px-4 py-6 text-sm text-gray-500 sm:px-6">
                            Choose a company first — the list reads its catalogue.
                        </div>

                        <div x-show="companyId" x-cloak class="flex min-h-0 flex-1 flex-col p-4 sm:p-6">
                            <x-text-input type="search" class="block w-full shrink-0"
                                          placeholder="Search by brand, generic, code or pack…"
                                          x-model="query" x-on:input.debounce.250ms="search()"
                                          x-on:keydown.escape="query = ''; results = []" />

                            <div class="mt-3 grid shrink-0 grid-cols-[minmax(0,1fr)_4.5rem_6rem_2.5rem] items-center gap-2 border-b border-gray-200 px-2 pb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                <span>Product</span>
                                <span class="text-right">Pack</span>
                                <span class="text-right">Rate</span>
                                <span class="text-right" title="Packs already on the order">On</span>
                            </div>

                            <div class="min-h-0 flex-1 overflow-y-auto"
                                 x-on:scroll.debounce.100ms="maybeLoadMore($event.target)">
                                <template x-if="query && ! results.length && ! searching">
                                    <p class="py-8 text-center text-sm text-gray-500">
                                        Nothing matches “<span x-text="query"></span>”.
                                        <button type="button" x-on:click="addBlank()"
                                                class="font-medium text-emerald-700 hover:text-emerald-800">Add it by hand</button>
                                    </p>
                                </template>

                                <template x-if="! query && ! catalogue.length && ! loadingCatalogue">
                                    <p class="py-8 text-center text-sm text-gray-500">
                                        This company has no products yet. Import its price list, or add lines by hand.
                                    </p>
                                </template>

                                <ul class="divide-y divide-gray-100">
                                    <template x-for="p in (query ? results : catalogue)" :key="p.id">
                                        <li x-on:click="openAdd(p)"
                                            class="cursor-pointer rounded px-2 py-2 hover:bg-emerald-50">
                                            @include('business.orders._product-row')
                                        </li>
                                    </template>
                                </ul>

                                <p x-show="loadingCatalogue" class="py-3 text-center text-xs text-gray-400">Loading…</p>

                                <button type="button" x-on:click="loadCatalogue()"
                                        x-show="! query && catalogueNext !== null && ! loadingCatalogue"
                                        class="mt-2 w-full rounded-md border border-gray-200 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-50">
                                    Load more
                                </button>
                            </div>

                            <button type="button" x-on:click="addBlank()"
                                    class="mt-3 shrink-0 text-left text-sm font-medium text-emerald-700 hover:text-emerald-800">
                                Not in the catalogue? Add a line by hand
                            </button>
                        </div>
                    </x-panel>
                </div>

                {{-- Right pane: the order as it stands. --}}
                <div class="flex min-h-0 flex-1 flex-col">
                    <x-panel class="flex min-h-0 flex-1 flex-col">
                        <div class="flex shrink-0 flex-wrap items-center gap-3 border-b border-gray-200 px-4 py-3 sm:px-6">
                            <h2 class="text-base font-semibold text-gray-900">Order</h2>
                            <span class="text-sm text-gray-500"><span x-text="lines.length"></span> lines</span>
                        </div>

                        <div class="min-h-0 flex-1 overflow-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="sticky top-0 z-10 bg-gray-50">
                                    <tr>
                                        @foreach ([['Product','left'],['Pack','left'],['Cartons','right'],['Packs','right'],['Rate','right'],['Disc %','right'],['Before disc.','right'],['Amount','right'],['','right']] as [$h,$align])
                                            <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first ? 'sm:px-6' : '' }}"
                                                @if ($h === 'Before disc.') x-show="anyDiscount" x-cloak @endif>{{ $h }}</th>
                                        @endforeach
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-gray-100">
                                    <template x-for="(line, i) in lines" :key="i">
                                        <tr class="align-top">
                                            <td class="px-3 py-2 sm:px-6">
                                                <input type="hidden" :name="`lines[${i}][company_product_id]`" :value="line.company_product_id ?? ''">
                                                <input type="hidden" :name="`lines[${i}][case_size]`" :value="line.case_size ?? ''">
                                                {{-- Display only, but posted so a failed save redraws
                                                     the order exactly as it was rather than as blanks. --}}
                                                <input type="hidden" :name="`lines[${i}][label]`" :value="line.label ?? ''">
                                                <input type="hidden" :name="`lines[${i}][generic_name]`" :value="line.generic_name ?? ''">
                                                <input type="hidden" :name="`lines[${i}][pack_size]`" :value="line.pack_size ?? ''">

                                                <template x-if="line.company_product_id">
                                                    <div class="max-w-[16rem]">
                                                        <span class="block break-words font-medium leading-snug text-gray-900"
                                                              x-text="line.label"></span>
                                                        <template x-if="line.generic_name">
                                                            <span class="block break-words text-xs leading-snug text-gray-500"
                                                                  x-text="line.generic_name"></span>
                                                        </template>
                                                    </div>
                                                </template>
                                                <template x-if="! line.company_product_id">
                                                    <input type="text" :name="`lines[${i}][brand_name]`" x-model="line.label" required
                                                           placeholder="Product name"
                                                           class="block w-44 rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                </template>
                                            </td>

                                            <td class="px-3 py-2 text-gray-600">
                                                <span x-text="line.pack_size || '—'"></span>
                                                <template x-if="line.case_size">
                                                    <div class="text-xs text-gray-400">case of <span x-text="line.case_size"></span></div>
                                                </template>
                                            </td>

                                            <td class="px-3 py-2 text-right">
                                                <input type="number" min="1" :name="`lines[${i}][cartons]`" x-model="line.cartons"
                                                       x-on:input="fromCartons(line)" :disabled="! line.case_size"
                                                       class="w-16 rounded-md border-gray-300 text-right text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600 disabled:bg-gray-100">
                                            </td>

                                            <td class="px-3 py-2 text-right">
                                                <input type="number" min="1" :name="`lines[${i}][packs]`" x-model="line.packs"
                                                       x-on:input="fromPacks(line)" required
                                                       class="w-20 rounded-md border-gray-300 text-right text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                            </td>

                                            <td class="px-3 py-2 text-right">
                                                <input type="text" inputmode="decimal" :name="`lines[${i}][rate]`" x-model="line.rate"
                                                       class="w-24 rounded-md border-gray-300 text-right font-mono text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                            </td>

                                            <td class="px-3 py-2 text-right">
                                                <input type="number" step="0.001" min="0" max="100" :name="`lines[${i}][discount_percent]`"
                                                       x-model="line.discount_percent" :placeholder="discount || '0'"
                                                       class="w-16 rounded-md border-gray-300 text-right text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                            </td>

                                            <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-400"
                                                x-show="anyDiscount" x-cloak x-text="fmt(grossPaisa(line) / 100)"></td>

                                            <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-900" x-text="fmt(net(line))"></td>

                                            <td class="px-3 py-2 text-right">
                                                <button type="button" x-on:click="lines.splice(i, 1)"
                                                        class="text-sm text-gray-400 hover:text-red-700" aria-label="Remove">✕</button>
                                            </td>
                                        </tr>
                                    </template>

                                    <tr x-show="! lines.length">
                                        <td :colspan="anyDiscount ? 9 : 8" class="px-6 py-10 text-center text-gray-500">
                                            Nothing on the order yet. Pick one from the catalogue on the left.
                                        </td>
                                    </tr>
                                </tbody>

                                {{-- Pinned to the bottom of this pane's scroller,
                                     so the total is on screen at all times. --}}
                                <tfoot class="sticky bottom-0 bg-gray-50 shadow-[0_-1px_0_0_rgb(229,231,235)]"
                                       x-show="lines.length" x-cloak>
                                    <tr>
                                        <td :colspan="anyDiscount ? 7 : 6" class="px-3 py-1.5 text-right text-sm text-gray-500 sm:px-6">Before discount</td>
                                        <td class="px-3 py-1.5 text-right font-mono tabular-nums text-gray-700" x-text="fmt(grossTotal)"></td>
                                        <td></td>
                                    </tr>
                                    <tr x-show="discountTotal > 0">
                                        <td :colspan="anyDiscount ? 7 : 6" class="px-3 py-1.5 text-right text-sm text-gray-500 sm:px-6">Discount</td>
                                        <td class="px-3 py-1.5 text-right font-mono tabular-nums text-emerald-700" x-text="'− ' + fmt(discountTotal)"></td>
                                        <td></td>
                                    </tr>
                                    <tr>
                                        <td :colspan="anyDiscount ? 7 : 6" class="px-3 py-2 text-right text-sm font-semibold text-gray-900 sm:px-6">Order total</td>
                                        <td class="px-3 py-2 text-right font-mono text-base font-semibold tabular-nums text-gray-900" x-text="fmt(netTotal)"></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-3 border-t border-gray-200 px-4 py-3 sm:px-6">
                            <button :disabled="! lines.length || ! companyId"
                                    class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-gray-300">
                                {{ $order ? 'Save changes' : 'Save draft' }}
                            </button>
                            <a href="{{ $order ? route('businesses.orders.show', [$business, $order]) : route('businesses.orders.index', $business) }}"
                               class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                            <x-input-error :messages="$errors->get('lines')" />
                        </div>
                    </x-panel>
                </div>
            </div>
        </form>

        {{--
            Asking how much, before the line goes on.

            Cartons and packs are both here because a company is ordered from in
            whichever the conversation used — "four cartons" or "two hundred
            packs" — and each keeps the other in step. The amount is shown
            before and after discount so what lands on the order is never a
            surprise.
        --}}
        <x-modal name="order-line" maxWidth="lg" focusable>
            <div class="p-6" x-show="draft.product">
                <template x-if="draft.product">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900" x-text="draft.product.label"></h2>
                        <p class="mt-0.5 text-sm text-gray-500" x-text="draft.product.generic_name || ''"></p>

                        <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-1 border-y border-gray-100 py-3 text-sm sm:grid-cols-4">
                            <div>
                                <dt class="text-xs text-gray-500">Pack</dt>
                                <dd class="text-gray-900" x-text="draft.product.pack_size || '—'"></dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500">Case</dt>
                                <dd class="text-gray-900" x-text="draft.product.case_size ? draft.product.case_size + ' packs' : '—'"></dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500">Trade price</dt>
                                <dd class="font-mono tabular-nums text-gray-900" x-text="draft.product.trade_price || '—'"></dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500">MRP</dt>
                                <dd class="font-mono tabular-nums text-gray-900" x-text="draft.product.mrp || '—'"></dd>
                            </div>
                        </dl>

                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="draft-cartons" value="Cartons" />
                                <x-text-input id="draft-cartons" type="number" min="1" class="mt-1 block w-full"
                                              x-model="draft.cartons" x-on:input="draftFromCartons()"
                                              ::disabled="! draft.product.case_size" />
                                <p class="mt-1 text-xs text-gray-500"
                                   x-text="draft.product.case_size ? draft.product.case_size + ' packs to a carton' : 'No carton size recorded — order in packs'"></p>
                            </div>

                            <div>
                                <x-input-label for="draft-packs" value="Packs" />
                                <x-text-input id="draft-packs" type="number" min="1" class="mt-1 block w-full"
                                              x-model="draft.packs" x-on:input="draftFromPacks()" />
                                <p class="mt-1 text-xs text-gray-500">Fill in either — the other follows.</p>
                            </div>

                            <div>
                                <x-input-label for="draft-rate" value="Rate per pack" />
                                <x-text-input id="draft-rate" type="text" inputmode="decimal" class="mt-1 block w-full font-mono"
                                              x-model="draft.rate" />
                            </div>

                            <div>
                                <x-input-label for="draft-discount" value="Discount % for this product" />
                                <x-text-input id="draft-discount" type="number" step="0.001" min="0" max="100"
                                              class="mt-1 block w-full" x-model="draft.discount_percent"
                                              ::placeholder="discount || '0'" />
                                <p class="mt-1 text-xs text-gray-500">Leave blank to use the order's discount.</p>
                            </div>
                        </div>

                        <div class="mt-4 rounded-md bg-gray-50 px-4 py-3 text-sm">
                            <div class="flex items-baseline justify-between">
                                <span class="text-gray-500">Amount</span>
                                <span class="font-mono tabular-nums text-gray-900" x-text="fmt(draftGross)"></span>
                            </div>
                            <template x-if="draftDiscount > 0">
                                <div class="mt-1 flex items-baseline justify-between text-emerald-700">
                                    <span>Less <span x-text="trimPercent(draftPercent)"></span>% discount</span>
                                    <span class="font-mono tabular-nums" x-text="'− ' + fmt(draftDiscount)"></span>
                                </div>
                            </template>
                            <div class="mt-1 flex items-baseline justify-between border-t border-gray-200 pt-1">
                                <span class="font-semibold text-gray-900">After discount</span>
                                <span class="font-mono text-base font-semibold tabular-nums text-gray-900" x-text="fmt(draftNet)"></span>
                            </div>
                        </div>

                        <div class="mt-5 flex items-center justify-end gap-3">
                            <button type="button" x-on:click="$dispatch('close-modal', 'order-line')"
                                    class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                Cancel
                            </button>
                            <button type="button" x-on:click="commitDraft()" :disabled="int(draft.packs) < 1"
                                    class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-gray-300">
                                <span x-text="draftIndex === null ? 'Add to order' : 'Update line'"></span>
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </x-modal>
    </div>

    @push('scripts')
        <script>
            function orderForm({ searchUrl, companyId, lines }) {
                return {
                    searchUrl,
                    companyId: companyId || '',
                    lines: lines || [],
                    discount: @js(old('discount_percent', $order?->discount_percent)) ?? '',
                    query: '',
                    results: [],
                    searching: false,

                    // Column two: the company's list, walked a page at a time
                    // so a catalogue of thousands does not arrive in one go.
                    catalogue: [],
                    catalogueTotal: 0,
                    catalogueNext: 0,
                    loadingCatalogue: false,

                    // The line being sized in the modal, before it joins the order.
                    draft: { product: null, cartons: '', packs: '', rate: '', discount_percent: '' },
                    draftIndex: null,

                    init() {
                        if (this.companyId) this.loadCatalogue();
                    },

                    companyChanged() {
                        // The catalogue just changed underneath them; lines from
                        // the old company would be silently dropped on save, so
                        // they go now, where it is visible.
                        this.lines = [];
                        this.results = [];
                        this.query = '';
                        this.catalogue = [];
                        this.catalogueTotal = 0;
                        this.catalogueNext = 0;
                        if (this.companyId) this.loadCatalogue();
                    },

                    async fetchProducts({ q = '', offset = 0, limit = 25 }) {
                        const params = new URLSearchParams({
                            company: this.companyId, q, offset, limit,
                        });
                        const response = await fetch(`${this.searchUrl}?${params}`, {
                            headers: { 'Accept': 'application/json' },
                        });

                        if (! response.ok) throw new Error('lookup failed');

                        return await response.json();
                    },

                    async search() {
                        if (! this.companyId || ! this.query) { this.results = []; return; }
                        this.searching = true;
                        try {
                            this.results = (await this.fetchProducts({ q: this.query, limit: 50 })).products;
                        } catch (e) {
                            this.results = [];
                        } finally {
                            this.searching = false;
                        }
                    },

                    async loadCatalogue() {
                        if (! this.companyId || this.loadingCatalogue || this.catalogueNext === null) return;
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

                    // Reading to the bottom of the list asks for the next page,
                    // so the common case needs no button at all.
                    maybeLoadMore(el) {
                        if (el.scrollTop + el.clientHeight >= el.scrollHeight - 120) this.loadCatalogue();
                    },

                    get orderDiscount() { return this.num(this.discount); },

                    /** Whether a discount is in play anywhere on this order. */
                    get anyDiscount() {
                        return this.orderDiscount > 0
                            || this.lines.some(l => this.num(l.discount_percent) > 0);
                    },

                    /** A rate with the order's discount already taken off it. */
                    discountedRate(p) {
                        const pct = this.orderDiscount;
                        const rate = this.num(p.rate);
                        if (pct <= 0 || rate <= 0) return null;

                        const paisa = Math.round(rate * 100);

                        return (paisa - Math.floor((paisa * pct) / 100 + 0.5)) / 100;
                    },

                    /** "13.000" reads better as "13". */
                    trimPercent(v) {
                        const n = this.num(v);

                        return n % 1 === 0 ? String(n) : String(parseFloat(n.toFixed(3)));
                    },

                    /** Packs of this product already on the order, for the badge. */
                    onOrder(p) {
                        const line = this.lines.find(l => l.company_product_id === p.id);

                        return line ? this.int(line.packs) : 0;
                    },

                    // --- Adding a product ------------------------------
                    // Nothing goes on the order straight from the list: the
                    // quantity is asked for first, in cartons or in packs.
                    openAdd(p) {
                        const existing = this.lines.findIndex(l => l.company_product_id === p.id);

                        if (existing !== -1) {
                            // Already on the order: this edits that line rather
                            // than listing the same product twice.
                            const line = this.lines[existing];
                            this.draftIndex = existing;
                            this.draft = {
                                product: p,
                                cartons: line.cartons ?? '',
                                packs: line.packs ?? '',
                                rate: line.rate ?? '',
                                discount_percent: line.discount_percent ?? '',
                            };
                        } else {
                            this.draftIndex = null;
                            this.draft = {
                                product: p,
                                cartons: p.case_size ? 1 : '',
                                packs: p.case_size || 1,
                                rate: p.rate ?? '',
                                discount_percent: '',
                            };
                        }

                        this.$dispatch('open-modal', 'order-line');
                    },

                    draftFromCartons() {
                        const size = this.draft.product?.case_size;
                        if (! size) return;
                        const cartons = this.int(this.draft.cartons);
                        if (cartons > 0) this.draft.packs = cartons * size;
                    },

                    draftFromPacks() {
                        const size = this.draft.product?.case_size;
                        if (! size) { this.draft.cartons = ''; return; }
                        const packs = this.int(this.draft.packs);
                        this.draft.cartons = packs > 0 && packs % size === 0 ? packs / size : '';
                    },

                    get draftPercent() {
                        const own = this.draft.discount_percent;

                        return own !== '' && own !== null && own !== undefined
                            ? this.num(own)
                            : this.num(this.discount);
                    },
                    get draftGross() {
                        return (Math.round(this.num(this.draft.rate) * 100) * this.int(this.draft.packs)) / 100;
                    },
                    get draftDiscount() {
                        const pct = this.draftPercent;
                        if (pct <= 0) return 0;

                        return Math.floor((this.draftGross * 100 * pct) / 100 + 0.5) / 100;
                    },
                    get draftNet() { return this.draftGross - this.draftDiscount; },

                    commitDraft() {
                        const p = this.draft.product;
                        if (! p || this.int(this.draft.packs) < 1) return;

                        const line = {
                            company_product_id: p.id,
                            label: p.label,
                            generic_name: p.generic_name,
                            pack_size: p.pack_size,
                            case_size: p.case_size,
                            cartons: this.draft.cartons === '' ? null : this.int(this.draft.cartons),
                            packs: this.int(this.draft.packs),
                            rate: this.draft.rate,
                            discount_percent: this.draft.discount_percent,
                        };

                        if (this.draftIndex === null) {
                            this.lines.push(line);
                        } else {
                            this.lines[this.draftIndex] = line;
                        }

                        this.$dispatch('close-modal', 'order-line');
                        this.draft = { product: null, cartons: '', packs: '', rate: '', discount_percent: '' };
                        this.draftIndex = null;
                    },

                    addBlank() {
                        this.lines.push({
                            company_product_id: null, label: '', generic_name: null,
                            pack_size: null, case_size: null,
                            cartons: null, packs: 1, rate: '', discount_percent: '',
                        });
                    },

                    // Cartons and packs are two ways of saying the same thing,
                    // so editing either keeps the other honest.
                    fromCartons(line) {
                        if (! line.case_size) return;
                        const cartons = this.int(line.cartons);
                        if (cartons > 0) line.packs = cartons * line.case_size;
                    },
                    fromPacks(line) {
                        if (! line.case_size) { line.cartons = null; return; }
                        const packs = this.int(line.packs);
                        line.cartons = packs > 0 && packs % line.case_size === 0 ? packs / line.case_size : null;
                    },

                    int(v) { const n = parseInt(v, 10); return isNaN(n) ? 0 : n; },
                    num(v) { const n = parseFloat(String(v ?? '').replace(/,/g, '')); return isNaN(n) ? 0 : n; },

                    // Worked in paisa so the figures here match what the server
                    // stores, rounding each line half up exactly as Money does.
                    grossPaisa(line) {
                        return Math.round(this.num(line.rate) * 100) * this.int(line.packs);
                    },
                    discountPaisa(line) {
                        const pct = line.discount_percent !== '' && line.discount_percent !== null
                            ? this.num(line.discount_percent)
                            : this.num(this.discount);
                        if (pct <= 0) return 0;
                        return Math.floor((this.grossPaisa(line) * pct) / 100 + 0.5);
                    },
                    net(line) { return (this.grossPaisa(line) - this.discountPaisa(line)) / 100; },

                    get grossTotal() { return this.lines.reduce((t, l) => t + this.grossPaisa(l), 0) / 100; },
                    get discountTotal() { return this.lines.reduce((t, l) => t + this.discountPaisa(l), 0) / 100; },
                    get netTotal() { return this.grossTotal - this.discountTotal; },

                    fmt(v) {
                        return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    },
                };
            }
        </script>
    @endpush
</x-workspace-layout>
