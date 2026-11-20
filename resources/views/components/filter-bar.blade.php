{{--
    The one search-and-filter bar, used on every list in the product.

    Use it as the x-filter-bar component, with each field inside as a label
    above its control. Props:

        submit   the button's word ("Filter", "Search", "Apply"); empty for a
                 bar whose control submits by itself
        active   whether anything is filtered right now
        clear    where Clear goes — the same list with nothing filtered
        count    slot: what is showing, e.g. "656 of 656 products"

    It draws the same thing everywhere: a bordered bar with the fields in a row,
    the button that applies them, and — only while something is filtered — a
    Clear button that is plainly a button, not a faint link. The count sits on
    its own line under the bar, beside the list it counts, rather than squeezed
    in among the controls.

    Any other attribute (x-data, id…) is passed through to the form element.
--}}
@props(['submit' => 'Filter', 'active' => false, 'clear' => null, 'count' => null])

<div class="{{ $attributes->get('class') }}">
    {{-- The fields share the whole width of the bar: each labelled block
         grows, and the box inside it fills the block, whatever width the page
         asked for. A tick box and the buttons keep their own size. A wrapper
         a page puts round some fields (a template, a span) is looked through. --}}
    <form method="GET" {{ $attributes->except('class') }}
          class="flex flex-wrap items-end gap-3 rounded-md border border-line bg-white px-4 py-3
                 [&>span]:contents
                 [&>div]:min-w-[11rem] [&>div]:flex-1 [&>span>div]:min-w-[11rem] [&>span>div]:flex-1
                 [&_div>input:not([type=checkbox]):not([type=radio])]:!w-full
                 [&_div>select]:!w-full [&_[data-kebab-wrap]]:!block [&_[data-kebab-wrap]]:!w-full [&_[data-date-wrap]]:!w-full">
        {{ $slot }}

        @if ($submit !== '' || ($active && $clear))
            <div class="flex !min-w-0 !flex-none items-center gap-2">
                @if ($submit !== '')
                    <button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2">
                        {{ $submit }}
                    </button>
                @endif

                @if ($active && $clear)
                    <a href="{{ $clear }}"
                       class="inline-flex items-center gap-1.5 rounded-md border border-gray-900 bg-white px-4 py-2 text-sm font-semibold text-gray-900 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2">
                        <span aria-hidden="true">&times;</span> Clear
                    </a>
                @endif
            </div>
        @endif
    </form>

    @if ($count !== null && trim((string) $count) !== '')
        <p class="mt-2 px-1 text-sm text-gray-500">{{ $count }}</p>
    @endif
</div>
