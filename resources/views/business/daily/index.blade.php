{{--
    The Daily Log: every day, newest first — what was traded, where the
    balances ended, and whether the day is closed. One list for both the log
    and the closings. A day opens on its own page, where Business standing and
    Close day are.

    A closed day shows the figures it was closed with. An open one is worked
    out now. A reopened day's last closing no longer stands, so it is shown as
    open, with the reason it was reopened.
--}}
@php
    $num = 'whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums';
    $th = 'whitespace-nowrap px-3 py-2 align-bottom text-xs font-semibold uppercase tracking-wide text-gray-500';
    $money = fn ($m) => ($m->isNegative() ? '−' : '') . $m->absolute()->format();
    // Money owed going down is good; cash going up is good.
    $move = fn ($m, bool $upIsGood) => $m->isZero()
        ? ['= no change', 'text-gray-400']
        : [($m->isPositive() ? '▲ ' : '▼ ') . $m->absolute()->format(), $m->isPositive() === $upIsGood ? 'text-emerald-700' : 'text-red-700'];

    // Days close in order: the one after the lock is due; the opening day never closes.
    $due = $business->locked_through_date?->copy()->addDay()
        ?? $business->opening_date?->copy()->addDay()
        ?? $business->today();
    $dueKey = $due->toDateString();
    $openingKey = $business->opening_date?->toDateString();
    $canClose = $seesClosing && auth()->user()->can('reconcile', [App\Models\DailyClosing::class, $business])
        && $dueKey <= $business->today()->toDateString();

    $heads = array_values(array_filter([
        ['Day', 'left'],
        ['Sales', 'right'],
        $seesProfit ? ['Gross profit', 'right'] : null,
        ['Purchases', 'right'],
        ['Expenses', 'right'],
        $seesProfit ? ['Net profit', 'right'] : null,
        $seesClosing ? ['Cash with owner', 'right'] : null,
        $seesClosing ? ['Client pending', 'right'] : null,
        $seesClosing ? ['Supplier pending', 'right'] : null,
        $seesClosing ? ['Closed by', 'left'] : null,
    ]));
