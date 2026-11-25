{{--
    The computer in front of you, by name — "Laptop POS 1" — and a way to name
    it. What is entered here is recorded as entered on this device, so the
    trail can say which screen a bill or a delivery came from.

    Until it has a name the chip asks for one. $dark is for the till's header.
--}}
@php($device = app(\App\Support\CurrentDevice::class)->get())
@if ($device)
    @php($dark = $dark ?? false)
    <div x-data="deviceChip({ url: @js(route('device.update')), name: @js($device->name ?? ''), label: @js($device->label()) })"
         class="relative whitespace-nowrap text-xs" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
        <button type="button" x-on:click="edit()" :aria-expanded="open"
                title="This device. All entries made here are recorded under its name. Click to rename it, or to change this screen's colours."
                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-medium ring-1 ring-inset"
                :class="name
                    ? '{{ $dark ? 'bg-slate-800 text-slate-200 ring-slate-600 hover:bg-slate-700' : 'bg-gray-100 text-gray-700 ring-gray-500/20 hover:bg-gray-200' }}'
                    : '{{ $dark ? 'bg-amber-900/40 text-amber-200 ring-amber-500/40' : 'bg-amber-50 text-amber-800 ring-amber-600/20 hover:bg-amber-100' }}'">
            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M2 4.25A2.25 2.25 0 014.25 2h11.5A2.25 2.25 0 0118 4.25v8.5A2.25 2.25 0 0115.75 15h-3.105a3.501 3.501 0 001.1 1.677A.75.75 0 0113.26 18H6.74a.75.75 0 01-.484-1.323A3.501 3.501 0 007.355 15H4.25A2.25 2.25 0 012 12.75v-8.5zm1.5 0a.75.75 0 01.75-.75h11.5a.75.75 0 01.75.75v7.5a.75.75 0 01-.75.75H4.25a.75.75 0 01-.75-.75v-7.5z" clip-rule="evenodd" />
            </svg>
            <span x-text="name ? label : 'Name this device'">{{ $device->isNamed() ? $device->label() : 'Name this device' }}</span>
        </button>

        <form x-show="open" x-cloak x-on:submit.prevent="save()"
              class="absolute right-0 z-50 mt-1 w-72 rounded-lg border border-gray-200 bg-white p-3 text-left shadow-lg">
            <label class="block text-xs font-medium text-gray-700" for="device_name">Device name</label>
            <input id="device_name" x-ref="box" x-model="draft" type="text" maxlength="60" placeholder="Laptop POS 1"
                   class="mt-1 block w-full rounded-md border-gray-300 text-sm text-gray-900 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
            <p class="mt-1 whitespace-normal text-xs text-gray-500">All entries made on this device are recorded under this name.</p>
            <p class="mt-1 whitespace-normal text-xs text-red-600" x-show="error" x-cloak x-text="error"></p>
            <div class="mt-2 flex justify-end gap-2">
                <button type="button" x-on:click="open = false" class="rounded-md px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100">Cancel</button>
                <button type="submit" :disabled="saving" class="rounded-md bg-emerald-700 px-3 py-1 text-xs font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">Save</button>
            </div>

            {{-- This screen's colours: for a dull or washed-out display. --}}
            <div class="mt-3 border-t border-gray-200 pt-3">
                <p class="text-xs font-medium text-gray-700">Screen</p>
                <div class="mt-1 grid grid-cols-2 gap-1 rounded-md bg-gray-100 p-1">
                    <button type="button" x-on:click="setScreen('standard')"
                            :class="screen === 'standard' ? 'bg-white text-gray-900 shadow ring-1 ring-gray-300' : 'text-gray-600 hover:text-gray-900'"
                            class="rounded px-2 py-1 text-xs font-medium">Standard</button>
                    <button type="button" x-on:click="setScreen('strong')"
                            :class="screen === 'strong' ? 'bg-white text-gray-900 shadow ring-1 ring-gray-300' : 'text-gray-600 hover:text-gray-900'"
                            class="rounded px-2 py-1 text-xs font-medium">Strong colours</button>
                </div>
                <p class="mt-1 whitespace-normal text-xs text-gray-500">Strong colours make text, lines and colours clearer on a dull or washed-out screen. Only this computer changes.</p>
            </div>
        </form>
    </div>

    @once
        @push('scripts')
            <script>
                function deviceChip({ url, name, label }) {
                    return {
                        name, label, draft: name, open: false, saving: false, error: '',
                        screen: window.pharmacoScreen ? window.pharmacoScreen() : 'standard',

                        setScreen(mode) {
                            this.screen = window.pharmacoScreen ? window.pharmacoScreen(mode) : mode;
                        },

                        edit() {
                            this.draft = this.name;
                            this.error = '';
                            this.open = ! this.open;
                            if (this.open) this.$nextTick(() => this.$refs.box.focus());
                        },

                        async save() {
                            if (this.saving) return;

                            if (this.draft.trim() === '') {
                                this.error = 'Enter a name.';
                                return;
                            }

                            this.saving = true;

                            try {
                                const response = await fetch(url, {
                                    method: 'PATCH',
                                    credentials: 'same-origin',
                                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                                    body: JSON.stringify({ name: this.draft }),
                                });
                                const body = await response.json().catch(() => ({}));

                                if (! response.ok) {
                                    this.error = body.message || 'The name could not be saved.';
                                    return;
                                }

                                this.name = this.draft.trim();
                                this.label = body.name;
                                this.open = false;
                            } finally {
                                this.saving = false;
                            }
                        },
                    };
                }
            </script>
        @endpush
    @endonce
@endif
