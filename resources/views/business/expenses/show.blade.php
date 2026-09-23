<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">{{ $category->name }}</h1>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $hasOwnLedger ? 'Account ' . $category->account->code : 'No ledger of its own yet' }}
                </p>
            </div>
            <a href="{{ route('businesses.expenses.index', $business) }}"
               class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                All heads
            </a>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        @unless ($hasOwnLedger)
            <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Spending is still tracked as a single Operating Expenses total, so this head has no
                statement of its own. Naming a head on a daily entry splits the ledger and this
                fills in from that day forward.
            </div>
        @endunless

        <x-panel :title="'Statement — spent to date ' . $balance->format()">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach (['Date', 'Description', 'Debit', 'Credit', 'Running total'] as $h)
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
                            <tr><td colspan="5" class="px-6 py-10 text-center text-gray-500">Nothing spent under this head yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-panel>
    </div>
</x-workspace-layout>
