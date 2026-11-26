{{--
    Company stock audit: choose a company and count its rack.

    Each company at least once a month. That is a reminder — the amber strip
    and the bell — and never a block: selling and receiving go on whatever it
    says. Left, every company that holds stock with its last audit; right, the
    one chosen, with what it holds and what came and went since its last audit.
--}}
<x-workspace-layout :business="$business">
    <div class="w-full px-4 py-5 sm:px-6 lg:px-8">
        {{-- A start that was refused, and the monthly round — a caution, never a block — as toasts. --}}
        <x-flash :keys="['company_id']" :notes="[$due->isNotEmpty() ? ['warn',
            $due->count() . ' of ' . $total . ' ' . Str::plural('company', $total) . ' ' . ($due->count() === 1 ? 'is' : 'are') . ' due and not started: '
            . $due->take(3)->map(fn ($r) => $r['company']->name)->join(', ', ' and ') . ($due->count() > 3 ? ' and ' . ($due->count() - 3) . ' more' : '')
            . '. A reminder only; selling and receiving carry on as usual.'] : null]" />

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-gray-200 px-4 py-2.5 ring-1 ring-gray-300">
            <div class="min-w-0">
                <h1 class="text-lg font-semibold text-gray-900">Company stock audit</h1>
                <p class="text-sm text-gray-600">
                    Count one company's rack at a time, each company at least once a month.
                    Stock does not change until the owner approves the report.
                </p>
            </div>
        </div>


        <div class="flex flex-wrap items-start gap-5">
            {{-- Companies that hold stock. --}}
            <div class="min-w-0 flex-[999_1_36rem]">
                <x-filter-bar submit="Search" :active="$search !== ''" clear="{{ route('businesses.company-audits.index', $business) }}" class="mb-3">
                    <x-slot name="count">{{ $companies->count() }} of {{ $total }} {{ Str::plural('company', $total) }} holding stock</x-slot>
                    <div>
                        <x-input-label for="q" value="Company" />
                        <x-text-input id="q" name="q" type="search" class="mt-1 block" :value="$search" placeholder="Search a company" />
                    </div>
                </x-filter-bar>

                <x-panel>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    @foreach ([['Company', 'left'], ['Products in stock', 'right'], ['Value at cost', 'right'], ['Last audit · due monthly', 'left'], ['', 'right']] as [$h, $align])
                                        <th class="whitespace-nowrap px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first ? 'sm:pl-6' : '' }} {{ $loop->last ? 'sm:pr-6' : '' }}">{{ $h }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($companies as $row)
                                    @php($isChosen = $chosen && $chosen['company']->id === $row['company']->id)
                                    <tr class="{{ $isChosen ? 'bg-emerald-50' : '' }}">
                                        <td class="px-4 py-3 sm:pl-6">
                                            <span class="font-semibold text-gray-900">{{ $row['company']->name }}</span>
                                            <span class="block text-xs text-gray-500">{{ number_format($row['company']->products_count) }} products in catalogue</span>
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono tabular-nums">{{ number_format($row['products']) }}</td>
                                        <td class="px-4 py-3 text-right font-mono tabular-nums">{{ $row['value']->format() }}</td>
                                        <td class="px-4 py-3">
                                            @if ($row['last'])
                                                <span class="text-gray-900">{{ $row['last']->business_date->format('j M Y') }}</span>
                                                <span class="block text-xs text-gray-500">{{ $row['last']->statusLabel() }}</span>
                                            @else
                                                <span class="text-gray-700">Never audited</span>
                                            @endif
                                            @if ($row['open'])
                                                <span class="mt-0.5 inline-block rounded bg-sky-50 px-1.5 py-0.5 text-[11px] font-semibold text-sky-800 ring-1 ring-sky-600/20">
                                                    {{ $row['open']->isCounting() ? 'Being counted' : 'Waiting for approval' }}
                                                </span>
                                            @elseif ($row['due'])
                                                <span class="mt-0.5 inline-block rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-amber-600/20">Due now</span>
                                            @else
                                                <span class="block text-xs text-gray-500">Next by {{ $row['next']->format('j M') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right sm:pr-6">
                                            @if ($row['open'])
                                                <a href="{{ route('businesses.company-audits.show', [$business, $row['open']]) }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-800">Open</a>
                                            @elseif ($isChosen)
                                                <span class="text-sm font-semibold text-emerald-800">Selected</span>
                                            @else
                                                <a href="{{ route('businesses.company-audits.index', array_filter([$business, 'company' => $row['company']->id, 'q' => $search ?: null])) }}"
                                                   class="text-sm font-semibold text-emerald-700 hover:text-emerald-800">Choose</a>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="px-6 py-10 text-center text-gray-500">{{ $search !== '' ? 'No company found.' : 'No company holds stock yet.' }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-panel>
            </div>

            {{-- The chosen company, before the count starts. --}}
            @if ($chosen && $summary)
                <x-panel class="min-w-0 flex-[1_1_26rem]">
                    <div class="border-b border-gray-200 px-5 py-4">
                        <p class="font-mono text-[11px] uppercase tracking-wider text-gray-500">Audit</p>
                        <h2 class="text-lg font-semibold text-gray-900">{{ $chosen['company']->name }}</h2>
                        <p class="text-sm text-gray-500">
                            {{ $chosen['last'] ? 'Since the last audit on ' . $chosen['last']->business_date->format('j M Y') : 'First audit · since the opening' . ($summary['from'] ? ' on ' . \Illuminate\Support\Carbon::parse($summary['from'])->format('j M Y') : '') }}
                        </p>
                    </div>

                    <dl class="grid grid-cols-2 gap-px bg-gray-200">
                        <div class="bg-white px-5 py-3">
                            <dt class="font-mono text-[11px] uppercase tracking-wider text-gray-500">In records</dt>
                            <dd class="mt-1 font-mono text-xl font-semibold tabular-nums text-gray-900">{{ number_format($chosen['products']) }} {{ Str::plural('product', $chosen['products']) }}</dd>
                            <dd class="text-xs text-gray-500">{{ number_format($summary['packs']) }} packs{{ $summary['loose'] ? ', ' . number_format($summary['loose']) . ' loose' : '' }}</dd>
                        </div>
                        <div class="bg-white px-5 py-3">
                            <dt class="font-mono text-[11px] uppercase tracking-wider text-gray-500">Value at cost</dt>
                            <dd class="mt-1 font-mono text-xl font-semibold tabular-nums text-gray-900">{{ $chosen['value']->format() }}</dd>
                            <dd class="text-xs text-gray-500">{{ $summary['mrp']->format() }} at MRP</dd>
                        </div>
                        <div class="bg-white px-5 py-3">
                            <dt class="font-mono text-[11px] uppercase tracking-wider text-gray-500">Bought since</dt>
                            <dd class="mt-1 font-mono text-xl font-semibold tabular-nums text-gray-900">{{ $summary['till']['bought']->format() }}</dd>
                            <dd class="text-xs text-gray-500">{{ $summary['till']['deliveries'] }} {{ Str::plural('delivery', $summary['till']['deliveries']) }}</dd>
                        </div>
                        <div class="bg-white px-5 py-3">
                            <dt class="font-mono text-[11px] uppercase tracking-wider text-gray-500">Sold since</dt>
                            <dd class="mt-1 font-mono text-xl font-semibold tabular-nums text-gray-900">{{ $summary['till']['sold_mrp']->format() }}</dd>
                            <dd class="text-xs text-gray-500">at the till · {{ $summary['till']['bills'] }} {{ Str::plural('bill', $summary['till']['bills']) }}</dd>
                        </div>
                    </dl>

                    @if ($chosen['open'])
                        <div class="space-y-3 border-t border-gray-200 px-5 py-4">
                            <p class="text-sm text-gray-700">
                                An audit of {{ $chosen['company']->name }} is {{ $chosen['open']->isCounting() ? 'being counted' : 'waiting for approval' }}
                                — started {{ $chosen['open']->business_date->format('j M Y') }} by {{ $chosen['open']->starter?->name }}.
                            </p>
                            <a href="{{ route('businesses.company-audits.show', [$business, $chosen['open']]) }}"
                               class="block rounded-md bg-emerald-700 px-4 py-2.5 text-center text-sm font-semibold text-white hover:bg-emerald-800">Open it</a>
                        </div>
                    @else
                        <form method="POST" action="{{ route('businesses.company-audits.store', $business) }}" class="space-y-3 border-t border-gray-200 px-5 py-4">
                            @csrf
                            <input type="hidden" name="company_id" value="{{ $chosen['company']->id }}">
                            <label class="flex items-start gap-3 text-sm">
                                <input type="hidden" name="by_batch" value="0">
                                <input type="checkbox" name="by_batch" value="1" checked class="mt-0.5 rounded border-gray-300 text-emerald-700 focus:ring-emerald-600">
                                <span><span class="font-medium text-gray-900">Count by batch</span>
                                    <span class="block text-xs text-gray-500">Each batch and expiry is checked, as when stock is received.</span></span>
                            </label>
                            <label class="flex items-start gap-3 text-sm">
                                <input type="checkbox" name="include_empty" value="1" class="mt-0.5 rounded border-gray-300 text-emerald-700 focus:ring-emerald-600">
                                <span><span class="font-medium text-gray-900">Also list products with no stock in records</span>
                                    <span class="block text-xs text-gray-500">For goods on the rack the records do not know about. Adds up to {{ number_format(max(0, $summary['catalogue'] - $chosen['products'])) }} lines.</span></span>
                            </label>
                            <p class="rounded-md bg-gray-50 px-3 py-2 text-xs leading-relaxed text-gray-700">
                                The till stays open. Each product's records are read at the moment it is counted, so a sale made during the audit is not counted as missing.
                            </p>
                            <button class="w-full rounded-md bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">
                                Start audit of {{ $chosen['company']->name }}
                            </button>
                        </form>
                    @endif
                </x-panel>
            @endif
        </div>

        {{-- Audits already taken. --}}
        @if ($recent->isNotEmpty())
            <x-panel class="mt-5" title="Recent audits">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($recent as $audit)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-gray-600 sm:pl-6">{{ $audit->business_date->format('j M Y') }}</td>
                                    <td class="px-4 py-2.5 font-medium text-gray-900">{{ $audit->company->name }}</td>
                                    <td class="px-4 py-2.5 text-gray-600">by {{ $audit->starter?->name }}</td>
                                    <td class="px-4 py-2.5"><x-badge :classes="$audit->statusClasses()">{{ $audit->statusLabel() }}</x-badge></td>
                                    <td class="px-4 py-2.5 text-right sm:pr-6">
                                        <a href="{{ route('businesses.company-audits.show', [$business, $audit]) }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-800">
                                            {{ $audit->isCounting() ? 'Continue counting' : 'Report' }}
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-panel>
        @endif
    </div>
</x-workspace-layout>
