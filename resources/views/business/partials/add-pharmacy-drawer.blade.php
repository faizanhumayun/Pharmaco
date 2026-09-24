{{--
    Adding a customer, from wherever you happen to be.

    Opened by name: $dispatch('open-drawer', 'add-pharmacy'). It saves without
    leaving the page, because at the counter the page holds a half-written
    bill. When it saves it announces the new customer as a `pharmacy-added`
    event; the counter selects it, the pharmacies page reloads to show it.

    $afterAdd — 'select' (stay, announce) or 'reload' (show the new row).
--}}
@props(['afterAdd' => 'reload'])

<x-drawer name="add-pharmacy" title="Add a customer"
          subtitle="A pharmacy or store you sell to. It gets its own ledger, so what it owes is tracked on its own.">
    <form id="add-pharmacy-form" class="space-y-4"
          x-data="addPharmacy({ url: @js(route('businesses.pharmacies.store', $business)), after: @js($afterAdd) })"
          x-on:submit.prevent="submit()">
        <div>
            <x-input-label for="pharmacy_name" value="Name" />
            <x-text-input id="pharmacy_name" x-model="form.name" type="text" class="mt-1 block w-full"
                          placeholder="e.g. AL SHIFA MEDICAL STORE" required maxlength="160" />
            <p class="mt-1 text-xs text-red-600" x-show="errors.name" x-cloak x-text="errors.name?.[0]"></p>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <x-input-label for="pharmacy_area" value="Area" />
                <x-text-input id="pharmacy_area" x-model="form.area" type="text" class="mt-1 block w-full"
                              placeholder="Bahtar" maxlength="120" />
            </div>
            <div>
                <x-input-label for="pharmacy_phone" value="Phone" />
                <x-text-input id="pharmacy_phone" x-model="form.phone" type="text" class="mt-1 block w-full"
                              placeholder="0300 1234567" maxlength="40" />
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <x-input-label for="pharmacy_contact" value="Contact person" />
                <x-text-input id="pharmacy_contact" x-model="form.contact" type="text" class="mt-1 block w-full" maxlength="120" />
            </div>
            <div>
                <x-input-label for="pharmacy_credit_days" value="Credit days" />
                <x-text-input id="pharmacy_credit_days" x-model="form.credit_days" type="number" min="0" max="365"
                              class="mt-1 block w-full" placeholder="0" />
            </div>
        </div>

        <div>
            <x-input-label for="pharmacy_code" value="Code" />
            <x-text-input id="pharmacy_code" x-model="form.code" type="text" class="mt-1 block w-full"
                          placeholder="Optional — your own reference" maxlength="40" />
        </div>

        <p class="text-xs text-gray-500">
            The first customer added moves the existing market total into an "Unallocated" ledger by a
            visible transfer — nothing in the history is rewritten.
        </p>

        <p class="text-sm text-red-600" x-show="failed" x-cloak x-text="failed"></p>
    </form>

    <x-slot name="footer">
        <div class="flex items-center justify-end gap-3">
            <button type="button" x-on:click="$dispatch('close')"
                    class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Cancel
            </button>
            <button type="submit" form="add-pharmacy-form"
                    class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                Add customer
            </button>
        </div>
    </x-slot>
</x-drawer>

@once
    @push('scripts')
        <script>
            function addPharmacy({ url, after }) {
                return {
                    form: { name: '', area: '', phone: '', contact: '', credit_days: '', code: '' },
                    errors: {},
                    failed: '',
                    saving: false,

                    async submit() {
                        if (this.saving) return;
                        this.saving = true;
                        this.errors = {};
                        this.failed = '';

                        try {
                            const response = await fetch(url, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                },
                                body: JSON.stringify(this.form),
                            });

                            if (response.status === 422) {
                                this.errors = (await response.json()).errors ?? {};
                                return;
                            }

                            if (! response.ok) {
                                this.failed = 'Could not save that customer. Try again.';
                                return;
                            }

                            const pharmacy = await response.json();

                            if (after === 'reload') {
                                window.location.reload();
                                return;
                            }

                            // Hand the new customer to whatever opened this.
                            window.dispatchEvent(new CustomEvent('pharmacy-added', { detail: pharmacy }));
                            this.form = { name: '', area: '', phone: '', contact: '', credit_days: '', code: '' };
                            this.$dispatch('close-drawer', 'add-pharmacy');
                        } finally {
                            this.saving = false;
                        }
                    },
                };
            }
        </script>
    @endpush
@endonce
