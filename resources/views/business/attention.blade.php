{{--
    What to do, as opposed to where the business stands.

    Two cards, one to a row and full width. The first is the day itself: every
    activity that has to happen before today is finished, in the order it
    happens, with each step's state read out of the records rather than from a
    box somebody ticked. The second is what the figures themselves are saying.

    Nothing on this page can be ticked off or dismissed — see the note at the
    foot for why that is deliberate.
--}}
@php
    /*
     * Unexplained opening equity belongs in the attention list rather than in a
     * banner of its own. It is the same kind of thing as the rest — something
     * calculated that somebody has to answer for.
     */
    $attention = $alerts;

    if ($showPosition && ! $openingEquity->isZero()) {
        $attention[] = [
            'level' => 'warning',
            'title' => 'Opening equity unexplained',
            'detail' => "Opening Balance Equity of {$openingEquity->format()} is still unexplained. "
                . 'It remains here until it is explained or corrected.',
        ];
    }

    $critical = collect($attention)->where('level', 'critical')->count();

    $total = $day['done'] + $day['outstanding'];
@endphp

<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Tasks</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Steps required to complete {{ $day['date']->format('D d M Y') }}.
                    For the business position, open the Dashboard.
                </p>
            </div>
            <a href="{{ route('businesses.show', $business) }}"
               class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Dashboard
            </a>
        </div>
    </x-slot>

    <div class="w-full space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        {{-- Completing the day. Every step carries its own way through, so this
             is the quick actions as well — in the order they happen, and saying
             which of them has already been done. --}}
        @if ($day['steps'] !== [])
            <div class="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 sm:px-6">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Steps to complete the day</p>
                        <p class="mt-0.5 text-sm text-gray-500">{{ $day['date']->format('D d M Y') }}</p>
                    </div>

                    <div class="flex items-center gap-3">
                        {{-- A step that is nobody's job today is not in the
                             total, so this is never short for want of a
                             delivery that was never coming. --}}
                        <span class="text-sm font-semibold tabular-nums text-gray-900">
                            {{ $day['done'] }} of {{ $total }} done
                        </span>
                        <x-badge :classes="$day['complete']
                            ? 'bg-emerald-600 text-white ring-emerald-700/20'
                            : 'bg-amber-500 text-white ring-amber-600/20'">
                            {{ $day['complete']
                                ? 'Day complete'
                                : $day['outstanding'] . ' ' . Str::plural('step', $day['outstanding']) . ' left' }}
                        </x-badge>
                    </div>
                </div>

                <div class="h-1 w-full bg-gray-100">
                    <div class="h-1 {{ $day['complete'] ? 'bg-emerald-600' : 'bg-amber-500' }}"
                         style="width: {{ $total > 0 ? round($day['done'] / $total * 100) : 0 }}%"></div>
                </div>

                <ul class="divide-y divide-gray-100">
                    @foreach ($day['steps'] as $step)
                        @php($done = $step['state'] === \App\Domain\Reporting\DayChecklist::DONE)
                        @php($blocked = $step['state'] === \App\Domain\Reporting\DayChecklist::BLOCKED)
                        @php($nothing = $step['state'] === \App\Domain\Reporting\DayChecklist::NONE)
                        @php($open = ! $done && ! $blocked && ! $nothing)

                        <li class="flex flex-wrap items-start gap-3 px-4 py-4 sm:flex-nowrap sm:px-6">
                            {{-- The mark says which of the four states it is in,
                                 and never by colour alone: done is a tick,
                                 blocked a dash, and a step nobody has to do is
                                 left hollow. --}}
                            <span @class([
                                'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-bold',
                                'bg-emerald-600 text-white' => $done,
                                'border-2 border-amber-500 text-amber-600' => $open,
                                'border-2 border-gray-300 text-gray-400' => $blocked || $nothing,
                            ])>
                                {{ $done ? '✓' : ($blocked ? '–' : ($open ? '!' : '')) }}
                            </span>

                            <div class="min-w-0 grow">
                                <p @class([
                                    'text-sm font-semibold',
                                    'text-gray-500' => $done || $nothing,
                                    'text-gray-900' => ! $done && ! $nothing,
                                ])>
                                    {{ $step['title'] }}
                                    @if ($blocked)
                                        <span class="ml-1 text-xs font-medium uppercase tracking-wide text-gray-400">not yet</span>
                                    @elseif ($nothing)
                                        <span class="ml-1 text-xs font-medium uppercase tracking-wide text-gray-400">not required</span>
                                    @endif
                                </p>
                                <p class="mt-0.5 text-sm text-gray-600">{{ $step['detail'] }}</p>

                                {{-- Why the step matters, kept quiet. Anybody who
                                     already knows reads past it; anybody
                                     wondering why they are being asked does not
                                     have to go looking for the reason. --}}
                                <p class="mt-1 text-xs leading-snug text-gray-400">{{ $step['why'] }}</p>
                            </div>

                            <a href="{{ route($step['route'], $business) }}"
                               @if ($step['new_tab'] ?? false) target="_blank" rel="noopener" @endif
                               @class([
                                   'shrink-0 whitespace-nowrap rounded-md px-3 py-2 text-sm font-semibold transition',
                                   'bg-emerald-700 text-white hover:bg-emerald-800' => $open,
                                   'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! $open,
                               ])>
                                {{ $step['action'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- A card even when it is empty: "nothing is wrong" is a finding, and a
             section that vanishes cannot say whether it was checked or is
             simply missing. It is left out entirely for a role that is not shown
             the day's money, rather than telling them everything is clear when
             they were never shown the checks. --}}
        @if ($seesTheMoney)
            <div class="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 sm:px-6">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Attention</p>
                        <p class="mt-0.5 text-sm text-gray-500">
                            Issues found in the figures
                        </p>
                    </div>
                    <x-badge :classes="$attention === []
                        ? 'bg-emerald-50 text-emerald-800 ring-emerald-600/20'
                        : ($critical > 0
                            ? 'bg-red-600 text-white ring-red-700/20'
                            : 'bg-amber-500 text-white ring-amber-600/20')">
                        {{ $attention === [] ? 'No issues' : count($attention) . ' ' . Str::plural('item', count($attention)) }}
                    </x-badge>
                </div>

                @if ($attention === [])
                    <p class="px-4 py-6 text-sm text-gray-500 sm:px-6">
                        No issues found. All checks are clear: market credit against sales, collections
                        against credit sales, cash cover, recurring cash differences, stock verification
                        and open days.
                    </p>
                @else
                    <ul class="divide-y divide-gray-100">
                        @foreach ($attention as $alert)
                            <li class="flex gap-3 px-4 py-4 sm:px-6">
                                <span @class([
                                    'mt-1.5 h-2 w-2 shrink-0 rounded-full',
                                    'bg-red-600' => $alert['level'] === 'critical',
                                    'bg-amber-500' => $alert['level'] !== 'critical',
                                ])></span>
                                <div>
                                    <p class="text-sm font-semibold text-gray-900">
                                        {{ $alert['title'] }}
                                        @if ($alert['level'] === 'critical')
                                            <span class="ml-1 text-xs font-semibold uppercase tracking-wide text-red-700">critical</span>
                                        @endif
                                    </p>
                                    <p class="mt-1 text-sm leading-relaxed text-gray-600">{{ $alert['detail'] }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        <p class="text-xs leading-relaxed text-gray-500">
            Items on this page cannot be marked as done manually. Each step and item is calculated from
            the records every time the page is opened. A step is shown as done only when it is recorded
            in the accounts.
        </p>
    </div>
</x-workspace-layout>
