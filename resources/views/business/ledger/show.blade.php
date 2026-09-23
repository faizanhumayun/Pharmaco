<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">
                    <span class="font-mono text-base text-gray-500">{{ $account->code }}</span>
                    {{ $account->name }}
                </h1>
                <p class="mt-1 text-sm text-gray-500">{{ $account->type->label() }} account</p>
            </div>
            <a href="{{ route('businesses.ledger', $business) }}"
               class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                All accounts
            </a>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-panel :title="'Statement — closing balance ' . $closing->format()">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Date</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Description</th>
                            <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Debit</th>
                            <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Credit</th>
                            <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($rows as $row)
                            @php($entry = $row['entry'])
                            @php($txn = $entry->transaction)
                            <tr>
                                <td class="px-4 py-2 tabular-nums text-gray-600 sm:px-6">
                                    {{ $entry->business_date->format('d M Y') }}
                                    @if ($txn->wasPostedLate())
                                        <span class="block text-xs text-amber-700"
                                              title="Posted after its own day was closed">
                                            for {{ $txn->original_business_date->format('d M Y') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    <span class="font-medium text-gray-900">{{ $txn->type->label() }}</span>
                                    <x-badge :classes="$txn->status->badgeClasses()" class="ml-1">{{ $txn->status->label() }}</x-badge>
                                    <p class="text-xs text-gray-500">
                                        #{{ $txn->id }}
                                        @if ($account->children->isNotEmpty() || $entry->account_id !== $account->id)
                                            · {{ $entry->account->name }}
                                        @endif
                                        @if ($txn->narration) · {{ $txn->narration }} @endif
                                        @if ($txn->correction_reason)
                                            · <span class="text-amber-700">{{ $txn->correction_reason }}</span>
                                        @endif
                                        · entered by {{ $txn->creator->name }}
                                    </p>
                                </td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900">
                                    {{ $entry->debit->isZero() ? '' : $entry->debit->format() }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900">
                                    {{ $entry->credit->isZero() ? '' : $entry->credit->format() }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums sm:px-6
                                           {{ $row['running']->isNegative() ? 'text-red-700' : 'text-gray-900' }}">
                                    {{ $row['running']->format() }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-10 text-center text-gray-500">No entries on this account.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-panel>
    </div>
</x-workspace-layout>
