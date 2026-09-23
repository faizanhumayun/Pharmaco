@props(['title' => null, 'description' => null])

<div {{ $attributes->merge(['class' => 'overflow-hidden bg-white shadow-sm sm:rounded-lg']) }}>
    @if ($title)
        <div class="border-b border-gray-200 px-4 py-4 sm:px-6">
            <h2 class="text-base font-semibold text-gray-900">{{ $title }}</h2>
            @if ($description)
                <p class="mt-1 text-sm text-gray-500">{{ $description }}</p>
            @endif
        </div>
    @endif

    {{ $slot }}
</div>
