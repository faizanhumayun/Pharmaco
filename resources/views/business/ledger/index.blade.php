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
        '1100' => 'Pending from market',
        '1000' => 'Cash in hand',
        '1010' => 'Bank',
        '1300' => 'Advance paid to companies',
        '1400' => 'Fixed assets',
        '2000' => 'Payable to companies',
        '2100' => 'Advance received from customers',
        '2200' => 'Unpaid expenses',
        '3000' => 'Owner investment',
        '3100' => 'Owner withdrawals',
        '3900' => 'Opening balance',
        '4000' => 'Sales',
        '4100' => 'Sales returns',
        '4200' => 'Discount received',
        '5000' => 'Cost of goods sold',
        '5100' => 'Stock lost or damaged',
        '5200' => 'Discount given',
        '5300' => 'Bad debts',
        '6000' => 'Expenses',
    ];

    // One line under each name saying what the figure is, in everyday words.
    $about = [
        '1200' => 'Value of the medicine in the shop, at purchase price',
        '1100' => 'Money customers still have to pay',
        '1000' => 'Cash available in the shop',
        '1010' => 'Money in bank and mobile accounts',
        '1300' => 'Money paid to companies before the goods arrived',
        '1400' => 'Furniture, equipment and other long-term items',
        '2000' => 'Money the business still has to pay to companies',
        '2100' => 'Money customers paid before taking the goods',
        '2200' => 'Expenses that are due but not paid yet',
        '3000' => 'Money the owner put into the business',
        '3100' => 'Money the owner took out of the business',
        '3900' => 'What the business had on its opening day',
        '4000' => 'Total sales since opening',
        '4100' => 'Goods returned by customers',
        '4200' => 'Discount received from companies',
        '5000' => 'Purchase price of the goods that were sold',
        '5100' => 'Stock that was lost, damaged or expired',
        '5200' => 'Discount given to customers',
        '5300' => 'Customer money that will not be recovered',
        '6000' => 'Rent, salaries, bills and other running costs',
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
        ->map(function ($a) use ($plain, $about, $hints, $balances, $print, $type) {
            [$amount, $negative] = $print[$type->value]($balances[$a->code]);

            return [
                'code' => $a->code,
                'name' => $plain[$a->code] ?? $a->name,
                'about' => $about[$a->code] ?? null,
                'type' => $type->value,
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

    // Each line's share of its block, for the bar under it.
    $withShare = fn ($lines, $total, string $tone) => $lines->map(fn ($l) => $l + [
        'tone' => $tone,
        'share' => $total->isPositive() && $l['money']->isPositive()
            ? min(100, round((float) $l['money']->toDecimal() / (float) $total->toDecimal() * 100, 1))
            : null,
    ]);

    $own = $withShare($own, $position->assets, 'has');
    $owe = $withShare($owe, $position->liabilities, 'pays');

    // The lower half: how the profit was made, and where the net position comes from.
    $isType = fn (AccountType ...$types) => fn ($l) => in_array($l['account']->type, $types, true);
    $earned = $story->filter($isType(AccountType::Income, AccountType::Expense))->values();
    $earnedEmpty = $storyEmpty->filter($isType(AccountType::Income, AccountType::Expense))->values();
    $capital = $story->filter($isType(AccountType::Equity))->values();
    $capitalEmpty = $storyEmpty->filter($isType(AccountType::Equity))->values();

    $net = $position->netPosition();
    $profit = $position->retainedProfit();

    /*
     * Up or down since the day before, as an arrow and the amount. Whether
     * up is good depends on the figure: more stock or cash is good, more to
     * pay is not. $upIsGood says which; null leaves the arrow grey.
     */
    $move = function ($now, $before, ?bool $upIsGood = true) {
        if ($before === null) return null;

        $delta = $now->minus($before);

        if ($delta->isZero()) return ['arrow' => '•', 'text' => 'No change', 'class' => 'text-gray-500'];

        $up = $delta->isPositive();
        $good = $upIsGood === null ? null : ($up === $upIsGood);

        return [
            'arrow' => $up ? '▲' : '▼',
            'text' => ($up ? 'Up ' : 'Down ') . $delta->absolute()->format(),
            'class' => $good === null ? 'text-gray-600' : ($good ? 'text-emerald-700' : 'text-red-700'),
        ];
    };

    $since = $compareDate ? 'since ' . $compareDate->format('j M') : null;
    $upGood = [
        AccountType::Asset->value => true, AccountType::Liability->value => false,
        AccountType::Equity->value => null, AccountType::Income->value => true, AccountType::Expense->value => false,
    ];
    $sound = $trial['balanced'] && $position->isConsistent();
@endphp

<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Ledger</h1>
            <p class="mt-1 text-sm text-gray-500">
                Business position as at {{ $asAt->format('D d M Y') }}: what it has, what it has to pay, and the profit made.
            </p>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        {{-- Which day, through the one filter bar every screen uses. The
             check that the books agree with themselves sits under it. --}}
        <x-filter-bar class="mb-6" submit="Apply" :active="$at !== 'today'" clear="{{ route('businesses.ledger', $business) }}">
            {{-- The day, from the calendar every list uses. --}}
            <div>
                <x-input-label for="as_at" value="Position as at" />
                <input id="as_at" name="as_at" type="date" value="{{ $asAt->toDateString() }}"
                       min="{{ $business->opening_date?->toDateString() }}" max="{{ $business->today()->toDateString() }}"
                       class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm">
            </div>

            {{-- The days asked about most, one press each. --}}
            <div class="flex !min-w-0 !flex-none flex-wrap items-center gap-2">
                @foreach ($points as $value => $label)
                    @continue($value === 'custom' || ($value === 'opening' && ! $business->opening_date))
                    <a href="{{ route('businesses.ledger', array_filter([$business, 'at' => $value === 'today' ? null : $value])) }}"
                       @class([
                           'rounded-md border px-3 py-2 text-sm font-medium',
                           'border-emerald-700 bg-emerald-50 text-emerald-800' => $at === $value,
                           'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => $at !== $value,
                       ])>{{ $label }}</a>
                @endforeach
            </div>

            @if ($sound)
                <x-slot name="count">
                    <span class="font-medium text-emerald-700"
                          title="Total debits and total credits are {{ $trial['debits']->format() }} each, and net position calculated two ways gives the same result">✓ Accounts are balanced</span>
                    · every figure on this page has been checked
                </x-slot>
            @endif
        </x-filter-bar>

        @unless ($sound)
            <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 sm:px-6" role="alert">
                <strong>The accounts do not balance.</strong>
                @unless ($trial['balanced'])
                    Debits and credits differ by <span class="font-mono">{{ $trial['difference']->format() }}</span>.
                @endunless
                @unless ($position->isConsistent())
                    Net position calculated two ways differs by <span class="font-mono">{{ $position->discrepancy()->format() }}</span>.
                @endunless
                The figures below may be incorrect until this is resolved.
            </div>
        @endunless

        @if ($business->opening_date && $asAt->lessThan($business->opening_date))
            <p class="mb-6 text-sm text-amber-700">
                This date is before the opening date ({{ $business->opening_date->format('d M Y') }}). No records exist for it.
            </p>
        @endif

        {{-- The whole page in one line: what it has, less what it has to
             pay, is what belongs to the owner. --}}
        <div class="mb-6 grid items-stretch gap-3 lg:grid-cols-[1fr_auto_1fr_auto_1fr]">
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-800">Business Value</p>
                <p class="mt-1 font-mono text-3xl font-semibold tabular-nums text-emerald-900">{{ $rs($position->assets) }}</p>
                @if ($m = $move($position->assets, $positionBefore?->assets, true))
                    <p class="mt-1 text-sm font-semibold {{ $m['class'] }}">{{ $m['arrow'] }} {{ $m['text'] }} <span class="font-normal text-gray-500">{{ $since }}</span></p>
                @endif
                <p class="mt-1 text-sm text-emerald-800">Everything the business has: stock, cash and money to receive.</p>
            </div>

            <div class="hidden items-center font-mono text-3xl font-semibold text-gray-400 lg:flex" aria-hidden="true">−</div>

            <div class="rounded-lg border border-amber-200 bg-amber-50 px-5 py-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">Business Credit/Pending amount</p>
                <p class="mt-1 font-mono text-3xl font-semibold tabular-nums text-amber-900">{{ $rs($position->liabilities) }}</p>
                @if ($m = $move($position->liabilities, $positionBefore?->liabilities, false))
                    <p class="mt-1 text-sm font-semibold {{ $m['class'] }}">{{ $m['arrow'] }} {{ $m['text'] }} <span class="font-normal text-gray-500">{{ $since }}</span></p>
                @endif
                <p class="mt-1 text-sm text-amber-800">Everything the business still has to pay.</p>
            </div>

            <div class="hidden items-center font-mono text-3xl font-semibold text-gray-400 lg:flex" aria-hidden="true">=</div>

            <div @class(['rounded-lg border px-5 py-4', 'border-red-300 bg-red-50' => $net->isNegative(), 'border-gray-900 bg-gray-900' => ! $net->isNegative()])>
                <p @class(['text-xs font-semibold uppercase tracking-wide', 'text-red-800' => $net->isNegative(), 'text-gray-300' => ! $net->isNegative()])>Net position</p>
                <p @class(['mt-1 font-mono text-3xl font-semibold tabular-nums', 'text-red-800' => $net->isNegative(), 'text-white' => ! $net->isNegative()])>{{ $rs($net) }}</p>
                @if ($m = $move($net, $positionBefore?->netPosition(), true))
                    {{-- On the dark box the colours are the light ones. --}}
                    <p @class(['mt-1 text-sm font-semibold', $m['class'] => $net->isNegative(),
                        'text-emerald-300' => ! $net->isNegative() && $m['class'] === 'text-emerald-700',
                        'text-red-300' => ! $net->isNegative() && $m['class'] === 'text-red-700',
                        'text-gray-300' => ! $net->isNegative() && ! in_array($m['class'], ['text-emerald-700', 'text-red-700'], true)])>
                        {{ $m['arrow'] }} {{ $m['text'] }} <span @class(['font-normal', 'text-gray-400' => ! $net->isNegative(), 'text-gray-500' => $net->isNegative()])>{{ $since }}</span>
                    </p>
                @endif
                <p @class(['mt-1 text-sm', 'text-red-800' => $net->isNegative(), 'text-gray-300' => ! $net->isNegative()])>
                    @if ($net->isNegative())
                        The business has to pay more than it has.
                    @elseif ($net->isZero())
                        What the business has equals what it has to pay.
                    @else
                        What belongs to the owner after everything is paid.
                    @endif
                </p>
            </div>
        </div>

        {{-- The two sides, line by line. Each line opens its own entries. --}}
        <div class="mb-6 grid gap-6 lg:grid-cols-2">
            @foreach ([
                ['What the business has', 'Stock, cash and money to receive. Click a name to see its entries.', $own, $ownEmpty, $position->assets, 'text-emerald-900'],
                ['What the business has to pay', 'Money still to be paid to companies and others.', $owe, $oweEmpty, $position->liabilities, 'text-amber-900'],
            ] as [$heading, $explain, $held, $empty, $total, $tone])
                <x-panel>
                    <div class="flex items-baseline justify-between gap-4 border-b border-gray-200 bg-gray-50 px-4 py-4 sm:px-6">
                        <div>
                            <h2 class="text-base font-semibold text-gray-900">{{ $heading }}</h2>
                            <p class="mt-0.5 text-sm text-gray-500">{{ $explain }}</p>
                        </div>
                        <p class="whitespace-nowrap font-mono text-xl font-semibold tabular-nums {{ $tone }}">{{ $rs($total) }}</p>
                    </div>

                    @forelse ($held as $line)
                        @include('business.ledger.partials.line', ['line' => $line, 'noun' => $nouns[$line['code']] ?? 'account'])
                    @empty
                        <p class="border-b border-gray-100 px-4 py-4 text-sm text-gray-500 sm:px-6">Nothing here on this date.</p>
                    @endforelse

                    @include('business.ledger.partials.empty', ['lines' => $empty])
                </x-panel>
            @endforeach
        </div>

        {{-- Below: how the profit was made, and where the net position
             comes from. The second reaches the same figure as the top of
             the page, which is the check that the accounts agree. --}}
        <div class="grid gap-6 lg:grid-cols-2">
            <x-panel>
                <div class="border-b border-gray-200 bg-gray-50 px-4 py-4 sm:px-6">
                    <h2 class="text-base font-semibold text-gray-900">{{ $profit->isNegative() ? 'Loss since opening' : 'Profit since opening' }}</h2>
                    <p class="mt-0.5 text-sm text-gray-500">Sales, less the cost of the goods sold and the expenses. + adds to profit, − reduces it.</p>
                </div>

                @forelse ($earned as $line)
                    @include('business.ledger.partials.line', ['line' => $line, 'noun' => $nouns[$line['code']] ?? 'account'])
                @empty
                    <p class="border-b border-gray-100 px-4 py-4 text-sm text-gray-500 sm:px-6">No sales or expenses yet.</p>
                @endforelse

                @include('business.ledger.partials.empty', ['lines' => $earnedEmpty])

                <div class="flex items-baseline justify-between gap-4 border-t-2 border-gray-900 px-4 py-4 font-semibold sm:px-6">
                    <span class="text-gray-900">{{ $profit->isNegative() ? 'Loss since opening' : 'Profit since opening' }}</span>
                    <span class="text-right">
                        <span @class(['block font-mono text-lg tabular-nums', 'text-red-700' => $profit->isNegative(), 'text-emerald-800' => ! $profit->isNegative()])>{{ $rs($profit) }}</span>
                        @if ($m = $move($profit, $positionBefore?->retainedProfit(), true))
                            <span class="block text-xs font-semibold {{ $m['class'] }}">{{ $m['arrow'] }} {{ $m['text'] }} <span class="font-normal text-gray-500">{{ $since }}</span></span>
                        @endif
                    </span>
                </div>
            </x-panel>

            <x-panel>
                <div class="border-b border-gray-200 bg-gray-50 px-4 py-4 sm:px-6">
                    <h2 class="text-base font-semibold text-gray-900">Where the net position comes from</h2>
                    <p class="mt-0.5 text-sm text-gray-500">What the business started with, plus the profit made since.</p>
                </div>

                @foreach ($capital as $line)
                    @include('business.ledger.partials.line', ['line' => $line, 'noun' => $nouns[$line['code']] ?? 'account'])
                @endforeach

                <div class="flex items-baseline justify-between gap-4 border-b border-gray-100 px-4 py-3 sm:px-6">
                    <span>
                        <span class="font-medium text-gray-900">{{ $profit->isNegative() ? 'Loss since opening' : 'Profit since opening' }}</span>
                        <span class="block text-xs text-gray-500">From the box on the left</span>
                    </span>
                    <span @class(['font-mono tabular-nums', 'text-red-700' => $profit->isNegative(), 'text-gray-900' => ! $profit->isNegative()])>{{ $rs($profit) }}</span>
                </div>

                @include('business.ledger.partials.empty', ['lines' => $capitalEmpty])

                @php($derived = $position->equityDerivation())
                <div class="flex items-baseline justify-between gap-4 border-t-2 border-gray-900 px-4 py-4 font-semibold sm:px-6">
                    <span class="text-gray-900">
                        Net position
                        @if ($position->isConsistent())
                            <span class="ml-2 text-xs font-normal text-emerald-700">✓ same as the figure at the top</span>
                        @else
                            <span class="ml-2 text-xs font-normal text-red-700">differs from the figure at the top by {{ $position->discrepancy()->format() }}</span>
                        @endif
                    </span>
                    <span @class(['font-mono text-lg tabular-nums', 'text-red-700' => $derived->isNegative(), 'text-gray-900' => ! $derived->isNegative()])>{{ $rs($derived) }}</span>
                </div>
            </x-panel>
        </div>
    </div>
</x-workspace-layout>
