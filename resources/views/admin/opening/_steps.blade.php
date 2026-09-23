@props(['current' => 2])

<ol class="mb-8 flex flex-wrap items-center gap-x-2 gap-y-2 text-sm">
    @foreach ([1 => 'Business information', 2 => 'Opening financial position', 3 => 'Review'] as $number => $label)
        <li class="flex items-center gap-2">
            <span @class([
                'flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold',
                'bg-emerald-700 text-white' => $number <= $current,
                'bg-gray-200 text-gray-500' => $number > $current,
            ])>
                {{ $number < $current ? '✓' : $number }}
            </span>
            <span @class([
                'font-medium text-gray-900' => $number === $current,
                'text-gray-500' => $number !== $current,
            ])>{{ $label }}</span>
        </li>
        @unless ($loop->last)
            <li aria-hidden="true" class="mx-1 text-gray-300">→</li>
        @endunless
    @endforeach
</ol>
