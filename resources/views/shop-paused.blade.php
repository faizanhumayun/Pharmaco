{{--
    What a shop's own computer shows on every screen while the back office is
    fixing something in its books (App\Http\Middleware\ShopPaused).

    It looks again every half minute, so the shop opens by itself when the
    fix is done. Styles: resources/css/app.css (.ph-paused), tokens only.
--}}
@php($name = config('app.name', 'Pharmaco'))
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="30">
    <title>We are fixing an issue — {{ $shop ?? $name }}</title>
    @include('partials.pharmaco-fonts')
    @vite(['resources/css/app.css'])
</head>
<body class="pharmaco ph-paused">
    <header class="ph-paused__bar">
        <strong class="ph-paused__shop">{{ $shop ?? $name }}</strong>
        <span class="ph-paused__chip">Paused by support</span>
    </header>

    <main class="ph-paused__body" role="status" aria-live="polite">
        <div class="ph-paused__badge" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>
            </svg>
        </div>

        <h1 class="ph-paused__title">We are fixing an issue</h1>

        <p>Our support team is working on your books right now, so nothing can be entered on this computer for a short while.</p>
        <p><strong>Nothing is lost.</strong> Everything you entered has been sent, and the shop will open again here by itself as soon as the fix is done.</p>

        <dl class="ph-paused__facts">
            <div>
                <dt>Your entries</dt>
                <dd class="is-good">
                    @if ($details['confirmed'])
                        All sent{{ $details['sent_at'] ? ' · '.$details['sent_at']->timezone($zone)->format('g:i A') : '' }}
                    @else
                        Being sent…
                    @endif
                </dd>
            </div>
            <div>
                <dt>Paused since</dt>
                <dd>{{ $details['since']?->timezone($zone)->format('g:i A') ?? '—' }}</dd>
            </div>
            <div>
                <dt>Checking again</dt>
                <dd>every minute</dd>
            </div>
        </dl>

        @if ($details['phone'])
            <p class="ph-paused__help">Need it urgently? Call support: <a href="tel:{{ preg_replace('/[^0-9+]/', '', $details['phone']) }}">{{ $details['phone'] }}</a></p>
        @endif

        {{-- Why it has not opened yet, when taking the books back failed. --}}
        @if ($details['error'])
            <p class="ph-paused__help">Support has been told: the books could not be taken back yet ({{ $details['error'] }}).</p>
        @endif
    </main>
</body>
</html>
