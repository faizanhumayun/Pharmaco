@props([
    'business',
    // What happens once a company is added: the page is drawn again so it
    // shows it. A page that takes the new company in itself — the delivery
    // screen chooses it at once — passes false and listens for the
    // `company-added` event instead.
    'reload' => true,
])

{{--
    Adding a company, from wherever one is needed. A modal window, the same
    on every screen.

    On every workspace screen (the layout includes it), and opened by an event
    from anywhere:

        <button x-on:click="$dispatch('add-company')">Add a company</button>
        $dispatch('add-company', { name: 'GSK' })   // with a head start

    It saves without leaving the page, to the ordinary companies.store route,
    so there is one set of rules for a new company. When it is added it
    announces it — window event `company-added`, with { id, name } — for a
    page that wants to use it at once.
--}}
<div x-data="addCompany({
        url: @js(route('businesses.companies.store', $business)),
        reload: @js((bool) $reload),
     })"
     x-on:add-company.window="open($event.detail)"
     x-show="showing" x-cloak x-on:keydown.escape.window="close()"
     class="fixed inset-0 z-[60] flex items-start justify-center overflow-y-auto bg-gray-900/50 p-4"
     role="dialog" aria-modal="true" aria-label="Add a company">
    <div class="my-auto w-full max-w-md rounded-xl bg-white shadow-xl" x-on:click.outside="close()">
        <div class="border-b border-gray-200 px-5 py-4">
            <h2 class="text-lg font-semibold text-gray-900">Add a company</h2>
            <p class="text-sm text-gray-500">It gets its own ledger under Company Payables.</p>
        </div>

        <div x-show="added" x-cloak class="space-y-4 px-5 py-6">
            <p class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                <strong x-text="added?.name"></strong> was added as <span class="font-mono font-semibold" x-text="added?.code"></span><span x-show="added?.account" x-text="' with its own ledger (' + added?.account + ')'"></span>.
            </p>
            <div class="flex items-center justify-end gap-3">
                <button type="button" x-on:click="open()"
                        class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Add another</button>
                <button type="button" x-on:click="close()"
                        class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Done</button>
            </div>
        </div>

        <form x-show="! added" x-on:submit.prevent="save()">
            <div class="space-y-4 px-5 py-4">
                <div>
                    <x-input-label for="new_company_name" value="Name" />
                    <x-text-input id="new_company_name" x-ref="name" x-model="draft.name" type="text" class="mt-1 block w-full" maxlength="160" />
                    <p class="mt-1 text-xs text-red-600" x-show="errors.name" x-cloak x-text="errors.name"></p>
                </div>

                <div>
                    <x-input-label for="new_company_credit_days" value="Credit days" />
                    <x-text-input id="new_company_credit_days" x-model="draft.credit_days" type="number" min="0" max="365" class="mt-1 block w-40" />
                    <p class="mt-1 text-xs text-red-600" x-show="errors.credit_days" x-cloak x-text="errors.credit_days"></p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <x-input-label for="new_company_contact" value="Contact" />
                        <x-text-input id="new_company_contact" x-model="draft.contact" type="text" class="mt-1 block w-full" maxlength="120" />
                        <p class="mt-1 text-xs text-red-600" x-show="errors.contact" x-cloak x-text="errors.contact"></p>
                    </div>
                    <div>
                        <x-input-label for="new_company_phone" value="Phone" />
                        <x-text-input id="new_company_phone" x-model="draft.phone" type="text" class="mt-1 block w-full" maxlength="40" />
                        <p class="mt-1 text-xs text-red-600" x-show="errors.phone" x-cloak x-text="errors.phone"></p>
                    </div>
                </div>

                <p class="text-xs text-gray-500">The company code (CMP-0001, CMP-0002 …) is created automatically.</p>
                <p class="text-xs text-gray-500">
                    When the first company is added, the existing total is moved to an "Unallocated" ledger by a
                    recorded transfer. Past records are not changed.
                </p>
                <p class="text-sm text-red-600" x-show="errors.general" x-cloak x-text="errors.general"></p>
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-gray-200 px-5 py-3">
                <button type="button" x-on:click="close()"
                        class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" :disabled="saving"
                        class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">
                    <span x-text="saving ? 'Adding…' : 'Add company'"></span>
                </button>
            </div>
        </form>
    </div>
</div>

@once
    @push('scripts')
        <script>
            function addCompany({ url, reload }) {
                const blank = () => ({ name: '', credit_days: '', contact: '', phone: '' });

                return {
                    showing: false,
                    saving: false,
                    added: null,
                    anyAdded: false,
                    errors: {},
                    draft: blank(),

                    /** Opened from anywhere, optionally knowing the name already. */
                    open(start) {
                        this.draft = { ...blank(), name: String(start?.name ?? '') };
                        this.errors = {};
                        this.added = null;
                        this.showing = true;
                        this.$nextTick(() => this.$refs.name?.focus());
                    },

                    close() {
                        if (! this.showing) return;

                        this.showing = false;

                        if (reload && this.anyAdded) window.location.reload();
                    },

                    async save() {
                        if (this.saving) return;

                        this.errors = {};

                        if (this.draft.name.trim() === '') {
                            this.errors.name = 'Enter the company name.';

                            return;
                        }

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
                                for (const [field, messages] of Object.entries(body.errors ?? {})) {
                                    this.errors[field] = Array.isArray(messages) ? messages[0] : String(messages);
                                }

                                if (Object.keys(this.errors).length === 0) {
                                    this.errors.general = response.status === 403
                                        ? 'Only the business owner can add a company.'
                                        : (body.message || 'The company could not be added. Try again.');
                                }

                                return;
                            }

                            this.anyAdded = true;
                            window.dispatchEvent(new CustomEvent('company-added', { detail: body }));

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
