{{--
    A company audit, finished: what the rack was worth in the records, what
    it was found to be worth, and the change — then the same set against the
    till over the period since this company's last audit, which is what turns
    a shortage into a question: goods that left the rack without a bill.

    Stock moves only when the owner approves. Until then the audit can be
    sent back to the rack for a recount, or closed with the records kept.
--}}
<?php
    $s = $summary;
    $signed = fn ($m) => ($m->isNegative() ? '−' : ($m->isPositive() ? '+' : '')) . $m->absolute()->format();
    $diffs = $rows->filter(fn ($r) => $r['counted'] && $r['diff_pieces'] !== 0)
        ->sortBy(fn ($r) => (float) $r['diff_value']->toDecimal())->values();
    $matched = $rows->where('matched', true);
    $uncounted = $rows->where('counted', false);
    $say = fn ($r, $pieces) => $r['product']->stockLabel(intdiv(abs($pieces), $r['per']), abs($pieces) % $r['per']);
?>

<x-workspace-layout :business="$business">
    <div class="w-full px-4 py-5 sm:px-6 lg:px-8">
        <x-flash :keys="['audit']" />

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-gray-200 px-4 py-2.5 ring-1 ring-gray-300 print:bg-white print:ring-0">
            <div class="min-w-0">
                <x-back-link :href="route('businesses.company-audits.index', $business)" label="Company stock audit" />
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-lg font-semibold text-gray-900">Audit report · {{ $audit->company->name }}</h1>
                    <x-badge :classes="$audit->statusClasses()">{{ $audit->statusLabel() }}</x-badge>
                </div>
                <p class="text-sm text-gray-600">
                    Counted {{ $audit->business_date->format('j M Y') }} by {{ $audit->starter?->name }}{{ $audit->device ? ' on ' . $audit->device->name : '' }}
                    · {{ $s['counted'] }} of {{ $s['products'] }} products
                    · period since {{ $audit->period_from ? $audit->period_from->format('j M Y') : 'the start of the records' }}
                    @if ($audit->decider) · {{ $audit->status === 'approved' ? 'approved' : 'closed' }} by {{ $audit->decider->name }} {{ $audit->decided_at->timezone($business->timezone)->format('j M Y, h:i A') }} @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2 print:hidden">
                <button type="button" onclick="window.print()"
                        class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50">Print</button>
                @if ($audit->status === 'submitted')
                    <form method="POST" action="{{ route('businesses.company-audits.reopen', [$business, $audit]) }}">
                        @csrf
                        <button class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50">Send back for a recount</button>
                    </form>
                @endif
            </div>
        </div>

        {{-- Laid out as Settings: the report's sections down the side, one
             shown at a time and kept in the link (?section=…). Printing shows
             them all. --}}
        <?php
            $sections = array_filter([
                'summary' => ['Summary', null],
                'till' => ['Against the till', null],
                'differences' => ['Differences', $diffs->count()],
                'matched' => ['Matched', $matched->count()],
                'uncounted' => $uncounted->isNotEmpty() ? ['Not counted', $uncounted->count()] : null,
                'decide' => [$audit->status === 'approved' ? 'In the books' : ($audit->status === 'submitted' ? 'Decide' : 'Decision'), null],
            ]);
            if (! isset($sections[$section])) { $section = 'summary'; }
        ?>
        <div class="flex flex-wrap items-start gap-6"
             x-data="{
                 section: @js($section),
                 show(key) {
                     this.section = key;
                     const url = new URL(window.location);
                     url.searchParams.set('section', key);
                     history.replaceState(null, '', url);
                 },
             }">
            <nav aria-label="Sections of the report"
                 class="flex w-full flex-col gap-0.5 rounded-lg border border-line bg-white p-2 print:hidden lg:w-60 lg:shrink-0">
                @foreach ($sections as $key => [$label, $count])
                    <a href="{{ route('businesses.company-audits.show', [$business, $audit, 'section' => $key]) }}" x-on:click.prevent="show(@js($key))"
                       :aria-current="section === @js($key) ? 'page' : null"
                       class="flex items-center justify-between gap-3 rounded-md px-3 py-2 text-sm"
                       :class="section === @js($key) ? 'bg-emerald-50 font-semibold text-emerald-800' : 'text-gray-700 hover:bg-gray-50'">
                        <span>{{ $label }}</span>
                        @if ($count !== null)
                            <span @class(['rounded-full px-2 py-0.5 text-xs tabular-nums',
                                          'bg-red-50 text-red-700' => $key === 'differences' && $count > 0,
                                          'bg-amber-50 text-amber-800' => $key === 'uncounted',
                                          'bg-gray-100 text-gray-600' => ! ($key === 'differences' && $count > 0) && $key !== 'uncounted'])>{{ $count }}</span>
                        @elseif ($key === 'decide' && $audit->status === 'submitted' && $canDecide)
                            <span class="h-2 w-2 rounded-full bg-amber-500" title="Waiting for your decision"></span>
                        @endif
                    </a>
                @endforeach
            </nav>

            <div class="min-w-0 flex-1 space-y-4">
                <div x-show="section === 'summary'" @if ($section !== 'summary') x-cloak @endif class="space-y-4 print:!block">
            {{-- Before, after, change. --}}
            <section class="mb-4 flex flex-wrap overflow-hidden rounded-lg border border-gray-200 bg-white">
                <div class="flex-[1_1_16rem] px-5 py-4">
                    <p class="font-mono text-[11px] uppercase tracking-wider text-gray-500">Stock value in records</p>
                    <p class="mt-1 font-mono text-3xl font-semibold tabular-nums text-gray-900">{{ $s['records']->format() }}</p>
                    <p class="text-sm text-gray-500">at cost · {{ $s['counted'] }} {{ Str::plural('product', $s['counted']) }} counted</p>
                </div>
                <div class="flex items-center px-2 text-2xl text-gray-400" aria-hidden="true">→</div>
                <div class="flex-[1_1_16rem] px-5 py-4">
                    <p class="font-mono text-[11px] uppercase tracking-wider text-gray-500">Stock value counted</p>
                    <p class="mt-1 font-mono text-3xl font-semibold tabular-nums text-gray-900">{{ $s['found']->format() }}</p>
                    <p class="text-sm text-gray-500">at cost · what is on the rack</p>
                </div>
                <div @class([
                    'flex-[1_1_18rem] border-l px-5 py-4',
                    'border-red-200 bg-red-50' => $s['change']->isNegative(),
                    'border-sky-200 bg-sky-50' => $s['change']->isPositive(),
                    'border-emerald-200 bg-emerald-50' => $s['change']->isZero(),
                ])>
                    <p @class(['font-mono text-[11px] uppercase tracking-wider', 'text-red-700' => $s['change']->isNegative(), 'text-sky-800' => $s['change']->isPositive(), 'text-emerald-800' => $s['change']->isZero()])>Change</p>
                    <p @class(['mt-1 font-mono text-3xl font-semibold tabular-nums', 'text-red-700' => $s['change']->isNegative(), 'text-sky-800' => $s['change']->isPositive(), 'text-emerald-800' => $s['change']->isZero()])>
                        {{ $s['change']->isZero() ? 'No change' : $signed($s['change']) }}
                    </p>
                    <p @class(['text-sm', 'text-red-700' => $s['change']->isNegative(), 'text-sky-800' => $s['change']->isPositive(), 'text-emerald-800' => $s['change']->isZero()])>
                        @if ($s['change']->isZero())
                            the rack matches the records
                        @else
                            {{ $s['change_percent'] !== null ? ($s['change_percent'] > 0 ? '+' : '') . $s['change_percent'] . '% of ' . $audit->company->name . ' stock · ' : '' }}{{ $s['change']->isNegative() ? 'decrease' : 'increase' }}
                        @endif
                    </p>
                </div>
            </section>

            <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-md border border-gray-200 bg-white px-4 py-3">
                    <p class="font-mono text-[11px] uppercase tracking-wider text-gray-500">Short</p>
                    <p class="mt-1 font-mono text-2xl font-semibold tabular-nums {{ $s['short']->isZero() ? 'text-gray-900' : 'text-red-700' }}">{{ $signed($s['short']) ?: '0.00' }}</p>
                    <p class="text-xs text-gray-500">{{ $s['short_count'] }} {{ Str::plural('product', $s['short_count']) }} · at cost</p>
                </div>
                <div class="rounded-md border border-gray-200 bg-white px-4 py-3">
                    <p class="font-mono text-[11px] uppercase tracking-wider text-gray-500">Short, at MRP</p>
                    <p class="mt-1 font-mono text-2xl font-semibold tabular-nums {{ $s['short_mrp']->isZero() ? 'text-gray-900' : 'text-red-700' }}">{{ $signed($s['short_mrp']) ?: '0.00' }}</p>
                    <p class="text-xs text-gray-500">what those goods sell for</p>
                </div>
                <div class="rounded-md border border-gray-200 bg-white px-4 py-3">
                    <p class="font-mono text-[11px] uppercase tracking-wider text-gray-500">Extra</p>
                    <p class="mt-1 font-mono text-2xl font-semibold tabular-nums {{ $s['extra']->isZero() ? 'text-gray-900' : 'text-sky-800' }}">{{ $signed($s['extra']) ?: '0.00' }}</p>
                    <p class="text-xs text-gray-500">{{ $s['extra_count'] }} {{ Str::plural('product', $s['extra_count']) }} · at cost</p>
                </div>
                <div class="rounded-md border border-gray-200 bg-white px-4 py-3">
                    <p class="font-mono text-[11px] uppercase tracking-wider text-gray-500">Matched</p>
                    <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-emerald-700">{{ $s['matched'] }} <span class="text-sm font-normal text-gray-500">of {{ $s['counted'] }}</span></p>
                    <p class="text-xs text-gray-500">counted as recorded{{ $uncounted->isNotEmpty() ? ' · ' . $uncounted->count() . ' not counted' : '' }}</p>
                </div>
            </div>
                    <div class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-md border border-gray-200 bg-white px-4 py-3">
                        <p class="font-mono text-[11px] uppercase tracking-wider text-gray-500">Monthly round</p>
                        <p class="mt-1 text-sm text-gray-900">
                            {{ $audit->company->name }} audited {{ $audit->business_date->format('j M Y') }}.
                            Next audit due by <span class="font-semibold">{{ $audit->business_date->copy()->addDays(\App\Models\CompanyAudit::EVERY_DAYS)->format('j M Y') }}</span>.
                        </p>
                        <p class="mt-1 text-xs text-gray-500">After that it shows as a reminder on the audit page and under the bell. Nothing is blocked.</p>
                    </div>

                    <div class="rounded-md border border-gray-200 bg-white px-4 py-3">
                        <p class="font-mono text-[11px] uppercase tracking-wider text-gray-500">Previous audits of {{ $audit->company->name }}</p>
                        @forelse ($previous as $p)
                            <a href="{{ route('businesses.company-audits.show', [$business, $p['audit']]) }}" class="mt-1 flex justify-between gap-3 text-sm hover:text-emerald-800">
                                <span>{{ $p['audit']->business_date->format('j M Y') }} <span class="text-xs text-gray-500">· {{ $p['audit']->statusLabel() }}</span></span>
                                <span class="font-mono tabular-nums {{ $p['change']->isNegative() ? 'text-red-700' : ($p['change']->isPositive() ? 'text-sky-800' : 'text-emerald-700') }}">{{ $p['change']->isZero() ? 'no change' : $signed($p['change']) }}</span>
                            </a>
                        @empty
                            <p class="mt-1 text-sm text-gray-900">This is the first one.</p>
                            <p class="mt-1 text-xs text-gray-500">From the next audit on, earlier changes are listed here so a growing shortage stands out.</p>
                        @endforelse
                    </div>
                    </div>
                </div>

                <div x-show="section === 'till'" @if ($section !== 'till') x-cloak @endif class="print:!block">
            {{-- Against the till. --}}
            <x-panel class="min-w-0 flex-[999_1_34rem]">
                <div class="border-b border-gray-200 px-5 py-3">
                    <h2 class="text-base font-semibold text-gray-900">Against the till, since {{ $audit->period_from ? $audit->period_from->format('j M Y') : 'the start of the records' }}</h2>
                    <p class="text-sm text-gray-500">What came in and was billed for these products over the same period.</p>
                </div>
                <dl class="divide-y divide-gray-100 text-sm">
                    <div class="flex justify-between gap-4 px-5 py-2.5">
                        <dt>Bought from {{ $audit->company->name }} <span class="block text-xs text-gray-500">{{ $s['till']['deliveries'] }} {{ Str::plural('delivery', $s['till']['deliveries']) }}, at cost</span></dt>
                        <dd class="font-mono tabular-nums">{{ $s['till']['bought']->format() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-2.5">
                        <dt>Sold through the till <span class="block text-xs text-gray-500">{{ $s['till']['bills'] }} {{ Str::plural('bill', $s['till']['bills']) }} · {{ $s['till']['sold_cost']->format() }} at cost</span></dt>
                        <dd class="font-mono tabular-nums">{{ $s['till']['sold_mrp']->format() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-2.5 font-semibold">
                        <dt>Should be on the rack</dt>
                        <dd class="font-mono tabular-nums">{{ $s['records']->format() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-2.5 font-semibold">
                        <dt>Found on the rack</dt>
                        <dd class="font-mono tabular-nums">{{ $s['found']->format() }}</dd>
                    </div>
                    @if ($s['short']->isNegative())
                        <div class="flex justify-between gap-4 bg-red-50 px-5 py-2.5 font-semibold text-red-700">
                            <dt>Left the rack without a bill</dt>
                            <dd class="font-mono tabular-nums">{{ $s['short']->absolute()->format() }}</dd>
                        </div>
                    @endif
                </dl>
                @if ($s['short']->isNegative())
                    <p class="bg-gray-50 px-5 py-3 text-sm leading-relaxed text-gray-700">
                        Goods worth <span class="font-mono font-semibold">{{ $s['short_mrp']->absolute()->format() }}</span> at MRP are missing.
                        @if ($s['per_hundred'] !== null)
                            That is <span class="font-semibold">{{ $s['per_hundred'] }} for every 100</span> sold through the till on these products in the same period.
                        @endif
                        Possible causes: a sale not entered at the till, breakage or a return to the company not recorded, or a counting mistake.
                    </p>
                @endif
            </x-panel>
                </div>

                <div x-show="section === 'differences'" @if ($section !== 'differences') x-cloak @endif class="print:!block">
            {{-- Every difference, largest loss first. --}}
            <x-panel>
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-gray-200 px-5 py-3">
                    <h2 class="text-base font-semibold text-gray-900">Differences</h2>
                    <p class="text-sm text-gray-500">Largest loss first. Products that matched are under Matched.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                @foreach ([['Product', 'left'], ['Batch', 'left'], ['In records', 'right'], ['Counted', 'right'], ['Difference', 'right'], ['At cost', 'right'], ['At MRP', 'right'], ['Sold at till since', 'right'], ['Note', 'left']] as [$h, $align])
                                    <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first ? 'sm:pl-5' : '' }}">{{ $h }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($diffs as $r)
                                @php($per = $r['per'])
                                <tr class="align-top">
                                    <td class="px-3 py-2.5 sm:pl-5"><span class="font-medium text-gray-900">{{ $r['product']->label() }}</span><span class="block text-xs text-gray-500">{{ $r['product']->pack_size }}</span></td>
                                    <td class="px-3 py-2.5">
                                        @forelse ($r['count']->lines->filter(fn ($l) => $l->difference($per) !== 0) as $line)
                                            <span class="block whitespace-nowrap text-xs {{ $line->is_new ? 'text-sky-800' : 'text-gray-700' }}">
                                                <span class="font-mono">{{ $line->batch_no ?: ($line->expires_on ? 'No batch no.' : 'Undated') }}</span>
                                                @if ($line->expires_on) · {{ $line->expires_on->format('M Y') }} @endif
                                                @if ($line->is_new) · not in records @endif
                                            </span>
                                        @empty
                                            <span class="text-xs text-gray-400">—</span>
                                        @endforelse
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono tabular-nums">{{ $say($r, $r['expected_pieces']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono tabular-nums">{{ $say($r, $r['counted_pieces']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono font-semibold tabular-nums {{ $r['diff_pieces'] < 0 ? 'text-red-700' : 'text-sky-800' }}">{{ $r['diff_pieces'] < 0 ? '−' : '+' }}{{ $say($r, $r['diff_pieces']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono font-semibold tabular-nums {{ $r['diff_pieces'] < 0 ? 'text-red-700' : 'text-sky-800' }}">{{ $signed($r['diff_value']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono tabular-nums {{ $r['diff_pieces'] < 0 ? 'text-red-700' : 'text-sky-800' }}">{{ $signed($r['diff_mrp']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono tabular-nums">
                                        {{ $r['sold_pieces'] ? $say($r, $r['sold_pieces']) : '0' }}
                                        <span class="block text-xs text-gray-500">{{ $r['last_sold'] ? 'last ' . $r['last_sold']->format('j M') : 'not sold' }}</span>
                                    </td>
                                    <td class="px-3 py-2.5 text-xs text-gray-600">{{ $r['count']->note ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="px-5 py-8 text-center text-emerald-800">No differences. Every product counted matches the records.</td></tr>
                            @endforelse
                        </tbody>
                        @if ($diffs->isNotEmpty())
                            <tfoot class="bg-gray-50">
                                <tr class="font-semibold">
                                    <td colspan="5" class="px-3 py-2.5 text-right">Total change</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono tabular-nums {{ $s['change']->isNegative() ? 'text-red-700' : 'text-sky-800' }}">{{ $signed($s['change']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono tabular-nums {{ $s['net_mrp']->isNegative() ? 'text-red-700' : 'text-sky-800' }}">{{ $signed($s['net_mrp']) }}</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </x-panel>
                </div>

                <div x-show="section === 'matched'" @if ($section !== 'matched') x-cloak @endif class="print:!block">
                <x-panel>
                    <div class="border-b border-gray-200 px-5 py-3">
                        <h2 class="text-base font-semibold text-gray-900">{{ $matched->count() }} {{ Str::plural('product', $matched->count()) }}</h2>
                        <p class="text-sm text-gray-500">Counted exactly as the records had them. Nothing changes for these.</p>
                    </div>
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($matched as $r)
                                <tr>
                                    <td class="px-5 py-2"><span class="font-medium text-gray-900">{{ $r['product']->label() }}</span><span class="block text-xs text-gray-500">{{ $r['product']->pack_size }}</span></td>
                                    <td class="whitespace-nowrap px-5 py-2 text-right font-mono tabular-nums text-gray-700">{{ $r['counted'] ? $say($r, $r['counted_pieces']) : $r['product']->stockLabel($r['now_packs'], $r['now_loose']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="px-5 py-8 text-center text-gray-500">No product matched the records.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-panel>
                </div>

                @if ($uncounted->isNotEmpty())
                    <div x-show="section === 'uncounted'" @if ($section !== 'uncounted') x-cloak @endif class="print:!block">
                    <x-panel>
                        <div class="border-b border-gray-200 px-5 py-3">
                            <h2 class="text-base font-semibold text-gray-900">{{ $uncounted->count() }} {{ Str::plural('product', $uncounted->count()) }}</h2>
                            <p class="text-sm text-gray-500">Not counted, so left out of every figure. Send the audit back for a recount to count them.</p>
                        </div>
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($uncounted as $r)
                                    <tr>
                                        <td class="px-5 py-2"><span class="font-medium text-gray-900">{{ $r['product']->label() }}</span><span class="block text-xs text-gray-500">{{ $r['product']->pack_size }}</span></td>
                                        <td class="whitespace-nowrap px-5 py-2 text-right font-mono tabular-nums text-gray-700">{{ $r['counted'] ? $say($r, $r['counted_pieces']) : $r['product']->stockLabel($r['now_packs'], $r['now_loose']) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="2" class="px-5 py-8 text-center text-gray-500"></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </x-panel>
                    </div>
                @endif

                <div x-show="section === 'decide'" @if ($section !== 'decide') x-cloak @endif class="space-y-4 print:!block">
                {{-- Where an approved audit went in the books. --}}
                @if ($audit->status === 'approved')
                    <div class="rounded-md border border-gray-200 bg-white px-4 py-3">
                        <p class="font-mono text-[11px] uppercase tracking-wider text-gray-500">In the books</p>
                        @if ($audit->transaction)
                            <p class="mt-1 text-sm text-gray-800">
                                Posted on {{ $audit->transaction->business_date->format('j M Y') }} as stock adjustment
                                <span class="font-mono">#{{ $audit->transaction->id }}</span>, at cost:
                            </p>
                            <dl class="mt-2 divide-y divide-gray-100 text-sm">
                                @if ($s['short']->isNegative())
                                    <div class="flex justify-between gap-3 py-1.5">
                                        <dt class="text-gray-700">Stock short — a loss, off net profit<span class="block text-xs text-gray-500">Stock Variance debited, Stock credited</span></dt>
                                        <dd class="font-mono tabular-nums text-red-700">−{{ $s['short']->absolute()->format() }}</dd>
                                    </div>
                                @endif
                                @if ($s['extra']->isPositive())
                                    <div class="flex justify-between gap-3 py-1.5">
                                        <dt class="text-gray-700">Stock found — added to stock and profit<span class="block text-xs text-gray-500">Stock debited, Stock Variance credited</span></dt>
                                        <dd class="font-mono tabular-nums text-sky-800">+{{ $s['extra']->format() }}</dd>
                                    </div>
                                @endif
                                <div class="flex justify-between gap-3 py-1.5 font-semibold">
                                    <dt>Change in stock value</dt>
                                    <dd class="font-mono tabular-nums {{ $s['change']->isNegative() ? 'text-red-700' : 'text-sky-800' }}">{{ $signed($s['change']) }}</dd>
                                </div>
                            </dl>
                            <p class="mt-2 text-xs text-gray-500">
                                Shown in the Daily Log of {{ $audit->transaction->business_date->format('j M') }} under Business standing, and in the month's net profit.
                                @can('viewLedger', $business)
                                    <a href="{{ route('businesses.ledger.account', [$business, \App\Enums\AccountCode::StockVariance->value]) }}" class="font-medium text-emerald-700 hover:text-emerald-800">Stock Variance account</a>
                                @endcan
                            </p>
                        @else
                            <p class="mt-1 text-sm text-gray-800">Every product matched, so nothing was posted. Stock value is unchanged.</p>
                        @endif
                    </div>
                @endif

                @if ($audit->status === 'submitted')
                    <div class="rounded-md border border-gray-300 bg-gray-50 px-4 py-3 print:hidden">
                        <p class="font-mono text-[11px] uppercase tracking-wider text-gray-500">Decide</p>
                        @if ($canDecide)
                            <p class="mt-1 text-sm leading-relaxed text-gray-800">
                                @if ($diffs->isEmpty())
                                    Every product counted matches the records. Approving records the audit; no stock changes.
                                @else
                                    Approving sets the stock of the {{ $diffs->count() }} differing {{ Str::plural('product', $diffs->count()) }} to what was counted{{ $diffs->contains(fn ($r) => $r['diff_pieces'] > 0) ? '; extra goods go in with the batch and expiry they were found under' : '' }},
                                    and books the value today at cost.
                                    @if ($s['short']->isNegative())
                                        <span class="font-mono font-semibold text-red-700">{{ $s['short']->absolute()->format() }}</span> short is a loss that comes off net profit.
                                    @endif
                                    @if ($s['extra']->isPositive())
                                        <span class="font-mono font-semibold text-sky-800">{{ $s['extra']->format() }}</span> found is added to stock and to profit.
                                    @endif
                                    The stock value {{ $s['change']->isNegative() ? 'falls' : 'rises' }} by <span class="font-mono font-semibold">{{ $s['change']->absolute()->format() }}</span>.
                                @endif
                            </p>
                            <form method="POST" class="mt-3 space-y-2">
                                @csrf
                                <input type="text" name="reason" maxlength="255" placeholder="Reason (optional)"
                                       class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                <div class="flex flex-wrap gap-2">
                                    <button formaction="{{ route('businesses.company-audits.approve', [$business, $audit]) }}"
                                            class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Approve corrections</button>
                                    <button formaction="{{ route('businesses.company-audits.close', [$business, $audit]) }}"
                                            class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-900 hover:bg-gray-50">Keep records, note as miscount</button>
                                </div>
                            </form>
                        @else
                            <p class="mt-1 text-sm text-gray-700">Waiting for the owner to approve it. Stock has not changed.</p>
                        @endif
                    </div>
                @endif
                    @if ($audit->status === 'counting')
                        <p class="text-sm text-gray-600">Still being counted.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-workspace-layout>
