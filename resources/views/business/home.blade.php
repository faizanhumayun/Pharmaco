{{--
    Landing page for roles that never see the day's money. It lists only what
    this person can open, so nothing on it leads to a refusal.
--}}
<x-workspace-layout :business="$business">
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">Welcome, {{ auth()->user()->name }}</h1>
        @if ($role)
            <p class="mt-1 text-sm text-gray-500">
                {{ $role->label() }} at {{ $business->name }}. {{ $role->description() }}
            </p>
        @endif
    </x-slot>

    <div class="w-full space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @can('viewProducts', $business)
                <a href="{{ route('businesses.products.index', $business) }}"
                   class="block rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm hover:border-emerald-600">
                    <p class="text-sm font-semibold text-gray-900">Products &amp; price lists</p>
                    <p class="mt-1 text-sm text-gray-500">Look up a pack size, a rate, or what a company sells.</p>
                </a>
            @endcan

            @can('viewOrders', $business)
                <a href="{{ route('businesses.orders.index', $business) }}"
                   class="block rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm hover:border-emerald-600">
                    <p class="text-sm font-semibold text-gray-900">Order forms</p>
                    <p class="mt-1 text-sm text-gray-500">Orders raised to companies.</p>
                </a>
            @endcan
        </div>

        @if ($role === \App\Enums\BusinessRole::OrderBooker)
            <div class="rounded-md border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600">
                Booking orders from pharmacies is being built. Until then, the price lists above are the
                reference for quoting.
            </div>
        @endif
    </div>
</x-workspace-layout>
