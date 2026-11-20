@props(['any' => false])

{{--
    What just happened, as a toast.

    Every page already carries <x-flash />, so this is the one place the
    message is drawn: a dark grey card at the top right of the window, with a
    green or red edge for good news or a refusal, over whatever is
    there, that takes no room in the page. Good news leaves on its own after a
    few seconds; a refusal stays until it is closed, because it has to be read.

    $any: also show the first validation error, for the screens that have no
    field to put one beside (the till, receiving stock).
--}}
@php
    $toasts = array_values(array_filter([
        session('status') && is_string(session('status')) ? ['ok', session('status')] : null,
        $errors->has('status') ? ['bad', $errors->first('status')] : null,
        $any && $errors->any() && ! $errors->has('status') ? ['bad', $errors->first()] : null,
    ]));
@endphp

@if ($toasts !== [])
    <div class="pointer-events-none fixed inset-x-0 top-4 z-[80] flex flex-col items-end gap-2 px-4 print:hidden sm:px-6" aria-live="polite">
        @foreach ($toasts as [$kind, $message])
            <div x-data="{ open: false }"
                 x-init="$nextTick(() => open = true); @if ($kind === 'ok') setTimeout(() => open = false, 6000) @endif"
                 x-show="open" x-cloak
                 x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-x-4 opacity-0" x-transition:enter-end="translate-x-0 opacity-100"
                 x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 role="{{ $kind === 'ok' ? 'status' : 'alert' }}"
                 @class([
                     'pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border-l-4 bg-gray-800 px-4 py-3 shadow-lg',
                     'border-emerald-500' => $kind === 'ok',
                     'border-red-500' => $kind === 'bad',
                 ])>
                <span @class([
                    'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white',
                    'bg-emerald-600' => $kind === 'ok',
                    'bg-red-600' => $kind === 'bad',
                ]) aria-hidden="true">{{ $kind === 'ok' ? '✓' : '!' }}</span>
                <p class="min-w-0 flex-1 text-sm leading-snug text-white">{{ $message }}</p>
                <button type="button" x-on:click="open = false" title="Close"
                        class="-mr-1 shrink-0 rounded p-0.5 text-lg leading-none text-gray-400 hover:bg-gray-700 hover:text-white">&times;</button>
            </div>
        @endforeach
    </div>
@endif
