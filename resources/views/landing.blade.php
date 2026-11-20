{{--
    The public landing page.

    Design: the layout supplied with the design system — docs/brand-book.md for
    the rules, resources/css/pharmaco/landing.css for the classes used here.
    Two columns, one book: pharmacy always on the left, distributor always on
    the right. Spacing comes from the layout's own gaps, so no margin
    utilities are used on this page (its stylesheet resets them).

    Words: config/landing.php. When a feature ships, add it there; anything not
    built yet carries 'status' => 'soon' and is shown with a Coming soon chip.

    Signing in lives in the top bar and nowhere else on the page.
--}}
@php
    $hero = config('landing.hero', []);
    $screens = config('landing.screens', []);
    $rows = config('landing.rows', []);
    $shared = config('landing.shared', []);
    $demo = config('landing.demo', []);
    $pricing = config('landing.pricing', []);
    $footer = config('landing.footer', []);
    $rupees = fn (int $amount) => 'Rs ' . number_format($amount);
    $contact = config('landing.contact_email');
    $name = config('app.name', 'Pharmaco');

    // Left then right. The order is the design, so it is fixed here once.
    $sides = ['pharmacy' => 'For pharmacies', 'distributor' => 'For distributors'];

    $enterUrl = auth()->check() ? route('dashboard') : route('login');
    $enterLabel = auth()->check() ? 'Open your workspace' : 'Sign in';
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" style="scroll-behavior: smooth;">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $name }} — one set of books for pharmacies and distributors</title>
    <meta name="description" content="{{ $hero['lede'] ?? '' }}">
    @include('partials.pharmaco-fonts')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="pharmaco">

    <header class="ph-header">
        <div class="ph-wrap ph-header__inner">
            <a class="ph-wordmark" href="{{ route('home') }}">{{ $name }}</a>
            <nav class="ph-nav" aria-label="Main">
                <a href="#pharmacies">For pharmacies</a>
                <a href="#distributors">For distributors</a>
                <a href="#shared">What both share</a>
                @if ($pricing)
                    <a href="#pricing">Pricing</a>
                @endif
                @if ($contact)
                    <a href="#demo">Request a demo</a>
                @endif
                <a class="ph-button" href="{{ $enterUrl }}">{{ $enterLabel }}</a>
            </nav>
        </div>
    </header>

    <main class="ph-wrap ph-main">

        <section class="ph-section ph-section--loose">
            <div class="ph-hero-text">
                <p class="text-label">{{ $hero['label'] ?? '' }}</p>
                <h1 class="ph-hero-title">{{ $hero['title'] ?? '' }}</h1>
                <p class="ph-lede">{{ $hero['lede'] ?? '' }}</p>
            </div>

            <div class="ph-columns">
                @foreach ($sides as $side => $label)
                    @continue(empty($hero[$side]))

                    <div class="ph-column ph-column--{{ $side }}" id="{{ $side === 'pharmacy' ? 'pharmacies' : 'distributors' }}">
                        <p class="text-label">{{ $label }}</p>
                        <h2 class="ph-row-title">{{ $hero[$side]['title'] }}</h2>
                        <p>{{ $hero[$side]['body'] }}</p>
                        <a class="ph-button ph-button--{{ $side }}" href="#rows">{{ $hero[$side]['button'] }}</a>

                        <div class="ph-vignette">
                            <div class="ph-vignette__head">
                                <span>{{ $hero[$side]['sample']['head'] }}</span>
                                <span class="text-label">Sample figures</span>
                            </div>
                            @foreach ($hero[$side]['sample']['rows'] as [$what, $figure, $overdue])
                                <div class="ph-vignette__row">
                                    <span>{{ $what }}</span>
                                    <span @class(['text-mono', 'is-alert' => $overdue])>{{ $figure }}</span>
                                </div>
                            @endforeach
                            <div @class(['ph-vignette__foot', 'text-label', 'is-alert' => $hero[$side]['sample']['alert']])>{{ $hero[$side]['sample']['foot'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- The application itself. One picture at a time; the buttons
             choose which. Without scripts the first one is simply shown. --}}
        @if (! empty($screens['items']))
            <section class="ph-section ph-section--loose" id="screens" x-data="{ shown: 0 }">
                <div class="ph-hero-text">
                    <p class="text-label">{{ $screens['label'] }}</p>
                    <h2 class="text-heading">{{ $screens['title'] }}</h2>
                    <p class="ph-lede">{{ $screens['body'] }}</p>
                </div>

                <div class="ph-screens__tabs" role="tablist" aria-label="Screens">
                    @foreach ($screens['items'] as $i => $screen)
                        <button type="button" role="tab" class="ph-screens__tab {{ $i === 0 ? 'is-on' : '' }}"
                                :class="{ 'is-on': shown === {{ $i }} }" :aria-selected="shown === {{ $i }}"
                                x-on:click="shown = {{ $i }}">{{ $screen['tab'] }}</button>
                    @endforeach
                </div>

                @foreach ($screens['items'] as $i => $screen)
                    <figure class="ph-screens__frame" x-show="shown === {{ $i }}" @if ($i > 0) x-cloak @endif>
                        <img src="{{ asset($screen['image']) }}" alt="{{ $screen['tab'] }} screen in {{ $name }}"
                             width="2880" height="1280" @if ($i > 0) loading="lazy" @endif>
                        <figcaption>{{ $screen['caption'] }}</figcaption>
                    </figure>
                @endforeach
            </section>
        @endif

        <div class="ph-rows" id="rows">
            @foreach ($rows as $i => $row)
                <section class="ph-section">
                    <div class="ph-row-head">
                        <span class="text-label">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <h2 class="ph-row-title">{{ $row['title'] }}</h2>
                    </div>

                    <div class="ph-columns">
                        @foreach ($sides as $side => $label)
                            @continue(empty($row[$side]))

                            <div class="ph-column ph-column--{{ $side }}">
                                <p class="text-label">{{ $label }}</p>
                                <h3 class="text-subheading">{{ $row[$side]['title'] }}</h3>
                                {{-- Whatever is not built yet says so. --}}
                                @if (($row[$side]['status'] ?? null) === 'soon')
                                    <span class="ph-chip">Coming soon</span>
                                @endif
                                <ul class="ph-list">
                                    @foreach ($row[$side]['points'] ?? [] as $point)
                                        <li>{{ $point }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

    </main>

    <section class="ph-shared" id="shared">
        <div class="ph-wrap">
            <p class="text-label">{{ $shared['label'] ?? '' }}</p>
            <h2 class="text-heading">{{ $shared['title'] ?? '' }}</h2>
            <div class="ph-shared__grid">
                @foreach ($shared['items'] ?? [] as $item)
                    <div>
                        <h3 class="text-subheading">{{ $item['title'] }}</h3>
                        <p>{{ $item['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- What it costs. The list price is crossed out beside what is charged
         today; both come from config/landing.php. --}}
    @if ($pricing)
        <section class="ph-wrap ph-pricing" id="pricing">
            <div class="ph-hero-text">
                <p class="text-label">{{ $pricing['label'] }}</p>
                <h2 class="text-heading">{{ $pricing['title'] }}</h2>
                <p class="ph-lede">{{ $pricing['body'] }}</p>
            </div>

            <div class="ph-columns">
                @foreach ($sides as $side => $label)
                    @continue(empty($pricing[$side]))
                    @php
                        $monthly = (int) $pricing[$side]['monthly'];
                        $yearly = $monthly * 12;
                        $monthlyNow = intdiv($monthly * (100 - $pricing['monthly_discount']), 100);
                        $yearlyNow = intdiv($yearly * (100 - $pricing['yearly_discount']), 100);
                    @endphp

                    <div class="ph-column ph-column--{{ $side }}">
                        <p class="text-label">{{ $label }}</p>

                        <div class="ph-vignette">
                            <div class="ph-price">
                                <div>
                                    <span class="text-subheading">Monthly</span>
                                    <span class="ph-chip">{{ $pricing['monthly_discount'] }}% off</span>
                                </div>
                                <div class="ph-price__figure">
                                    <s>{{ $rupees($monthly) }}</s>
                                    <span class="text-mono">{{ $rupees($monthlyNow) }}</span>
                                    <span class="text-small">a month</span>
                                </div>
                            </div>

                            <div class="ph-price">
                                <div>
                                    <span class="text-subheading">Yearly</span>
                                    <span class="ph-chip">{{ $pricing['yearly_discount'] }}% off</span>
                                </div>
                                <div class="ph-price__figure">
                                    <s>{{ $rupees($yearly) }}</s>
                                    <span class="text-mono">{{ $rupees($yearlyNow) }}</span>
                                    <span class="text-small">a year · {{ $rupees(intdiv($yearlyNow, 12)) }} a month</span>
                                </div>
                            </div>

                            <div class="ph-price">
                                <div>
                                    <span class="text-subheading">One-time setup</span>
                                    @if ($pricing['setup_free'])
                                        <span class="ph-chip">Free for now</span>
                                    @endif
                                </div>
                                <div class="ph-price__figure">
                                    @if ($pricing['setup_free'])
                                        <s>{{ $rupees((int) $pricing[$side]['setup']) }}</s>
                                        <span class="text-mono">Free</span>
                                    @else
                                        <span class="text-mono">{{ $rupees((int) $pricing[$side]['setup']) }}</span>
                                    @endif
                                    <span class="text-small">paid once</span>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Only when there is somewhere for the request to go. --}}
    @if ($contact)
        <section class="ph-wrap ph-demo" id="demo">
            <div class="ph-columns">
                @foreach ($sides as $side => $label)
                    @continue(empty($demo[$side]))

                    <div class="ph-column ph-column--{{ $side }}">
                        <p class="text-label">{{ $label }}</p>
                        <h2 class="ph-row-title">{{ $demo[$side]['title'] }}</h2>
                        <p>{{ $demo[$side]['body'] }}</p>
                        <a class="ph-button ph-button--{{ $side }}"
                           href="mailto:{{ $contact }}?subject={{ rawurlencode($demo[$side]['button']) }}">{{ $demo[$side]['button'] }}</a>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <footer class="ph-footer">
        <div class="ph-wrap ph-foot__base">
            <span class="text-small">&copy; {{ now()->year }} {{ $name }}</span>
            @if (! empty($footer['powered_by']))
                <span class="text-small">Powered by <strong>{{ $footer['powered_by'] }}</strong></span>
            @endif
        </div>
    </footer>

</body>
</html>
