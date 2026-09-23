{{--
    Every closed day, newest first. Ten columns, each figure with its day's
    movement underneath, so the list fits a laptop without scrolling sideways.
    Green is good for the business and red is not — for money owed, that means
    going down is green.
--}}
@php($statuses = [
    'finalized' => ['Closed', 'bg-emerald-50 text-emerald-800 ring-emerald-600/20'],
    'superseded' => ['Reopened', 'bg-amber-50 text-amber-800 ring-amber-600/20'],
    'draft' => ['Counted, not closed', 'bg-gray-100 text-gray-600 ring-gray-500/20'],
])
@php($money = fn ($m) => ($m->isNegative() ? '−' : '') . $m->absolute()->format())
@php($move = fn ($m, bool $upIsGood) => $m->isZero()
    ? ['no change', 'text-gray-400']
    : [($m->isPositive() ? '▲ +' : '▼ −') . $m->absolute()->format(),
       $m->isPositive() === $upIsGood ? 'text-emerald-700' : 'text-red-700'])

<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Daily closings</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Closed through
                    <span class="font-medium text-gray-700">{{ $business->locked_through_date?->format('D d M Y') ?? 'nothing yet' }}</span>.
                    A closed day is locked; reopen it from its own page to change it.
                </p>
            </div>
            <a href="{{ route('businesses.closing.show', $business) }}"
               class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                Close a day
            </a>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        <x-panel>
            <table class="w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        @foreach ([
                            ['Day', 'left'],
                            ['Sales', 'right'],
                            ['Gross profit', 'right'],
                            ['Expenses', 'right'],
                            ['After expenses', 'right'],
                            ['Cash in drawer', 'right'],
                            ['Market owes us', 'right'],
                            ['We owe companies', 'right'],
                            ['Net position', 'right'],
                            ['Closed by', 'left'],
                        ] as [$head, $align])
                            <th class="px-3 py-2 align-bottom text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }}">
                                {{ $head }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($closings as $closing)
                        @php([$statusLabel, $statusClasses] = $statuses[$closing->status] ?? [ucfirst($closing->status), $statuses['draft'][1]])
                        @php($stale = $closing->status === 'superseded')
                        <tr @class(['align-top', 'bg-gray-50/70' => $stale])>
                            <td class="whitespace-nowrap px-3 py-2">
                                <a href="{{ route('businesses.closing.show', [$business, $closing->business_date->toDateString()]) }}"
                                   class="font-medium text-gray-900 hover:text-emerald-700">
                                    {{ $closing->business_date->format('D d M Y') }}
                                </a>
                                <div class="mt-1">
                                    <x-badge :classes="$statusClasses">{{ $statusLabel }}</x-badge>
                                </div>
                            </td>

                            {{-- A reopened day's figures are from its last close and no longer
                                 stand; they are shown faded for reference only. --}}
                            <td @class(['whitespace-nowrap px-3 py-2 text-right tabular-nums', 'text-gray-400' => $stale, 'text-gray-900' => ! $stale])>
                                {{ $money($closing->sales) }}
                            </td>

                            <td @class(['whitespace-nowrap px-3 py-2 text-right tabular-nums', 'text-gray-400' => $stale, 'text-gray-900' => ! $stale])>
                                {{ $money($closing->gross_profit) }}
                            </td>

                            {{-- Spent on the day: salaries, fuel, freight and the rest. --}}
                            <td @class(['whitespace-nowrap px-3 py-2 text-right tabular-nums', 'text-gray-400' => $stale, 'text-gray-900' => ! $stale])>
                                {{ $closing->expenses->isZero() ? '—' : $money($closing->expenses) }}
                            </td>

                            <td @class(['whitespace-nowrap px-3 py-2 text-right font-medium tabular-nums',
                                        'text-gray-400' => $stale,
                                        'text-red-700' => ! $stale && $closing->net_profit->isNegative(),
                                        'text-emerald-700' => ! $stale && ! $closing->net_profit->isNegative()])>
                                {{ $money($closing->net_profit) }}
                            </td>

                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                                <div @class(['font-medium',
                                             'text-gray-400' => $stale,
                                             'text-red-700' => ! $stale && $closing->closing_cash->isNegative(),
                                             'text-gray-900' => ! $stale && ! $closing->closing_cash->isNegative()])>
                                    {{ $money($closing->closing_cash) }}
                                </div>
                                <div class="text-xs">
                                    @if ($closing->counted_cash === null)
                                        <span class="text-gray-400">not counted</span>
                                    @elseif (! $closing->hasCashVariance())
                                        <span class="text-emerald-700">✓ count matched</span>
                                    @else
                                        @php([$varText, $varClass] = $move($closing->cash_variance, true))
                                        <span class="{{ $varClass }}">count {{ $varText }}</span>
                                    @endif
                                </div>
                            </td>

                            @foreach ([
                                [$closing->closing_receivable, $closing->receivable_delta],
                                [$closing->closing_payable, $closing->payable_delta],
                            ] as [$balance, $delta])
                                {{-- Money owed going down is the good direction. --}}
                                @php([$moveText, $moveClass] = $move($delta, false))
                                <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                                    <div @class(['text-gray-400' => $stale, 'text-gray-900' => ! $stale])>{{ $money($balance) }}</div>
                                    <div class="text-xs {{ $stale ? 'text-gray-400' : $moveClass }}">{{ $moveText }}</div>
                                </td>
                            @endforeach

                            <td @class(['whitespace-nowrap px-3 py-2 text-right font-medium tabular-nums',
                                        'text-gray-400' => $stale,
                                        'text-red-700' => ! $stale && $closing->net_position->isNegative(),
                                        'text-gray-900' => ! $stale && ! $closing->net_position->isNegative()])>
                                {{ $money($closing->net_position) }}
                            </td>

                            <td class="px-3 py-2">
                                @if ($closing->finalizer)
                                    <div class="whitespace-nowrap text-gray-900">
                                        {{ $closing->finalizer->name }}
                                        @php($role = $closing->finalizer->isPlatformAdmin() ? null : $closing->finalizer->roleIn($business)?->label())
                                        @if ($role)
                                            <span class="text-gray-500">· {{ $role }}</span>
                                        @endif
                                    </div>
                                    <div class="whitespace-nowrap text-xs text-gray-500">
                                        {{ $stale ? 'closed' : '' }}
                                        {{ $closing->finalized_at?->timezone($business->timezone)->format('d M, h:i A') }}
                                    </div>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                                @if ($stale && $closing->reopen_reason)
                                    <div class="mt-0.5 max-w-[16rem] text-xs text-amber-800">
                                        Reopened: <span class="italic">“{{ $closing->reopen_reason }}”</span>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-6 py-10 text-center text-gray-500">No days closed yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-panel>

        <div class="mt-4">{{ $closings->links() }}</div>
    </div>
</x-workspace-layout>
