<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-xl font-semibold text-gray-900">{{ $business->name }}</h1>
                    <x-badge :classes="$business->status->badgeClasses()">{{ $business->status->label() }}</x-badge>
                </div>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $business->business_type->label() }} · {{ $business->currency }} · {{ $business->timezone }}
                </p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('businesses.show', $business) }}"
                   target="_blank" rel="opener"
                   onclick="return openWorkspace(event, this, '{{ $business->slug }}')"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Open workspace ↗
                </a>
                <a href="{{ route('admin.businesses.edit', $business) }}"
                   class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    Edit
                </a>
            </div>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        @if ($business->opening_date === null)
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <span>
                    <strong>No opening balance yet.</strong>
                    This business cannot record transactions until its opening financial position is
                    entered and finalized.
                </span>
                <a href="{{ route('admin.businesses.opening.edit', $business) }}"
                   class="shrink-0 rounded-md bg-amber-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-amber-800">
                    {{ $business->openingBalance ? 'Continue setup' : 'Set opening balance' }}
                </a>
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 space-y-6">
                <x-panel title="Members" description="Access is granted per business. A user may hold a different role in each.">
                    <div class="divide-y divide-gray-100">
                        @forelse ($business->members as $member)
                            <div class="flex items-center justify-between px-4 py-3 sm:px-6">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-gray-900">{{ $member->name }}</span>
                                        @unless ($member->pivot->is_active)
                                            <x-badge classes="bg-gray-100 text-gray-600 ring-gray-500/20">Revoked</x-badge>
                                        @endunless
                                    </div>
                                    <p class="text-xs text-gray-500">
                                        {{ $member->email }} · {{ $member->pivot->role->label() }}
                                    </p>
                                </div>
                                @if ($member->pivot->is_active)
                                    <form method="POST" action="{{ route('admin.businesses.members.destroy', [$business, $member]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-sm font-medium text-red-700 hover:text-red-900">Revoke</button>
                                    </form>
                                @endif
                            </div>
                        @empty
                            <p class="px-4 py-6 text-sm text-gray-500 sm:px-6">Nobody has access to this business yet.</p>
                        @endforelse
                    </div>

                    <form method="POST" action="{{ route('admin.businesses.members.store', $business) }}"
                          class="flex flex-wrap items-end gap-3 border-t border-gray-200 bg-gray-50 px-4 py-4 sm:px-6">
                        @csrf
                        <div class="min-w-56 flex-1">
                            <x-input-label for="user_id" value="Add member" />
                            <select id="user_id" name="user_id" required
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                <option value="">Select a user…</option>
                                @foreach ($assignableUsers as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="role" value="Role" />
                            <select id="role" name="role"
                                    class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                @foreach (\App\Enums\BusinessRole::cases() as $role)
                                    <option value="{{ $role->value }}">{{ $role->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Add</button>
                        <x-input-error :messages="$errors->get('user_id')" class="w-full" />
                    </form>
                </x-panel>
            </div>

            <div class="space-y-6">
                <x-panel title="Financial position">
                    <dl class="divide-y divide-gray-100 text-sm">
                        <div class="flex justify-between px-4 py-3 sm:px-6">
                            <dt class="text-gray-500">Opening date</dt>
                            <dd class="tabular-nums font-medium text-gray-900">
                                @if ($business->openingBalance)
                                    <a href="{{ route('admin.businesses.opening.show', $business) }}"
                                       class="text-emerald-700 hover:text-emerald-900">
                                        {{ $business->opening_date?->format('d M Y') ?? 'Draft' }}
                                    </a>
                                @else
                                    Not set
                                @endif
                            </dd>
                        </div>
                        <div class="flex justify-between px-4 py-3 sm:px-6">
                            <dt class="text-gray-500">Closed through</dt>
                            <dd class="tabular-nums font-medium text-gray-900">{{ $business->locked_through_date?->format('d M Y') ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between px-4 py-3 sm:px-6">
                            <dt class="text-gray-500">Records activity</dt>
                            <dd class="font-medium {{ $business->acceptsTransactions() ? 'text-emerald-700' : 'text-gray-500' }}">
                                {{ $business->acceptsTransactions() ? 'Yes' : 'Not yet' }}
                            </dd>
                        </div>
                    </dl>
                </x-panel>

                <x-panel title="Status">
                    <form method="POST" action="{{ route('admin.businesses.status', $business) }}" class="space-y-3 p-4 sm:p-6">
                        @csrf
                        @method('PATCH')
                        <select name="status" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                            @foreach (\App\Enums\BusinessStatus::cases() as $status)
                                <option value="{{ $status->value }}" @selected($business->status === $status)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('status')" />
                        <button class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Change status
                        </button>
                        <p class="text-xs text-gray-500">
                            Activation is earned by finalizing an opening balance, not granted here.
                        </p>
                    </form>
                </x-panel>

                <x-panel title="Record">
                    <dl class="divide-y divide-gray-100 text-sm">
                        <div class="flex justify-between px-4 py-3 sm:px-6">
                            <dt class="text-gray-500">Created by</dt>
                            <dd class="font-medium text-gray-900">{{ $business->creator->name }}</dd>
                        </div>
                        <div class="flex justify-between px-4 py-3 sm:px-6">
                            <dt class="text-gray-500">Created</dt>
                            <dd class="tabular-nums text-gray-900">{{ $business->created_at->format('d M Y H:i') }}</dd>
                        </div>
                        <div class="flex justify-between px-4 py-3 sm:px-6">
                            <dt class="text-gray-500">Identifier</dt>
                            <dd class="font-mono text-xs text-gray-600">{{ $business->slug }}</dd>
                        </div>
                    </dl>
                </x-panel>
            </div>
        </div>
    </div>
</x-app-layout>
