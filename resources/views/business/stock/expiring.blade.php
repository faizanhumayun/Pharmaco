{{--
    What is running out of time, soonest first.

    Two tabs. Near expiry is a list to walk the shelves with: what is close
    enough to move to the near-expiry shelf, or send back, while it still can.
    Expired is what is out of date and has not gone back to the company — the
    money lost at cost unless it is returned.
--}}
@php($rs = fn ($m) => $m->format())
@php($isExpired = $show === 'expired')

<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Near expiry</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Every batch still in stock that carries a date. Sold oldest first, this is what
                    the shelf is holding.
                </p>
            </div>

        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <div class="rounded-md border px-4 py-3 shadow-sm {{ $expired->isNotEmpty() ? 'border-red-200 bg-red-50' : 'border-gray-200 bg-white' }}">
                <p class="text-xs uppercase tracking-wide {{ $expired->isNotEmpty() ? 'text-red-700' : 'text-gray-400' }}">Loss — expired, not returned</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums {{ $expired->isNotEmpty() ? 'text-red-800' : 'text-gray-400' }}">
                    {{ $rs($loss) }}
                </p>
                <p class="mt-0.5 text-xs {{ $expired->isNotEmpty() ? 'text-red-700' : 'text-gray-400' }}">
                    {{ number_format($expired->sum('packs')) }} {{ $business->unit()->many() }} at cost — lost unless sent back to the company
                </p>
            </div>

            <div class="rounded-md border px-4 py-3 shadow-sm {{ $near->isNotEmpty() ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-white' }}">
                <p class="text-xs uppercase tracking-wide {{ $near->isNotEmpty() ? 'text-amber-700' : 'text-gray-400' }}">At risk — near expiry</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums {{ $near->isNotEmpty() ? 'text-amber-900' : 'text-gray-400' }}">
                    {{ $rs($nearValue) }}
                </p>
                <p class="mt-0.5 text-xs {{ $near->isNotEmpty() ? 'text-amber-700' : 'text-gray-400' }}">
                    {{ number_format($near->sum('packs')) }} {{ $business->unit()->many() }} at cost — sell or return in time
                </p>
            </div>

            <div class="rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-gray-400">Batches listed</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $near->count() + $expired->count() }}</p>
                <p class="mt-0.5 text-xs text-gray-500">{{ $expired->count() }} expired, {{ $near->count() }} near expiry</p>
            </div>
        </div>

        {{-- How far ahead to look. It only applies to near expiry, and
             applies as soon as it is chosen. --}}
        @unless ($isExpired)
            <x-filter-bar submit="" class="mb-4" :active="request()->filled('within')"
                          :clear="route('businesses.stock.expiring', $business)">
                <div>
                    <x-input-label for="within" value="Show stock expiring" />
                    <select id="within" name="within" onchange="this.form.submit()"
                            class="mt-1 block w-56 rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                        @foreach ([1 => 'within 1 month', 3 => 'within 3 months', 6 => 'within 6 months', 12 => 'within 12 months', 120 => 'everything dated'] as $months => $label)
                            <option value="{{ $months }}" @selected((int) $within === $months)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <x-slot name="count">{{ number_format($near->count()) }} {{ Str::plural('batch', $near->count()) }} near expiry</x-slot>
            </x-filter-bar>
        @endunless

        {{-- The two lists. The window only applies to near expiry, so it is
             carried along when switching back to that tab. --}}
        <nav class="mb-4 flex gap-1 border-b border-gray-200" aria-label="Expiry lists">
            @foreach ([
                'near' => ['Near expiry', $near->count(), route('businesses.stock.expiring', array_filter([$business, 'within' => request('within')]))],
                'expired' => ['Expired — not returned', $expired->count(), route('businesses.stock.expiring', [$business, 'show' => 'expired'])],
            ] as $key => [$label, $count, $href])
                @php($here = $show === $key)
                <a href="{{ $href }}"
                   @class([
                       'inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition',
                       'border-emerald-700 text-emerald-800' => $here,
                       'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' => ! $here,
                   ])
                   @if ($here) aria-current="page" @endif>
                    {{ $label }}
                    <span @class([
                        'rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums',
                        'bg-red-600 text-white' => $key === 'expired' && $count > 0,
                        'bg-amber-500 text-white' => $key === 'near' && $count > 0,
                        'bg-gray-100 text-gray-500' => $count === 0,
                    ])>{{ $count }}</span>
                </a>
            @endforeach
        </nav>

        @if ($isExpired && $lossByCompany->isNotEmpty())
            <x-panel class="mb-4">
                <div class="px-4 py-3 sm:px-6">
                    <h2 class="text-sm font-semibold text-gray-900">Loss by company</h2>
                    <p class="mt-0.5 text-xs text-gray-500">What to send back to whom. Returned stock leaves this list once it is taken out of stock.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Company</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">{{ Str::ucfirst($business->unit()->many()) }}</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Loss at cost</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($lossByCompany as $company => $c)
                                <tr>
                                    <td class="px-4 py-2 text-gray-900 sm:px-6">{{ $company }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right font-mono tabular-nums text-gray-900">{{ number_format($c['packs']) }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right font-mono font-semibold tabular-nums text-red-800 sm:px-6">{{ $rs($c['value']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-panel>
        @endif

        <x-panel>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach (['Product', 'Company', 'Expires', $isExpired ? 'Expired' : 'Time left', Str::ucfirst($business->unit()->many()), $isExpired ? 'Loss at cost' : 'At cost'] as $h)
                                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ in_array($h, ['Product','Company','Expires']) ? 'text-left' : 'text-right' }} {{ $loop->first || $loop->last ? 'sm:px-6' : '' }}">{{ $h }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            @php($gone = $row['days'] < 0)
                            <tr class="{{ $gone ? 'bg-red-50/60' : ($row['days'] <= 90 ? 'bg-amber-50/50' : '') }}">
                                <td class="px-4 py-2 sm:px-6">
                                    <a href="{{ route('businesses.products.show', [$business, $row['product']]) }}"
                                       class="font-medium text-gray-900 hover:text-emerald-700">{{ $row['product']->label() }}</a>
                                    <span class="block font-mono text-xs text-gray-400">{{ $row['product']->code }}</span>
                                </td>
                                <td class="px-4 py-2 text-gray-600">{{ $row['product']->company?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-2 {{ $gone ? 'font-semibold text-red-800' : 'text-gray-900' }}">
                                    {{ $row['expires_on']->format('M Y') }}
                                    @if ($row['batch_no'])
                                        <span class="block font-mono text-xs font-normal text-gray-500">Batch {{ $row['batch_no'] }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">
                                    @if ($gone)
                                        <x-badge classes="bg-red-600 text-white ring-red-700/20">
                                            {{ abs($row['days']) }} {{ Str::plural('day', abs($row['days'])) }} ago
                                        </x-badge>
                                    @else
                                        <x-badge :classes="$row['days'] <= 90
                                            ? 'bg-amber-500 text-white ring-amber-600/20'
                                            : 'bg-gray-100 text-gray-700 ring-gray-500/20'">
                                            {{ $row['days'] }} {{ Str::plural('day', $row['days']) }}
                                        </x-badge>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-right font-mono tabular-nums text-gray-900">
                                    {{ number_format($row['packs']) }}
                                    @if ($row['loose'] > 0)
                                        <span class="block font-sans text-xs text-gray-500">+ {{ number_format($row['loose']) }} loose {{ Str::plural($row['product']->pieceNoun(), $row['loose']) }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-right font-mono tabular-nums sm:px-6 {{ $gone ? 'font-semibold text-red-800' : 'text-gray-900' }}">{{ $rs($row['value']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                    @if ($isExpired)
                                        Nothing expired is still in stock.
                                    @else
                                        Nothing in stock is dated within that window.
                                    @endif
                                    <span class="mt-1 block text-xs">
                                        Only batches recorded with an expiry date appear here — deliveries
                                        taken in before the date was asked for carry none.
                                    </span>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-panel>

        <p class="mt-4 text-xs leading-relaxed text-gray-500">
            Batches are worked out by taking what is on the shelf and filling them oldest first,
            so where it is unsure it counts stock as older rather than newer. Expired stock stays
            here — and in the loss — until it is taken out of stock, as a return or a write-off.
            Nothing here is stored: correct a delivery's date and this answers differently at once.
        </p>
    </div>
</x-workspace-layout>
