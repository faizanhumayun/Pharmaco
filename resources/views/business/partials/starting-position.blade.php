{{-- Where the business started: the finalized (or draft) opening balance. --}}
<div x-data="{ open: false }"
     class="rounded-lg border border-gray-200 bg-white shadow-sm">
    <div class="flex flex-wrap items-center gap-x-8 gap-y-3 px-4 py-3 sm:px-6">
        <div class="mr-auto">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Starting position</p>
            <p class="mt-0.5 text-sm font-semibold text-gray-900">
                {{ $startingPoint->opening_date?->format('d M Y') ?? 'Date not set' }}
                @if ($startingPoint->status === \App\Enums\OpeningBalanceStatus::Draft)
                    <x-badge classes="bg-amber-100 text-amber-800 ring-amber-600/20" class="ml-1">
                        Draft — not posted yet
                    </x-badge>
                @endif
            </p>
        </div>

        {{-- As corrected: the figures the business actually started from. --}}
        @php($corrected = $startingPoint->correctedTotals())
        @foreach ([
            ['Assets', $corrected['assets'], $startingPoint->total_assets],
            ['Liabilities', $corrected['liabilities'], $startingPoint->total_liabilities],
            ['Net position', $corrected['net'], $startingPoint->net_position],
        ] as [$label, $value, $entered])
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</p>
                <p class="mt-0.5 font-mono text-sm font-semibold tabular-nums {{ $value->isNegative() ? 'text-red-700' : 'text-gray-900' }}">
                    {{ $value->format() }}
                </p>
                @unless ($value->equals($entered))
                    <p class="text-xs text-gray-400">first entered {{ $entered->format() }}</p>
                @endunless
            </div>
        @endforeach

        <button type="button" x-on:click="open = ! open"
                class="rounded-md border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
            <span x-text="open ? 'Hide detail' : 'Show detail'">Show detail</span>
        </button>
    </div>

    <div x-show="open" x-cloak class="border-t border-gray-100 px-4 py-3 sm:px-6">
        <dl class="grid gap-x-8 gap-y-2 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($startingPoint->lines as $line)
                <div class="flex justify-between gap-4 text-sm">
                    <dt class="text-gray-500">{{ $line->account->name }}</dt>
                    <dd class="font-mono tabular-nums text-gray-900">{{ $line->amount->format() }}</dd>
                </div>
            @endforeach

            @if (! $startingPoint->balancing_figure->isZero())
                <div class="flex justify-between gap-4 text-sm">
                    <dt class="text-gray-500">Balancing figure</dt>
                    <dd class="font-mono tabular-nums {{ $startingPoint->balancing_figure->isNegative() ? 'text-red-700' : 'text-gray-900' }}">
                        {{ $startingPoint->balancing_figure->format() }}
                    </dd>
                </div>
            @endif
        </dl>

        @include('business.partials.opening-corrections')

        @if ($startingPoint->notes)
            <p class="mt-3 border-t border-gray-100 pt-3 text-sm text-gray-600">
                {{ $startingPoint->notes }}
            </p>
        @endif
    </div>
</div>
