{{-- One report: a date range where it has one, a table, and its totals. --}}
@php($show = fn ($value, $type) => $value instanceof \App\Support\Money ? $value->format() : ($type === 'number' ? number_format((float) $value) : $value))
<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm text-gray-500">
                    <a href="{{ route('businesses.reports.index', $business) }}" class="hover:text-emerald-700 hover:underline">Reports</a> · {{ $meta['group'] }}
                </p>
                <h1 class="text-xl font-semibold text-gray-900">{{ $meta['title'] }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $meta['about'] }}</p>
            </div>
            @can('exportReports', $business)
                <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Download as spreadsheet (CSV)
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="w-full space-y-4 px-4 py-8 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-end justify-between gap-4">
            @if ($meta['dated'])
                <x-filter-bar submit="Show">
                    <div>
                        <x-input-label for="from" value="From" />
                        <input id="from" type="date" name="from" value="{{ $from->toDateString() }}"
                               class="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <x-input-label for="to" value="To" />
                        <input id="to" type="date" name="to" value="{{ $to->toDateString() }}"
                               class="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    @php($today = $business->today())
                    @foreach ([
                        'Today' => [$today, $today],
                        'This month' => [$today->copy()->startOfMonth(), $today],
                        'Last month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
                    ] as $label => [$a, $b])
                        <a href="{{ route('businesses.reports.show', [$business, $key, 'from' => $a->toDateString(), 'to' => $b->toDateString()]) }}"
                           class="rounded-md px-2 py-2 text-sm font-medium text-emerald-700 hover:underline">{{ $label }}</a>
                    @endforeach
                    <x-slot name="count">{{ number_format(count($table['rows'])) }} {{ Str::plural('row', count($table['rows'])) }}</x-slot>
                </x-filter-bar>
            @else
                <p class="text-sm text-gray-500">As it stands now, {{ $business->today()->format('j F Y') }}.</p>
            @endif

        </div>

        <x-panel>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach ($table['columns'] as $column)
                                <th class="whitespace-nowrap px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ $column['type'] === 'text' ? 'text-left' : 'text-right' }} {{ $loop->first ? 'sm:pl-6' : '' }} {{ $loop->last ? 'sm:pr-6' : '' }}">
                                    {{ $column['label'] }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($table['rows'] as $row)
                            <tr class="hover:bg-gray-50">
                                @foreach ($table['columns'] as $column)
                                    <td class="px-3 py-2 {{ $column['type'] === 'text' ? 'text-gray-900' : 'whitespace-nowrap text-right font-mono tabular-nums text-gray-900' }} {{ $loop->first ? 'font-medium sm:pl-6' : '' }} {{ $loop->last ? 'sm:pr-6' : '' }}">
                                        {{ $show($row[$column['key']] ?? '', $column['type']) }}
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($table['columns']) }}" class="px-6 py-10 text-center text-gray-500">
                                    Nothing to show{{ $meta['dated'] ? ' for these dates' : '' }}.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($table['rows'] !== [] && $table['totals'] !== [])
                        <tfoot class="border-t-2 border-gray-300 bg-gray-50">
                            <tr>
                                @foreach ($table['columns'] as $column)
                                    <td class="px-3 py-2.5 font-semibold {{ $column['type'] === 'text' ? 'text-gray-900' : 'whitespace-nowrap text-right font-mono tabular-nums text-gray-900' }} {{ $loop->first ? 'sm:pl-6' : '' }} {{ $loop->last ? 'sm:pr-6' : '' }}">
                                        @if ($loop->first) Total
                                        @elseif (array_key_exists($column['key'], $table['totals'])) {{ $show($table['totals'][$column['key']], $column['type']) }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </x-panel>
    </div>
</x-workspace-layout>
