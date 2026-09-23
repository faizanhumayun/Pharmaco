@props(['label', 'value', 'hint' => null])

<div class="rounded-lg bg-white px-4 py-4 shadow-sm">
    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</dt>
    <dd class="mt-1 text-xl font-semibold tabular-nums text-gray-900 xl:text-2xl">{{ $value }}</dd>
    @if ($hint)
        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif
</div>
