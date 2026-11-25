{{-- Whether this day is closed, or waits on an earlier one. Needs $closeState, $day, $business. --}}
@if ($closeState['isClosed'])
    <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 sm:px-6">
        <p class="font-semibold">This day is closed.</p>
        <p class="mt-0.5">
            Closed by <span class="font-medium">{{ $closeState['closedBy'] ?? '—' }}</span> on {{ $closeState['closedAt'] }}.
            The figures are final. Nothing can be posted on or before {{ $day->format('d M Y') }}.
            @if ($closeState['canReopen'])
                Use <strong>Reopen day</strong> at the top to change it.
            @elseif (! $closeState['isLatestClosed'])
                Later days are also closed. Reopen them first, starting from the newest.
            @endif
        </p>
    </div>
@elseif ($closeState['closable'] && $closeState['dueLater'])
    {{-- Days close in order: say so, and offer the way there. --}}
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <p><strong class="font-semibold">{{ $closeState['due']->format('D d M') }} is still open.</strong> Days are closed in order, so close it first. You can still look at this day.</p>
        <a href="{{ route('businesses.daily.day', [$business, $closeState['due']->toDateString(), 'section' => 'standing']) }}"
           class="rounded-md bg-amber-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-amber-800">Go to {{ $closeState['due']->format('d M') }}</a>
    </div>
@endif
