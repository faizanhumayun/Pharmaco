@props([
    'business',
    // What happens once a product is added: the page is drawn again so it
    // shows it. A page that takes the new product in itself — the delivery
    // screen puts it straight on the delivery — passes false and listens for
    // the `product-added` event instead.
    'reload' => true,
])

{{--
    Adding a product to the catalogue, from wherever one is needed.

    On every workspace screen (the layout includes it), and opened by an event
    from anywhere:

        <button x-on:click="$dispatch('add-product')">Add a product</button>
        $dispatch('add-product', { company_id: 12, name: 'PANADOL' })   // with a head start

    It saves without leaving the page, to the same route a delivery uses, so
    there is one set of rules for a new product: it belongs to a company, it
    has a type, and its name is not already in the catalogue.

    When it is added it announces it — window event `product-added`, with the
    product — for a page that wants to do something with it at once.
--}}
@php
    $companies = \App\Models\Company::forBusiness($business)->orderBy('name')->get(['id', 'name']);
    $forms = array_map(fn (\App\Enums\DosageForm $form) => $form->label(), \App\Enums\DosageForm::cases());
@endphp

<div x-data="addProduct({
        url: @js(route('businesses.stock.receive.product', $business)),
        reload: @js((bool) $reload),
     })"
     x-on:add-product.window="open($event.detail)"
     x-show="showing" x-cloak x-on:keydown.escape.window="close()"
     class="fixed inset-0 z-[60] flex items-start justify-center overflow-y-auto bg-gray-900/50 p-4"
     role="dialog" aria-modal="true" aria-label="Add a product">
    <div class="my-auto w-full max-w-md rounded-xl bg-white shadow-xl" x-on:click.outside="close()">
        <div class="border-b border-gray-200 px-5 py-4">
            <h2 class="text-lg font-semibold text-gray-900">Add a product</h2>
            <p class="text-sm text-gray-500">
                The product is added to the company's list. It can then be received, counted and sold.
            </p>
        </div>

        {{-- Added: say so, with the code it was given, and offer the next one. --}}
        <div x-show="added" x-cloak class="space-y-4 px-5 py-6">
            <p class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                <strong x-text="added?.name"></strong> was added.
                Its code is <span class="font-mono font-semibold" x-text="added?.code"></span>.
            </p>
            <div class="flex items-center justify-end gap-3">
                <button type="button" x-on:click="another()"
                        class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Add another</button>
                <button type="button" x-on:click="close()"
                        class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Done</button>
            </div>
        </div>

        <form x-show="! added" x-on:submit.prevent="save()">
            <div class="space-y-4 px-5 py-4">
                <div>
                    <x-input-label for="new_product_company" value="Company" />
                    <select id="new_product_company" x-ref="company" x-model="draft.company_id" data-search
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                        <option value="">Select company…</option>
                        @foreach ($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-red-600" x-show="errors.company_id" x-cloak x-text="errors.company_id"></p>
                    @if ($companies->isEmpty())
                        <p class="mt-1 text-xs text-amber-700">There are no companies yet. Add the company first, then its products.</p>
                    @endif
                </div>

                <div>
                    <x-input-label for="new_product_name" value="Name" />
                    <x-text-input id="new_product_name" x-ref="name" x-model="draft.brand_name" type="text" class="mt-1 block w-full"
                                  placeholder="e.g. PANADOL 500MG" maxlength="200" />
                    {{-- One name, one product: a second one of the same name,
                         strength and type is the same product entered twice. --}}
                    <p class="mt-1 text-xs text-red-600" x-show="errors.brand_name" x-cloak x-text="errors.brand_name"></p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <x-input-label for="new_product_form" value="Type" />
                        <select id="new_product_form" x-ref="form" x-model="draft.dosage_form"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">Select type (tablet, syrup…)</option>
                            @foreach ($forms as $form)
                                <option value="{{ $form }}">{{ $form }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-red-600" x-show="errors.dosage_form" x-cloak x-text="errors.dosage_form"></p>
                    </div>
                    <div>
                        <x-input-label for="new_product_strength" value="Strength" />
                        <x-text-input id="new_product_strength" x-model="draft.strength" type="text" class="mt-1 block w-full" placeholder="500mg" maxlength="60" />
                    </div>
                </div>

                <div>
                    <x-input-label for="new_product_pack" value="Pack size" />
                    <x-text-input id="new_product_pack" x-model="draft.pack_size" type="text" class="mt-1 block w-full" placeholder="10/10" maxlength="60" />
                    <p class="mt-1 text-xs text-gray-500">
                        Strips × tablets, as printed on the box: “10/10”, “1/14”. Leave blank for a bottle or a single item.
                        @if ($business->sellsLoose())
                            <span class="font-medium text-gray-700" x-show="pieces > 1" x-text="'Total: ' + pieces + ' pieces per pack.'"></span>
                        @endif
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <x-input-label for="new_product_rate" value="Purchase rate" />
                        <x-text-input id="new_product_rate" x-model="draft.purchase_rate" type="text" inputmode="decimal"
                                      class="mt-1 block w-full text-right tabular-nums" placeholder="0.00" />
                    </div>
                    <div>
                        <x-input-label for="new_product_mrp" value="MRP" />
                        <x-text-input id="new_product_mrp" x-model="draft.mrp" type="text" inputmode="decimal"
                                      class="mt-1 block w-full text-right tabular-nums" placeholder="0.00" />
                    </div>
                </div>

                <p class="text-xs text-gray-500">The product code is created automatically.</p>
                <p class="text-sm text-red-600" x-show="errors.general" x-cloak x-text="errors.general"></p>
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-gray-200 px-5 py-3">
                <button type="button" x-on:click="close()"
                        class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" :disabled="saving"
                        class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">
                    <span x-text="saving ? 'Adding…' : 'Add product'"></span>
                </button>
            </div>
        </form>
    </div>
</div>

@once
    @push('scripts')
        <script>
            function addProduct({ url, reload }) {
                const blank = () => ({ company_id: '', brand_name: '', dosage_form: '', pack_size: '', strength: '', purchase_rate: '', mrp: '' });

                return {
                    showing: false,
                    saving: false,
                    added: null,
                    anyAdded: false,
                    errors: {},
                    draft: blank(),

                    /* How many pieces a printed pack size means: the same rule
                       as App\Support\PackSize. Keep them in step. */
                    get pieces() {
                        const t = String(this.draft.pack_size || '').trim().toLowerCase();
                        let m = t.match(/^(\d+)\s*[\/\\x×*]\s*(\d+)/);
                        if (m) return Math.max(1, Number(m[1]) * Number(m[2]));
                        m = t.match(/^(\d+)\s*('s|s\b|tab|cap|pcs|pieces?)/);
                        if (m) return Math.max(1, Number(m[1]));
                        return 1;
                    },

                    /** Opened from anywhere, optionally knowing the company and the name already. */
                    open(start) {
                        const company = this.draft.company_id;

                        this.draft = { ...blank(), company_id: String(start?.company_id ?? company ?? ''), brand_name: String(start?.name ?? '') };
                        this.errors = {};
                        this.added = null;
                        this.showing = true;

                        this.$nextTick(() => {
                            // The dropdowns are drawn by script: have them read the values just set.
                            this.$refs.company?._kebabSync?.();
                            this.$refs.form?._kebabSync?.();
                            this.$refs.name?.focus();
                        });
                    },

                    another() {
                        this.open({ company_id: this.draft.company_id });
                    },

                    close() {
                        if (! this.showing) return;

                        this.showing = false;

                        // A page that does nothing with the new product itself
                        // is drawn again, so its lists show it.
                        if (reload && this.anyAdded) window.location.reload();
                    },

                    async save() {
                        if (this.saving) return;

                        this.errors = {};

                        if (! this.draft.company_id) this.errors.company_id = 'Select the company.';
                        if (this.draft.brand_name.trim() === '') this.errors.brand_name = 'Enter the product name.';
                        if (! this.draft.dosage_form) this.errors.dosage_form = 'Select the type.';

                        if (Object.keys(this.errors).length > 0) return;

                        this.saving = true;

                        try {
                            const response = await fetch(url, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                },
                                body: JSON.stringify(this.draft),
                            });

                            const body = await response.json().catch(() => ({}));

                            if (! response.ok) {
                                // The server's own words, field by field.
                                for (const [field, messages] of Object.entries(body.errors ?? {})) {
                                    this.errors[field] = Array.isArray(messages) ? messages[0] : String(messages);
                                }

                                if (Object.keys(this.errors).length === 0) {
                                    this.errors.general = body.message || 'The product could not be added. Try again.';
                                }

                                return;
                            }

                            this.anyAdded = true;
                            window.dispatchEvent(new CustomEvent('product-added', { detail: body }));

                            if (reload) {
                                this.added = body;
                            } else {
                                this.showing = false;
                            }
                        } catch {
                            this.errors.general = 'The server could not be reached. Check the connection and try again.';
                        } finally {
                            this.saving = false;
                        }
                    },
                };
            }
        </script>
    @endpush
@endonce
