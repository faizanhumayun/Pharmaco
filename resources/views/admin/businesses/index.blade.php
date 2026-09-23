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
            ask(business) {
                this.target = business;
                this.typed = '';
                this.open = true;
            },
         }">
        <x-flash />
        <x-input-error :messages="$errors->get('status')" class="mb-4" />

        <form method="GET" class="mb-4 flex flex-wrap gap-3">
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
            <button class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Filter
            </button>
        </form>

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
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-10 text-center text-gray-500">No businesses match.</td></tr>
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
                <form method="POST" :action="`{{ url('admin/businesses') }}/${target.slug}`">
                    @csrf
                    @method('DELETE')

                    <div class="px-6 py-5">
                        <h2 class="text-lg font-semibold text-gray-900">
                            Delete <span x-text="target.name"></span>?
                        </h2>

                        <template x-if="target.hasHistory">
                            <div class="mt-3 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900">
                                <p class="font-semibold">This business has financial history.</p>
                                <p class="mt-1">
                                    Deleting it destroys
                                    <strong x-text="target.transactions"></strong> ledger transactions,
                                    <strong x-text="target.entries"></strong> recorded days and
                                    <strong x-text="target.closings"></strong> closings — including the record
                                    of who entered what. Nothing can reconstruct them afterwards.
                                </p>
                                <p class="mt-2">
                                    If you only want to stop activity, <strong>suspend</strong> the business
                                    instead and the records stay intact.
                                </p>
                            </div>
                        </template>

                        <template x-if="! target.hasHistory">
                            <p class="mt-2 text-sm text-gray-600">
                                This business has no financial history, so nothing is lost but its setup:
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
                            Delete permanently
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
