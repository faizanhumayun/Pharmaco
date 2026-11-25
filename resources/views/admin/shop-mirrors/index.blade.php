{{--
    Shops that run Pharmaco on their own computer and keep a read-only copy
    here. Registering one gives its computer a token to send snapshots with;
    the copy is then opened by a link under this site's own address.
--}}
<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Shop copies</h1>
            <p class="mt-1 text-sm text-gray-500">
                A shop can run on its own computer without the internet. When it is online, it sends its records here,
                and the owner can view them from anywhere. A copy is read-only.
            </p>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        {{-- The token, once. --}}
        @if ($issued)
            <div class="mb-6 rounded-md border border-emerald-300 bg-emerald-50 px-4 py-4 text-sm text-emerald-900 sm:px-6">
                <p class="font-semibold">Settings for {{ $issued['name'] }}'s computer. Copy them now. The token will not be shown again.</p>
                <p class="mt-1">Add these two lines to the <span class="font-mono">.env</span> file on the shop's computer:</p>
                <pre class="mt-2 overflow-x-auto rounded-md border border-emerald-200 bg-white px-3 py-2 font-mono text-xs text-gray-900">MIRROR_PUSH_URL={{ $receiveUrl }}
MIRROR_PUSH_TOKEN={{ $issued['token'] }}</pre>
            </div>
        @endif

        {{-- Shown on a shop's screen while it is paused for a fix (Businesses → the switch). --}}
        <form method="POST" action="{{ route('admin.shop-mirrors.support-phone') }}"
              class="mb-6 flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white px-4 py-4 sm:px-6">
            @csrf
            @method('PUT')
            <div>
                <x-input-label for="support_phone" value="Support number shown to a paused shop" />
                <x-text-input id="support_phone" name="support_phone" type="text" inputmode="tel" class="mt-1 block w-64"
                              :value="old('support_phone', $supportPhone)" placeholder="03xx-xxxxxxx" />
                <x-input-error :messages="$errors->get('support_phone')" class="mt-1" />
            </div>
            <x-primary-button>Save</x-primary-button>
            <p class="basis-full text-xs text-gray-500">
                To pause a shop for maintenance, use the switch beside it on the Businesses page. The shop's computer sends its last entries and locks. Make your corrections here, then switch it back on to send your changes to the shop.
            </p>
        </form>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="xl:col-span-2">
                <x-panel title="Registered shops">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Shop</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Access link</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Last snapshot</th>
                                    <th class="px-4 py-3 sm:px-6"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse ($mirrors as $mirror)
                                    <tr>
                                        <td class="px-4 py-3 sm:px-6">
                                            <span class="font-medium text-gray-900">{{ $mirror->name }}</span>
                                            <span class="block text-xs text-gray-500">{{ $mirror->isMerged() ? 'Stored in this site\'s database' : 'Separate database: '.$mirror->database }}</span>
                                        </td>
                                        <td class="px-4 py-3">
                                            @if ($mirror->isMerged())
                                                <span class="text-gray-700">Its users sign in here as usual</span>
                                                <a href="{{ route('login') }}" target="_blank" rel="noopener" class="block text-xs font-medium text-emerald-700 hover:underline">{{ route('login') }}</a>
                                            @else
                                                <a href="{{ route('copy.enter', $mirror->slug) }}" target="_blank" rel="noopener" class="font-medium text-emerald-700 hover:underline">{{ route('copy.enter', $mirror->slug) }}</a>
                                            @endif
                                            @if ($mirror->host)
                                                <a href="//{{ $mirror->host }}" target="_blank" rel="noopener" class="block text-xs text-gray-500 hover:underline">also {{ $mirror->host }}</a>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-gray-600">
                                            @if ($mirror->last_error)
                                                <x-badge classes="bg-red-50 text-red-800 ring-red-600/20">Last snapshot failed</x-badge>
                                                <span class="mt-1 block max-w-md text-xs text-red-700">{{ $mirror->last_error }}</span>
                                            @elseif ($mirror->last_synced_at)
                                                {{ $mirror->last_synced_at->diffForHumans() }}
                                                <span class="block text-xs text-gray-500">created on the shop's computer {{ $mirror->snapshot_made_at?->diffForHumans() }}</span>
                                            @else
                                                <span class="text-gray-400">None yet</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right sm:px-6">
                                            <div class="flex items-center justify-end gap-3">
                                                <form method="POST" action="{{ route('admin.shop-mirrors.token', $mirror) }}"
                                                      onsubmit="return confirm('Create a new token? The shop\'s computer will stop sending until the new token is added to its settings.')">
                                                    @csrf
                                                    <button class="text-sm font-medium text-emerald-700 hover:underline">New token</button>
                                                </form>
                                                <form method="POST" action="{{ route('admin.shop-mirrors.destroy', $mirror) }}"
                                                      onsubmit="return confirm('Remove this copy? Its database will not be changed.')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="text-sm font-medium text-red-700 hover:underline">Remove</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-6 py-10 text-center text-gray-500">No shops registered yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-panel>
            </div>

            <div>
                <x-panel title="Register a shop" description="Only a name is required.">
                    <form method="POST" action="{{ route('admin.shop-mirrors.store') }}" class="space-y-4 p-4 sm:p-6">
                        @csrf
                        <div>
                            <x-input-label for="name" value="Shop name" />
                            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required maxlength="160" />
                            <x-input-error :messages="$errors->get('name')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="slug" value="Short name for the link" />
                            <x-text-input id="slug" name="slug" type="text" class="mt-1 block w-full font-mono" :value="old('slug')" placeholder="tayyaba" maxlength="80" />
                            <p class="mt-1 text-xs text-gray-500">Identifies the shop. If left empty, it is created from the shop's name.</p>
                            <x-input-error :messages="$errors->get('slug')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="host" value="Separate address (optional)" />
                            <x-text-input id="host" name="host" type="text" class="mt-1 block w-full font-mono" :value="old('host')"
                                          placeholder="shopname.{{ parse_url(config('app.url'), PHP_URL_HOST) }}" />
                            <p class="mt-1 text-xs text-gray-500">A subdomain that points to this site. The link works without it.</p>
                            <x-input-error :messages="$errors->get('host')" class="mt-1" />
                        </div>
                        {{-- Where the copy is kept. Nothing to fill in by default: it
                             goes in this site's own database, read-only, beside the
                             businesses that work online. A database of its own is
                             there for a shop too large to share one. --}}
                        <div x-data="{ own: @js(filled(old('database'))) }">
                            <p class="rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600" x-show="! own">
                                The copy is stored in this site's database. No other setup is needed.
                            </p>
                            <label class="mt-2 flex items-start gap-2 text-sm text-gray-700">
                                <input type="checkbox" x-model="own" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-emerald-700 focus:ring-emerald-600">
                                <span>Use a separate database for this shop</span>
                            </label>

                            <template x-if="own">
                                <div class="mt-3 space-y-3">
                                    <div>
                                        <x-input-label for="database" value="Database for the copy" />
                                        <x-text-input id="database" name="database" type="text" class="mt-1 block w-full font-mono" :value="old('database')" maxlength="64" />
                                        <p class="mt-1 text-xs text-gray-500">Use this database for the copy only. Each snapshot replaces all of its contents. It is created automatically where the server allows. Otherwise, create an empty database in the hosting panel first.</p>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <x-input-label for="db_username" value="Database user" />
                                            <x-text-input id="db_username" name="db_username" type="text" class="mt-1 block w-full font-mono" :value="old('db_username')" autocomplete="off" />
                                        </div>
                                        <div>
                                            <x-input-label for="db_password" value="Database password" />
                                            <x-text-input id="db_password" name="db_password" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                                        </div>
                                    </div>
                                    <p class="text-xs text-gray-500">Leave the user and password empty if the site's database user has access to this database.</p>
                                </div>
                            </template>
                            <x-input-error :messages="$errors->get('database')" class="mt-1" />
                        </div>
                        <button class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Register</button>
                    </form>
                </x-panel>
            </div>
        </div>
    </div>
</x-app-layout>
