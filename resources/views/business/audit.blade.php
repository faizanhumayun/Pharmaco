{{--
    Everything done in this business, newest first — the same timeline as a
    day's Change history, across every day, with what each step was about.
--}}
<x-workspace-layout :business="$business">
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">Activity</h1>
        <p class="mt-1 text-sm text-gray-500">
            Everything anyone has done in {{ $business->name }}. Nothing here can be edited or deleted.
        </p>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        {{-- Summary and filters on one line. --}}
        <div class="mb-6 flex flex-wrap items-center justify-between gap-x-6 gap-y-3 rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm sm:px-6">
            <p class="text-sm">
                <span class="text-base font-semibold text-gray-900">{{ $event ? $events[$event] : 'All activity' }}</span>
                @if ($who)
                    <span class="text-gray-500">by {{ $who->name }}</span>
                @endif
                <span class="text-gray-500">· {{ $range === 'custom'
                    ? ($from?->format('d M Y') ?? 'start') . ' – ' . ($to?->format('d M Y') ?? 'today')
                    : $ranges[$range] }}</span>
                <span class="text-gray-300">|</span>
                <span class="text-gray-500">{{ $activities->total() }} {{ Str::plural('step', $activities->total()) }}</span>
            </p>

            <form method="GET" x-data="{ range: @js($range) }" class="flex flex-wrap items-center gap-2">
                <label for="event" class="sr-only">What happened</label>
                <select id="event" name="event"
                        class="rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    <option value="">Everything</option>
                    @foreach ($events as $value => $label)
                        <option value="{{ $value }}" @selected($event === $value)>{{ $label }}</option>
                    @endforeach
                </select>

                <label for="who" class="sr-only">Who</label>
                <select id="who" name="who"
                        class="rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    <option value="">Anyone</option>
                    @foreach ($people as $person)
                        <option value="{{ $person->id }}" @selected($who?->is($person))>{{ $person->name }}</option>
                    @endforeach
                </select>

                <label for="range" class="sr-only">Period</label>
                <select id="range" name="range" x-model="range"
                        class="rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach ($ranges as $value => $label)
                        <option value="{{ $value }}" @selected($range === $value)>{{ $label }}</option>
                    @endforeach
                </select>

                <template x-if="range === 'custom'">
                    <span class="flex items-center gap-2">
                        <label for="from" class="sr-only">From</label>
                        <input id="from" name="from" type="date" value="{{ $from?->toDateString() }}"
                               class="rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                        <span class="text-sm text-gray-400">to</span>
                        <label for="to" class="sr-only">To</label>
                        <input id="to" name="to" type="date" value="{{ $to?->toDateString() }}"
                               class="rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    </span>
                </template>

                <button class="rounded-md bg-gray-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-800">Apply</button>
                @if ($event || $who || $range !== 'all')
                    <a href="{{ route('businesses.audit', $business) }}" class="text-sm text-gray-500 hover:text-gray-800">Clear</a>
                @endif
            </form>
        </div>

        @include('business.partials.change-history', [
            'history' => $activities,
            'panelTitle' => 'Timeline',
            'panelDescription' => 'Newest first. Each step shows who did it, their role, when, what it was about, and the reason they gave.',
        ])

        <div class="mt-4">{{ $activities->links() }}</div>
    </div>
</x-workspace-layout>
