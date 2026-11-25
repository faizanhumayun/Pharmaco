<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-gray-900">Businesses</h1>
            <a href="{{ route('admin.businesses.create') }}"
               class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">
                New business
            </a>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8"
         x-data="{
            open: false,
            target: {},
            typed: '',
            // 'business' removes the business itself; 'data' empties it and keeps it.
            mode: 'business',
            ask(business, mode = 'business') {
                this.mode = mode;
                this.target = business;
                this.typed = '';
                this.open = true;
            },
         }">
        <x-flash />
        <x-input-error :messages="$errors->get('status')" class="mb-4" />

        <x-filter-bar submit="Filter" :active="($filters['q'] ?? '') !== '' || ($filters['status'] ?? '') !== ''" clear="{{ route('admin.businesses.index') }}" class="mb-4">
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search by name"
                   class="w-64 rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
            <select name="status" class="rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>
                        {{ $status->label() }}
                    </option>
                @endforeach
            </select>
        </x-filter-bar>

        <x-panel>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Business</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Opening date</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Members</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">History</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($businesses as $business)
                            <tr>
                                <td class="px-4 py-3 sm:px-6">
                                    <a href="{{ route('admin.businesses.show', $business) }}"
                                       class="font-medium text-gray-900 hover:text-emerald-700">{{ $business->name }}</a>
                                    <div class="text-xs text-gray-500">{{ $business->business_type->label() }} · {{ $business->currency }}</div>
                                    {{-- Lives on a shop's own computer; this is its read-only copy. --}}
                                    @if ($business->isShopCopy())
                                        @php($shop = $business->shopMirror)
                                        @php($entry = $shop?->entry ?? 'shop')
                                        @if ($entry === 'office')
                                            <x-badge classes="mt-1 bg-red-50 text-red-800 ring-red-600/20">Back office · maintenance</x-badge>
                                            <span class="mt-1 block text-xs text-gray-500">Shop is locked · last entries received {{ $shop->entry_changed_at?->diffForHumans() }}</span>
                                        @elseif ($entry === 'pausing')
                                            <x-badge classes="mt-1 bg-amber-50 text-amber-800 ring-amber-600/20">Waiting for the shop to lock</x-badge>
                                            <span class="mt-1 block text-xs text-gray-500">Requested {{ $shop->entry_changed_at?->diffForHumans() }} · read-only until the last entries are received</span>
                                        @elseif ($entry === 'returning')
                                            <x-badge classes="mt-1 bg-amber-50 text-amber-800 ring-amber-600/20">Returning to the shop</x-badge>
                                            <span class="mt-1 block text-xs text-gray-500">Since {{ $shop->entry_changed_at?->diffForHumans() }} · read-only</span>
                                        @else
                                            <x-badge classes="mt-1 bg-amber-50 text-amber-800 ring-amber-600/20">Runs in the shop · read-only copy</x-badge>
                                        @endif
                                        @if ($entry !== 'shop' && $shop?->last_error)
                                            <span class="mt-1 block max-w-md text-xs text-red-700">{{ $shop->last_error }}</span>
                                        @endif

                                        {{-- The switch. On: the shop's computer enters the books.
                                             Off: it is locked and they are entered here, to fix something. --}}
                                        @if ($shop && $shop->isMerged())
                                            @php($on = $entry === 'shop' || $entry === 'returning')
                                            <form method="POST" action="{{ route('admin.shop-mirrors.entry', $shop) }}" class="mt-2 flex flex-wrap items-center gap-2"
                                                  onsubmit="return confirm(this.dataset.ask)"
                                                  data-ask="{{ $entry === 'shop'
                                                      ? 'Pause '.$shop->name.' for maintenance? The shop\'s computer will send its last entries and lock until you switch this back on.'
                                                      : ($entry === 'office' ? 'Send your changes to '.$shop->name.' and reopen the shop?' : 'Cancel the pause for '.$shop->name.'?') }}">
                                                @csrf
                                                <input type="hidden" name="action" value="{{ $entry === 'shop' ? 'pause' : 'resume' }}">
                                                <button type="submit" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}"
                                                        @disabled($entry === 'returning')
                                                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition disabled:opacity-50 {{ $on ? 'bg-emerald-700' : 'bg-gray-300' }}">
                                                    <span class="inline-block h-[1.125rem] w-[1.125rem] rounded-full bg-white transition {{ $on ? 'translate-x-[1.375rem]' : 'translate-x-[0.1875rem]' }}"></span>
                                                </button>
                                                <span class="text-xs text-gray-600">
                                                    {{ match ($entry) {
                                                        'shop' => 'Shop computer: on',
                                                        'pausing' => 'Off: waiting for the shop',
                                                        'office' => 'Off: paused for maintenance',
                                                        default => 'On: shop is receiving its records',
                                                    } }}
                                                </span>
                                            </form>
                                            @if ($entry === 'office' || $entry === 'returning')
                                                <form method="POST" action="{{ route('admin.shop-mirrors.entry', $shop) }}" class="mt-1"
                                                      data-ask="Cancel maintenance? {{ $shop->name }} will reopen with its own records unchanged. All changes made here will be lost."
                                                      onsubmit="return confirm(this.dataset.ask)">
                                                    @csrf
                                                    <input type="hidden" name="action" value="discard">
                                                    <button type="submit" class="text-xs font-medium text-red-700 hover:text-red-900">Cancel maintenance and reopen the shop</button>
                                                </form>
                                            @endif
                                        @endif
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <x-badge :classes="$business->status->badgeClasses()">{{ $business->status->label() }}</x-badge>
                                </td>
                                <td class="px-4 py-3 tabular-nums text-gray-600">
                                    {{ $business->opening_date?->format('d M Y') ?? '—' }}
                                </td>
                                <td class="px-4 py-3 tabular-nums text-gray-600">{{ $business->active_members_count }}</td>
                                <td class="px-4 py-3">
                                    @if ($business->hasFinancialHistory())
                                        <x-badge classes="bg-gray-100 text-gray-700 ring-gray-500/20">Has records</x-badge>
                                    @else
                                        <span class="text-xs text-gray-400">Setup only</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right sm:px-6">
                                    <div class="flex items-center justify-end gap-3">
                                        @if ($business->acceptsTransactions())
                                            <a href="{{ route('businesses.show', $business) }}"
                                               target="_blank" rel="opener"
                                               onclick="return openWorkspace(event, this, '{{ $business->slug }}')"
                                               class="text-sm font-medium text-gray-700 hover:text-gray-900">Open ↗</a>
                                        @endif
                                        {{-- A copy is changed on the shop's computer, and
                                             removed from Shop copies, not from here. --}}
                                        @unless ($business->isShopCopy())
                                        <a href="{{ route('admin.businesses.edit', $business) }}"
                                           class="text-sm font-medium text-emerald-700 hover:text-emerald-900">Edit</a>
                                        <button type="button"
                                                @click="ask({{ Js::from([
                                                    'name' => $business->name,
                                                    'slug' => $business->slug,
                                                    'hasHistory' => $business->hasFinancialHistory(),
                                                    'transactions' => $business->transactions_count,
                                                    'entries' => $business->entries_count,
                                                    'closings' => $business->closings_count,
                                                ]) }})"
                                                class="text-sm font-medium text-red-700 hover:text-red-900">
                                            Delete
                                        </button>
                                        <button type="button"
                                                @click="ask({{ Js::from([
                                                    'name' => $business->name,
                                                    'slug' => $business->slug,
                                                    'hasHistory' => $business->hasFinancialHistory(),
                                                    'transactions' => $business->transactions_count,
                                                    'entries' => $business->entries_count,
                                                    'closings' => $business->closings_count,
                                                ]) }}, 'data')"
                                                class="whitespace-nowrap text-sm font-medium text-red-700 hover:text-red-900">
                                            Delete data
                                        </button>
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-10 text-center text-gray-500">No businesses found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-panel>

        <div class="mt-4">{{ $businesses->links() }}</div>

        {{-- Confirmation. Deliberately high-friction: the name has to be typed. --}}
        <div x-show="open" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-on:keydown.escape.window="open = false">
            <div class="absolute inset-0 bg-gray-900/50" @click="open = false"></div>

            <div class="relative w-full max-w-lg rounded-lg bg-white shadow-xl"
                 role="dialog" aria-modal="true">
                <form method="POST" :action="`{{ url('admin/businesses') }}/${target.slug}` + (mode === 'data' ? '/data' : '')">
                    @csrf
                    @method('DELETE')

                    <div class="px-6 py-5">
                        <h2 class="text-lg font-semibold text-gray-900">
                            <span x-text="mode === 'data' ? 'Delete all data in' : 'Delete'"></span> <span x-text="target.name"></span>?
                        </h2>

                        {{-- Emptying a business: what goes and what stays. --}}
                        <template x-if="mode === 'data'">
                            <div class="mt-3 space-y-2 text-sm">
                                <div class="rounded-md border border-red-300 bg-red-50 px-4 py-3 text-red-900">
                                    <p class="font-semibold">All data recorded in this business will be deleted.</p>
                                    <p class="mt-1">
                                        Products, stock, companies, customers, imports, the opening balance, and
                                        <strong x-text="target.transactions"></strong> ledger transactions,
                                        <strong x-text="target.entries"></strong> recorded days and
                                        <strong x-text="target.closings"></strong> closings.
                                        They cannot be recovered.
                                    </p>
                                </div>
                                <p class="text-gray-600">
                                    <strong class="text-gray-900">Kept:</strong> the business, its settings, and its
                                    members with their roles. The business returns to setup. You can then import again
                                    and enter a new opening balance.
                                </p>
                            </div>
                        </template>

                        <template x-if="mode === 'business' && target.hasHistory">
                            <div class="mt-3 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900">
                                <p class="font-semibold">This business has financial records.</p>
                                <p class="mt-1">
                                    Deleting it will permanently delete
                                    <strong x-text="target.transactions"></strong> ledger transactions,
                                    <strong x-text="target.entries"></strong> recorded days and
                                    <strong x-text="target.closings"></strong> closings, including the record
                                    of who entered them. They cannot be recovered.
                                </p>
                                <p class="mt-2">
                                    To stop activity only, <strong>suspend</strong> the business
                                    instead. The records will be kept.
                                </p>
                            </div>
                        </template>

                        <template x-if="mode === 'business' && ! target.hasHistory">
                            <p class="mt-2 text-sm text-gray-600">
                                This business has no financial records. Only its setup will be deleted:
                                the chart of accounts, expense categories, member access and any draft.
                            </p>
                        </template>

                        <label class="mt-4 block">
                            <span class="text-sm font-medium text-gray-700">
                                Type <span class="font-mono text-gray-900" x-text="target.name"></span> to confirm
                            </span>
                            <input type="text" x-model="typed"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-red-600 focus:ring-red-600"
                                   autocomplete="off">
                        </label>

                        <p class="mt-2 text-xs text-gray-500">This cannot be undone.</p>
                    </div>

                    <div class="flex items-center justify-end gap-3 rounded-b-lg border-t border-gray-200 bg-gray-50 px-6 py-3">
                        <button type="button" @click="open = false"
                                class="text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                        <button type="submit" :disabled="typed !== target.name"
                                class="rounded-md bg-red-700 px-3 py-2 text-sm font-semibold text-white hover:bg-red-800 disabled:cursor-not-allowed disabled:bg-gray-300">
                            <span x-text="mode === 'data' ? 'Delete data' : 'Delete permanently'"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
