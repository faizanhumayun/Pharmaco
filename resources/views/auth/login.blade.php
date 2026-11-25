{{--
    Signing in — two doors drawn from one page.

      /login              the business's staff: owner, manager, operator, order booker
      /back-office/login  the people who run the platform

    $area says which ('business' or 'backoffice'). Each door offers a button to
    the other, and the controller turns away an account that came to the wrong one.

    Design: docs/brand-book.md and resources/css/pharmaco/login.css. Signing in
    belongs to neither side of the trade, so the form is in ink; the side
    colours appear only inside their own columns on the business page. The
    back office looks after every business, so its page carries no side colour.
--}}
@php
    $name = config('app.name', 'Pharmaco');
    $backoffice = ($area ?? 'business') === 'backoffice';
    $hero = config('landing.hero', []);
    $sides = ['pharmacy' => 'For pharmacies', 'distributor' => 'For distributors'];

    // What the back office is for, in the words on its own screens.
    $looksAfter = [
        ['Businesses', 'Sets up each pharmacy and distributor, and suspends a business when required.'],
        ['Opening positions', 'Enters the opening position of a business on its first day, then locks it.'],
        ['People', 'Creates every account. There is no public sign-up.'],
        ['Payment methods', 'Maintains the list of wallets and banks that businesses can receive payments through.'],
    ];
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $backoffice ? 'Back office sign in' : 'Sign in' }} — {{ $name }}</title>
    @include('partials.pharmaco-fonts')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="pharmaco">
    @include('partials.mirror-banner')
    <div class="ph-login">

        <main class="ph-login__form">
            <a class="ph-wordmark" href="{{ route('home') }}">{{ $name }}</a>

            <div class="ph-login__body">
                <div class="ph-login__intro">
                    <p class="text-label">{{ $backoffice ? 'Back office sign in' : 'Business sign in' }}</p>
                    <h1 class="text-heading">{{ $backoffice ? 'Manage the platform' : 'Sign in to your business' }}</h1>
                    <p class="text-body">
                        {{ $backoffice
                            ? 'For staff who set up and manage businesses on '.$name.'.'
                            : 'For owners, managers and staff. Use the email and password of your account.' }}
                    </p>
                </div>

                @if (session('status'))
                    <p class="ph-status" role="status">{{ session('status') }}</p>
                @endif

                <form method="POST" action="{{ $backoffice ? route('backoffice.login.store') : route('login') }}" class="ph-form">
                    @csrf

                    <div class="ph-field">
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}"
                               required autofocus autocomplete="username"
                               @class(['ph-input', 'is-wrong' => $errors->has('email')])
                               @if ($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif>
                        @error('email')
                            <p class="ph-field__error" id="email-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="ph-field">
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password"
                               required autocomplete="current-password"
                               @class(['ph-input', 'is-wrong' => $errors->has('password')])
                               @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif>
                        @error('password')
                            <p class="ph-field__error" id="password-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="ph-form__row">
                        <label class="ph-check" for="remember_me">
                            <input id="remember_me" name="remember" type="checkbox">
                            <span>Keep me signed in</span>
                        </label>

                        {{-- Not offered at the back office door: a platform
                             account is not reset from a public page. --}}
                        @if (! $backoffice && Route::has('password.request'))
                            <a href="{{ route('password.request') }}">Forgot your password?</a>
                        @endif
                    </div>

                    <button type="submit" class="ph-button">{{ $backoffice ? 'Sign in to the back office' : 'Sign in' }}</button>
                </form>

                {{-- The other door. --}}
                <div class="ph-login__other">
                    @if ($backoffice)
                        <p class="text-small">Work at a pharmacy or a distributor?</p>
                        <a class="ph-button ph-button--quiet" href="{{ route('login') }}">Business sign in</a>
                    @else
                        <p class="text-small">Platform staff?</p>
                        <a class="ph-button ph-button--quiet" href="{{ route('backoffice.login') }}">Back office sign in</a>
                    @endif
                </div>
            </div>

            <p class="text-small ph-login__note">
                {{ $backoffice
                    ? 'Back office accounts are not part of any business and can view all businesses.'
                    : 'Your account is created by the person who manages your business on '.$name.'. There is no public sign-up.' }}
            </p>
        </main>

        {{-- Shared ground: ink. --}}
        <aside class="ph-login__aside" aria-label="About {{ $name }}">
            @if ($backoffice)
                <p class="text-label">Back office</p>
                <p class="ph-hero-title">The platform that manages every business.</p>

                <ul class="ph-login__list">
                    @foreach ($looksAfter as [$what, $does])
                        <li>
                            <span class="text-subheading">{{ $what }}</span>
                            <span class="text-body">{{ $does }}</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-label">{{ $hero['label'] ?? '' }}</p>
                <p class="ph-hero-title">{{ $hero['title'] ?? '' }}</p>

                {{-- Pharmacy left, distributor right. --}}
                <div class="ph-columns">
                    @foreach ($sides as $side => $label)
                        @continue(empty($hero[$side]))

                        <div class="ph-column ph-column--{{ $side }}">
                            <p class="text-label">{{ $label }}</p>

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
            @endif
        </aside>
    </div>
</body>
</html>
