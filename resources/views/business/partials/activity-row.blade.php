{{--
    One thing that happened, on the Activity page: when, what, the details in
    a sentence, who did it, and a link to the record. The figures an edit
    changed stay folded away until asked for.

    $item: a ChangeHistory feed item.
--}}
@php
    $tones = [
        'good' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
        'change' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        'bad' => 'bg-red-50 text-red-800 ring-red-600/20',
        'correction' => 'bg-violet-50 text-violet-800 ring-violet-600/20',
        'info' => 'bg-sky-50 text-sky-800 ring-sky-600/20',
        'neutral' => 'bg-gray-100 text-gray-700 ring-gray-500/20',
    ];
@endphp
<div class="px-4 py-3 sm:px-6" x-data="{ open: false }">
    <div class="grid grid-cols-[4.5rem_minmax(0,1fr)] items-start gap-x-4 gap-y-1 lg:grid-cols-[4.5rem_11rem_minmax(0,1fr)_auto]">
        <time class="whitespace-nowrap pt-0.5 font-mono text-xs tabular-nums text-gray-500" datetime="{{ $item['at']->toIso8601String() }}">
            {{ $item['at']->format('h:i A') }}
        </time>

        <div><x-badge :classes="$tones[$item['kind']] ?? $tones['neutral']">{{ $item['action'] }}</x-badge></div>

        <div class="col-span-2 min-w-0 text-sm lg:col-span-1">
            @if (! empty($item['about']))
                @if (! empty($item['link']))
                    <a href="{{ $item['link'] }}" class="font-semibold text-emerald-700 underline decoration-emerald-300 underline-offset-2 hover:text-emerald-900">{{ $item['about'] }}</a>
                @else
                    <span class="font-semibold text-gray-900">{{ $item['about'] }}</span>
                @endif
            @endif
            @if ($item['detail'])
                <span class="text-gray-700">{{ ! empty($item['about']) ? '· ' : '' }}{{ $item['detail'] }}</span>
            @endif
            @if ($item['reason'])
                <span class="block text-gray-600">Reason: “{{ $item['reason'] }}”</span>
            @endif
            @if ($item['changes'] !== [])
                <button type="button" x-on:click="open = ! open" :aria-expanded="open"
                        class="mt-0.5 block text-xs font-medium text-emerald-700 hover:underline">
                    <span x-text="open ? 'Hide what changed' : 'Show what changed ({{ count($item['changes']) }})'"></span>
                </button>
            @endif
        </div>

        <div class="col-span-2 whitespace-nowrap text-xs text-gray-500 lg:col-span-1 lg:text-right">
            by <span class="font-medium text-gray-900">{{ $item['who'] }}</span>
            @if ($item['role'] && $item['role'] !== $item['who'])
                <span>({{ $item['role'] }})</span>
            @endif
            @if (! empty($item['device']))
                <span class="block" title="The computer this was done on">on {{ $item['device'] }}</span>
            @endif
        </div>
    </div>

    @if ($item['changes'] !== [])
        <div x-show="open" x-cloak class="mt-2 overflow-hidden rounded-md border border-gray-200 lg:ml-[16.5rem]">
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
                            <td class="px-3 py-1.5 text-right tabular-nums text-gray-500">{{ $before ?? '—' }}</td>
                            <td class="px-3 py-1.5 text-right font-medium tabular-nums text-gray-900">{{ $after ?? '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-1.5 text-right font-medium tabular-nums {{ $delta === null ? 'text-gray-300' : ($delta->isNegative() ? 'text-red-700' : 'text-emerald-700') }}">
                                {{ $delta === null ? '—' : ($delta->isNegative() ? '− ' : '+ ') . $delta->absolute()->format() }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
