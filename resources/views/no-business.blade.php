<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">No business assigned</h1>
    </x-slot>

    <div class="w-full px-4 py-12 sm:px-6 lg:px-8">
        <x-panel>
            <div class="px-6 py-10 text-center">
                <p class="text-sm text-gray-600">
                    Your account is active, but it has not been given access to a business yet.
                </p>
                <p class="mt-2 text-sm text-gray-500">
                    Access is granted per business by the App Owner. Ask them to add you.
                </p>
            </div>
        </x-panel>
    </div>
</x-app-layout>
