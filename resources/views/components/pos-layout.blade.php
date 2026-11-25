@props(['business', 'title' => 'POS', 'mode' => null])

{{--
    The counter's own screen — and, with a mode, any other screen that is
    stood at and worked through: receiving stock uses it in its own colour
    (mode="receiving", see tokens.css).

    No workspace tabs: at a till the whole screen is the till, and the only way
    out is deliberate. Fills the viewport exactly, so the bill scrolls and the
    totals never leave the screen.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title }} — {{ $business->name }}</title>

        @include('partials.pharmaco-fonts')

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @stack('head')
    </head>
    <body class="side-{{ $business->business_type->value }} accent-{{ $business->accentColour()->value }} {{ $mode ? 'mode-'.$mode : '' }} h-full bg-app font-sans antialiased">
        <div class="flex h-full flex-col overflow-hidden">
            @include('partials.mirror-banner')
            {{ $slot }}
        </div>

        @stack('scripts')
    </body>
</html>
