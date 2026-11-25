{{--
    The Close day window, opened from the button at the top of the Daily Log.

    Left: what must be done first. Right: the one figure the app cannot work
    out for itself — what is actually in the drawer — and the close. "It
    matches" only fills in the box; someone still counts and saves. Saving the
    count comes back to the day with this window open again.

    Also the Reopen day window for the latest closed day.

    Needs: $business, $day, $close, $closeState.
--}}
@php
    [
        'figures' => $figures, 'closing' => $closing, 'checks' => $checks,
        'canClose' => $canClose, 'openingCashCorrection' => $openingCashCorrection,
    ] = $close;
    $rs = fn ($m) => ($m->isNegative() ? '−' : '') . $m->absolute()->format();
    $due = $closeState['due'];

    // The drawer holds only the day's own cash: the owner takes the takings
    // home each day. What they already had is added back when saving.
    $carried = $figures['opening_cash']->plus($openingCashCorrection);
    $drawerCash = $figures['closing_cash']->minus($carried);
    $drawerCounted = $closing?->counted_cash?->minus($carried);
@endphp

@if (! $closeState['isClosed'] && $closeState['closable'])
    <x-modal name="close-day" :maxWidth="$seesProfit ? '6xl' : '4xl'"
             :show="session('closeDay') || request()->boolean('close') || $errors->hasAny(['counted_cash', 'variance_reason', 'status'])">
        <div class="flex items-start justify-between gap-4 border-b border-gray-200 px-6 py-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Close {{ $day->format('D d M Y') }}</h2>
                <p class="mt-0.5 text-sm text-gray-500">Check the list, count the cash, then close. All figures come from the day's records.</p>
            </div>
            <button type="button" x-on:click="$dispatch('close')" aria-label="Close this window"
                    class="rounded-md px-2 py-1 text-lg leading-none text-gray-400 hover:bg-gray-100 hover:text-gray-700">×</button>
        </div>

        <div class="space-y-4 p-6">
            <x-input-error :messages="$errors->get('status')" />
            @if (session('closeDay') && session('status'))
                <div class="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ session('status') }}</div>
            @endif
            @if ($closeState['dueLater'])
                <div class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    <strong>{{ $due->format('D d M') }} is still open.</strong> Days are closed in order.
                    <a href="{{ route('businesses.daily.day', [$business, $due->toDateString(), 'section' => 'standing']) }}" class="font-semibold underline">Go to {{ $due->format('d M') }}</a>
                </div>
            @endif

            <div @class(['grid gap-6 md:grid-cols-2', 'lg:grid-cols-3' => $seesProfit])>
                {{-- 1. Before closing --}}
                <div>
                    <h3 class="text-sm font-semibold text-gray-900">1 · Before closing</h3>
                    <ul class="mt-2 divide-y divide-gray-100 rounded-lg border border-line text-sm">
                        @foreach ($checks as $check)
                            {{-- A failed advisory is worth reading but does not hold the day; a failed blocker does. --}}
                            <li class="flex items-start gap-3 px-3 py-2.5">
                                <span class="mt-0.5 font-semibold {{ $check['ok'] ? 'text-emerald-600' : ($check['blocking'] ? 'text-amber-600' : 'text-sky-600') }}"
                                      aria-label="{{ $check['ok'] ? 'Done' : ($check['blocking'] ? 'Still to do' : 'For information') }}">
                                    {{ $check['ok'] ? '✓' : ($check['blocking'] ? '!' : 'i') }}
                                </span>
                                <span>
                                    <span class="font-medium {{ $check['ok'] ? 'text-gray-900' : ($check['blocking'] ? 'text-amber-800' : 'text-gray-900') }}">{{ $check['label'] }}</span>
                                    @if (! $check['blocking'] && ! $check['ok'])
                                        <span class="text-xs text-sky-700">· does not block closing</span>
                                    @endif
                                    <span class="block text-xs text-gray-500">{{ $check['detail'] }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- 2. Count the cash, 3. Close --}}
                <div class="space-y-4">
                    @can('reconcile', [App\Models\DailyClosing::class, $business])
                        <form method="POST" action="{{ route('businesses.closing.reconcile', [$business, $day->toDateString()]) }}"
                              class="space-y-3"
                              x-data="{
                                  expected: {{ $drawerCash->toDecimal() }},
                                  carried: {{ $carried->toDecimal() }},
                                  counted: @js(old('counted_cash', $drawerCounted?->toDecimal() ?? '')),
                                  n(v) { const p = parseFloat(String(v ?? '').replace(/,/g, '')); return isNaN(p) ? null : p; },
                                  get diff() { const c = this.n(this.counted); return c === null ? null : Math.round((c - this.expected) * 100) / 100; },
                                  fmt(v) { return Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                              }">
                            @csrf
                            <h3 class="text-sm font-semibold text-gray-900">2 · Count the cash</h3>

                            <div class="rounded-md bg-gray-50 px-4 py-3">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Should be in the drawer today</p>
                                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums {{ $drawerCash->isNegative() ? 'text-red-700' : 'text-gray-900' }}">
                                    Rs. {{ $rs($drawerCash) }}
                                </p>
                                <p class="mt-1 text-xs text-gray-500">
                                    Today's cash only: received {{ $rs($figures['cash_sales']->plus($figures['collections'])) }},
                                    paid out {{ $rs($figures['purchases_paid']->plus($figures['company_payments'])->plus($figures['expenses'])) }}.
                                    Each line is under "Cash in the drawer today" in Business standing.
                                </p>
                            </div>

                            @if ($drawerCash->isNegative())
                                <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                    <strong>More cash was paid out today than came in.</strong>
                                    The rest came from the owner's cash. You can still close the day. Enter the count with a minus sign.
                                </div>
                            @endif

                            <div>
                                <x-input-label for="counted_cash" value="Cash counted in the drawer" />
                                <div class="mt-1 flex gap-2">
                                    <x-text-input id="counted_cash" name="counted_cash" type="text" inputmode="decimal"
                                                  class="block w-full text-right font-mono tabular-nums"
                                                  x-model="counted" placeholder="0.00" required />
                                    <button type="button" x-on:click="counted = expected.toFixed(2)"
                                            class="shrink-0 whitespace-nowrap rounded-md border border-emerald-600 px-3 text-sm font-medium text-emerald-800 hover:bg-emerald-50"
                                            title="Use only after counting. Fills in the expected amount.">
                                        Counted, it matches
                                    </button>
                                </div>
                                <x-input-error :messages="$errors->get('counted_cash')" class="mt-2" />

                                <p class="mt-2 text-sm" x-show="diff !== null" x-cloak>
                                    <span x-show="diff === 0" class="font-medium text-emerald-700">✓ Matches exactly.</span>
                                    <span x-show="diff < 0" class="font-medium text-red-700">▼ Short by Rs. <span x-text="fmt(diff)"></span>. Less cash than expected.</span>
                                    <span x-show="diff > 0" class="font-medium text-amber-700">▲ Over by Rs. <span x-text="fmt(diff)"></span>. More cash than expected.</span>
                                </p>
                            </div>

                            {{-- Where the owner stands once the drawer goes home. --}}
                            <div class="flex items-baseline justify-between gap-3 rounded-md border border-line px-4 py-2 text-sm">
                                <span class="text-gray-600">Cash the owner should have after today
                                    <span class="block text-xs text-gray-500">Earlier days {{ $rs($carried) }} + the drawer</span>
                                </span>
                                <span class="font-mono font-semibold tabular-nums text-gray-900"
                                      x-text="'Rs. ' + (n(counted) === null ? fmt(expected + carried) : (n(counted) + carried < 0 ? '−' : '') + fmt(n(counted) + carried))">Rs. {{ $rs($figures['closing_cash']) }}</span>
                            </div>

                            @if ($drawerCounted !== null)
                                <div class="rounded-md {{ $closing->hasCashVariance() ? 'bg-amber-50 text-amber-900' : 'bg-emerald-50 text-emerald-900' }} px-3 py-2 text-sm">
                                    Last saved count: Rs. {{ $rs($drawerCounted) }}
                                    {{ $closing->hasCashVariance() ? '· Difference ' . $rs($closing->cash_variance) : '✓ Matched' }}
                                </div>
                            @endif

                            {{-- Only asked for when there is something to explain. --}}
                            <div x-show="diff !== null && diff !== 0" x-cloak>
                                <x-input-label for="variance_reason" value="Reason for the difference" />
                                <x-text-input id="variance_reason" name="variance_reason" type="text" class="mt-1 block w-full"
                                              :value="old('variance_reason', $closing?->variance_reason)"
                                              placeholder="Owner took 2,000 for fuel" />
                                <x-input-error :messages="$errors->get('variance_reason')" class="mt-2" />
                            </div>

                            <button class="w-full rounded-md border border-emerald-700 bg-white px-3 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-50">
                                Save cash count
                            </button>
                        </form>
                    @endcan

                    @can('finalize', [App\Models\DailyClosing::class, $business])
                        <div class="space-y-2 border-t border-gray-200 pt-4">
                            <h3 class="text-sm font-semibold text-gray-900">3 · Close the day</h3>
                            @if ($canClose)
                                <form method="POST" action="{{ route('businesses.closing.finalize', [$business, $day->toDateString()]) }}">
                                    @csrf
                                    <p class="mb-3 text-sm text-gray-600">
                                        Closing makes these figures final. Nothing can be posted on or before
                                        {{ $day->format('d M Y') }} afterwards unless the day is reopened.
                                    </p>
                                    <button class="w-full rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                                        Close the day
                                    </button>
                                </form>
                            @else
                                <button type="button" disabled
                                        class="w-full cursor-not-allowed rounded-md bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-400">
                                    {{ $closeState['dueLater'] ? 'Close ' . $due->format('d M') . ' first' : 'Finish the list first' }}
                                </button>
                                <p class="text-xs text-gray-500">The items marked ! under "Before closing" must be done before this day can be closed.</p>
                            @endif
                        </div>
                    @endcan
                </div>

                {{-- The month so far: what the owner has earned from the 1st up to tonight. --}}
                @if ($seesProfit)
                    @php
                        $m = $close['month'];
                        $monthLines = array_values(array_filter([
                            ['Sales', $m['sales'], false],
                            ['− Purchase price of these sales', $m['cogs'], false],
                            ['Gross profit', $m['gross_profit'], true],
                            ['− Expenses', $m['expenses'], false],
                            $m['written_off']->isZero() ? null : ['− Stock and debts written off', $m['written_off'], false],
                        ]));
                        $monthLoss = $m['net_profit']->isNegative();
                    @endphp
                    <div class="md:col-span-2 lg:col-span-1">
                        <h3 class="text-sm font-semibold text-gray-900">This month so far</h3>
                        <p class="text-xs text-gray-500">{{ $day->copy()->startOfMonth()->format('d M') }} – {{ $day->format('d M Y') }}</p>

                        <div @class(['mt-2 rounded-lg border px-4 py-4',
                                     'border-emerald-200 bg-emerald-50' => ! $monthLoss,
                                     'border-red-200 bg-red-50' => $monthLoss])>
                            <p @class(['text-xs font-medium uppercase tracking-wide', 'text-emerald-800' => ! $monthLoss, 'text-red-800' => $monthLoss])>
                                {{ $monthLoss ? 'Net loss till date' : 'Net profit till date' }}
                            </p>
                            <p @class(['mt-1 font-mono text-2xl font-semibold tabular-nums', 'text-emerald-800' => ! $monthLoss, 'text-red-800' => $monthLoss])>
                                Rs. {{ $rs($m['net_profit']) }}
                            </p>
                            <p @class(['mt-1 text-xs', 'text-emerald-800' => ! $monthLoss, 'text-red-800' => $monthLoss])>
                                Today {{ $rs($figures['net_profit']) }}
                            </p>
                        </div>

                        <dl class="mt-3 divide-y divide-gray-100 rounded-lg border border-line text-sm">
                            @foreach ($monthLines as [$label, $value, $strong])
                                <div @class(['flex justify-between gap-3 px-3 py-2', 'font-semibold' => $strong])>
                                    <dt class="{{ $strong ? 'text-gray-900' : 'text-gray-600' }}">{{ $label }}</dt>
                                    <dd class="whitespace-nowrap font-mono tabular-nums {{ $value->isNegative() ? 'text-red-700' : 'text-gray-900' }}">{{ $rs($value) }}</dd>
                                </div>
                            @endforeach
                            <div class="flex justify-between gap-3 bg-gray-50 px-3 py-2 font-semibold">
                                <dt class="text-gray-900">Net profit</dt>
                                <dd class="whitespace-nowrap font-mono tabular-nums {{ $monthLoss ? 'text-red-700' : 'text-emerald-800' }}">{{ $rs($m['net_profit']) }}</dd>
                            </div>
                        </dl>
                    </div>
                @endif
            </div>
        </div>
    </x-modal>
@endif

{{-- Reopening is deliberate: it asks why, and says what it will do. --}}
@if ($closeState['canReopen'])
    <x-modal name="reopen-day" :show="$errors->has('reason')" maxWidth="md">
        <form method="POST" action="{{ route('businesses.closing.reopen', [$business, $day->toDateString()]) }}" class="p-6">
            @csrf
            <h2 class="text-lg font-semibold text-gray-900">Reopen {{ $day->format('D d M Y') }}?</h2>
            <p class="mt-2 text-sm text-gray-600">
                The day will be unlocked so its entries can be changed. The figures are not final until the
                day is closed again, and the cash must be counted again. The previous closing and your
                reason are kept on record.
            </p>
            <div class="mt-4">
                <x-input-label for="reason" value="Reason for reopening" />
                <x-text-input id="reason" name="reason" type="text" class="mt-1 block w-full"
                              :value="old('reason')" placeholder="Company invoice for this day arrived late"
                              required minlength="5" />
                <x-input-error :messages="$errors->get('reason')" class="mt-2" />
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" x-on:click="$dispatch('close')"
                        class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                <button class="rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-700">Reopen day</button>
            </div>
        </form>
    </x-modal>
@endif
