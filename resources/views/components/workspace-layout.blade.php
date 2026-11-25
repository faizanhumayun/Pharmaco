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
    // Beside the counter button: goods arriving is the other thing done all day.
    $addStock = $tab('Add stock', 'businesses.stock.receive', 'businesses.stock.receive*', $user->can('manageOrders', $business));

    $counter = $tab('Open POS','businesses.pos.index', 'businesses.pos.*', $user->can('sellAtPos', $business));

    $groups = array_filter([
        'The day' => array_filter([
            // Closing a day is part of its Daily Log: Business standing and Close day.
            $tab('Daily Log', 'businesses.daily.index',   'businesses.daily.*',     $user->can('viewAny', [\App\Models\DailyEntry::class, $business])),
            $tab('Expenses',    'businesses.expenses.index','businesses.expenses.*',  $user->can('configure', $business)),
            $tab('Bill Collection', 'businesses.collections.index','businesses.collections.*', $user->can('viewAny', [\App\Models\DailyEntry::class, $business])),
        ]),
        'Stock' => array_filter([
            $tab('Products',    'businesses.products.index','businesses.products.*',  $user->can('viewProducts', $business)),
            $tab('Stock held',  'businesses.stock.index',   'businesses.stock.index', $user->can('verifyStock', $business)),
            $tab('Order forms', 'businesses.orders.index',  'businesses.orders.*',    $user->can('viewOrders', $business)),
            $tab('Stock audit', 'businesses.stock.audit',   'businesses.stock.audit*', $user->can('verifyStock', $business)),
        ]),
        'People' => array_filter([
            $tab('Customers',   'businesses.pharmacies.index','businesses.pharmacies.*', $user->can('configure', $business)),
            $tab('Companies',   'businesses.companies.index', 'businesses.companies.*',  $user->can('configure', $business)),
            $tab('Team',        'businesses.team.index',      'businesses.team.*',       $user->can('manageMembers', $business)),
        ]),
        'Records' => array_filter([
            $tab('Reports',     'businesses.reports.index', 'businesses.reports.*',   $user->can('viewOperationalReports', $business)),
            $tab('History',     'businesses.history',       'businesses.history',     $user->can('viewReports', $business)),
            $tab('Ledger',      'businesses.ledger',        'businesses.ledger*',     $user->can('viewLedger', $business)),
            $tab('Activity',    'businesses.audit',         'businesses.audit',       $user->can('viewAudit', $business)),
        ]),
    ]);

    $dashboard = $tab('Dashboard', 'businesses.show', 'businesses.show', true);

    /*
     * The bell: whether the books can be trusted, how far through the day it
     * is, and what needs attention — on every page, and the way in to "What
     * to do", which is why that has no tab of its own. Only for those who see
     * the day's figures, and only once the business is trading.
     */
    $notice = $business->acceptsTransactions() && $user->can('viewAny', [\App\Models\DailyEntry::class, $business])
        ? app(\App\Domain\Reporting\Notices::class)->for($business, $user)
        : null;

    // Out of the Stock group and in plain sight: expired stock that has not
    // gone back to the company is money being lost, and it is checked often.
    $expiring = $tab('Near expiry', 'businesses.stock.expiring', 'businesses.stock.expiring', $user->can('viewProducts', $business));

    // Its own tab rather than inside a group: it is reached rarely and looked
    // for by name, which is the opposite of everything above.
    $settings = $tab('Settings', 'businesses.settings.edit', 'businesses.settings.*', $user->can('configure', $business));

