<x-workspace-layout :business="$business">
    {{-- No heading above the page: the tab that brought you here already
         says where you are. A heading is kept for screen readers. --}}
    <h1 class="sr-only">Customers</h1>

    <div class="w-full px-4 py-5 sm:px-6 lg:px-8">
        <x-flash />

        {{-- Two lists on a grey band: everyone, and those with something
             still to pay. Adding a customer sits at the end of the band. --}}
        @php($carried = array_filter(request()->only(['q', 'area']), fn ($v) => $v !== null && $v !== ''))
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-gray-200 p-1.5 ring-1 ring-gray-300">
            <nav class="flex flex-wrap gap-1.5" aria-label="Customer lists">
                @foreach ([
                    'all' => ['All customers', $customerCount, route('businesses.pharmacies.index', [$business] + $carried)],
                    'pending' => ['With pending amount', $pendingCount, route('businesses.pharmacies.index', [$business, 'show' => 'pending'] + $carried)],
                ] as $key => [$label, $count, $href])
                    @php($here = $show === $key)
                    <a href="{{ $href }}"
                       @class([
                           'inline-flex items-center gap-2 whitespace-nowrap rounded-md px-4 py-2 text-sm font-semibold transition',
                           'bg-white text-gray-900 shadow-sm ring-1 ring-gray-300' => $here,
                           'text-gray-600 hover:bg-gray-300 hover:text-gray-900' => ! $here,
                       ])
                       @if ($here) aria-current="page" @endif>
                        {{ $label }}
                        <span @class([
                            'rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums',
                            'bg-amber-500 text-white' => $key === 'pending' && $count > 0,
                            'bg-gray-100 text-gray-600' => $key !== 'pending' || $count === 0,
                        ])>{{ number_format($count) }}</span>
                    </a>
                @endforeach
            </nav>

            @can('configure', $business)
                <button type="button" x-data x-on:click="$dispatch('open-drawer', 'add-pharmacy')"
                        class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    Add a customer
                </button>
            @endcan
        </div>

        <x-filter-bar submit="Search" class="mb-4" :active="$search !== '' || $area !== ''"
                      clear="{{ route('businesses.pharmacies.index', array_filter([$business, 'show' => $show === 'pending' ? 'pending' : null])) }}">
            @if ($show === 'pending') <input type="hidden" name="show" value="pending"> @endif
            <div>
                <x-input-label for="q" value="Customer" />
                <input id="q" name="q" type="search" value="{{ $search }}" placeholder="Name, phone or code"
                       class="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <x-input-label for="f_area" value="Area" />
                <input id="f_area" name="area" type="search" value="{{ $area }}" placeholder="Any part of the area"
                       class="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <x-slot name="count">{{ number_format($pharmacies->total()) }} {{ Str::plural('customer', $pharmacies->total()) }} listed</x-slot>
        </x-filter-bar>

        {{-- The list in three figures, right above it. --}}
        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-gray-400">Customers listed</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ number_format($pharmacies->total()) }}</p>
                <p class="mt-0.5 text-xs text-gray-500">{{ number_format($pendingCount) }} with a pending amount</p>
            </div>

            <div class="rounded-md border px-4 py-3 shadow-sm {{ $listedPending->isPositive() ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50' }}">
                <p class="text-xs uppercase tracking-wide {{ $listedPending->isPositive() ? 'text-amber-700' : 'text-emerald-700' }}">Pending from these customers</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums {{ $listedPending->isPositive() ? 'text-amber-900' : 'text-emerald-800' }}">{{ $listedPending->format() }}</p>
                <p class="mt-0.5 text-xs {{ $listedPending->isPositive() ? 'text-amber-700' : 'text-emerald-700' }}">
                    {{ $listedPending->isPositive() ? 'still to be collected' : 'nothing to collect' }}
                </p>
            </div>

            <div class="rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-gray-400">Total pending in market</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-gray-900">{{ $total->format() }}</p>
                <p class="mt-0.5 text-xs">
                    <span class="font-medium {{ $reconciles ? 'text-emerald-700' : 'text-red-700' }}">{{ $reconciles ? 'Customer totals match ✓' : 'Customer totals do not match' }}</span>
                    @unless ($unallocated->isZero())
                        <span class="text-gray-500">· {{ $unallocated->format() }} not linked to any customer</span>
                    @endunless
                </p>
            </div>
        </div>

        <div class="grid gap-6">
            <div>
                <x-panel>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    @foreach (['Customer', 'Phone', 'Area', 'Credit days', 'Pending'] as $h)
                                        <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ $h === 'Pending' ? 'text-right' : 'text-left' }} {{ $loop->first ? 'sm:px-6' : '' }}">{{ $h }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($pharmacies as $pharmacy)
                                    <tr>
                                        <td class="px-4 py-2 sm:px-6">
                                            <a href="{{ route('businesses.pharmacies.show', [$business, $pharmacy]) }}"
                                               class="font-semibold text-emerald-700 underline decoration-emerald-300 underline-offset-2 hover:text-emerald-900">{{ $pharmacy->name }}</a>
                                            <span class="block font-mono text-xs text-gray-400">Account {{ $pharmacy->account?->code ?? '—' }}</span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2 font-mono text-sm text-gray-600">{{ $pharmacy->phone ?: '—' }}</td>
                                        <td class="px-4 py-2 text-gray-600">{{ $pharmacy->area ?? '—' }}</td>
                                        <td class="px-4 py-2 text-gray-600">{{ $pharmacy->credit_days ?? '—' }}</td>
                                        {{-- A pharmacy in credit has paid ahead; showing it red would read as a problem. --}}
                                        <td class="whitespace-nowrap px-4 py-2 text-right font-mono tabular-nums sm:px-6">
                                            @if ($balances[$pharmacy->id]->isPositive())
                                                <span class="font-semibold text-amber-700">{{ $balances[$pharmacy->id]->format() }}</span>
                                            @elseif ($balances[$pharmacy->id]->isNegative())
                                                <span class="text-emerald-800" title="Paid in advance">{{ $balances[$pharmacy->id]->absolute()->format() }} advance</span>
                                            @else
                                                <span class="font-sans text-xs font-medium text-gray-400">No pending amount</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-6 py-10 text-center text-gray-500">
                                            @if ($search !== '' || $area !== '')
                                                No customer matches the search.
                                            @elseif ($show === 'pending')
                                                No customer has a pending amount.
                                            @else
                                                No customers yet. A customer named on a bill is added here automatically.
                                            @endif
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