@endphp
<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Daily Log</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Each day's trading, where the balances ended, and whether it is closed.
                    @if ($seesClosing)
                        Closed up to <span class="font-medium text-gray-700">{{ $business->locked_through_date?->format('D d M Y') ?? 'no day yet' }}</span>.
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('businesses.daily.day', $business) }}"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Open today
                </a>
                @if ($canClose)
                    {{-- Straight to the day due, with the Close day window open. --}}
                    <a href="{{ route('businesses.daily.day', [$business, $dueKey, 'section' => 'standing', 'close' => 1]) }}"
                       class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                        Close {{ $due->format('D d M') }}
                    </a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        {{-- Days between two dates; either end may be left open. --}}
        <x-filter-bar class="mb-4" submit="Show" :active="$from !== null || $to !== null || $status !== null"
                      :clear="route('businesses.daily.index', $business)">
            <div>
                <x-input-label for="from" value="From" />
                <input id="from" name="from" type="date" value="{{ $from?->toDateString() }}" max="{{ $business->today()->toDateString() }}"
                       class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <x-input-label for="to" value="To" />
                <input id="to" name="to" type="date" value="{{ $to?->toDateString() }}" max="{{ $business->today()->toDateString() }}"
                       class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            @if ($seesClosing)
                <div>
                    <x-input-label for="status" value="Days" />
                    <select id="status" name="status"
                            class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                        <option value="">All days</option>
                        <option value="closed" @selected($status === 'closed')>Closed</option>
                        <option value="open" @selected($status === 'open')>Not closed yet</option>
                    </select>
                </div>
            @endif
            <x-slot name="count">{{ number_format($days->total()) }} of {{ number_format($total) }} {{ Str::plural('day', $total) }}</x-slot>
        </x-filter-bar>

        <x-panel>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach ($heads as [$head, $align])
                                <th class="{{ $th }} text-{{ $align }} {{ $loop->first ? 'sm:px-6' : '' }}">{{ $head }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($days as $row)
                            @php
                                ['date' => $date, 'entry' => $entry, 'closing' => $closing, 'figures' => $live] = $row;
                                $key = $date->toDateString();
                                $isClosed = (bool) $closing?->isFinalized();
                                $zero = \App\Support\Money::zero();

                                $purchases = $entry ? \App\Support\Money::sum($entry->purchaseLines->filter(fn ($l) => $l->amount->isPositive())->pluck('amount')) : $zero;

                                // Closed: the figures it was closed with. Open: as they stand now.
                                $f = $isClosed ? [
                                    'sales' => $closing->sales, 'gross_profit' => $closing->gross_profit,
                                    'expenses' => $closing->expenses, 'net_profit' => $closing->net_profit,
                                    'closing_cash' => $closing->closing_cash, 'cash_move' => null,
                                    'closing_receivable' => $closing->closing_receivable, 'receivable_delta' => $closing->receivable_delta,
                                    'closing_payable' => $closing->closing_payable, 'payable_delta' => $closing->payable_delta,
                                ] : ($live ? [
                                    'sales' => $live['sales'], 'gross_profit' => $live['gross_profit'],
                                    'expenses' => $live['expenses'], 'net_profit' => $live['net_profit'],
                                    'closing_cash' => $live['closing_cash'], 'cash_move' => $live['closing_cash']->minus($live['opening_cash']),
                                    'closing_receivable' => $live['closing_receivable'], 'receivable_delta' => $live['receivable_delta'],
                                    'closing_payable' => $live['closing_payable'], 'payable_delta' => $live['payable_delta'],
                                ] : [
                                    'sales' => $entry?->totalSales() ?? $zero, 'gross_profit' => $entry?->gross_profit ?? $zero,
                                    'expenses' => $entry?->expenses_cash ?? $zero, 'net_profit' => $entry?->netProfit() ?? $zero,
                                ]);

                                [$statusLabel, $statusClasses] = match (true) {
                                    ! $seesClosing => [null, null],
                                    $isClosed => ['Closed', 'bg-emerald-50 text-emerald-800 ring-emerald-600/20'],
                                    $key === $openingKey => ['Opening day', 'bg-gray-100 text-gray-600 ring-gray-500/20'],
                                    $openingKey !== null && $key < $openingKey => ['Before opening', 'bg-gray-100 text-gray-500 ring-gray-500/20'],
                                    $closing?->status === 'superseded' => ['Reopened', 'bg-amber-50 text-amber-800 ring-amber-600/20'],
                                    $key === $dueKey => ['Next to close', 'bg-amber-50 text-amber-800 ring-amber-600/20'],
                                    $closing?->counted_cash !== null => ['Counted, not closed', 'bg-gray-100 text-gray-600 ring-gray-500/20'],
                                    default => ['Open', 'bg-gray-100 text-gray-600 ring-gray-500/20'],
                                };
                            @endphp
                            <tr class="align-top hover:bg-gray-50">
                                <td class="whitespace-nowrap px-3 py-2 sm:px-6">
                                    <a href="{{ route('businesses.daily.day', [$business, $key]) }}"
                                       class="font-medium text-emerald-700 underline decoration-emerald-300 underline-offset-2 hover:text-emerald-900">
                                        {{ $date->format('D d M Y') }}
                                    </a>
                                    @if ($statusLabel)
                                        <div class="mt-1"><x-badge :classes="$statusClasses">{{ $statusLabel }}</x-badge></div>
                                    @endif
                                </td>

                                <td class="{{ $num }} text-gray-900">{{ $money($f['sales']) }}</td>
                                @if ($seesProfit)
                                    <td class="{{ $num }} font-semibold {{ $f['gross_profit']->isNegative() ? 'text-red-700' : 'text-emerald-700' }}">{{ $money($f['gross_profit']) }}</td>
                                @endif
                                <td class="{{ $num }} text-gray-700">{{ $money($purchases) }}</td>
                                <td class="{{ $num }} text-gray-700">{{ $f['expenses']->isZero() ? '—' : $money($f['expenses']) }}</td>
                                @if ($seesProfit)
                                    <td class="{{ $num }} font-semibold {{ $f['net_profit']->isNegative() ? 'text-red-700' : 'text-gray-900' }}">{{ $money($f['net_profit']) }}</td>
                                @endif

                                @if ($seesClosing)
                                    {{-- Cash: on a closed day, whether the count matched; on an open one, how it moved. --}}
                                    <td class="{{ $num }}">
                                        @isset($f['closing_cash'])
                                            <div class="font-medium {{ $f['closing_cash']->isNegative() ? 'text-red-700' : 'text-gray-900' }}">{{ $money($f['closing_cash']) }}</div>
                                            <div class="font-sans text-xs">
                                                @if ($isClosed)
                                                    @if ($closing->counted_cash === null)
                                                        <span class="text-gray-400">Not counted</span>
                                                    @elseif (! $closing->hasCashVariance())
                                                        <span class="text-emerald-700">✓ Count matched</span>
                                                    @else
                                                        @php([$varText, $varClass] = $move($closing->cash_variance, true))
                                                        <span class="{{ $varClass }}">Count {{ $varText }}</span>
                                                    @endif
                                                @else
                                                    @php([$t, $c] = $move($f['cash_move'], true))
                                                    <span class="{{ $c }}">{{ $t }}</span>
                                                @endif
                                            </div>
                                        @endisset
                                    </td>
                                    @foreach ([['closing_receivable', 'receivable_delta'], ['closing_payable', 'payable_delta']] as [$balance, $delta])
                                        <td class="{{ $num }}">
                                            @isset($f[$balance])
                                                @php([$t, $c] = $move($f[$delta], false))
                                                <div class="text-gray-900">{{ $money($f[$balance]) }}</div>
                                                <div class="font-sans text-xs {{ $c }}">{{ $t }}</div>
                                            @endisset
                                        </td>
                                    @endforeach

                                    <td class="px-3 py-2">
                                        @if ($isClosed && $closing->finalizer)
                                            <div class="whitespace-nowrap text-gray-900">
                                                {{ $closing->finalizer->name }}
                                                @php($role = $closing->finalizer->isPlatformAdmin() ? null : $closing->finalizer->roleIn($business)?->label())
                                                @if ($role)<span class="text-gray-500">· {{ $role }}</span>@endif
                                            </div>
                                            <div class="whitespace-nowrap text-xs text-gray-500">{{ $closing->finalized_at?->timezone($business->timezone)->format('d M, h:i A') }}</div>
                                        @elseif ($closing?->status === 'superseded' && $closing->reopen_reason)
                                            <div class="max-w-[16rem] text-xs text-amber-800">Reopened: <span class="italic">“{{ $closing->reopen_reason }}”</span></div>
                                        @elseif ($key === $dueKey && $canClose)
                                            <a href="{{ route('businesses.daily.day', [$business, $key, 'section' => 'standing', 'close' => 1]) }}"
                                               class="whitespace-nowrap text-xs font-semibold text-emerald-700 hover:text-emerald-900">Close this day →</a>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($heads) }}" class="px-6 py-10 text-center text-gray-500">Nothing in the Daily Log yet. Days appear here as soon as something is sold, received, paid or spent.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-panel>

        <div class="mt-4">{{ $days->links() }}</div>
    </div>
</x-workspace-layout>
