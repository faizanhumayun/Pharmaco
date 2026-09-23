@props(['classes' => 'bg-gray-100 text-gray-700 ring-gray-500/20'])

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset {$classes}"]) }}>
    {{ $slot }}
</span>