@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $business->name }} — Pharmaco</title>

        @include('partials.pharmaco-fonts')
        @include('partials.theme-script')

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="side-{{ $business->business_type->value }} accent-{{ $business->accentColour()->value }} font-sans antialiased">
        <div @class(['bg-app', 'min-h-screen' => ! $fill, 'flex h-screen flex-col overflow-hidden' => $fill])>
            @include('partials.mirror-banner')
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
                                stock counted in {{ $business->unit()->many() }} ·
                                today {{ $business->today()->format('D d M Y') }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                        {{-- A shop's own computer: internet, and the online copy. --}}
                        @include('partials.sync-status')

                        {{-- Which computer this is; what is entered here says so. --}}
                        @include('partials.device-chip')

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


                    @if ($expiring)
                        @php($active = request()->routeIs($expiring['pattern']))
                        <a href="{{ route($expiring['route'], $business) }}"
                           @class([
                               'whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition',
                               'border-emerald-700 text-emerald-800' => $active,
                               'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' => ! $active,
                           ])
                           @if ($active) aria-current="page" @endif>
                            {{ $expiring['label'] }}
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

                    @if ($settings)
                        <a href="{{ route($settings['route'], $business) }}"
                           @class([
                               'whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition',
                               'border-emerald-700 text-emerald-800' => request()->routeIs($settings['pattern']),
                               'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' => ! request()->routeIs($settings['pattern']),
                           ])>
                            {{ $settings['label'] }}
                        </a>
                    @endif

                    {{-- Add stock sits just before the counter button. Whichever of
                         the two comes first carries the push to the right. --}}
                    {{-- Alerts, behind a bell: the count is everything that is not
                         as it should be, red when the books themselves are in doubt. --}}
                    @if ($notice)
                        <div class="relative my-1 ms-auto" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                            <button type="button" x-on:click="open = ! open" :aria-expanded="open"
                                    title="{{ $notice['count'] > 0 ? $notice['count'].' '.Str::plural('alert', $notice['count']) : 'Alerts' }}"
                                    class="relative inline-flex h-9 w-9 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-600 hover:bg-gray-50">
                                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M10 2a6 6 0 00-6 6c0 1.887-.454 3.665-1.257 5.234a.75.75 0 00.515 1.076 32.91 32.91 0 003.256.508 3.5 3.5 0 006.972 0 32.903 32.903 0 003.256-.508.75.75 0 00.515-1.076A11.448 11.448 0 0116 8a6 6 0 00-6-6zM8.05 14.943a33.54 33.54 0 003.9 0 2 2 0 01-3.9 0z" clip-rule="evenodd" />
                                </svg>
                                <span class="sr-only">Alerts</span>
                                @if ($notice['count'] > 0)
                                    <span @class([
                                        'absolute -right-1.5 -top-1.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-[11px] font-bold text-white',
                                        'bg-red-600' => $notice['level'] === 'critical',
                                        'bg-amber-500' => $notice['level'] !== 'critical',
                                    ])>{{ $notice['count'] }}</span>
                                @endif
                            </button>

                            <div x-show="open" x-cloak x-transition.opacity.duration.100ms
                                 class="absolute right-0 z-40 mt-1 w-96 max-w-[90vw] overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg">
                                <div class="border-b border-gray-200 px-4 py-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Integrity</p>
                                    <ul class="mt-2 space-y-1.5 text-sm">
                                        @foreach ($notice['checks'] as [$what, $says, $state])
                                            <li class="flex items-center justify-between gap-3">
                                                <span class="text-gray-700">{{ $what }}</span>
                                                <x-badge :classes="['ok' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20', 'warn' => 'bg-amber-50 text-amber-800 ring-amber-600/20', 'bad' => 'bg-red-50 text-red-800 ring-red-600/20'][$state]">
                                                    {{ $says }}{{ $state === 'ok' ? ' ✓' : '' }}
                                                </x-badge>
                                            </li>
                                        @endforeach
                                    </ul>
                                    @if ($notice['unexplained'])
                                        <p class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                            Opening Balance Equity of <strong>{{ $notice['unexplained']->format() }}</strong> is still unexplained.
                                        </p>
                                    @endif
                                </div>

                                <a href="{{ route('businesses.attention', $business) }}" class="block px-4 py-3 hover:bg-gray-50">
                                    <p class="flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        Tasks
                                        <span class="normal-case tracking-normal text-emerald-800">Open &rarr;</span>
                                    </p>
                                    <p class="mt-2 text-sm font-semibold {{ $notice['day']['complete'] ? 'text-emerald-800' : 'text-gray-900' }}">
                                        @if ($notice['day']['complete'])
                                            Today is complete
                                        @else
                                            {{ $notice['day']['outstanding'] }} {{ Str::plural('step', $notice['day']['outstanding']) }} left to finish today
                                            <span class="font-normal text-gray-500 tabular-nums">· {{ $notice['day']['done'] }} of {{ $notice['day']['done'] + $notice['day']['outstanding'] }} done</span>
                                        @endif
                                    </p>
                                    @if ($notice['alerts'] !== [])
                                        <p class="mt-2 text-sm font-semibold text-gray-900">
                                            {{ count($notice['alerts']) }} {{ Str::plural('item', count($notice['alerts'])) }} {{ count($notice['alerts']) === 1 ? 'needs' : 'need' }} attention
                                        </p>
                                        <ul class="mt-1 space-y-1 text-sm text-gray-600">
                                            @foreach ($notice['alerts'] as $alert)
                                                <li class="flex items-start gap-2">
                                                    <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full {{ ($alert['level'] ?? '') === 'critical' ? 'bg-red-600' : 'bg-amber-500' }}"></span>
                                                    {{ $alert['title'] }}
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </a>
                            </div>
                        </div>
                    @endif

                    {{-- A product that is not in the catalogue yet, added from
                         whichever screen it was missed on. --}}
                    @if ($addStock)
                        <button type="button" x-data x-on:click="$dispatch('add-product')"
                                class="my-1 {{ $notice ? 'ms-2' : 'ms-auto' }} inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            <span aria-hidden="true" class="text-base leading-none">+</span>
                            Product
                        </button>
                    @endif

                    @if ($addStock)
                        <a href="{{ route($addStock['route'], $business) }}"
                           class="my-1 ms-2 inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-emerald-600 bg-white px-3 py-1.5 text-sm font-semibold text-emerald-700 hover:bg-emerald-50">
                            <span aria-hidden="true" class="text-base leading-none">+</span>
                            {{ $addStock['label'] }}
                        </a>
                    @endif

                    @if ($counter)
                        {{-- The counter opens in its own tab: the till is a place you
                             stand at all day, and the back office stays where it was. --}}
                        <a href="{{ route($counter['route'], $business) }}" target="_blank"
                           title="Opens POS in a new tab. A POS tab that is already open moves its bill to the new tab and closes."
                           class="my-1 {{ $addStock || $notice ? 'ms-2' : 'ms-auto' }} inline-flex items-center gap-1.5 whitespace-nowrap rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
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
                    <div class="w-full px-4 py-5 sm:px-6 lg:px-8" data-live-header>
                        {{ $header }}
                    </div>
                </div>
            @endisset

            <main @class(['min-h-0 flex-1 overflow-hidden' => $fill])>
                {{ $slot }}
            </main>
        </div>

        {{-- Adding a product, from any screen of the workspace. --}}
        @can('manageOrders', $business)
            <x-add-product :business="$business" />
        @endcan

        {{-- Adding a company, from any screen of the workspace. --}}
        @can('configure', $business)
            <x-add-company :business="$business" />
        @endcan

        @stack('scripts')
    </body>
</html>
