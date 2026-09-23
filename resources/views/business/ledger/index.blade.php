@php
    /*
     * What the business owns and owes, in the owner's words. The top-level
     * accounts already include their sub-accounts, so these add up to the
     * Assets and Liabilities totals exactly. Anything not named here still
     * appears, under its account name.
     */
    $plain = [
        '1200' => ['Medicine in stock', 'On the shelves, valued at cost. An estimate until stock is physically counted.'],
        '1100' => ['Market owes us', 'Credit given to pharmacies and not yet collected.'],
        '1000' => ['Cash in the drawer', 'As at the last count. Below zero means more went out than came in.'],
        '1010' => ['Bank', 'Money in the bank.'],
        '1300' => ['Paid to companies in advance', 'Prepayments for goods not yet received.'],
        '1400' => ['Fixed assets', 'Vehicles, equipment, deposits.'],
        '2000' => ['We owe companies', 'Bought on credit and not yet paid.'],
        '2100' => ['Paid to us in advance', 'Pharmacies that paid before being supplied.'],
        '2200' => ['Bills not yet paid', 'Expenses owed but not yet settled.'],
    ];

    $side = fn (string $type) => $accounts->get($type, collect())
        ->whereNull('parent_id')
        ->map(fn ($a) => [
            'code' => $a->code,
            'name' => $plain[$a->code][0] ?? $a->name,
            'note' => $plain[$a->code][1] ?? $a->description,
            'amount' => $balances[$a->code],
        ])
        // Largest first, and anything at zero at the end, faded.
        ->sortByDesc(fn ($r) => [! $r['amount']->isZero(), (float) $r['amount']->toDecimal()])
        ->values();

    $own = $side(\App\Enums\AccountType::Asset->value);
    $owe = $side(\App\Enums\AccountType::Liability->value);

    $net = $position->netPosition();
    $started = $position->equity;
    $profit = $position->retainedProfit();

    $rs = fn ($m) => ($m->isNegative() ? '−' : '') . $m->absolute()->format();
@endphp

