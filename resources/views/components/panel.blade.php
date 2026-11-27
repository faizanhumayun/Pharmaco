@props(['title' => null, 'description' => null])

<div {{ $attributes->merge(['class' => 'overflow-hidden border-y border-line bg-white sm:rounded-lg sm:border']) }}>
    @if ($title)
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 px-4 py-4 sm:px-6">
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-gray-900">{{ $title }}</h2>
                @if ($description)
                    <p class="mt-1 text-sm text-gray-500">{{ $description }}</p>
                @endif
            </div>
            {{-- Buttons for what the panel holds, on the right of its heading. --}}
            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    {{ $slot }}
</div>
