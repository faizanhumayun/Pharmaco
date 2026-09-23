@props(['business'])

@php
    $user = auth()->user();
    // Each tab is shown on the same ability its page authorizes, so the menu
    // never offers something the page will refuse.
    $tabIf = fn (string $label, string $route, string $pattern, bool $allowed) =>
        $allowed ? ['label' => $label, 'route' => $route, 'pattern' => $pattern] : null;

    $tabs = array_filter([
        $tabIf('Dashboard',   'businesses.show',            'businesses.show',         true),
        $tabIf('History',     'businesses.history',         'businesses.history',      $user->can('viewReports', $business)),
        $tabIf('Counter',     'businesses.pos.index',       'businesses.pos.*',        $user->can('sellAtPos', $business)),
        $tabIf('Daily entry', 'businesses.daily.index',     'businesses.daily.*',      $user->can('viewAny', [\App\Models\DailyEntry::class, $business])),
        $tabIf('Closing',     'businesses.closing.index',   'businesses.closing.*',    $user->can('viewAny', [\App\Models\DailyClosing::class, $business])),
        $tabIf('Stock',       'businesses.stock.index',     'businesses.stock.*',      $user->can('verifyStock', $business)),
        $tabIf('Companies',   'businesses.companies.index', 'businesses.companies.*',  $user->can('configure', $business)),
        $tabIf('Products',    'businesses.products.index',  'businesses.products.*',   $user->can('viewProducts', $business)),
        $tabIf('Order form',  'businesses.orders.index',    'businesses.orders.*',     $user->can('viewOrders', $business)),
        $tabIf('Pharmacies',  'businesses.pharmacies.index','businesses.pharmacies.*', $user->can('configure', $business)),
        $tabIf('Expenses',    'businesses.expenses.index',  'businesses.expenses.*',   $user->can('configure', $business)),
        $tabIf('Ledger',      'businesses.ledger',          'businesses.ledger*',      $user->can('viewLedger', $business)),
        $tabIf('Activity',    'businesses.audit',           'businesses.audit',        $user->can('viewAudit', $business)),
        $tabIf('Team',        'businesses.team.index',      'businesses.team.*',       $user->can('manageMembers', $business)),
    ]);

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
        <div class="min-h-screen bg-gray-100">
            <header class="border-b border-gray-200 bg-white">
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

                {{-- Tabs --}}
                <nav class="mt-3 flex gap-1 overflow-x-auto px-4 sm:px-6 lg:px-8" aria-label="Workspace sections">
                    @foreach ($tabs as $tab)
                        @php($active = request()->routeIs($tab['pattern']))
                        <a href="{{ route($tab['route'], $business) }}"
                           @class([
                               'whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition',
                               'border-emerald-700 text-emerald-800' => $active,
                               'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' => ! $active,
                           ])
                           @if ($active) aria-current="page" @endif>
                            {{ $tab['label'] }}
                        </a>
                    @endforeach

                    <span class="ms-2 inline-flex items-center gap-2 whitespace-nowrap border-b-2 border-transparent px-3 py-2.5 text-sm font-medium text-gray-300"
                          title="Pharmacy support is not built yet">
                        Pharmacy
                        <span class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-amber-700">Soon</span>
                    </span>
                </nav>
            </header>

            @isset($header)
                <div class="border-b border-gray-200 bg-white">
                    <div class="w-full px-4 py-5 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </div>
            @endisset

            <main>
                {{ $slot }}
            </main>
        </div>

        @stack('scripts')
    </body>
</html>
