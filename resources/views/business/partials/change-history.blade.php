{{--
    Who did what to this day, newest first. Read from the audit trail, which
    keeps every step — an edit never overwrites the name of whoever came before.

    Each step has a coloured marker for what sort of step it was, and an edit
    lists the figures it changed with the difference in green (up) or red (down).
--}}
@php($markers = [
    'good' => ['bg-emerald-500', 'bg-emerald-50 text-emerald-800 ring-emerald-600/20'],
    'change' => ['bg-amber-500', 'bg-amber-50 text-amber-800 ring-amber-600/20'],
    'bad' => ['bg-red-500', 'bg-red-50 text-red-800 ring-red-600/20'],
    'correction' => ['bg-violet-500', 'bg-violet-50 text-violet-800 ring-violet-600/20'],
    'info' => ['bg-sky-500', 'bg-sky-50 text-sky-800 ring-sky-600/20'],
    'neutral' => ['bg-gray-400', 'bg-gray-100 text-gray-700 ring-gray-500/20'],
])
<x-panel :title="$panelTitle ?? 'Change history'"
         :description="$panelDescription ?? 'Everyone who entered, edited, counted, closed or reopened this day — with their role, the time, and the reason they gave.'">
    @if ($history->isEmpty())
        <p class="px-4 py-6 text-sm text-gray-500 sm:px-6">Nothing recorded yet.</p>
    @else
        <ol class="px-4 py-4 sm:px-6">
            @foreach ($history as $item)
                @php([$dot, $badge] = $markers[$item['kind']] ?? $markers['neutral'])
                <li class="relative flex gap-4 pb-5 last:pb-0">
                    {{-- The line joining one step to the next. --}}
                    @unless ($loop->last)
                        <span class="absolute left-[5px] top-4 h-full w-px bg-gray-200" aria-hidden="true"></span>
                    @endunless
                    <span class="relative mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ring-4 ring-white {{ $dot }}" aria-hidden="true"></span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <x-badge :classes="$badge">{{ $item['action'] }}</x-badge>
                                <span class="font-semibold text-gray-900">{{ $item['who'] }}</span>
                                @if ($item['role'] && $item['role'] !== $item['who'])
                                    <span class="text-gray-500">{{ $item['role'] }}</span>
                                @endif
                            </div>
                            <time class="whitespace-nowrap text-xs text-gray-500" datetime="{{ $item['at']->toIso8601String() }}">
                                {{ $item['at']->format('d M Y · h:i A') }}
                            </time>
                        </div>

                        {{-- On the business-wide feed: what the step was about, linked. --}}
                        @if (! empty($item['about']))
                            <p class="mt-1 text-sm">
                                @if (! empty($item['link']))
                                    <a href="{{ $item['link'] }}" class="font-medium text-emerald-700 hover:text-emerald-900">{{ $item['about'] }} →</a>
                                @else
                                    <span class="font-medium text-gray-700">{{ $item['about'] }}</span>
                                @endif
                            </p>
                        @endif

                        @if ($item['detail'] || $item['amount'])
                            <p class="mt-1 flex flex-wrap items-baseline gap-x-2 text-sm text-gray-600">
                                @if ($item['detail'])
                                    <span>{{ $item['detail'] }}</span>
                                @endif
                                @if ($item['amount'])
                                    <span class="font-medium tabular-nums {{ $item['amount']->isNegative() ? 'text-red-700' : 'text-emerald-700' }}">
                                        {{ $item['amount']->isNegative() ? '▼ −' : '▲ +' }}Rs. {{ $item['amount']->absolute()->format() }}
                                    </span>
                                @endif
                            </p>
                        @endif

                        @if ($item['reason'])
                            <p class="mt-1 text-sm text-gray-600">
                                <span class="text-gray-500">Reason:</span> <span class="italic">“{{ $item['reason'] }}”</span>
                            </p>
                        @endif

                        @if ($item['changes'] !== [])
                            <div class="mt-2 overflow-hidden rounded-md border border-gray-200">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                        <tr>
                                            <th class="px-3 py-1.5 text-left font-medium">Figure</th>
                                            <th class="px-3 py-1.5 text-right font-medium">Before</th>
                                            <th class="px-3 py-1.5 text-right font-medium">After</th>
                                            <th class="px-3 py-1.5 text-right font-medium">Change</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($item['changes'] as [$label, $before, $after, $delta])
                                            <tr>
                                                <td class="px-3 py-1.5 text-gray-700">{{ $label }}</td>
                                                {{-- Any of the three may be absent: a first close has no
                                                     "before", a correction only a change. --}}
                                                <td class="px-3 py-1.5 text-right tabular-nums {{ $before === null ? 'text-gray-300' : 'text-gray-400 line-through' }}">{{ $before ?? '—' }}</td>
                                                <td class="px-3 py-1.5 text-right font-medium tabular-nums {{ $after === null ? 'text-gray-300' : (str_starts_with($after, '-') ? 'text-red-700' : 'text-gray-900') }}">{{ $after ?? '—' }}</td>
                                                @if ($delta === null)
                                                    <td class="px-3 py-1.5 text-right tabular-nums text-gray-300">—</td>
                                                @else
                                                    <td class="whitespace-nowrap px-3 py-1.5 text-right font-medium tabular-nums {{ $delta->isNegative() ? 'text-red-700' : 'text-emerald-700' }}">
                                                        {{ $delta->isNegative() ? '▼' : '▲' }}
                                                        {{ $delta->isNegative() ? '−' : '+' }}{{ $delta->absolute()->format() }}
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</x-panel>
