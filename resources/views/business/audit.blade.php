{{--
    Everything done in this business, newest first — the same timeline as a
    day's Change history, across every day, with what each step was about.
--}}
<x-workspace-layout :business="$business">
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">Activity</h1>
        <p class="mt-1 text-sm text-gray-500">
            Everything done in {{ $business->name }}, day by day: who did it, when, and what it was. These records cannot be edited or deleted.
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
                <span class="text-gray-500">{{ $activities->total() }} {{ Str::plural('record', $activities->total()) }}</span>
            </p>

            <x-filter-bar submit="Apply" :active="$event || $who || $range !== 'all'" clear="{{ route('businesses.audit', $business) }}" x-data="{ range: {{ Illuminate\Support\Js::from($range) }} }">
                <div>
                    <x-input-label for="event" value="Action" />
                    <select id="event" name="event"
                        class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    <option value="">All actions</option>
                    @foreach ($events as $value => $label)
                        <option value="{{ $value }}" @selected($event === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                </div>

                <div>
                    <x-input-label for="who" value="User" />
                    <select id="who" name="who"
                        class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    <option value="">All users</option>
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

        {{-- Day by day, newest first. Sales made one after another by the
             same person are one line that opens, so a busy counter does not
             bury everything else that happened. --}}
        @php
            $today = $business->today()->toDateString();
            $yesterday = $business->today()->copy()->subDay()->toDateString();

            $days = collect($activities->items())
                ->groupBy(fn ($item) => $item['at']->toDateString())
                ->map(function ($items) {
                    $rows = [];

                    foreach ($items as $item) {
                        $last = count($rows) - 1;

                        if (($item['event'] ?? null) === 'pos.sold' && $last >= 0 && $rows[$last]['type'] === 'sales'
                            && $rows[$last]['who'] === $item['who']) {
                            $rows[$last]['items'][] = $item;
                        } elseif (($item['event'] ?? null) === 'pos.sold') {
                            $rows[] = ['type' => 'sales', 'who' => $item['who'], 'items' => [$item]];
                        } else {
                            $rows[] = ['type' => 'one', 'item' => $item];
                        }
                    }

                    return ['rows' => $rows, 'counts' => $items->countBy('action')->sortDesc()];
                });
        @endphp

        @forelse ($days as $date => $day)
            @php($when = \Illuminate\Support\Carbon::parse($date))
            <x-panel class="mb-5">
                <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 border-b border-gray-200 bg-gray-50 px-4 py-3 sm:px-6">
                    <h2 class="text-base font-semibold text-gray-900">
                        {{ $date === $today ? 'Today' : ($date === $yesterday ? 'Yesterday' : $when->format('l')) }}
                        <span class="font-normal text-gray-500">· {{ $when->format('j F Y') }}</span>
                    </h2>
                    {{-- The day in a few words. --}}
                    <p class="text-sm text-gray-600">
                        @foreach ($day['counts']->take(5) as $action => $count)
                            <span class="whitespace-nowrap">{{ number_format($count) }} × {{ $action }}</span>@unless ($loop->last) <span class="text-gray-300">·</span> @endunless
                        @endforeach
                    </p>
                </div>

                <div class="divide-y divide-gray-100">
                    @foreach ($day['rows'] as $row)
                        @if ($row['type'] === 'one')
                            @include('business.partials.activity-row', ['item' => $row['item']])
                        @elseif (count($row['items']) === 1)
                            @include('business.partials.activity-row', ['item' => $row['items'][0]])
                        @else
                            @php($first = $row['items'][0])
                            @php($lastItem = $row['items'][count($row['items']) - 1])
                            @php($sum = \App\Support\Money::sum(collect($row['items'])->pluck('value')->filter()))
                            <div x-data="{ open: false }">
                                <button type="button" x-on:click="open = ! open" :aria-expanded="open"
                                        class="block w-full px-4 py-3 text-left hover:bg-gray-50 sm:px-6">
                                    <span class="grid grid-cols-[4.5rem_minmax(0,1fr)] items-start gap-x-4 gap-y-1 lg:grid-cols-[4.5rem_11rem_minmax(0,1fr)_auto]">
                                        <span class="whitespace-nowrap pt-0.5 font-mono text-xs tabular-nums text-gray-500">{{ $first['at']->format('h:i A') }}</span>
                                        <span><x-badge classes="bg-emerald-50 text-emerald-800 ring-emerald-600/20">{{ count($row['items']) }} sales</x-badge></span>
                                        <span class="col-span-2 min-w-0 text-sm lg:col-span-1">
                                            <span class="font-semibold text-gray-900">{{ count($row['items']) }} bills · Rs. {{ $sum->format() }}</span>
                                            <span class="text-gray-600">· from {{ $lastItem['at']->format('h:i A') }} to {{ $first['at']->format('h:i A') }}</span>
                                            <span class="block text-xs font-medium text-emerald-700" x-text="open ? 'Hide the bills' : 'Show each bill'"></span>
                                        </span>
                                        <span class="col-span-2 whitespace-nowrap text-xs text-gray-500 lg:col-span-1 lg:text-right">
                                            by <span class="font-medium text-gray-900">{{ $row['who'] }}</span>
                                        </span>
                                    </span>
                                </button>

                                <div x-show="open" x-cloak class="divide-y divide-gray-100 border-t border-gray-100 bg-gray-50/60">
                                    @foreach ($row['items'] as $item)
                                        @include('business.partials.activity-row', ['item' => $item])
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </x-panel>
        @empty
            <div class="rounded-md border border-gray-200 bg-white px-6 py-12 text-center shadow-sm">
                <p class="text-base font-medium text-gray-900">No activity found</p>
                <p class="mt-1 text-sm text-gray-500">Nothing matches the action, user and period selected.</p>
            </div>
        @endforelse

        <div class="mt-4">{{ $activities->links() }}</div>
    </div>
</x-workspace-layout>
