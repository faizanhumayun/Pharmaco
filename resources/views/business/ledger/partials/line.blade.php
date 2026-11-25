{{--
    One account on the ledger page: its name in plain words, its code small
    beside it, and its balance. An account that has ledgers of its own under it
    opens in place.

    $line: code, name, title, hint, amount (the text to print), negative,
           account (the Account, with children_count)
    $noun: what one of its sub-accounts is called ('customer', 'company'…)

    Closed, a control account shows only the sub-accounts that hold something,
    largest first — a business with three hundred customers usually has a
    handful who owe. "Show all" swaps that for the full list, a page at a time.
--}}
@php
    $account = $line['account'];
    $hasSubs = $account->children_count > 0;
    $showingAll = $opened && $opened->id === $account->id && $children;

    $zero = \App\Support\Money::zero();
    $subName = fn ($sub) => str_ends_with($sub->code, '-000') ? 'Not assigned to any ' . $noun : $sub->name;

    $holding = $hasSubs
        ? $subs->get($account->id, collect())
            ->filter(fn ($sub) => ! ($balances[$sub->code] ?? $zero)->isZero())
            ->sortByDesc(fn ($sub) => (float) $balances[$sub->code]->absolute()->toDecimal())
            ->values()
        : collect();

    $shown = $holding->take(5);
    $more = $holding->count() - $shown->count();
    $empty = $account->children_count - $holding->count();
@endphp

<div id="acc-{{ $line['code'] }}" class="border-b border-gray-100" x-data="{ open: @js((bool) $showingAll) }">
    <div class="flex items-baseline justify-between gap-4 px-4 py-3 sm:px-6">
        <div class="min-w-0">
            <a href="{{ route('businesses.ledger.account', [$business, $line['code']] + $dateQuery) }}"
               title="{{ $line['title'] }}"
               class="font-medium text-gray-900 hover:text-emerald-700">{{ $line['name'] }}</a>
            <span class="ml-1.5 font-mono text-xs text-gray-400">{{ $line['code'] }}</span>

            @if ($hasSubs)
                <button type="button" x-on:click="open = ! open" :aria-expanded="open"
                        class="ml-2 text-xs font-medium text-emerald-700 hover:underline">
                    @if ($holding->isNotEmpty())
                        {{ number_format($holding->count()) }} {{ Str::plural($noun, $holding->count()) }}
                    @else
                        {{ number_format($account->children_count) }} {{ Str::plural($noun, $account->children_count) }}, all at zero
                    @endif
                    <span aria-hidden="true" x-text="open ? '▾' : '▸'"></span>
                </button>
            @endif

            @if ($line['hint'])
                <span class="ml-2 text-xs text-gray-500">{{ $line['hint'] }}</span>
            @endif

            {{-- What the figure is, in everyday words. --}}
            @if ($line['about'] ?? null)
                <span class="block text-xs text-gray-500">{{ $line['about'] }}</span>
            @endif
        </div>

        <div class="text-right">
            <div @class(['whitespace-nowrap font-mono tabular-nums', 'text-red-700' => $line['negative'], 'text-gray-900' => ! $line['negative']])>
                {{ $line['amount'] }}
            </div>
            {{-- Up or down since the day before. --}}
            @if (($balancesBefore ?? null) && ($m = $move($line['money'], $balancesBefore[$line['code']] ?? $zero, $upGood[$line['type'] ?? ''] ?? null)) && $m['arrow'] !== '•')
                <div class="whitespace-nowrap text-xs font-semibold {{ $m['class'] }}" title="{{ $since }}">{{ $m['arrow'] }} {{ $m['text'] }}</div>
            @endif
        </div>
    </div>

    {{-- How much of its block this line is: a glance says where the
         money sits before any figure is read. --}}
    @if ($line['share'] ?? null)
        <div class="flex items-center gap-2 px-4 pb-3 sm:px-6">
            <div class="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-gray-100">
                <div @class(['h-full rounded-full', 'bg-emerald-600' => ($line['tone'] ?? '') === 'has', 'bg-amber-500' => ($line['tone'] ?? '') !== 'has'])
                     style="width: {{ $line['share'] }}%"></div>
            </div>
            <span class="w-12 shrink-0 text-right text-xs tabular-nums text-gray-500">{{ rtrim(rtrim(number_format($line['share'], 1), '0'), '.') }}%</span>
        </div>
    @endif

    @if ($hasSubs)
        <div x-show="open" x-cloak class="border-t border-gray-100 bg-gray-50 text-sm">
            @foreach ($showingAll ? $children : $shown as $sub)
                @php($amount = $balances[$sub->code] ?? $zero)
                <a href="{{ route('businesses.ledger.account', [$business, $sub->code] + $dateQuery) }}"
                   @class(['flex justify-between gap-4 border-b border-gray-100 py-2 pl-10 pr-4 hover:bg-emerald-50 sm:pr-6', 'opacity-60' => $amount->isZero()])>
                    <span class="min-w-0 truncate text-gray-700">{{ $subName($sub) }}</span>
                    <span @class(['whitespace-nowrap font-mono tabular-nums', 'text-red-700' => $amount->isNegative(), 'text-gray-700' => ! $amount->isNegative()])>{{ $amount->format() }}</span>
                </a>
            @endforeach

            @if ($showingAll)
                <div class="flex flex-wrap items-center justify-between gap-3 py-2 pl-10 pr-4 sm:pr-6">
                    <a href="{{ request()->fullUrlWithQuery(['under' => null, 'page' => null]) }}#acc-{{ $line['code'] }}"
                       class="font-medium text-emerald-700 hover:underline">‹ Only with a balance</a>
                    <div class="min-w-0 flex-1">{{ $children->fragment('acc-' . $line['code'])->links() }}</div>
                </div>
            @else
                <div class="flex flex-wrap justify-between gap-3 py-2 pl-10 pr-4 font-medium text-emerald-700 sm:pr-6">
                    <span>
                        @if ($more > 0)
                            {{ number_format($more) }} more with a balance ·
                        @endif
                        {{ number_format($empty) }} at zero
                    </span>
                    <a href="{{ request()->fullUrlWithQuery(['under' => $line['code'], 'page' => null]) }}#acc-{{ $line['code'] }}"
                       class="hover:underline">Show all ›</a>
                </div>
            @endif
        </div>
    @endif
</div>
