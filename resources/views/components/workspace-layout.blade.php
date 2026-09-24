@props(['business', 'fill' => false])

{{-- $fill: the page is the window, and scrolls inside itself rather than
     running off the bottom. For screens worked through rather than read —
     checking in a delivery, where the list and what you have ticked both need
     to stay in sight. --}}

@php
    $user = auth()->user();
    // Each tab is shown on the same ability its page authorizes, so the menu
    // never offers something the page will refuse.
    $tabIf = fn (string $label, string $route, string $pattern, bool $allowed, bool $highlight = false) =>
        $allowed ? ['label' => $label, 'route' => $route, 'pattern' => $pattern, 'highlight' => $highlight] : null;

    $tab = fn (string $label, string $route, string $pattern, bool $allowed) =>
        $allowed ? ['label' => $label, 'route' => $route, 'pattern' => $pattern] : null;

    /*
     * Grouped, because fourteen tabs in a row is a list to read rather than a
     * place to point at. The grouping is the owner's own division of the work:
     * the day's trading, what is bought and held, who is owed and owes, and
     * the record. The counter is not in a group — it is the button.
     */
    $counter = $tab('Sell at the counter', 'businesses.pos.index', 'businesses.pos.*', $user->can('sellAtPos', $business));

    $groups = array_filter([
        'The day' => array_filter([
            $tab('Daily entry', 'businesses.daily.index',   'businesses.daily.*',     $user->can('viewAny', [\App\Models\DailyEntry::class, $business])),
            $tab('Day closing', 'businesses.closing.index', 'businesses.closing.*',   $user->can('viewAny', [\App\Models\DailyClosing::class, $business])),
            $tab('Expenses',    'businesses.expenses.index','businesses.expenses.*',  $user->can('configure', $business)),
            $tab('Collection',  'businesses.collections.index','businesses.collections.*', $user->can('viewAny', [\App\Models\DailyEntry::class, $business])),
        ]),
        'Stock' => array_filter([
            $tab('Products',    'businesses.products.index','businesses.products.*',  $user->can('viewProducts', $business)),
            $tab('Stock held',  'businesses.stock.index',   'businesses.stock.*',     $user->can('verifyStock', $business)),
            $tab('Order forms', 'businesses.orders.index',  'businesses.orders.*',    $user->can('viewOrders', $business)),
        ]),
        'People' => array_filter([
            $tab('Customers',   'businesses.pharmacies.index','businesses.pharmacies.*', $user->can('configure', $business)),
            $tab('Companies',   'businesses.companies.index', 'businesses.companies.*',  $user->can('configure', $business)),
            $tab('Team',        'businesses.team.index',      'businesses.team.*',       $user->can('manageMembers', $business)),
        ]),
        'Books' => array_filter([
            $tab('History',     'businesses.history',       'businesses.history',     $user->can('viewReports', $business)),
            $tab('Ledger',      'businesses.ledger',        'businesses.ledger*',     $user->can('viewLedger', $business)),
            $tab('Activity',    'businesses.audit',         'businesses.audit',       $user->can('viewAudit', $business)),
        ]),
    ]);

    $dashboard = $tab('Dashboard', 'businesses.show', 'businesses.show', true);

