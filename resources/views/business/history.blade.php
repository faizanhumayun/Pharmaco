@php
    $rows = $data['rows'];
    $totals = $data['totals'];
    $opening = $data['opening'];
    $labels = array_column($rows, 'label');

    $num = fn ($m) => (float) $m->toDecimal();
    $series = fn (string $key) => array_map(fn ($r) => $num($r[$key]), $rows);

    // The position lines start from the balances carried into the range, so the
    // first period reads as a movement rather than as a standing start.
    $positionLabels = ['Opening', ...$labels];
    $positionSeries = fn (string $key) => [$num($opening[$key]), ...$series($key)];

    $tab = fn (string $value) => request()->fullUrlWithQuery(['view' => $value]);

    // Listing cells. Defined here, not in a @php block lower down: this view
    // also uses inline @php(), and Blade mis-pairs the two forms when mixed.
    $cell = 'px-3 py-2 text-right whitespace-nowrap tabular-nums';
    $th = 'px-3 py-2 align-bottom text-xs font-semibold uppercase tracking-wide text-gray-500';
    $tone = fn ($m, $base = 'text-gray-700') => $m->isNegative() ? 'text-red-700' : $base;
    $hasActivity = $rows !== [] && ! ($totals['sales']->isZero() && $totals['purchases']->isZero());

    // Progress is a change against the equivalent preceding range, not a raw total.
    $change = function ($now, $before) {
        $b = (float) $before->toDecimal();
        $n = (float) $now->toDecimal();

        if ($b == 0.0) {
            return $n == 0.0 ? null : null;
        }

        return round(($n - $b) / abs($b) * 100, 1);
    };

    /*
     * One plain sentence per chart, for readers who are not accountants: what
     * the picture says, in rupees, before they have to read the picture.
     * Whole rupees only — paise add digits and no meaning at this level.
     */
    $rs = fn ($m) => \Illuminate\Support\Str::beforeLast($m->absolute()->format(true), '.');
    $per100 = fn ($part, $whole) => $whole->isZero() ? null
        : (int) round((float) $part->toDecimal() / (float) $whole->toDecimal() * 100);

    $says = [];

    if ($hasActivity) {
        $end = $totals['closing'];

        $kept = $per100($totals['net_profit'], $totals['sales']);
        $says['money'] = $totals['sales']->isZero()
            ? 'No sales were recorded in this range.'
            : 'Customers bought ' . $rs($totals['sales']) . ' worth of medicine. After paying for that stock and '
                . 'for expenses, the business ' . ($totals['net_profit']->isNegative() ? 'lost ' : 'kept ')
                . $rs($totals['net_profit'])
                . ($kept === null ? '.' : ' — about Rs. ' . abs($kept)
                    . ($totals['net_profit']->isNegative() ? ' lost for every Rs. 100 sold.' : ' out of every Rs. 100 sold.'));

        $gap = $end['net_position'];
        $moved = $gap->minus($opening['net_position']);
        $says['worth'] = 'Right now the business owns ' . $rs($end['assets']) . ' and owes ' . $rs($end['liabilities']) . '. '
            . ($gap->isNegative()
                ? 'It owes ' . $rs($gap) . ' more than it owns.'
                : 'It owns ' . $rs($gap) . ' more than it owes.')
            . ($moved->isZero() ? '' : ' That is ' . $rs($moved) . ($moved->isPositive() ? ' better' : ' worse')
                . ' than at the start of this range.');

        $owed = $end['receivables']->minus($opening['receivables']);
        $says['market'] = 'You gave ' . $rs($totals['credit_sales']) . ' of medicine on credit and collected '
            . $rs($totals['collections']) . ' back. The market now owes you ' . $rs($end['receivables'])
            . ($owed->isZero() ? '.' : ', ' . $rs($owed) . ($owed->isPositive() ? ' more' : ' less') . ' than at the start of this range.');

        $held = $end['cash']->plus($end['stock'])->plus($end['receivables']);
        $cashShare = $per100($end['cash'], $held);
        $says['where'] = 'Of the money the business has, ' . $rs($end['cash']) . ' is cash, '
            . $rs($end['stock']) . ' is medicine on the shelves and ' . $rs($end['receivables']) . ' is still with the market'
            . ($cashShare === null ? '.' : ' — only about Rs. ' . max(0, $cashShare) . ' of every Rs. 100 is cash you can spend today.');

        $says['margin'] = $totals['margin'] === null
            ? 'Once there are sales, this shows how much is left from each sale after paying for the medicine.'
            : 'On average, from every Rs. 100 of sales, Rs. ' . (int) round($totals['margin'])
                . ' was left after paying for the medicine itself — before rent, salaries and other expenses come out of it.';
    }
