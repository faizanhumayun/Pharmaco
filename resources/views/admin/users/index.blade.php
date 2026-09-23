<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Users</h1>
                <p class="mt-1 text-sm text-gray-500">Every account is created here. There is no public sign-up.</p>
            </div>
            <a href="{{ route('admin.users.create') }}"
               class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">
                New user
            </a>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        <form method="GET" class="mb-4">
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search name or email"
                   class="w-72 rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
        </form>

        <x-panel>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">User</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Access</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">State</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($users as $user)
                            <tr>
                                <td class="px-4 py-3 sm:px-6">
                                    <div class="font-medium text-gray-900">{{ $user->name }}</div>
                                    <div class="text-xs text-gray-500">{{ $user->email }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($user->isPlatformAdmin())
                                        <x-badge classes="bg-indigo-50 text-indigo-800 ring-indigo-600/20">App Owner</x-badge>
                                    @elseif ($user->businesses->isEmpty())
                                        <span class="text-xs text-gray-400">No business</span>
                                    @else
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($user->businesses as $business)
                                                <x-badge classes="bg-gray-100 text-gray-700 ring-gray-500/20">
                                                    {{ $business->name }} · {{ $business->pivot->role->label() }}
                                                </x-badge>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if ($user->is_active)
                                        <x-badge classes="bg-emerald-50 text-emerald-800 ring-emerald-600/20">Active</x-badge>
                                    @else
                                        <x-badge classes="bg-red-50 text-red-800 ring-red-600/20">Deactivated</x-badge>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right sm:px-6">
                                    <div class="flex items-center justify-end gap-3">
                                        <a href="{{ route('admin.users.edit', $user) }}"
                                           class="text-sm font-medium text-emerald-700 hover:text-emerald-900">Edit</a>
                                        @can('deactivate', $user)
                                            <form method="POST" action="{{ route('admin.users.toggle-active', $user) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button class="text-sm font-medium {{ $user->is_active ? 'text-red-700 hover:text-red-900' : 'text-gray-600 hover:text-gray-900' }}">
                                                    {{ $user->is_active ? 'Deactivate' : 'Reactivate' }}
                                                </button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-6 py-10 text-center text-gray-500">No users match.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-panel>

        <div class="mt-4">{{ $users->links() }}</div>
    </div>
</x-app-layout>
