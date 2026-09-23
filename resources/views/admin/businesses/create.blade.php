<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">New business</h1>
        <p class="mt-1 text-sm text-gray-500">
            The business starts in <strong>setup</strong>. It becomes active once its opening balance is finalized.
        </p>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('admin.businesses.store') }}">
            @csrf
            <x-panel>
                @include('admin.businesses._form', ['business' => null])

                <div class="flex items-center justify-end gap-3 border-t border-gray-200 bg-gray-50 px-4 py-3 sm:px-6">
                    <a href="{{ route('admin.businesses.index') }}" class="text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</a>
                    <button class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">
                        Create business
                    </button>
                </div>
            </x-panel>
        </form>
    </div>
</x-app-layout>
