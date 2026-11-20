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

            <x-filter-bar submit="Apply" :active="$event || $who || $range !== 'all'" clear="{{ route('businesses.audit', $business) }}" x-data="{ range: {{ Illuminate\Support\Js::from($range) }} }">
                <div>
                    <x-input-label for="event" value="What happened" />
                    <select id="event" name="event"
                        class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    <option value="">Everything</option>
                    @foreach ($events as $value => $label)
                        <option value="{{ $value }}" @selected($event === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                </div>

                <div>
                    <x-input-label for="who" value="Who" />
                    <select id="who" name="who"
                        class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    <option value="">Anyone</option>
                    @foreach ($people as $person)
                        <option value="{{ $person->id }}" @selected($who?->is($person))>{{ $person->name }}</option>
                    @endforeach
                </select>
                </div>

                <div>
                    <x-input-label for="range" value="Period" />
                    <select id="range" name="range" x-model="range"
                        class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach ($ranges as $value => $label)
                        <option value="{{ $value }}" @selected($range === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                </div>

                <template x-if="range === 'custom'">
                    <span class="flex items-end gap-2">
                        <div>
                            <x-input-label for="from" value="From" />
                            <input id="from" name="from" type="date" value="{{ $from?->toDateString() }}"
                               class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                        </div>
                        <span class="text-sm text-gray-400">to</span>
                        <div>
                            <x-input-label for="to" value="To" />
                            <input id="to" name="to" type="date" value="{{ $to?->toDateString() }}"
                               class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                        </div>
                    </span>
                </template>
            </x-filter-bar>
        </div>

        @include('business.partials.change-history', [
            'history' => $activities,
            'panelTitle' => 'Timeline',
            'panelDescription' => 'Newest first. Each step shows who did it, their role, when, what it was about, and the reason they gave.',
        ])

        <div class="mt-4">{{ $activities->links() }}</div>
    </div>
</x-workspace-layout>