<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Ledger</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Every figure below is summed from ledger entries. Nothing here is stored as a balance.
                </p>
            </div>

        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        {{-- Which day, and whether the books agree with themselves — on one line. --}}
        <div class="mb-6 flex flex-wrap items-center justify-between gap-x-6 gap-y-3 rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm sm:px-6">
            <p class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-base font-semibold text-gray-900">Balances as at {{ $asAt->format('D d M Y') }}</span>
                @if ($at !== 'custom' && $at !== 'today')
                    <span class="text-gray-500">· {{ strtolower($points[$at]) }}</span>
                @endif
                @if ($business->opening_date && $asAt->lessThan($business->opening_date))
                    <span class="text-amber-700">· before the business opened on {{ $business->opening_date->format('d M Y') }}, so nothing is recorded yet</span>
                @endif
                <span class="text-gray-300">|</span>
                @if ($trial['balanced'])
                    <x-badge classes="bg-emerald-50 text-emerald-800 ring-emerald-600/20"
                             title="Every debit has a matching credit: {{ $trial['debits']->format() }} each side">Books balance ✓</x-badge>
                @else
                    <x-badge classes="bg-red-50 text-red-800 ring-red-600/20">Books out by {{ $trial['difference']->format() }}</x-badge>
                @endif
                @if ($position->isConsistent())
                    <x-badge classes="bg-emerald-50 text-emerald-800 ring-emerald-600/20"
                             title="Net position worked out two ways gives the same answer">Figures agree ✓</x-badge>
                @else
                    <x-badge classes="bg-red-50 text-red-800 ring-red-600/20">Figures disagree by {{ $position->discrepancy()->format() }}</x-badge>
                @endif
            </p>

            <form method="GET" x-data="{ at: @js($at) }" class="flex flex-wrap items-center gap-2">
                <label for="at" class="sr-only">As at</label>
                <select id="at" name="at" x-model="at"
                        class="rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach ($points as $value => $label)
                        <option value="{{ $value }}" @selected($at === $value)>{{ $label }}</option>
                    @endforeach
                </select>

                <template x-if="at === 'custom'">
                    <span>
                        <label for="as_at" class="sr-only">Date</label>
                        <input id="as_at" name="as_at" type="date" value="{{ $asAt->toDateString() }}"
                               min="{{ $business->opening_date?->toDateString() }}" max="{{ $business->today()->toDateString() }}"
                               class="rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    </span>
                </template>

                <button class="rounded-md bg-gray-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-800">Apply</button>
                @if ($at !== 'today')
                    <a href="{{ route('businesses.ledger', $business) }}" class="text-sm text-gray-500 hover:text-gray-800">Clear</a>
                @endif
            </form>
        </div>

        <dl class="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            <x-stat label="Assets" :value="$position->assets->format()" />
            <x-stat label="Liabilities" :value="$position->liabilities->format()" />
            <x-stat label="Net position" :value="$position->netPosition()->format()"
                    hint="Management position — not a balance sheet" />
            <x-stat label="Income" :value="$position->income->format()" />
            <x-stat label="Retained profit" :value="$position->retainedProfit()->format()"
                    hint="Income less expenses" />
        </dl>

        {{-- The two totals above, opened up: what each is made of. --}}
        <x-panel class="mb-8" title="What you own and what you owe"
                 :description="'As at ' . $asAt->format('D d M Y') . '. Each line is the sum of its ledger; click one to see its entries.'">
            <div class="grid divide-y divide-gray-200 lg:grid-cols-2 lg:divide-x lg:divide-y-0">
                @foreach ([['What you own', $own, $position->assets], ['What you owe', $owe, $position->liabilities]] as [$heading, $rows, $total])
                    <div>
                        <h3 class="bg-gray-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">{{ $heading }}</h3>
                        <dl class="divide-y divide-gray-100 text-sm">
                            @foreach ($rows as $row)
                                <div @class(['flex items-start justify-between gap-4 px-4 py-2.5 sm:px-6', 'opacity-50' => $row['amount']->isZero()])>
                                    <dt>
                                        <a href="{{ route('businesses.ledger.account', [$business, $row['code']]) }}"
                                           class="font-medium text-gray-900 hover:text-emerald-700">{{ $row['name'] }}</a>
                                        @if ($row['note'])
                                            <p class="text-xs text-gray-500">{{ $row['note'] }}</p>
                                        @endif
                                    </dt>
                                    <dd class="whitespace-nowrap tabular-nums {{ $row['amount']->isNegative() ? 'font-semibold text-red-700' : 'text-gray-900' }}">
                                        {{ $rs($row['amount']) }}
                                    </dd>
                                </div>
                            @endforeach
                            <div class="flex justify-between gap-4 bg-gray-50 px-4 py-2.5 font-semibold sm:px-6">
                                <dt class="text-gray-900">Total</dt>
                                <dd class="tabular-nums text-gray-900">{{ $rs($total) }}</dd>
                            </div>
                        </dl>
                    </div>
                @endforeach
            </div>

            {{-- The bottom line, and why it is what it is. --}}
            <div class="border-t border-gray-200 px-4 py-4 text-sm sm:px-6">
                <p class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="font-semibold text-gray-900">
                        Net position — what you own minus what you owe
                    </span>
                    <span class="text-lg font-semibold tabular-nums {{ $net->isNegative() ? 'text-red-700' : 'text-emerald-700' }}">
                        {{ $rs($position->assets) }} − {{ $rs($position->liabilities) }} = {{ $rs($net) }}
                    </span>
                </p>
                <p class="mt-1 text-gray-500">
                    The business started at <span class="tabular-nums text-gray-700">{{ $rs($started) }}</span>
                    (the opening position, with its corrections), and has
                    {{ $profit->isNegative() ? 'lost' : 'made' }}
                    <span class="tabular-nums {{ $profit->isNegative() ? 'text-red-700' : 'text-emerald-700' }}">{{ $rs($profit->absolute()) }}</span>
                    since — sales less the cost of what was sold and expenses.
                    @if ($net->isNegative())
                        It still owes {{ $rs($net->absolute()) }} more than it owns.
                    @else
                        It owns {{ $rs($net) }} more than it owes.
                    @endif
                </p>
            </div>
        </x-panel>

        @foreach ($types as $type)
            @php($group = $accounts->get($type->value, collect()))
            @continue($group->isEmpty())

            <x-panel class="mb-6" :title="$type->label()">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Code</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Account</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($group as $account)
                                <tr class="{{ $account->parent_id ? 'bg-gray-50/50' : '' }}">
                                    <td class="px-4 py-2 font-mono text-xs text-gray-500 sm:px-6">{{ $account->code }}</td>
                                    <td class="px-4 py-2">
                                        <a href="{{ route('businesses.ledger.account', [$business, $account->code]) }}"
                                           class="font-medium text-gray-900 hover:text-emerald-700">{{ $account->name }}</a>
                                        @unless ($account->is_postable)
                                            <x-badge classes="ml-2 bg-gray-100 text-gray-600 ring-gray-500/20">
                                                {{ $account->children->isNotEmpty() ? 'has sub-accounts' : 'not in use' }}
                                            </x-badge>
                                        @endunless
                                        @if ($account->description)
                                            <p class="mt-0.5 text-xs text-gray-500">{{ $account->description }}</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono tabular-nums sm:px-6
                                               {{ $balances[$account->code]->isNegative() ? 'text-red-700' : 'text-gray-900' }}">
                                        {{ $balances[$account->code]->format() }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-panel>
        @endforeach
    </div>
</x-workspace-layout>
