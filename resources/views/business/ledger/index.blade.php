@php
    use App\Enums\AccountType;

    /*
     * The ledger in three blocks: which day, what the business owns and owes,
     * and how it got there. Each account is drawn once. The second block
     * reaches the same net position the other way round, which is also the
     * check that the books agree with themselves.
     *
     * Accounts are named in the owner's words. Anything not named here still
     * appears, under its own account name; the account's real name and
     * description are on the link's tooltip.
     */
    $plain = [
        '1200' => 'Medicine in stock',
        '1100' => 'Market owes us',
        '1000' => 'Cash in hand',
        '1010' => 'Bank',
        '1300' => 'Paid to companies in advance',
        '1400' => 'Fixed assets',
        '2000' => 'We owe companies',
        '2100' => 'Paid to us in advance',
        '2200' => 'Bills not yet paid',
        '3000' => 'Owner put in',
        '3100' => 'Owner took out',
        '3900' => 'Started with',
        '4000' => 'Sales',
        '4100' => 'Sales returns',
        '4200' => 'Discount received',
        '5000' => 'Cost of what was sold',
        '5100' => 'Stock lost or damaged',
        '5200' => 'Discount given',
        '5300' => 'Bad debts',
        '6000' => 'Expenses',
    ];

    // What one sub-account of a control account is called.
    $nouns = [
        '1100' => $business->sellsAtMrp() ? 'customer' : 'pharmacy',
        '2000' => 'company',
        '6000' => 'expense head',
    ];

    $hints = [
        '3900' => $business->opening_date ? 'opening position on ' . $business->opening_date->format('d M Y') : null,
    ];

    $rs = fn ($m) => ($m->isNegative() ? '−' : '') . $m->absolute()->format();

    // How a figure is printed in each block: as it stands for what is owned
    // and owed, and as what it adds or takes away in "How it got here".
    $print = [
        AccountType::Asset->value => fn ($m) => [$rs($m), $m->isNegative()],
        AccountType::Liability->value => fn ($m) => [$rs($m), $m->isNegative()],
        AccountType::Equity->value => fn ($m) => [$rs($m), $m->isNegative()],
        AccountType::Income->value => fn ($m) => [($m->isZero() ? '' : ($m->isNegative() ? '− ' : '+ ')) . $m->absolute()->format(), false],
        AccountType::Expense->value => fn ($m) => [($m->isZero() ? '' : ($m->isPositive() ? '− ' : '+ ')) . $m->absolute()->format(), false],
    ];

    $lines = fn (AccountType $type) => $accounts->get($type->value, collect())
        ->map(function ($a) use ($plain, $hints, $balances, $print, $type) {
            [$amount, $negative] = $print[$type->value]($balances[$a->code]);

            return [
                'code' => $a->code,
                'name' => $plain[$a->code] ?? $a->name,
                'title' => trim($a->name . ($a->description ? ' — ' . $a->description : '')),
                'hint' => $hints[$a->code] ?? null,
                'amount' => $amount,
                'negative' => $negative,
                'money' => $balances[$a->code],
                'account' => $a,
            ];
        });

    // Largest first; whatever holds nothing is folded away under the block.
    $split = function ($lines, bool $sort) {
        [$empty, $held] = $lines->partition(fn ($l) => $l['money']->isZero());

        return [
            $sort ? $held->sortByDesc(fn ($l) => (float) $l['money']->toDecimal())->values() : $held->values(),
            $empty->values(),
        ];
    };

    [$own, $ownEmpty] = $split($lines(AccountType::Asset), true);
    [$owe, $oweEmpty] = $split($lines(AccountType::Liability), true);

    // In the order the story is told: where it started, what came in, what went out.
    [$story, $storyEmpty] = $split(
        $lines(AccountType::Equity)->concat($lines(AccountType::Income))->concat($lines(AccountType::Expense)),
        false,
    );

    $net = $position->netPosition();
    $profit = $position->retainedProfit();
    $sound = $trial['balanced'] && $position->isConsistent();
@endphp

