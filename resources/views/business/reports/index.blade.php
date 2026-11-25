{{-- Every report in one place, grouped the way the work is. --}}
<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Reports</h1>
            <p class="mt-1 text-sm text-gray-500">Sales, purchase and stock reports. Filter by date and download as a spreadsheet.</p>
        </div>
    </x-slot>

    <div class="w-full space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        @foreach ($groups as $group => $reports)
            <x-panel :title="$group">
                {{-- Hairlines drawn by the cells themselves, so a row that is one
                     cell short stays white instead of showing the gap colour. --}}
                <div class="-mb-px -mr-px grid sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($reports as $report)
                        <a href="{{ route('businesses.reports.show', [$business, $report['key']]) }}"
                           class="block border-b border-r border-line px-4 py-4 hover:bg-gray-50 sm:px-6">
                            <p class="font-medium text-gray-900">{{ $report['title'] }}</p>
                            <p class="mt-1 text-sm leading-snug text-gray-500">{{ $report['about'] }}</p>
                        </a>
                    @endforeach
                    @if ($group === 'Stock')
                        @can('viewProducts', $business)
                            <a href="{{ route('businesses.stock.expiring', $business) }}" class="block border-b border-r border-line px-4 py-4 hover:bg-gray-50 sm:px-6">
                                <p class="font-medium text-gray-900">Near expiry</p>
                                <p class="mt-1 text-sm leading-snug text-gray-500">Expired stock and stock near expiry, by batch.</p>
                            </a>
                        @endcan
                    @endif
                </div>
            </x-panel>
        @endforeach

        @can('viewReports', $business)
            <p class="text-sm text-gray-500">
                For profit and position over time, see
                <a href="{{ route('businesses.history', $business) }}" class="font-medium text-emerald-700 hover:underline">History</a>.
                For the entries of any account, see the
                <a href="{{ route('businesses.ledger', $business) }}" class="font-medium text-emerald-700 hover:underline">Ledger</a>.
            </p>
        @endcan
    </div>
</x-workspace-layout>
