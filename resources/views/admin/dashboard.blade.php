<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Back office</h1>
                <p class="mt-1 text-sm text-gray-500">Distributor businesses on this platform.</p>
            </div>
            <a href="{{ route('admin.businesses.create') }}"
               class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">
                New business
            </a>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            <x-stat label="Businesses" :value="$counts['total']" />
            <x-stat label="Awaiting opening" :value="$counts['setup']" hint="Cannot record activity yet" />
            <x-stat label="Active" :value="$counts['active']" />
            <x-stat label="Suspended" :value="$counts['suspended']" />
            <x-stat label="Active users" :value="$counts['users']" />
        </dl>

        <x-panel class="mt-8" title="Recently added">
            @forelse ($businesses as $business)
                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 last:border-0 sm:px-6">
                    <div class="min-w-0">
                        <a href="{{ route('admin.businesses.show', $business) }}"
                           class="text-sm font-medium text-gray-900 hover:text-emerald-700">
                            {{ $business->name }}
                        </a>
                        <p class="mt-0.5 text-xs text-gray-500">
                            {{ $business->business_type->label() }} ·
                            {{ $business->active_members_count }} {{ Str::plural('member', $business->active_members_count) }} ·
                            {{ $business->timezone }}
                        </p>
                    </div>
                    <x-badge :classes="$business->status->badgeClasses()">{{ $business->status->label() }}</x-badge>
                </div>
            @empty
                <div class="px-4 py-10 text-center sm:px-6">
                    <p class="text-sm text-gray-500">No businesses yet.</p>
                    <a href="{{ route('admin.businesses.create') }}"
                       class="mt-3 inline-block rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                        Create the first one
                    </a>
                </div>
            @endforelse
        </x-panel>
    </div>
</x-app-layout>
