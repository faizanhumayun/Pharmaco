{{--
    A product's supplier, in a list. Delivered by more than one: the one with
    the best rate, a count of the others, and the others listed on hover over
    the count. The list is placed on the screen, not in the table, so a
    scrolling table never cuts it off.
--}}
@props(['product', 'from' => null])

@if ($from)
    @php($best = $from->first())
    <span>{{ $best['company']->name }}</span>
    <span x-data="{ open: false, x: 0, y: 0 }" class="inline-block">
        <button type="button"
                x-on:mouseenter="const r = $el.getBoundingClientRect(); x = r.left; y = r.bottom + 4; open = true"
                x-on:mouseleave="open = false"
                x-on:focus="const r = $el.getBoundingClientRect(); x = r.left; y = r.bottom + 4; open = true"
                x-on:blur="open = false"
                class="ml-1 cursor-default rounded-full bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600 hover:bg-gray-200">+{{ $from->count() - 1 }}</button>
        <template x-teleport="body">
            <div x-show="open" x-cloak :style="`left:${x}px; top:${y}px`"
                 class="pointer-events-none fixed z-50 min-w-[14rem] rounded-md border border-gray-200 bg-white py-1.5 text-xs shadow-lg">
                <p class="px-3 pb-1 font-semibold uppercase tracking-wide text-gray-500">Also supplied by</p>
                @foreach ($from->slice(1) as $other)
                    <div class="flex justify-between gap-4 px-3 py-0.5">
                        <span class="text-gray-900">{{ $other['company']->name }}</span>
                        <span class="font-mono tabular-nums text-gray-600">{{ $other['rate']?->format() ?? '—' }}</span>
                    </div>
                @endforeach
                <p class="mt-1 border-t border-gray-100 px-3 pt-1 text-gray-500">Rate of each one's latest delivery</p>
            </div>
        </template>
    </span>
    @if ($best['rate'])
        <div class="text-xs text-emerald-700">Best rate {{ $best['rate']->format() }}</div>
    @endif
@else
    {{ $product->company?->name ?? '—' }}
@endif
