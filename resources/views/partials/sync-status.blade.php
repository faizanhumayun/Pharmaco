{{--
    On a shop's own computer: whether there is internet, and whether the
    online copy is up to date. Shown on every workspace screen and on the till.

        Online · synced 2 minutes ago        everything entered is online
        Online · sync pending   [Sync now]   something entered is not sent yet
        Offline · sync pending               it will go when the internet is back
        Online copy not set up               this computer has not been given a platform to send to

    Nothing is drawn on the platform itself. $dark is for the till's dark header.
--}}
@php($sync = app(\App\Domain\Mirror\SyncStatus::class))
@if ($sync->isShop() && ! \App\Support\MirrorHost::active())
    @php($dark = $dark ?? false)
    <div x-data="syncStatus({ url: @js(route('mirror.status')), syncUrl: @js(route('mirror.sync')), configured: @js($sync->configured()) })"
         class="flex items-center gap-2 whitespace-nowrap text-xs" role="status" aria-live="polite">
        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-medium ring-1 ring-inset"
              :class="tone === 'good' ? '{{ $dark ? 'bg-emerald-900/40 text-emerald-200 ring-emerald-500/40' : 'bg-emerald-50 text-emerald-800 ring-emerald-600/20' }}'
                    : (tone === 'wait' ? '{{ $dark ? 'bg-amber-900/40 text-amber-200 ring-amber-500/40' : 'bg-amber-50 text-amber-800 ring-amber-600/20' }}'
                    : (tone === 'bad' ? '{{ $dark ? 'bg-red-900/40 text-red-200 ring-red-500/40' : 'bg-red-50 text-red-800 ring-red-600/20' }}'
                    : '{{ $dark ? 'bg-slate-800 text-slate-300 ring-slate-600' : 'bg-gray-100 text-gray-600 ring-gray-500/20' }}'))"
              :title="detail">
            <span class="h-2 w-2 rounded-full"
                  :class="online === false ? 'bg-red-500' : (online === true ? 'bg-emerald-500' : 'bg-gray-400')"></span>
            <span x-text="label">Checking…</span>
        </span>

        <button type="button" x-show="canSync" x-cloak x-on:click="syncNow()" :disabled="syncing"
                class="rounded-md px-2 py-1 font-semibold {{ $dark ? 'text-emerald-300 hover:bg-slate-800' : 'text-emerald-700 hover:bg-emerald-50' }} disabled:opacity-50">
            <span x-text="syncing ? 'Sending…' : 'Sync now'">Sync now</span>
        </button>
    </div>

    @once
        @push('scripts')
            <script>
                function syncStatus({ url, syncUrl, configured }) {
                    return {
                        // What the server last said. `online` is the server's reach to
                        // the platform when one is set; otherwise this browser's own.
                        state: null,
                        browserOnline: navigator.onLine,
                        syncing: false,

                        init() {
                            this.refresh();
                            setInterval(() => this.refresh(), 30000);
                            window.addEventListener('online', () => { this.browserOnline = true; this.refresh(); });
                            window.addEventListener('offline', () => { this.browserOnline = false; });
                        },

                        async refresh() {
                            try {
                                const response = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                                if (response.ok) this.state = await response.json();
                            } catch {
                                // The shop's own computer did not answer; the last answer stands.
                            }
                        },

                        get online() {
                            if (! configured) return this.browserOnline;

                            return this.state ? this.state.online : null;
                        },

                        get tone() {
                            if (! configured) return 'plain';
                            if (! this.state) return 'plain';
                            if (this.state.attempt === 'failed') return 'bad';
                            if (this.state.synced) return 'good';

                            return this.state.online ? 'wait' : 'bad';
                        },

                        get label() {
                            const net = this.online === null ? 'Checking' : (this.online ? 'Online' : 'Offline');

                            if (! configured) return net + ' · online copy not set up';
                            if (! this.state) return 'Checking…';
                            if (this.state.attempt === 'failed') return net + ' · sync failed';
                            if (this.state.synced) return net + ' · synced' + (this.state.last_sent ? ' ' + this.state.last_sent : '');

                            return net + ' · sync pending';
                        },

                        get detail() {
                            if (! configured) return 'This computer has not been given a platform to send its books to. See Shop copies in the platform\'s back office.';
                            if (! this.state) return '';
                            if (this.state.attempt === 'failed') return 'The platform did not accept the last one: ' + (this.state.message || 'no reason given');
                            if (this.state.synced) return 'Everything entered here is in the online copy.';

                            return this.state.online
                                ? 'Something entered here has not been sent yet. It goes by itself within five minutes, or press Sync now.'
                                : 'The platform cannot be reached. What has been entered is safe on this computer and is sent when it can be.'
                                    + (this.state.message ? ' Reason: ' + this.state.message : '');
                        },

                        get canSync() {
                            return configured && this.state && this.state.pending && this.state.online;
                        },

                        async syncNow() {
                            if (this.syncing) return;

                            this.syncing = true;

                            try {
                                const response = await fetch(syncUrl, {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                                });

                                if (response.ok) this.state = await response.json();
                            } finally {
                                this.syncing = false;
                            }
                        },
                    };
                }
            </script>
        @endpush
    @endonce
@endif
