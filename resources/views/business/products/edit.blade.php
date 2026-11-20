{{--
    Changing a product by hand. Price lists and deliveries keep the catalogue
    up to date on their own; this is for what they cannot know — a name spelled
    wrong, a reorder level, how many pieces are in a pack.
--}}
<x-workspace-layout :business="$business" :fill="true">
    <x-slot name="header">
        <div>
            {{-- One slim line, as on the product's own page. --}}
            <div class="-my-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h1 class="text-xl font-semibold text-gray-900">Edit {{ $product->brand_name }}</h1>
                <p class="text-sm text-gray-500">{{ $product->company?->name }} · every change is kept on the product's record.</p>
            </div>
        </div>
    </x-slot>

    {{-- The form is the window: it does not scroll the page. If a screen is
         too short to hold it, the form scrolls inside itself. --}}
    <div class="h-full w-full overflow-auto px-4 py-4 sm:px-6 lg:px-8">
        <x-flash />

        <form method="POST" action="{{ route('businesses.products.update', [$business, $product]) }}" class="grid gap-4 xl:grid-cols-3"
              x-data="productForm({{ Illuminate\Support\Js::from(['pack' => (string) old('pack_size', $product->pack_size), 'pieces' => (int) old('units_per_pack', $product->units_per_pack)]) }})">
            @csrf
            @method('PUT')

            <div class="space-y-4 xl:col-span-2">
                <x-panel title="What it is">
                    <div class="grid gap-x-4 gap-y-3 p-4 sm:grid-cols-2 sm:px-6 sm:py-4">
                        <div class="sm:col-span-2">
                            <x-input-label for="brand_name" value="Brand name" />
                            <x-text-input id="brand_name" name="brand_name" type="text" class="mt-1 block w-full" :value="old('brand_name', $product->brand_name)" required />
                            <x-input-error :messages="$errors->get('brand_name')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label for="generic_name" value="Generic name" />
                            <x-text-input id="generic_name" name="generic_name" type="text" class="mt-1 block w-full" :value="old('generic_name', $product->generic_name)" />
                        </div>
                        <div>
                            <x-input-label for="code" value="Product code / barcode" />
                            <x-text-input id="code" name="code" type="text" class="mt-1 block w-full" :value="old('code', $product->code)" />
                            <p class="mt-1 text-xs text-gray-500">What a scanner at the counter looks for.</p>
                        </div>
                        <div>
                            <x-input-label for="strength" value="Strength" />
                            <x-text-input id="strength" name="strength" type="text" class="mt-1 block w-full" :value="old('strength', $product->strength)" placeholder="500mg" />
                        </div>
                        <div>
                            <x-input-label for="dosage_form" value="Dosage form" />
                            <select id="dosage_form" name="dosage_form" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                <option value="">—</option>
                                @foreach ($forms as $form)
                                    <option value="{{ $form->value }}" @selected(old('dosage_form', $product->dosage_form) === $form->value)>{{ $form->label() }}</option>
                                @endforeach
                                @if ($product->dosage_form && ! \App\Enums\DosageForm::tryFrom($product->dosage_form))
                                    <option value="{{ $product->dosage_form }}" selected>{{ $product->dosage_form }}</option>
                                @endif
                            </select>
                        </div>
                        <div>
                            <x-input-label for="pack_type" value="Pack type" />
                            <select id="pack_type" name="pack_type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                <option value="">—</option>
                                @foreach ($packTypes as $type)
                                    <option value="{{ $type->value }}" @selected(old('pack_type', $product->pack_type) === $type->value)>{{ $type->label() }}</option>
                                @endforeach
                                @if ($product->pack_type && ! \App\Enums\PackType::tryFrom($product->pack_type))
                                    <option value="{{ $product->pack_type }}" selected>{{ $product->pack_type }}</option>
                                @endif
                            </select>
                        </div>
                        <div>
                            <x-input-label for="pack_size" value="Pack size (as printed)" />
                            <x-text-input id="pack_size" name="pack_size" type="text" class="mt-1 block w-full" x-model="pack" x-on:input="fromPack()" placeholder="1/10, 20's, 120ml" />
                        </div>
                        <div>
                            <x-input-label for="case_size" value="Packs per carton" />
                            <x-text-input id="case_size" name="case_size" type="number" min="1" class="mt-1 block w-full" :value="old('case_size', $product->case_size)" />
                            <x-input-error :messages="$errors->get('case_size')" class="mt-1" />
                        </div>
                    </div>
                </x-panel>

                <x-panel title="Prices" description="Per {{ $business->unit()->one() }}. A change here is added to the price history, dated today.">
                    <div class="grid gap-4 p-4 {{ $business->sellsAtMrp() ? 'sm:grid-cols-2' : 'sm:grid-cols-3' }} sm:p-6">
                        {{-- A pharmacy sells at the MRP and has no selling price of its
                             own; a distributor needs its trade price as well. --}}
                        @php($priceFields = $business->sellsAtMrp()
                            ? ['mrp' => ['MRP', 'Printed on the pack. This is what you sell it for; a discount is given at the counter.'], 'purchase_rate' => ['Purchase rate', 'What it costs you. Nothing sells below this.']]
                            : ['mrp' => ['MRP', 'Printed on the pack — what the patient pays.'], 'trade_price' => ['Trade price', 'What you sell it for to a pharmacy.'], 'purchase_rate' => ['Purchase rate', 'What it costs you. Nothing sells below this.']])
                        @foreach ($priceFields as $field => [$label, $help])
                            <div>
                                <x-input-label :for="$field" :value="$label" />
                                <x-text-input :id="$field" :name="$field" type="text" inputmode="decimal" class="mt-1 block w-full text-right tabular-nums"
                                              :value="old($field, $product->{$field}?->toDecimal())" placeholder="0.00" />
                                <p class="mt-1 text-xs text-gray-500">{{ $help }}</p>
                                <x-input-error :messages="$errors->get($field)" class="mt-1" />
                            </div>
                        @endforeach
                    </div>
                </x-panel>
            </div>

            <div class="space-y-4">
                <x-panel title="Stock rules">
                    <div class="space-y-5 p-4 sm:p-6">
                        <div>
                            <x-input-label for="reorder_level" value="Reorder level" />
                            <x-text-input id="reorder_level" name="reorder_level" type="number" min="0" class="mt-1 block w-full"
                                          :value="old('reorder_level', $product->reorder_level)" />
                            <p class="mt-1 text-xs text-gray-500">
                                When stock falls to this many {{ $business->unit()->many() }} or fewer, the product appears on the
                                reorder report. Zero means it is never flagged.
                            </p>
                            <x-input-error :messages="$errors->get('reorder_level')" class="mt-1" />
                        </div>
                        {{-- A pharmacy opens packs; a distributor sells them whole
                             and has no such figure to set. --}}
                        @if ($business->sellsLoose())
                        <div>
                            <x-input-label for="units_per_pack" value="Pieces in one {{ $business->unit()->one() }}" />
                            {{-- Follows the pack size while that is being typed, until
                                 a figure is typed here by hand: after that it is
                                 left alone, because the person knows better. --}}
                            <x-text-input id="units_per_pack" name="units_per_pack" type="number" min="1" class="mt-1 block w-full"
                                          x-model.number="pieces" x-on:input="byHand = true" />
                            <p class="mt-1 text-xs text-emerald-700" x-show="suggested > 1 && suggested !== Number(pieces)" x-cloak>
                                The pack size reads as <span class="font-semibold" x-text="suggested"></span> pieces.
                                <button type="button" class="font-semibold underline" x-on:click="pieces = suggested; byHand = false">Use <span x-text="suggested"></span></button>
                            </p>
                            <p class="mt-1 text-xs text-gray-500">
                                Filled in from the pack size: "1/10" is 10. The counter can then sell a whole pack or loose pieces at
                                that fraction of the price. Leave at 1 for something never opened, such as a bottle. It cannot be
                                changed once loose pieces have been sold.
                            </p>
                            <x-input-error :messages="$errors->get('units_per_pack')" class="mt-1" />
                        </div>
                        @else
                            <p class="rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600">
                                Sold in whole {{ $business->unit()->many() }} only. A distributor does not open a pack.
                            </p>
                        @endif
                        <label class="flex items-start gap-3">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $product->is_active))
                                   class="mt-0.5 rounded text-emerald-700 focus:ring-emerald-600">
                            <span>
                                <span class="block text-sm font-medium text-gray-900">On sale</span>
                                <span class="block text-xs text-gray-500">Untick to withdraw it: it leaves the counter and order forms but keeps its history.</span>
                            </span>
                        </label>
                    </div>
                    <div class="flex items-center justify-end gap-3 border-t border-gray-200 px-4 py-3 sm:px-6">
                        <a href="{{ route('businesses.products.show', [$business, $product]) }}"
                           class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
                        <button class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Save product</button>
                    </div>
                </x-panel>
            </div>
        </form>
    </div>
    @push('scripts')
        <script>
            /*
             * How many pieces a printed pack size means.
             *
             *   "1/10", "2\\10", "3 x 10"  → strips × tablets: 10, 20, 30
             *   "20's", "30 tab"            → 20, 30
             *   "120ml", "Nos", blank       → 1: a bottle or a single item is not opened
             *
             * A reading, not a fact — which is why it fills the field only
             * while the pack size is being typed and never overrides a figure
             * typed by hand.
             */
            function piecesFromPack(text) {
                const t = String(text || '').trim().toLowerCase();
                let m = t.match(/^(\d+)\s*[\/\\x×*]\s*(\d+)/);
                if (m) return Math.max(1, Number(m[1]) * Number(m[2]));
                m = t.match(/^(\d+)\s*('s|s\b|tab|cap|pcs|pieces?)/);
                if (m) return Math.max(1, Number(m[1]));
                return 1;
            }

            function productForm(start) {
                return {
                    pack: start.pack,
                    pieces: start.pieces || 1,
                    byHand: false,
                    get suggested() { return piecesFromPack(this.pack); },
                    fromPack() {
                        if (! this.byHand) this.pieces = this.suggested;
                    },
                };
            }
        </script>
    @endpush
</x-workspace-layout>
