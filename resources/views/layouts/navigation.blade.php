@php($user = Auth::user())

<nav x-data="{ open: false }" class="border-b border-gray-200 bg-white">
    <div class="w-full px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 justify-between">
            <div class="flex">
                <div class="flex shrink-0 items-center">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                        <span class="rounded bg-emerald-700 px-2 py-1 text-sm font-bold text-white">Rx</span>
                        <span class="text-sm font-semibold text-gray-900">Pharmaco</span>
                        @if ($user?->isPlatformAdmin())
                            <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-gray-600">Back office</span>
                        @endif
                    </a>
                </div>

                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    @if ($user?->isPlatformAdmin())
                        <x-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.dashboard')">
                            Overview
                        </x-nav-link>
                        <x-nav-link :href="route('admin.businesses.index')" :active="request()->routeIs('admin.businesses.*')">
                            Businesses
                        </x-nav-link>
                        <x-nav-link :href="route('admin.users.index')" :active="request()->routeIs('admin.users.*')">
                            Users
                        </x-nav-link>
                    @else
                        {{-- Members land here only between workspaces: each business
                             opens in its own window with its own chrome. --}}
                        @foreach ($user?->activeBusinesses ?? [] as $business)
                            <a href="{{ route('businesses.show', $business) }}"
                               target="_blank" rel="opener"
                               onclick="return openWorkspace(event, this, '{{ $business->slug }}')"
                               class="inline-flex items-center border-b-2 border-transparent px-1 pt-1 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700">
                                {{ $business->name }}
                            </a>
                        @endforeach
                    @endif

                    <span class="inline-flex items-center border-b-2 border-transparent px-1 pt-1 text-sm font-medium text-gray-300"
                          title="Pharmacy support is not built yet">
                        Pharmacy
                        <span class="ms-2 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-amber-700">Soon</span>
                    </span>
                </div>
            </div>

            <div class="hidden sm:ms-6 sm:flex sm:items-center">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition hover:text-gray-700 focus:outline-none">
                            <div>{{ $user?->name }}</div>
                            <svg class="ms-1 h-4 w-4 fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">Profile</x-dropdown-link>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')"
                                             onclick="event.preventDefault(); this.closest('form').submit();">
                                Log out
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-500 focus:outline-none">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="space-y-1 pt-2 pb-3">
            @if ($user?->isPlatformAdmin())
                <x-responsive-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.dashboard')">Overview</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('admin.businesses.index')" :active="request()->routeIs('admin.businesses.*')">Businesses</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('admin.users.index')" :active="request()->routeIs('admin.users.*')">Users</x-responsive-nav-link>
            @else
                @foreach ($user?->activeBusinesses ?? [] as $business)
                    <x-responsive-nav-link :href="route('businesses.show', $business)">{{ $business->name }}</x-responsive-nav-link>
                @endforeach
            @endif
        </div>

        <div class="border-t border-gray-200 pt-4 pb-1">
            <div class="px-4">
                <div class="text-base font-medium text-gray-800">{{ $user?->name }}</div>
                <div class="text-sm font-medium text-gray-500">{{ $user?->email }}</div>
            </div>
            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit')">Profile</x-responsive-nav-link>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-responsive-nav-link :href="route('logout')"
                                           onclick="event.preventDefault(); this.closest('form').submit();">
                        Log out
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
