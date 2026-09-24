{{--
    Recording a delivery that never had an order form.

    Pick who it came from, tick through what was in the van, and put the
    invoice on it. Behind the scenes this is an ordinary order marked sent and
    received at once — the order was placed, just on the telephone — so the
    goods, the invoice and the trail are identical to every other delivery.
--}}
<x-workspace-layout :business="$business" fill>
    <div class="flex h-full w-full flex-col gap-4 overflow-hidden px-4 py-4 sm:px-6 lg:px-8" x-data="delivery({
        products: @js($products),
    })">
        <x-flash />

        @if ($dayClosed)
            <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ $today->format('l j F Y') }} is closed, so an invoice cannot be put on it.
                Reopen the day on its closing page first.
            </div>
        @endif

        <x-input-error :messages="$errors->get('lines')" class="mb-4" />

        <form method="POST" action="{{ route('businesses.stock.receive.store', $business) }}"
              class="flex min-h-0 flex-1 flex-col gap-4" x-ref="form">
            @csrf

            {{-- The delivery itself, across the top: everything here is about the
                 invoice rather than the goods, and it is answered once. --}}
            <x-panel class="shrink-0">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 sm:px-6">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">The delivery</h2>
                        <p class="mt-0.5 text-sm text-gray-500">
                            Goods that arrived without an order form — ordered by phone, or brought
                            without being asked for. They go into stock, and onto today's entry as an invoice.
                        </p>
                    </div>
                    <a href="{{ route('businesses.stock.index', $business) }}"
                       class="shrink-0 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Back to stock
                    </a>
                </div>

                <div class="grid gap-4 p-4 sm:p-6 lg:grid-cols-4">
                    <div>
                        <x-input-label for="company_id" value="Company" />
                        <select id="company_id" name="company_id" x-model="companyId" required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">Choose the company…</option>
                            @foreach ($companies as $company)
                                <option value="{{ $company->id }}">{{ $company->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('company_id')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="invoice_no" value="Invoice number" />
                        <x-text-input id="invoice_no" name="invoice_no" type="text" class="mt-1 block w-full"
                                      placeholder="As printed on their bill" maxlength="60" />
                    </div>

                    <div>
                        <x-input-label for="paid" value="Paid now" />
                        <x-text-input id="paid" name="paid" x-model="paid" type="text" inputmode="decimal"
                                      class="mt-1 block w-full text-right tabular-nums" placeholder="0.00" />
                    </div>

                    <div>
                        <x-input-label for="notes" value="Note" />
                        <x-text-input id="notes" name="notes" type="text" class="mt-1 block w-full"
                                      placeholder="Ordered by phone on Tuesday" maxlength="255" />
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-x-8 gap-y-3 border-t border-gray-200 bg-gray-50 px-4 py-3 sm:px-6">
                    <dl class="flex flex-wrap items-center gap-x-8 gap-y-2 text-sm">
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Lines</dt>
                            <dd class="font-mono text-base font-semibold tabular-nums text-gray-900" x-text="lines.length"></dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">{{ Str::ucfirst($business->unit()->many()) }}</dt>
                            <dd class="font-mono text-base font-semibold tabular-nums text-gray-900" x-text="totalPacks"></dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Bill comes to</dt>
                            <dd class="font-mono text-base font-semibold tabular-nums text-gray-900" x-text="'Rs. ' + money(total)"></dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">On their account</dt>
                            <dd class="font-mono text-base font-semibold tabular-nums"
                                :class="onAccount > 0 ? 'text-amber-800' : 'text-gray-900'"
                                x-text="'Rs. ' + money(onAccount)"></dd>
                        </div>
                    </dl>

                    <div class="flex items-center gap-3">
                        <p class="text-xs" :class="missingSellPrice > 0 ? 'font-medium text-amber-700' : 'text-gray-500'"
                           x-text="missingSellPrice > 0
                               ? missingSellPrice + ' ' + (missingSellPrice === 1 ? 'line needs' : 'lines need') + ' a selling price'
                               : @js('Recorded on ' . $today->format('j M Y'))"></p>
                        <button type="submit" :disabled="lines.length === 0 || ! companyId || missingSellPrice > 0 || {{ $dayClosed ? 'true' : 'false' }}"
                                class="rounded-md bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-40">
                            Put it into stock
                        </button>
                    </div>
                </div>
            </x-panel>

            <div class="grid min-h-0 flex-1 gap-4 xl:grid-cols-2">
                {{-- Their catalogue, browsable the way the order form's is: you
                     check a delivery against a list, not by remembering names. --}}
                <x-panel class="flex min-h-0 flex-col">
                    <div class="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-1 border-b border-gray-200 px-4 py-3 sm:px-6">
                        <h2 class="text-base font-semibold text-gray-900">Their products</h2>
                        <span class="text-sm text-gray-500" x-show="companyId" x-cloak>
                            <span x-text="shown.length"></span> of <span x-text="ofCompany.length"></span>
                        </span>
                        <button type="button" x-show="companyId" x-cloak x-on:click="openNew()"
                                class="ms-auto text-sm font-medium text-emerald-700 hover:text-emerald-800">
                            Not in the list? Add it
                        </button>
                    </div>

                    <div x-show="! companyId" class="px-4 py-10 text-center text-sm text-gray-500 sm:px-6">
                        Choose a company first — the list reads its catalogue.
                    </div>

                    <div x-show="companyId" x-cloak class="flex min-h-0 flex-1 flex-col p-4 sm:p-6">
                        <x-text-input type="search" class="block w-full shrink-0" x-model="query"
                                      placeholder="Search by brand or code…" x-ref="search" />

                        <div class="mt-3 grid shrink-0 grid-cols-[minmax(0,1fr)_5rem_5rem] items-center gap-2 border-b border-gray-200 px-2 pb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">
                            <span>Product</span>
                            <span class="text-right">Rate</span>
                            <span class="text-right" title="{{ Str::ucfirst($business->unit()->many()) }} already on this delivery">On</span>
                        </div>

                        <ul class="min-h-0 flex-1 divide-y divide-gray-100 overflow-y-auto">
                            <template x-for="product in shown" :key="product.id">
                                <li x-on:click="add(product)"
                                    class="grid cursor-pointer grid-cols-[minmax(0,1fr)_5rem_5rem] items-center gap-2 px-2 py-2 hover:bg-emerald-50">
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-medium text-gray-900" x-text="product.name"></span>
                                        <span class="block truncate text-xs text-gray-500" x-text="product.code || '—'"></span>
                                    </span>
                                    <span class="text-right font-mono text-sm tabular-nums text-gray-600" x-text="money(product.rate)"></span>
                                    <span class="text-right font-mono text-sm tabular-nums"
                                          :class="onDelivery(product.id) ? 'font-semibold text-emerald-700' : 'text-gray-300'"
                                          x-text="onDelivery(product.id) || '—'"></span>
                                </li>
                            </template>
                        </ul>

                        <p x-show="shown.length === 0" x-cloak class="py-8 text-center text-sm text-gray-500">
                            Nothing of theirs matches that.
                            <button type="button" x-on:click="openNew()"
                                    class="font-medium text-emerald-700 hover:text-emerald-800">Add it as a new product</button>
                        </p>
                    </div>
                </x-panel>

                {{-- What is going into stock. --}}
                <x-panel class="flex min-h-0 flex-col">
                    <div class="shrink-0 border-b border-gray-200 px-4 py-3 sm:px-6">
                        <h2 class="text-base font-semibold text-gray-900">On this delivery</h2>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    @foreach (['Product', Str::ucfirst($business->unit()->many()), 'Cost', $sellLabel, 'MRP', 'Total', ''] as $h)
                                        <th class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ $loop->first ? 'text-left sm:px-6' : 'text-right' }}">{{ $h }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <template x-for="(line, i) in lines" :key="line.id">
                                    <tr>
                                        <td class="px-3 py-2 sm:px-6">
                                            <span class="block text-sm font-medium text-gray-900" x-text="line.name"></span>
                                            <input type="hidden" :name="`lines[${i}][company_product_id]`" :value="line.id">
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <input type="text" inputmode="numeric" x-model.number="line.packs"
                                                   :name="`lines[${i}][packs]`"
                                                   class="w-20 rounded-md border-gray-300 py-1 text-right text-sm tabular-nums">
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right" x-data>
                                            <template x-if="priceMove(line)">
                                                <span class="mr-1 inline-flex items-center gap-0.5 text-xs font-medium"
                                                      :class="priceMove(line).up ? 'text-red-700' : 'text-emerald-700'"
                                                      :title="'Was ' + priceMove(line).was.toFixed(2)">
                                                    <span x-text="priceMove(line).up ? '▲' : '▼'"></span>
                                                    <span x-text="priceMove(line).percent.toFixed(0) + '%'"></span>
                                                </span>
                                            </template>
                                            <input type="text" inputmode="decimal" x-model.number="line.rate"
                                                   :name="`lines[${i}][rate]`"
                                                   class="w-24 rounded-md border-gray-300 py-1 text-right text-sm tabular-nums">
                                        </td>
                                        {{-- What this business sells it for. --}}
                                        <td class="px-3 py-2 text-right">
                                            <input type="text" inputmode="decimal" x-model.number="line.trade"
                                                   :name="`lines[${i}][trade]`" placeholder="{{ $sellRequired ? 'required' : '—' }}"
                                                   :class="{{ $sellRequired ? 'true' : 'false' }} && ! (Number(line.trade) > 0)
                                                       ? 'border-amber-400 bg-amber-50'
                                                       : 'border-gray-300'"
                                                   class="w-24 rounded-md py-1 text-right text-sm tabular-nums">
                                        </td>

                                        {{-- Printed on the pack. --}}
                                        <td class="px-3 py-2 text-right">
                                            <input type="text" inputmode="decimal" x-model.number="line.mrp"
                                                   :name="`lines[${i}][mrp]`" placeholder="—"
                                                   class="w-24 rounded-md border-gray-300 py-1 text-right text-sm tabular-nums">
                                        </td>

                                        <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-900"
                                            x-text="money((Number(line.packs) || 0) * (Number(line.rate) || 0))"></td>
                                        <td class="px-3 py-2 text-right">
                                            <button type="button" x-on:click="lines.splice(i, 1)"
                                                    class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-700" title="Remove">&times;</button>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="lines.length === 0">
                                    <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500">
                                        Nothing added yet. Pick from their products on the left.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </x-panel>
            </div>
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
                            <x-input-label value="Pack size" />
                            <x-text-input x-model="draft.pack_size" type="text" class="mt-1 block w-full" placeholder="10x10" maxlength="60" />
                        </div>
                        <div>
                            <x-input-label value="Strength" />
                            <x-text-input x-model="draft.strength" type="text" class="mt-1 block w-full" placeholder="500mg" maxlength="60" />
                        </div>
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

                    <div>
                        <x-input-label value="Their code" />
                        <x-text-input x-model="draft.code" type="text" class="mt-1 block w-full"
                                      placeholder="Optional — from their price list" maxlength="60" />
                        <p class="mt-1 text-xs text-gray-500">
                            Giving their code means their next price list updates this product
                            rather than adding a second copy of it.
                        </p>
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
                    draft: { brand_name: '', pack_size: '', strength: '', purchase_rate: '', mrp: '', code: '' },

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
                        this.draft = { brand_name: this.query.trim(), pack_size: '', strength: '', purchase_rate: '', mrp: '', code: '' };
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
                                // What it cost before this delivery, so a rate
                                // typed differently shows as a change.
                                list_rate: product.rate,
                            });
                        }

                        this.$refs.search?.focus();
                    },

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

                    /** Lines a pharmacy may not save without a selling price. */
                    get missingSellPrice() {
                        @if ($sellRequired)
                            return this.lines.filter(l => ! (Number(l.trade) > 0)).length;
                        @else
                            return 0;
                        @endif
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
                    },
                };
            }
        </script>
    @endpush
</x-workspace-layout>
