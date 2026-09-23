<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white shadow">
                    <div class="w-full py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>

        <script>
            /*
             * A workspace opens in its own window, sized to the screen, so the
             * back office stays where it was. Naming the window per business
             * means re-opening the same one focuses it instead of stacking
             * duplicates. Opened by script so its Exit button can close it.
             */
            function openWorkspace(event, link, slug) {
                event.preventDefault();

                const width = Math.min(screen.availWidth, 1680);
                const height = Math.min(screen.availHeight, 1050);
                const left = Math.max(0, Math.round((screen.availWidth - width) / 2));

                const win = window.open(
                    link.href,
                    'pharmaco-workspace-' + slug,
                    `width=${width},height=${height},left=${left},top=0`
                );

                // Popup blocked: fall through to a normal navigation rather than
                // leaving the click doing nothing.
                if (win) { win.focus(); } else { window.location = link.href; }

                return false;
            }
        </script>

        @stack('scripts')
    </body>
</html>