<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Ledger</h1>
            <p class="mt-1 text-sm text-gray-500">
                Where the business stands as at {{ $asAt->format('D d M Y') }}, worked out from every entry. Click any line to see what is behind it.
            </p>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        {{-- Which day, through the one filter bar every screen uses. The
             check that the books agree with themselves sits under it. --}}
        <x-filter-bar class="mb-6" submit="Apply" :active="$at !== 'today'" clear="{{ route('businesses.ledger', $business) }}"
                      x-data="{ at: {{ Illuminate\Support\Js::from($at) }} }">
            <div>
                <x-input-label for="at" value="As at" />
                <select id="at" name="at" x-model="at"
                        class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach ($points as $value => $label)
                        @continue($value === 'opening' && ! $business->opening_date)
                        <option value="{{ $value }}" @selected($at === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <template x-if="at === 'custom'">
                <span>
                    <div>
                        <x-input-label for="as_at" value="Date" />
                        <input id="as_at" name="as_at" type="date" value="{{ $asAt->toDateString() }}"
                               min="{{ $business->opening_date?->toDateString() }}" max="{{ $business->today()->toDateString() }}"
                               class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                </span>
            </template>

            @if ($sound)
                <x-slot name="count">
                    <span class="font-medium text-emerald-700"
                          title="Debits and credits are {{ $trial['debits']->format() }} each side, and net position worked out two ways gives the same answer">✓ Books check out</span>
                    · debits equal credits, both totals agree
                </x-slot>
            @endif
        </x-filter-bar>

        @unless ($sound)
            <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 sm:px-6" role="alert">
                <strong>The books do not check out.</strong>
                @unless ($trial['balanced'])
                    Debits and credits differ by <span class="font-mono">{{ $trial['difference']->format() }}</span>.
                @endunless
                @unless ($position->isConsistent())
                    Net position worked out two ways differs by <span class="font-mono">{{ $position->discrepancy()->format() }}</span>.
                @endunless
                Every figure below is suspect until this is found.
            </div>
        @endunless

        @if ($business->opening_date && $asAt->lessThan($business->opening_date))
            <p class="mb-6 text-sm text-amber-700">
                This is before the business opened on {{ $business->opening_date->format('d M Y') }}, so nothing is recorded yet.
            </p>
        @endif

        {{-- What the business owns and owes. --}}
        <x-panel class="mb-6">
            <div class="grid divide-y divide-gray-200 lg:grid-cols-2 lg:divide-x lg:divide-y-0">
                @foreach ([['Business Value', $own, $ownEmpty, $position->assets], ['Business Credit/Pending amount', $owe, $oweEmpty, $position->liabilities]] as [$heading, $held, $empty, $total])
                    <div>
                        <div class="flex items-baseline justify-between gap-4 border-b border-gray-200 bg-gray-50 px-4 py-4 sm:px-6">
                            <h2 class="font-sans text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $heading }}</h2>
                            <p class="font-mono text-xl font-medium tabular-nums text-gray-900">{{ $rs($total) }}</p>
                        </div>

                        @foreach ($held as $line)
                            @include('business.ledger.partials.line', ['line' => $line, 'noun' => $nouns[$line['code']] ?? 'account'])
                        @endforeach

                        @include('business.ledger.partials.empty', ['lines' => $empty])
                    </div>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center justify-between gap-4 border-t border-gray-200 px-4 py-5 sm:px-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Net position</p>
                    <p class="mt-1 text-gray-900">
                        @if ($net->isNegative())
                            You owe {{ $net->absolute()->format() }} more than you own.
                        @elseif ($net->isZero())
                            You own exactly what you owe.
                        @else
                            You own {{ $net->format() }} more than you owe.
                        @endif
                    </p>
                </div>
                <p @class(['font-mono text-3xl font-semibold tabular-nums', 'text-red-700' => $net->isNegative(), 'text-gray-900' => ! $net->isNegative()])>{{ $rs($net) }}</p>
            </div>
        </x-panel>

        {{-- The same figure, reached the other way. --}}
        <x-panel title="How it got here"
                 description="The same net position, reached the other way: where you started, plus what you have made since.">
            @foreach ($story as $line)
                @include('business.ledger.partials.line', ['line' => $line, 'noun' => $nouns[$line['code']] ?? 'account'])
            @endforeach

            <div class="flex items-baseline justify-between gap-4 border-b border-gray-100 bg-gray-50 px-4 py-3 font-semibold sm:px-6">
                <span class="text-gray-900">{{ $profit->isNegative() ? 'Loss since opening' : 'Profit since opening' }}</span>
                <span @class(['font-mono tabular-nums', 'text-red-700' => $profit->isNegative(), 'text-gray-900' => ! $profit->isNegative()])>{{ $rs($profit) }}</span>
            </div>

            @include('business.ledger.partials.empty', ['lines' => $storyEmpty])

            @php($derived = $position->equityDerivation())
            <div class="flex items-baseline justify-between gap-4 border-t-2 border-gray-900 px-4 py-4 font-semibold sm:px-6">
                <span class="text-gray-900">
                    Net position
                    @if ($position->isConsistent())
                        <span class="ml-2 text-xs font-normal text-emerald-700">✓ same figure as above</span>
                    @else
                        <span class="ml-2 text-xs font-normal text-red-700">differs from the figure above by {{ $position->discrepancy()->format() }}</span>
                    @endif
                </span>
                <span @class(['font-mono text-lg tabular-nums', 'text-red-700' => $derived->isNegative(), 'text-gray-900' => ! $derived->isNegative()])>{{ $rs($derived) }}</span>
            </div>
        </x-panel>
    </div>
</x-workspace-layout>
