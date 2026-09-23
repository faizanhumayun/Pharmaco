@props([
    'name',
    'title' => null,
    'subtitle' => null,
    'show' => false,
    'maxWidth' => 'md',
])

@php
    $panelWidth = [
        'sm' => 'max-w-sm',
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
        'xl' => 'max-w-xl',
        '2xl' => 'max-w-2xl',
    ][$maxWidth];
@endphp

{{--
    A side panel, addressed by name from anywhere:

        <button x-on:click="$dispatch('open-drawer', 'pharmacy-sale')">
        <x-drawer name="pharmacy-sale" title="Add a pharmacy sale"> … </x-drawer>

    Preferred over <x-modal> for entering a record: it is tall rather than wide,
    so a column of fields needs no scrolling, and it leaves the figures on the
    page behind it visible while you type. Modals are for short confirmations,
    where covering the page is the point.

    Slots: the default one is the body, $footer is pinned to the bottom.
--}}
<div
    x-data="{
        show: @js($show),
        close() { this.show = false },
    }"
    x-on:open-drawer.window="$event.detail == '{{ $name }}' ? show = true : null"
    x-on:close-drawer.window="$event.detail == '{{ $name }}' ? show = false : null"
    x-on:close.stop="close()"
    x-on:keydown.escape.window="close()"
    x-init="$watch('show', value => document.body.classList.toggle('overflow-y-hidden', value))"
    x-show="show"
    class="fixed inset-0 z-50 overflow-hidden"
    style="display: none;"
    role="dialog"
    aria-modal="true"
>
    <div
        x-show="show"
        x-on:click="close()"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="absolute inset-0 bg-gray-500/75"
    ></div>

    <div class="pointer-events-none absolute inset-y-0 right-0 flex max-w-full pl-10">
        <div
            x-show="show"
            x-transition:enter="transform transition ease-in-out duration-300"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transform transition ease-in-out duration-200"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            {{ $attributes->merge(['class' => "pointer-events-auto w-screen {$panelWidth}"]) }}
        >
            <div class="flex h-full flex-col bg-white shadow-xl">
                @if ($title || $subtitle)
                    <div class="flex items-start justify-between border-b border-gray-200 px-4 py-4 sm:px-6">
                        <div>
                            @if ($title)
                                <h2 class="text-base font-semibold text-gray-900">{{ $title }}</h2>
                            @endif
                            @if ($subtitle)
                                <p class="mt-0.5 text-sm text-gray-500">{{ $subtitle }}</p>
                            @endif
                        </div>
                        <button type="button" x-on:click="close()"
                                class="-mr-1 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                                aria-label="Close">
                            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M5 5l10 10M15 5L5 15" stroke-linecap="round" />
                            </svg>
                        </button>
                    </div>
                @endif

                <div class="flex-1 overflow-y-auto px-4 py-5 sm:px-6">
                    {{ $slot }}
                </div>

                @isset($footer)
                    <div class="border-t border-gray-200 px-4 py-4 sm:px-6">
                        {{ $footer }}
                    </div>
                @endisset
            </div>
        </div>
    </div>
</div>
