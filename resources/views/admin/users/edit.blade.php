<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">{{ $user->name }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ $user->email }}</p>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        <form method="POST" action="{{ route('admin.users.update', $user) }}">
            @csrf
            @method('PUT')
            <x-panel>
                @include('admin.users._form')
                <div class="flex items-center justify-between border-t border-gray-200 bg-gray-50 px-4 py-3 sm:px-6">
                    <a href="{{ route('admin.users.index') }}" class="text-sm font-medium text-gray-600 hover:text-gray-900">Back</a>
                    <button class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Save changes</button>
                </div>
            </x-panel>
        </form>

        @if ($user->businesses->isNotEmpty())
            <x-panel class="mt-6" title="Business access">
                <div class="divide-y divide-gray-100 text-sm">
                    @foreach ($user->businesses as $business)
                        <div class="flex items-center justify-between px-4 py-3 sm:px-6">
                            <a href="{{ route('admin.businesses.show', $business) }}" class="font-medium text-gray-900 hover:text-emerald-700">
                                {{ $business->name }}
                            </a>
                            <span class="text-gray-500">
                                {{ $business->pivot->role->label() }}
                                @unless ($business->pivot->is_active) · revoked @endunless
                            </span>
                        </div>
                    @endforeach
                </div>
            </x-panel>
        @endif
    </div>
</x-app-layout>
