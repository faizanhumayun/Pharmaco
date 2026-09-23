@props(['business'])

{{--
    The counter's own screen.

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

        <title>Counter — {{ $business->name }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full bg-slate-100 font-sans antialiased">
        <div class="flex h-full flex-col overflow-hidden">
            {{ $slot }}
        </div>

        @stack('scripts')
    </body>
</html>
