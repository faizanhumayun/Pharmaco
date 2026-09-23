<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">{{ $company->name }}</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Account {{ $company->account?->code }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('businesses.products.index', ['business' => $business, 'company' => $company->id]) }}"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Catalogue ({{ number_format($productCount) }})
                </a>
                <a href="{{ route('businesses.companies.imports.index', [$business, $company]) }}"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Price lists
                </a>
                @can('importProducts', $business)
                    <a href="{{ route('businesses.companies.imports.create', [$business, $company]) }}"
                       class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                        Import price list
                    </a>
                @endcan
                <a href="{{ route('businesses.companies.index', $business) }}"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    All companies
                </a>
            </div>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        @if ($draftImport)
            <div class="mb-6 flex flex-wrap items-center gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <span>
                    <span class="font-semibold">{{ $draftImport->original_filename }}</span>
                    was read {{ $draftImport->created_at->diffForHumans() }} and is still waiting to be reviewed.
                    Nothing from it has reached the catalogue.
                </span>
                <a href="{{ route('businesses.companies.imports.show', [$business, $company, $draftImport]) }}"
                   class="font-semibold underline">Review it</a>
            </div>
        @endif

        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <x-stat label="Invoices" :value="number_format($purchases['invoices'])"
                    :hint="$purchases['last'] ? 'last ' . $purchases['last'] : 'none yet'" />
            <x-stat label="Purchased" :value="$purchases['billed']->format()" hint="On posted days" />
            <x-stat label="Paid" :value="$purchases['paid']->format()" />
            <x-stat label="Unpaid on invoices" :value="$purchases['outstanding']->format()" />
            <x-stat label="Balance owed" :value="$balance->format()" hint="From this company's ledger" />
        </div>

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
                            <tr><td colspan="5" class="px-6 py-10 text-center text-gray-500">No activity on this company yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-panel>
    </div>
</x-workspace-layout>
