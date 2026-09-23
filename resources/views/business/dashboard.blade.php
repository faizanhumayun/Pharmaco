<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Where the business stands</h1>
                <p class="mt-1 text-sm text-gray-500">
                    As at {{ $date->format('D d M Y') }}. Every figure is summed from the ledger.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('businesses.daily.create', $business) }}"
                   class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    Enter a day
                </a>
                <a href="{{ route('businesses.closing.show', $business) }}"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Close a day
                </a>
            </div>
        </div>
    </x-slot>

    <div class="w-full space-y-8 px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        {{-- Integrity strip. If any of this is red, treat every other number here as suspect. --}}
        <div class="flex flex-wrap items-center gap-2 rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Integrity</span>
            <x-badge :classes="$integrity['trial']['balanced'] ? 'bg-emerald-50 text-emerald-800 ring-emerald-600/20' : 'bg-red-50 text-red-800 ring-red-600/20'">
                Trial balance {{ $integrity['trial']['balanced'] ? '✓' : 'out by ' . $integrity['trial']['difference']->format() }}
            </x-badge>
            <x-badge :classes="$integrity['positionConsistent'] ? 'bg-emerald-50 text-emerald-800 ring-emerald-600/20' : 'bg-red-50 text-red-800 ring-red-600/20'">
                Position derivations {{ $integrity['positionConsistent'] ? 'agree ✓' : 'disagree by ' . $integrity['positionDiscrepancy']->format() }}
            </x-badge>
            <x-badge :classes="$integrity['openDays'] === 0 ? 'bg-emerald-50 text-emerald-800 ring-emerald-600/20' : 'bg-amber-50 text-amber-800 ring-amber-600/20'">
                {{ $integrity['openDays'] === 0 ? 'All days closed ✓' : $integrity['openDays'] . ' days open' }}
            </x-badge>
            <x-badge :classes="$confidence['stale'] ? 'bg-amber-50 text-amber-800 ring-amber-600/20' : 'bg-emerald-50 text-emerald-800 ring-emerald-600/20'">
                Stock {{ $confidence['label'] }}
            </x-badge>
        </div>

        @if (! $integrity['trial']['balanced'] || ! $integrity['positionConsistent'])
            <div class="rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900">
                <strong>The ledger does not reconcile.</strong>
                Until this is resolved, treat every figure on this page as unreliable.
            </div>
        @endif

        {{-- Band 1 — Position --}}
        @if ($showPosition)
            <section>
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Position</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5">
                    <div class="rounded-lg border-2 border-dashed border-gray-300 bg-white/60 px-4 py-4">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            Stock value <span class="text-amber-700">· estimated</span>
                        </dt>
                        <dd class="mt-1 font-mono text-2xl font-semibold tabular-nums text-gray-700">{{ $stock->format() }}</dd>
                        <p class="mt-1 text-xs text-gray-500">{{ $confidence['detail'] }}</p>
                    </div>

                    <x-stat label="Cash in hand" :value="$cash->format()" hint="Counted and reconciled at close" />
                    <x-stat label="Market receivables" :value="$receivables->format()"
                            :hint="$customerAdvances->isZero() ? 'Owed by the market' : 'Plus ' . $customerAdvances->format() . ' held as advances'" />
                    <x-stat label="Company payables" :value="$payables->format()"
                            :hint="$companyAdvances->isZero() ? 'Owed to companies' : 'Less ' . $companyAdvances->format() . ' paid in advance'" />

                    <div class="rounded-lg bg-white px-4 py-4 shadow-sm">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Net position</dt>
                        <dd class="mt-1 font-mono text-2xl font-semibold tabular-nums {{ $position->netPosition()->isNegative() ? 'text-red-700' : 'text-gray-900' }}">
                            {{ $position->netPosition()->format() }}
                        </dd>
                        <p class="mt-1 text-xs text-gray-500">Management position — not a balance sheet</p>
                    </div>
                </div>

                @unless ($openingEquity->isZero())
                    <p class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900">
                        Opening Balance Equity of <strong>{{ $openingEquity->format() }}</strong> is still unexplained.
                        It stays visible here until it is explained or corrected, rather than being folded into
                        equity and forgotten.
                    </p>
                @endunless
            </section>
        @endif

        {{-- Band 2 — Today --}}
        <section>
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">
                Today · {{ $date->format('d M Y') }}
            </h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-6">
                <x-stat label="Sales" :value="$today['sales']->format()" />
                <x-stat label="Cost of goods sold" :value="$today['cogs']->format()" />
                <x-stat label="Gross profit" :value="$today['grossProfit']->format()"
                        :hint="$today['sales']->isZero() ? null : number_format((float) $today['grossProfit']->toDecimal() / (float) $today['sales']->toDecimal() * 100, 1) . '% margin'" />
                <x-stat label="Expenses" :value="$today['expenses']->format()" />
                <x-stat label="Net profit" :value="$today['netProfit']->format()" hint="After expenses and write-offs" />

                <div class="rounded-lg bg-white px-4 py-4 shadow-sm">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Δ market credit</dt>
                    <dd class="mt-1 font-mono text-2xl font-semibold tabular-nums {{ $today['receivableDelta']->isPositive() ? 'text-red-700' : 'text-emerald-700' }}">
                        {{ $today['receivableDelta']->format() }}
                    </dd>
                    <p class="mt-1 text-xs text-gray-500">
                        Deserves equal billing with profit — a distributor can be profitable daily while the
                        money sits in a pharmacy's ledger.
                    </p>
                </div>
            </div>
        </section>

        {{-- Band 3 — Attention --}}
        @if ($alerts !== [])
            <section>
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Attention</h2>
                <div class="grid gap-3 lg:grid-cols-2">
                    @foreach ($alerts as $alert)
                        <div class="rounded-md border-l-4 bg-white px-4 py-3 shadow-sm
                                    {{ $alert['level'] === 'critical' ? 'border-red-600' : 'border-amber-500' }}">
                            <p class="text-sm font-semibold text-gray-900">{{ $alert['title'] }}</p>
                            <p class="mt-0.5 text-sm text-gray-600">{{ $alert['detail'] }}</p>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Trends --}}
        @if ($showPosition && count($trend['labels']) > 1)
            <section>
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Last {{ count($trend['labels']) }} closed days
                </h2>
                <x-panel>
                    <div class="p-4 sm:p-6">
                        <canvas id="trendChart" height="90"></canvas>
                        <p class="mt-3 text-xs text-gray-500">
                            The pairing that matters most is receivables against sales: receivables rising while
                            sales stay flat is the earliest possible warning of a collections problem, and it is
                            invisible in any single day's numbers.
                        </p>
                    </div>
                </x-panel>
            </section>

            @push('scripts')
                <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
                <script>
                    new Chart(document.getElementById('trendChart'), {
                        type: 'line',
                        data: {
                            labels: @json($trend['labels']),
                            datasets: [
                                @foreach ($trend['series'] as $label => $values)
                                    {
                                        label: @json($label),
                                        data: @json($values),
                                        borderWidth: 2,
                                        tension: 0.25,
                                        pointRadius: 2,
                                    },
                                @endforeach
                            ],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: { mode: 'index', intersect: false },
                            scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() } } },
                        },
                    });
                </script>
            @endpush
        @endif
    </div>
</x-workspace-layout>
