<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">{{ $pharmacy->name }}</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Account {{ $pharmacy->account?->code }}
                    @if ($pharmacy->area) · {{ $pharmacy->area }} @endif
                </p>
            </div>
            <a href="{{ route('businesses.pharmacies.index', $business) }}"
               class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                All pharmacies
            </a>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-panel :title="'Statement — balance owed ' . $balance->format()">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach (['Date', 'Description', 'Debit', 'Credit', 'Balance'] as $h)
                                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ in_array($h, ['Date','Description']) ? 'text-left' : 'text-right' }} {{ $loop->first || $loop->last ? 'sm:px-6' : '' }}">{{ $h }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            @php($entry = $row['entry'])
                            <tr>
                                <td class="whitespace-nowrap px-4 py-2 text-gray-600 sm:px-6">{{ $entry->business_date->format('d M Y') }}</td>
                                <td class="px-4 py-2">
                                    <span class="font-medium text-gray-900">{{ $entry->transaction->type->label() }}</span>
                                    <p class="text-xs text-gray-500">
                                        #{{ $entry->transaction->id }}
                                        @if ($entry->transaction->narration) · {{ $entry->transaction->narration }} @endif
                                        · {{ $entry->transaction->creator->name }}
                                    </p>
                                </td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums">{{ $entry->debit->isZero() ? '' : $entry->debit->format() }}</td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums">{{ $entry->credit->isZero() ? '' : $entry->credit->format() }}</td>
                                <td class="px-4 py-2 text-right font-mono font-semibold tabular-nums sm:px-6">{{ $row['running']->format() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-10 text-center text-gray-500">No activity on this pharmacy yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-panel>
    </div>
</x-workspace-layout>