@endphp

<x-workspace-layout :business="$business">
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">History &amp; progress</h1>
        <p class="mt-1 text-sm text-gray-500">
            {{ $from->format('d M Y') }} – {{ $to->format('d M Y') }} ·
            {{ strtolower($granularities[$granularity]) }} ·
            {{ count($rows) }} {{ Str::plural('period', count($rows)) }}.
            Built from the ledger, so open days count too.
        </p>
    </x-slot>

    <div class="w-full space-y-6 px-4 py-8 sm:px-6 lg:px-8">

        {{-- One bar: which view, and which range. Everything below it is data. --}}
        <div class="flex flex-wrap items-end justify-between gap-3 rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
        <nav class="inline-flex rounded-md border border-gray-300 bg-gray-50 p-0.5 text-sm font-medium">
            @foreach (['table' => 'Listing view', 'charts' => 'Graph view'] as $value => $label)
                <a href="{{ $tab($value) }}"
                   @class([
                       'rounded px-3 py-1.5',
                       'bg-white text-emerald-800 shadow-sm ring-1 ring-gray-200' => $view === $value,
                       'text-gray-500 hover:text-gray-800' => $view !== $value,
                   ])>
                    {{ $label }}
                </a>
            @endforeach
        </nav>

        <form method="GET" x-data="{ preset: '{{ $preset }}' }" class="flex flex-wrap items-end gap-3">
            <div>
                <x-input-label for="preset" value="Range" />
                <select id="preset" name="preset" x-model="preset"
                        class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach ($presets as $value => $label)
                        <option value="{{ $value }}" @selected($preset === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div x-show="preset === 'custom'" x-cloak>
                <x-input-label for="from" value="From" />
                <x-text-input id="from" name="from" type="date" class="mt-1 block" :value="$from->toDateString()" />
            </div>
            <div x-show="preset === 'custom'" x-cloak>
                <x-input-label for="to" value="To" />
                <x-text-input id="to" name="to" type="date" class="mt-1 block" :value="$to->toDateString()" />
            </div>

            <div>
                <x-input-label for="granularity" value="Group by" />
                <select id="granularity" name="granularity"
                        class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach ($granularities as $value => $label)
                        <option value="{{ $value }}" @selected($granularity === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Keeps the active pane when the range changes. --}}
            <input type="hidden" name="view" value="{{ $view }}">

            <button class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                Apply
            </button>
        </form>
        </div>

        {{--
            With nothing traded there are no charts or tables to carry the
            starting position, so it shows here on its own. Otherwise it sits
            below the charts, and in the listing it is the opening row.
        --}}
        @if ($startingPoint && ! $hasActivity)
            @include('business.partials.starting-position')
        @endif

        @if (! $hasActivity)
            <x-panel>
                <div class="px-6 py-16 text-center">
                    <p class="text-sm text-gray-600">No activity in this range.</p>
                    <p class="mt-1 text-sm text-gray-500">
                        Enter and post a day, or widen the range, and the trends fill in.
                        @if ($startingPoint)
                            The starting position above is where the business began.
                        @endif
                    </p>
                </div>
            </x-panel>
        @else
            @if ($view === 'charts')
            {{--
                Written for the owner, not the accountant: each chart is a question
                they would actually ask, a line on how to read it, and a sentence
                with the answer in rupees so the picture is confirmation rather
                than homework.
            --}}
            <div class="grid gap-6 2xl:grid-cols-2">
                @foreach ([
                    ['money', 'Are we making money?',
                        'Blue bars are what customers bought. The orange line is what the business kept after paying for the stock and the expenses — below zero means that period lost money.', true],
                    ['worth', 'What we own against what we owe',
                        'Blue is everything the business has: cash, medicine in stock, and money the market owes it. Orange is what it owes companies and others. When blue is above orange, the business is worth something; when orange is above, it owes more than it has.', false],
                    ['market', 'Is the market paying us back?',
                        'Orange bars are medicine given on credit. Blue bars are money collected back. When orange keeps beating blue, more and more of your money is sitting with customers.', false],
                    ['where', 'Where is our money?',
                        'Each bar is everything the business holds at the end of that period, split into cash, stock on the shelves, and money still with the market. Only the blue part can pay a bill today.', false],
                    ['margin', 'How much is left from every Rs. 100 sold?',
                        'Only the cost of the medicine is taken out here. Rent, salaries and other expenses still have to come out of what is left.', false],
                ] as [$id, $title, $howTo, $wide])
                    <x-panel :title="$title" :description="$howTo" :class="$wide ? '2xl:col-span-2' : ''">
                        <div class="p-4 sm:p-6">
                            <p class="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-900">
                                {{ $says[$id] }}
                            </p>
                            <div class="h-72">
                                <canvas id="{{ $id }}Chart" role="img" aria-label="{{ $title }} {{ $says[$id] }}"></canvas>
                            </div>
                        </div>
                    </x-panel>
                @endforeach
            </div>

            {{--
                Below the charts, not above: the page opens on the trend and the
                figures back it up. Totals are for the range, each against the
                equivalent preceding range.
            --}}
            <x-panel title="Range summary">
                <dl class="grid divide-y divide-gray-100 sm:grid-cols-3 sm:divide-y-0 2xl:grid-cols-6">
                @foreach ([
                    ['Sales', $totals['sales'], $previous['sales'], true],
                    ['Gross profit', $totals['gross_profit'], $previous['gross_profit'], true],
                    ['Net profit', $totals['net_profit'], $previous['net_profit'], true],
                    ['Purchases', $totals['purchases'], $previous['purchases'], null],
                    ['Collections', $totals['collections'], $previous['collections'], true],
                    ['Δ market credit', $totals['receivable_delta'], $previous['receivable_delta'], false],
                ] as [$label, $value, $prior, $higherIsBetter])
                    @php($pct = $change($value, $prior))
                    <div class="px-4 py-4 sm:px-6">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</dt>
                        <dd class="mt-1 font-mono text-xl font-semibold tabular-nums {{ $value->isNegative() ? 'text-red-700' : 'text-gray-900' }}">
                            {{ $value->format() }}
                        </dd>
                        <p class="mt-1 text-xs">
                            @if ($pct === null)
                                <span class="text-gray-400">no comparable period</span>
                            @else
                                @php($good = $higherIsBetter === null ? null : ($higherIsBetter ? $pct >= 0 : $pct <= 0))
                                <span class="{{ $good === null ? 'text-gray-500' : ($good ? 'text-emerald-700' : 'text-red-700') }}">
                                    {{ $pct >= 0 ? '▲' : '▼' }} {{ number_format(abs($pct), 1) }}%
                                </span>
                                <span class="text-gray-400">vs previous {{ $from->diffInDays($to) + 1 }} days</span>
                            @endif
                        </p>
                    </div>
                @endforeach
                </dl>
            </x-panel>

            @if ($startingPoint)
                @include('business.partials.starting-position')
            @endif
            @else
            {{--
                Two tables rather than one: fifteen columns of money cannot fit a
                laptop screen without scrolling sideways, and flows and balances
                read differently anyway — flows add up, balances carry forward.
            --}}
            <x-panel title="Trading"
                     description="What happened in each period. These add up to the totals at the bottom.">
                <table class="w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach (['Period', 'Sales', 'COGS', 'Gross profit', 'Margin', 'Expenses', 'Net profit',
                                       'Purchases', 'Collections', 'Payments'] as $h)
                                <th class="{{ $th }} {{ $loop->first ? 'text-left' : 'text-right' }}">{{ $h }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="whitespace-nowrap px-3 py-2 font-medium text-gray-900">{{ $row['label'] }}</td>
                                @foreach (['sales', 'cogs', 'gross_profit'] as $k)
                                    <td class="{{ $cell }} text-gray-700">{{ $row[$k]->format() }}</td>
                                @endforeach
                                <td class="{{ $cell }} text-gray-700">
                                    {{ $row['margin'] === null ? '—' : number_format($row['margin'], 2) . '%' }}
                                </td>
                                @foreach (['expenses', 'net_profit', 'purchases', 'collections', 'company_payments'] as $k)
                                    <td class="{{ $cell }} {{ $tone($row[$k]) }}">{{ $row[$k]->format() }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t-2 border-gray-300 bg-gray-50 font-semibold">
                        <tr>
                            <td class="px-3 py-2 text-gray-900">Total</td>
                            @foreach (['sales', 'cogs', 'gross_profit'] as $k)
                                <td class="{{ $cell }}">{{ $totals[$k]->format() }}</td>
                            @endforeach
                            <td class="{{ $cell }}">
                                {{ $totals['margin'] === null ? '—' : number_format($totals['margin'], 2) . '%' }}
                            </td>
                            @foreach (['expenses', 'net_profit', 'purchases', 'collections', 'company_payments'] as $k)
                                <td class="{{ $cell }} {{ $tone($totals[$k], 'text-gray-900') }}">{{ $totals[$k]->format() }}</td>
                            @endforeach
                        </tr>
                    </tfoot>
                </table>
            </x-panel>

            <x-panel title="Position"
                     description="Closing balances at the end of each period, carried forward from the opening row. These are not added up.">
                <table class="w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach (['Period', 'Cash', 'Receivables', 'Payables', 'Stock', 'Net position'] as $h)
                                <th class="{{ $th }} {{ $loop->first ? 'text-left' : 'text-right' }}">{{ $h }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    @php($isStart = $startingPoint && $startingPoint->opening_date?->isSameDay($opening['date']))
                    <tbody class="divide-y divide-gray-100" x-data="{ open: false }">
                        {{-- Balances brought in. Every row below is this plus its movement. --}}
                        <tr class="bg-emerald-50/60">
                            <td class="px-3 py-2">
                                <span class="font-semibold text-gray-900">Opening</span>
                                <span class="block text-xs text-gray-500">
                                    @if ($isStart)
                                        {{ $opening['date']->format('d M Y') }} · starting position
                                        @if ($opening['restated']) · includes later corrections @endif
                                        <button type="button" x-on:click="open = ! open"
                                                class="ml-1 font-medium text-emerald-700 hover:text-emerald-900"
                                                x-text="open ? 'hide' : 'detail'">detail</button>
                                    @else
                                        brought forward from {{ $opening['date']->format('d M Y') }}
                                    @endif
                                </span>
                            </td>
                            @foreach (['cash', 'receivables', 'payables', 'stock'] as $k)
                                <td class="{{ $cell }} {{ $tone($opening[$k]) }}">{{ $opening[$k]->format() }}</td>
                            @endforeach
                            <td class="{{ $cell }} font-semibold {{ $tone($opening['net_position'], 'text-gray-900') }}">
                                {{ $opening['net_position']->format() }}
                            </td>
                        </tr>

                        {{-- What the starting position was made of, as entered at cutover. --}}
                        @if ($isStart)
                            <tr x-show="open" x-cloak class="bg-emerald-50/30">
                                <td colspan="6" class="px-3 py-3">
                                    <dl class="grid gap-x-8 gap-y-1.5 sm:grid-cols-2 lg:grid-cols-4">
                                        @foreach ($startingPoint->lines as $line)
                                            <div class="flex justify-between gap-4">
                                                <dt class="text-gray-500">{{ $line->account->name }}</dt>
                                                <dd class="tabular-nums text-gray-900">{{ $line->amount->format() }}</dd>
                                            </div>
                                        @endforeach

                                        @unless ($startingPoint->balancing_figure->isZero())
                                            <div class="flex justify-between gap-4">
                                                <dt class="text-gray-500">Balancing figure</dt>
                                                <dd class="tabular-nums {{ $tone($startingPoint->balancing_figure, 'text-gray-900') }}">
                                                    {{ $startingPoint->balancing_figure->format() }}
                                                </dd>
                                            </div>
                                        @endunless
                                    </dl>

                                    @include('business.partials.opening-corrections')

                                    @if ($startingPoint->notes)
                                        <p class="mt-2 border-t border-emerald-100 pt-2 text-gray-600">{{ $startingPoint->notes }}</p>
                                    @endif
                                </td>
                            </tr>
                        @endif

                        @foreach ($rows as $row)
                            <tr>
                                <td class="whitespace-nowrap px-3 py-2 font-medium text-gray-900">{{ $row['label'] }}</td>
                                @foreach (['cash', 'receivables', 'payables', 'stock'] as $k)
                                    <td class="{{ $cell }} {{ $tone($row[$k]) }}">{{ $row[$k]->format() }}</td>
                                @endforeach
                                <td class="{{ $cell }} {{ $tone($row['net_position'], 'text-gray-900') }}">
                                    {{ $row['net_position']->format() }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-panel>
            @endif

            @if ($view === 'charts')
            @push('scripts')
                <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
                <script>
                    // Colours validated for colour-blind readers (dataviz palette,
                    // slots 1–3). Identity never rests on colour alone: every chart
                    // has a legend, and the listing view has the same numbers.
                    const BLUE = '#2a78d6', ORANGE = '#eb6834', AQUA = '#1baf7a';

                    const labels = @json($labels);
                    const withOpening = @json($positionLabels);

                    // Full rupees in tooltips; lakh and crore on the axis, which is
                    // how the owner says these amounts out loud.
                    const rs = v => 'Rs. ' + Math.round(v).toLocaleString('en-US');
                    const short = v => {
                        const a = Math.abs(v), s = v < 0 ? '-' : '';
                        if (a >= 1e7) return s + (a / 1e7).toFixed(1).replace(/\.0$/, '') + ' crore';
                        if (a >= 1e5) return s + (a / 1e5).toFixed(1).replace(/\.0$/, '') + ' lakh';
                        return s + Math.round(a).toLocaleString('en-US');
                    };

                    const line = (label, data, color, extra = {}) => ({
                        type: 'line', label, data, borderColor: color, backgroundColor: color,
                        borderWidth: 2, tension: 0.25, order: 0,
                        pointRadius: 4, pointHoverRadius: 6, pointBorderColor: '#fff', pointBorderWidth: 2,
                        ...extra,
                    });
                    const bar = (label, data, color, extra = {}) => ({
                        type: 'bar', label, data, backgroundColor: color,
                        borderRadius: 4, borderSkipped: 'start', maxBarThickness: 40, order: 1,
                        ...extra,
                    });

                    const options = ({ stacked = false, tick = short, tip = rs } = {}) => ({
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 16 } },
                            tooltip: { callbacks: { label: c => ` ${c.dataset.label}: ${tip(c.parsed.y)}` } },
                        },
                        scales: {
                            y: { stacked, ticks: { callback: tick, maxTicksLimit: 6 }, grid: { color: '#f1f1ef' }, border: { display: false } },
                            x: { stacked, ticks: { maxRotation: 0, autoSkipPadding: 16 }, grid: { display: false } },
                        },
                    });

                    new Chart(document.getElementById('moneyChart'), {
                        data: {
                            labels,
                            datasets: [
                                line('Money kept (after stock and expenses)', @json($series('net_profit')), ORANGE),
                                bar('What customers bought', @json($series('sales')), BLUE),
                            ],
                        },
                        options: options(),
                    });

                    new Chart(document.getElementById('worthChart'), {
                        data: {
                            labels: withOpening,
                            datasets: [
                                line('What we own', @json($positionSeries('assets')), BLUE),
                                line('What we owe', @json($positionSeries('liabilities')), ORANGE),
                            ],
                        },
                        options: options(),
                    });

                    new Chart(document.getElementById('marketChart'), {
                        data: {
                            labels,
                            datasets: [
                                bar('Given on credit', @json($series('credit_sales')), ORANGE),
                                bar('Collected back', @json($series('collections')), BLUE),
                            ],
                        },
                        options: options(),
                    });

                    new Chart(document.getElementById('whereChart'), {
                        data: {
                            labels: withOpening,
                            datasets: [
                                // A 2px white edge separates the stacked parts.
                                bar('Cash', @json($positionSeries('cash')), BLUE, { borderRadius: 0, borderColor: '#fff', borderWidth: 2 }),
                                bar('Medicine in stock', @json($positionSeries('stock')), ORANGE, { borderRadius: 0, borderColor: '#fff', borderWidth: 2 }),
                                bar('Still with the market', @json($positionSeries('receivables')), AQUA, { borderRadius: 0, borderColor: '#fff', borderWidth: 2 }),
                            ],
                        },
                        options: options({ stacked: true }),
                    });

                    new Chart(document.getElementById('marginChart'), {
                        data: {
                            labels,
                            datasets: [
                                line('Left from every Rs. 100 sold', @json(array_map(fn ($r) => $r['margin'], $rows)), BLUE, { spanGaps: true }),
                            ],
                        },
                        options: {
                            ...options({ tick: v => 'Rs. ' + v, tip: v => v === null ? '—' : 'Rs. ' + v.toFixed(0) + ' of every Rs. 100' }),
                            // One series: the title names it, so no legend box.
                            plugins: {
                                legend: { display: false },
                                tooltip: { callbacks: { label: c => ` Rs. ${c.parsed.y?.toFixed(0) ?? '—'} left from every Rs. 100 sold` } },
                            },
                        },
                    });
                </script>
            @endpush
            @endif
        @endif
    </div>
</x-workspace-layout>
