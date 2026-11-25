{{--
    The way back to the list a page belongs to, above its title: "‹ All
    companies". The same on every page that shows one record, so going back
    is always in the same place and never depends on the browser's button.
--}}
@props(['href', 'label'])

<a href="{{ $href }}" {{ $attributes->merge(['class' => 'block w-fit text-xs font-medium text-gray-600 hover:text-emerald-800 hover:underline']) }}>
    <span aria-hidden="true">‹</span> {{ $label }}
</a>
