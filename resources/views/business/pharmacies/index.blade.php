<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Customers</h1>
                <p class="mt-1 text-sm text-gray-500">
                    The pharmacies and stores you sell to. Each keeps its own ledger of what it owes.
                </p>
            </div>
            @can('configure', $business)
                <button type="button" x-data x-on:click="$dispatch('open-drawer', 'add-pharmacy')"
                        class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    Add a customer
                </button>
            @endcan
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        <div class="mb-6 flex flex-wrap items-center justify-between gap-x-6 gap-y-3 rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm sm:px-6">
            <p class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-base font-semibold text-gray-900">{{ number_format($pharmacies->total()) }} {{ Str::plural('customer', $pharmacies->total()) }}</span>
                <span class="text-gray-500">· the market owes</span>
                <span class="font-semibold tabular-nums text-gray-900">Rs. {{ $total->format() }}</span>
                <x-badge :classes="$reconciles ? 'bg-emerald-50 text-emerald-800 ring-emerald-600/20' : 'bg-red-50 text-red-800 ring-red-600/20'">
                    {{ $reconciles ? 'Adds up across customers ✓' : 'Does not add up across customers' }}
                </x-badge>
                @unless ($unallocated->isZero())
                    <span class="text-gray-500">· <span class="tabular-nums">{{ $unallocated->format() }}</span> not under any customer</span>
                @endunless
            </p>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label for="q" class="sr-only">Search</label>
                <input id="q" name="q" type="search" value="{{ $search }}" placeholder="Search name, area or phone"
                       class="w-56 rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                <button class="rounded-md bg-gray-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-800">Search</button>
                @if ($search !== '')
                    <a href="{{ route('businesses.pharmacies.index', $business) }}" class="text-sm text-gray-500 hover:text-gray-800">Clear</a>
                @endif
            </form>
        </div>

        <div class="grid gap-6">
            <div>
                <x-panel>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    @foreach (['Pharmacy', 'Account', 'Area', 'Credit days', 'Owes'] as $h)
                                        <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ $h === 'Owes' ? 'text-right' : 'text-left' }} {{ $loop->first ? 'sm:px-6' : '' }}">{{ $h }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($pharmacies as $pharmacy)
                                    <tr>
                                        <td class="px-4 py-2 sm:px-6">
                                            <a href="{{ route('businesses.pharmacies.show', [$business, $pharmacy]) }}"
                                               class="font-medium text-gray-900 hover:text-emerald-700">{{ $pharmacy->name }}</a>
                                        </td>
                                        <td class="px-4 py-2 font-mono text-xs text-gray-500">{{ $pharmacy->account?->code ?? '—' }}</td>
                                        <td class="px-4 py-2 text-gray-600">{{ $pharmacy->area ?? '—' }}</td>
                                        <td class="px-4 py-2 text-gray-600">{{ $pharmacy->credit_days ?? '—' }}</td>
                                        {{-- A pharmacy in credit has paid ahead; showing it red would read as a problem. --}}
                                        <td class="px-4 py-2 text-right font-mono tabular-nums {{ $balances[$pharmacy->id]->isNegative() ? 'text-emerald-800' : 'text-gray-900' }}">
                                            {{ $balances[$pharmacy->id]->format() }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-6 py-10 text-center text-gray-500">
                                            No pharmacies yet. Until one exists, receivables are tracked as a single total.
                                            Naming a pharmacy on a daily entry creates it here.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-panel>
            </div>

        </div>

        <div class="mt-4">{{ $pharmacies->links() }}</div>

        @include('business.partials.add-pharmacy-drawer', ['afterAdd' => 'reload'])
    </div>
</x-workspace-layout>
