{{--
    The accounts in a block that hold nothing, folded into one line that names
    them. Opening it draws them as ordinary lines, so each can still be opened.

    $lines: the same shape partials/line takes.
--}}
@if ($lines->isNotEmpty())
    <div x-data="{ open: @js($opened !== null && $lines->contains(fn ($l) => $l['account']->id === $opened->id)) }">
        <button type="button" x-on:click="open = ! open" :aria-expanded="open"
                class="block w-full px-4 py-3 text-left text-sm text-gray-500 hover:text-gray-900 sm:px-6">
            <span aria-hidden="true" x-text="open ? '−' : '+'"></span>
            {{ $lines->count() }} with nothing in {{ $lines->count() === 1 ? 'it' : 'them' }} · {{ $lines->pluck('name')->join(', ') }}
        </button>

        <div x-show="open" x-cloak class="border-t border-gray-100">
            @foreach ($lines as $line)
                @include('business.ledger.partials.line', ['line' => $line, 'noun' => $nouns[$line['code']] ?? 'account'])
            @endforeach
        </div>
    </div>
@endif
