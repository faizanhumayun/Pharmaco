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
                A shop can run on its own computer, so it never depends on the internet. Whenever it is online it sends its books here,
                where the owner can read them from anywhere. Nothing can be entered into a copy.
            </p>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        {{-- The token, once. --}}
        @if ($issued)
            <div class="mb-6 rounded-md border border-emerald-300 bg-emerald-50 px-4 py-4 text-sm text-emerald-900 sm:px-6">
                <p class="font-semibold">Settings for {{ $issued['name'] }}'s computer — copy them now, the token is not shown again.</p>
                <p class="mt-1">Put these two lines in the <span class="font-mono">.env</span> file on the shop's computer:</p>
                <pre class="mt-2 overflow-x-auto rounded-md border border-emerald-200 bg-white px-3 py-2 font-mono text-xs text-gray-900">MIRROR_PUSH_URL={{ $receiveUrl }}
MIRROR_PUSH_TOKEN={{ $issued['token'] }}</pre>
            </div>
        @endif

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="xl:col-span-2">
                <x-panel title="Registered shops">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Shop</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Opened at</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Last snapshot</th>
                                    <th class="px-4 py-3 sm:px-6"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse ($mirrors as $mirror)
                                    <tr>
                                        <td class="px-4 py-3 sm:px-6">
                                            <span class="font-medium text-gray-900">{{ $mirror->name }}</span>
                                            <span class="block font-mono text-xs text-gray-500">{{ $mirror->database }}</span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <a href="{{ route('copy.enter', $mirror->slug) }}" target="_blank" rel="noopener" class="font-medium text-emerald-700 hover:underline">{{ route('copy.enter', $mirror->slug) }}</a>
                                            @if ($mirror->host)
                                                <a href="//{{ $mirror->host }}" target="_blank" rel="noopener" class="block text-xs text-gray-500 hover:underline">also {{ $mirror->host }}</a>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-gray-600">
                                            @if ($mirror->last_error)
                                                <x-badge classes="bg-red-50 text-red-800 ring-red-600/20">Last one failed</x-badge>
                                                <span class="mt-1 block max-w-md text-xs text-red-700">{{ $mirror->last_error }}</span>
                                            @elseif ($mirror->last_synced_at)
                                                {{ $mirror->last_synced_at->diffForHumans() }}
                                                <span class="block text-xs text-gray-500">made on the shop's computer {{ $mirror->snapshot_made_at?->diffForHumans() }}</span>
                                            @else
                                                <span class="text-gray-400">None yet</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right sm:px-6">
                                            <div class="flex items-center justify-end gap-3">
                                                <form method="POST" action="{{ route('admin.shop-mirrors.token', $mirror) }}"
                                                      onsubmit="return confirm('Make a new token? The shop\'s computer stops sending until the new one is put in its settings.')">
                                                    @csrf
                                                    <button class="text-sm font-medium text-emerald-700 hover:underline">New token</button>
                                                </form>
                                                <form method="POST" action="{{ route('admin.shop-mirrors.destroy', $mirror) }}"
                                                      onsubmit="return confirm('Stop serving this copy? Its database is left as it is.')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="text-sm font-medium text-red-700 hover:underline">Remove</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-6 py-10 text-center text-gray-500">No shop is registered yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-panel>
            </div>

            <div>
                <x-panel title="Register a shop" description="Create an empty database for it on the server first.">
                    <form method="POST" action="{{ route('admin.shop-mirrors.store') }}" class="space-y-4 p-4 sm:p-6">
                        @csrf
                        <div>
                            <x-input-label for="name" value="Shop name" />
                            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required maxlength="160" />
                            <x-input-error :messages="$errors->get('name')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="slug" value="Short name for its link" />
                            <x-text-input id="slug" name="slug" type="text" class="mt-1 block w-full font-mono" :value="old('slug')" placeholder="tayyaba" maxlength="80" />
                            <p class="mt-1 text-xs text-gray-500">The copy is opened at {{ url('/copy') }}/<span class="font-mono">short-name</span>. Left empty, it is made from the shop's name.</p>
                            <x-input-error :messages="$errors->get('slug')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="host" value="An address of its own (optional)" />
                            <x-text-input id="host" name="host" type="text" class="mt-1 block w-full font-mono" :value="old('host')"
                                          placeholder="shopname.{{ parse_url(config('app.url'), PHP_URL_HOST) }}" />
                            <p class="mt-1 text-xs text-gray-500">Only if you want one: a subdomain pointing at this same site. The link above works without it.</p>
                            <x-input-error :messages="$errors->get('host')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="database" value="Database for the copy" />
                            <x-text-input id="database" name="database" type="text" class="mt-1 block w-full font-mono" :value="old('database')" required maxlength="64" />
                            <p class="mt-1 text-xs text-gray-500">Empty, and used for nothing else: each snapshot replaces everything in it.</p>
                            <x-input-error :messages="$errors->get('database')" class="mt-1" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <x-input-label for="db_username" value="Database user" />
                                <x-text-input id="db_username" name="db_username" type="text" class="mt-1 block w-full font-mono" :value="old('db_username')" autocomplete="off" />
                            </div>
                            <div>
                                <x-input-label for="db_password" value="Its password" />
                                <x-text-input id="db_password" name="db_password" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                            </div>
                        </div>
                        <p class="text-xs text-gray-500">Leave the user and password empty when the platform's own database login is allowed into this database too.</p>
                        <button class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Register</button>
                    </form>
                </x-panel>
            </div>
        </div>
    </div>
</x-app-layout>
