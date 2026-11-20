{{-- What an import did, kept after the file itself has gone. --}}
@php($r = $import->result ?? [])
@php($rs = fn ($v) => \App\Support\Money::of($v ?? '0')->format())
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Imported from {{ $import->original_name }}</h1>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $business->name }} ·
                    {{ $import->imported_at?->timezone($business->timezone)->format('l j F Y, h:i A') }}
                </p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('admin.businesses.import.create', $business) }}"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Import another file</a>
                <a href="{{ route('admin.businesses.show', $business) }}"
                   class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Back to the business</a>
            </div>
        </div>
    </x-slot>

    <div class="w-full space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <x-stat label="Companies added" :value="number_format($r['companies'] ?? 0)" />
            <x-stat label="Customers added" :value="number_format($r['customers'] ?? 0)" />
            <x-stat label="Products added" :value="number_format($r['products'] ?? 0)" />
            <x-stat label="Stock brought in" :value="number_format($r['stock_packs'] ?? 0)"
                    :hint="number_format($r['stock_lines'] ?? 0).' products'" />
            <x-stat label="Balances named" :value="number_format(($r['customer_balances'] ?? 0) + ($r['company_balances'] ?? 0))"
                    :hint="'Customers Rs. '.$rs($r['owed_by_customers'] ?? '0').' · companies Rs. '.$rs($r['owed_to_companies'] ?? '0')" />
        </div>

@php($kept = $r['kept_balances'] ?? null)
        @php($keptCount = $kept ? count($kept['customers'] ?? []) + count($kept['companies'] ?? []) : 0)
        @php($keptTotal = fn ($rows) => array_reduce($rows ?? [], fn ($t, $row) => $t->plus($row['balance']), \App\Support\Money::zero()))

        <x-panel title="What to do next">
            <ol class="list-decimal space-y-3 px-8 py-4 text-sm text-gray-700">
                @if ($business->opening_date === null)
                    <li>
                        <strong class="text-gray-900">Check and finalise the opening balance.</strong>
                        @if ($r['opening_prefilled'] ?? false)
                            The draft has been filled in from this file: the stock that came in at cost, what customers owed in
                            total and what was owed to companies in total. Add cash and anything else, check the figures against
                            statements, then finalise.
                        @else
                            Enter the figures and finalise it.
                        @endif
                        <a class="font-medium text-emerald-700 hover:text-emerald-800" href="{{ route('admin.businesses.opening.edit', $business) }}">Open the opening balance →</a>
                    </li>
                @endif

                @if ($keptCount > 0)
                    <li>
                        <strong class="text-gray-900">Bring over who owes what.</strong>
                        {{ number_format(count($kept['customers'] ?? [])) }} customers owed Rs. {{ $rs($keptTotal($kept['customers'] ?? [])->toDecimal()) }}
                        and {{ number_format(count($kept['companies'] ?? [])) }} companies were owed Rs. {{ $rs($keptTotal($kept['companies'] ?? [])->toDecimal()) }}
                        in the old system. These were kept by name and can be put onto each account without uploading the file again.
                        @if ($business->acceptsTransactions())
                            <form method="POST" action="{{ route('admin.businesses.import.balances', [$business, $import]) }}" class="mt-2">
                                @csrf
                                <button class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                                    Bring over the balances
                                </button>
                                <span class="ml-2 text-xs text-gray-500">The totals owed do not change; each amount moves from “Unallocated” to its own account.</span>
                            </form>
                        @else
                            <span class="mt-1 block text-amber-700">Available as soon as the opening balance is finalised.</span>
                        @endif
                    </li>
                @endif

                <li>The uploaded file has been removed from the server.</li>
            </ol>
        </x-panel>
    </div>
</x-app-layout>