@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $business->name }} — Pharmaco</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div @class(['bg-gray-100', 'min-h-screen' => ! $fill, 'flex h-screen flex-col overflow-hidden' => $fill])>
            <header @class(['border-b border-gray-200 bg-white', 'shrink-0' => $fill])>
                {{-- Identity row: which business you are inside, and its state. --}}
                <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 px-4 pt-3 sm:px-6 lg:px-8">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="rounded bg-emerald-700 px-2 py-1 text-sm font-bold text-white">Rx</span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="truncate text-base font-semibold text-gray-900">{{ $business->name }}</h1>
                                <x-badge :classes="$business->status->badgeClasses()">{{ $business->status->label() }}</x-badge>
                                @if ($business->openingBalance?->status)
                                    <x-badge classes="bg-gray-100 text-gray-600 ring-gray-500/20">
                                        Opening {{ strtolower($business->openingBalance->status->label()) }}
                                    </x-badge>
                                @endif
                            </div>
                            <p class="truncate text-xs text-gray-500">
                                {{ $business->business_type->label() }} ·
                                {{ $business->currency }} ·
                                {{ $business->timezone }} ·
                                counted in {{ $business->unit()->many() }} ·
                                today {{ $business->today()->format('D d M Y') }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                        <dl class="hidden gap-6 text-xs md:flex">
                            <div>
                                <dt class="uppercase tracking-wide text-gray-400">Opening date</dt>
                                <dd class="mt-0.5 font-medium tabular-nums text-gray-700">
                                    {{ $business->opening_date?->format('d M Y') ?? 'Not set' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="uppercase tracking-wide text-gray-400">Closed through</dt>
                                <dd class="mt-0.5 font-medium tabular-nums text-gray-700">
                                    {{ $business->locked_through_date?->format('d M Y') ?? '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="uppercase tracking-wide text-gray-400">Signed in</dt>
                                <dd class="mt-0.5 font-medium text-gray-700">
                                    {{ $user->name }}
                                    <span class="text-gray-400">
                                        · {{ $user->isPlatformAdmin() ? 'App Owner' : ($user->roleIn($business)?->label() ?? 'Viewer') }}
                                    </span>
                                </dd>
                            </div>
                        </dl>

                        {{-- The App Owner came in from the back office and needs a way
                             back to it; everyone else lives here, so they just sign out. --}}
                        <div class="flex items-center gap-2">
                            @if ($user->isPlatformAdmin())
                                <a href="{{ route('admin.businesses.show', $business) }}"
                                   class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                    Back office
                                </a>
                            @endif

                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M3 4.25A2.25 2.25 0 015.25 2h5.5A2.25 2.25 0 0113 4.25v2a.75.75 0 01-1.5 0v-2a.75.75 0 00-.75-.75h-5.5a.75.75 0 00-.75.75v11.5c0 .414.336.75.75.75h5.5a.75.75 0 00.75-.75v-2a.75.75 0 011.5 0v2A2.25 2.25 0 0110.75 18h-5.5A2.25 2.25 0 013 15.75V4.25z" clip-rule="evenodd" />
                                        <path fill-rule="evenodd" d="M6 10a.75.75 0 01.75-.75h9.19l-1.72-1.72a.75.75 0 111.06-1.06l3 3a.75.75 0 010 1.06l-3 3a.75.75 0 11-1.06-1.06l1.72-1.72H6.75A.75.75 0 016 10z" clip-rule="evenodd" />
                                    </svg>
                                    Log out
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                {{-- Tabs, in groups. A group opens on click and marks itself
                     when the page inside it is the one being shown. --}}
                <nav class="mt-3 flex flex-wrap items-center gap-1 px-4 sm:px-6 lg:px-8" aria-label="Workspace sections">
                    @if ($dashboard)
                        @php($active = request()->routeIs($dashboard['pattern']))
                        <a href="{{ route($dashboard['route'], $business) }}"
                           @class([
                               'whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition',
                               'border-emerald-700 text-emerald-800' => $active,
                               'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' => ! $active,
                           ])
                           @if ($active) aria-current="page" @endif>
                            {{ $dashboard['label'] }}
                        </a>
                    @endif

                    @foreach ($groups as $name => $items)
                        @continue($items === [])
                        @php($inside = collect($items)->contains(fn ($i) => request()->routeIs($i['pattern'])))

                        <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false"
                             x-on:keydown.escape="open = false">
                            <button type="button" x-on:click="open = ! open"
                                    @class([
                                        'inline-flex items-center gap-1 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition',
                                        'border-emerald-700 text-emerald-800' => $inside,
                                        'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' => ! $inside,
                                    ])
                                    :aria-expanded="open">
                                {{ $name }}
                                <svg class="h-3.5 w-3.5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M5.22 7.22a.75.75 0 011.06 0L10 10.94l3.72-3.72a.75.75 0 111.06 1.06l-4.25 4.25a.75.75 0 01-1.06 0L5.22 8.28a.75.75 0 010-1.06z" clip-rule="evenodd" />
                                </svg>
                            </button>

                            <div x-show="open" x-cloak x-transition.opacity.duration.100ms
                                 class="absolute left-0 z-40 mt-1 w-56 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                                @foreach ($items as $item)
                                    @php($here = request()->routeIs($item['pattern']))
                                    <a href="{{ route($item['route'], $business) }}"
                                       @class([
                                           'block px-4 py-2 text-sm',
                                           'bg-emerald-50 font-medium text-emerald-800' => $here,
                                           'text-gray-700 hover:bg-gray-50' => ! $here,
                                       ])
                                       @if ($here) aria-current="page" @endif>
                                        {{ $item['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    @if ($counter)
                        {{-- The counter opens in its own tab: the till is a place you
                             stand at all day, and the back office stays where it was. --}}
                        <a href="{{ route($counter['route'], $business) }}" target="_blank" rel="noopener"
                           title="Opens in a new tab"
                           class="my-1 ms-auto inline-flex items-center gap-1.5 whitespace-nowrap rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M3 4.5A1.5 1.5 0 014.5 3h11A1.5 1.5 0 0117 4.5v3A1.5 1.5 0 0115.5 9h-11A1.5 1.5 0 013 7.5v-3zM3 12a1 1 0 011-1h12a1 1 0 011 1v3.5A1.5 1.5 0 0115.5 17h-11A1.5 1.5 0 013 15.5V12zm3 1.5a.75.75 0 000 1.5h3a.75.75 0 000-1.5H6z" />
                            </svg>
                            {{ $counter['label'] }}
                        </a>
                    @endif
                </nav>
            </header>

            @isset($header)
                <div class="border-b border-gray-200 bg-white">
                    <div class="w-full px-4 py-5 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </div>
            @endisset

            <main @class(['min-h-0 flex-1 overflow-hidden' => $fill])>
                {{ $slot }}
            </main>
        </div>

        @stack('scripts')
    </body>
</html>
