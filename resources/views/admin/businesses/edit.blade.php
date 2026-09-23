<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">{{ $business->name }}</h1>
        <p class="mt-1 text-sm text-gray-500">Platform configuration</p>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        <form method="POST" action="{{ route('admin.businesses.update', $business) }}">
            @csrf
            @method('PUT')
            <x-panel>
                @include('admin.businesses._form')

                <div class="flex items-center justify-between border-t border-gray-200 bg-gray-50 px-4 py-3 sm:px-6">
                    <a href="{{ route('admin.businesses.show', $business) }}" class="text-sm font-medium text-gray-600 hover:text-gray-900">Back</a>
                    <button class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">
                        Save changes
                    </button>
                </div>
            </x-panel>
        </form>
    </div>
</x-app-layout>
