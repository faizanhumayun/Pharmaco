{{--
    Expenses: every one entered on a day, and the total per head beside them.
    Adding a head is a drawer, so the list stays in view while you type.
--}}
@php($money = fn ($m) => ($m->isNegative() ? '−' : '') . $m->absolute()->format())

<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Expenses</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Everything spent, as entered on each day. Expenses are added on the daily entry; heads group them.
                </p>
            </div>
            <div class="flex gap-2" x-data>
                <button type="button" x-on:click="$dispatch('open-drawer', 'add-expense-head')"
                        class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Add expense head
                </button>
                @can('create', [App\Models\DailyEntry::class, $business])
                    <button type="button" x-on:click="$dispatch('open-drawer', 'add-expense')"
                            class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                        Add expense
                    </button>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        <div class="grid gap-6 xl:grid-cols-3">
            {{-- Every expense entered, newest first. --}}
            <div class="xl:col-span-2">
                <x-panel>
                    {{-- Title, total and filters on one line; the filters wrap under it on a narrow screen. --}}
                    <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-b border-gray-200 px-4 py-3 sm:px-6">
                        <p class="text-sm">
                            <span class="text-base font-semibold text-gray-900">{{ $head ? $head->name . ' expenses' : 'All expenses' }}</span>
                            <span class="text-gray-500">· {{ $range === 'custom'
                                ? ($from?->format('d M Y') ?? 'start') . ' – ' . ($to?->format('d M Y') ?? 'today')
                                : $ranges[$range] }}</span>
                            <span class="text-gray-300">|</span>
                            <span class="text-gray-500">{{ $expenses->total() }} {{ Str::plural('expense', $expenses->total()) }} ·</span>
                            <span class="font-semibold tabular-nums text-gray-900">Rs. {{ $money($shownTotal) }}</span>
                        </p>

                        {{-- Filter by head and by period; both stay in the link. --}}
                        <form method="GET" x-data="{ range: @js($range) }" class="flex flex-wrap items-center gap-2">
                            <label for="head" class="sr-only">Head</label>
                            <select id="head" name="head"
                                    class="rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                <option value="">All heads</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected($head?->is($category))>{{ $category->name }}</option>
                                @endforeach
                            </select>

                            <label for="range" class="sr-only">Period</label>
                            <select id="range" name="range" x-model="range"
                                    class="rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                @foreach ($ranges as $value => $label)
                                    <option value="{{ $value }}" @selected($range === $value)>{{ $label }}</option>
                                @endforeach
                            </select>

                            <template x-if="range === 'custom'">
                                <span class="flex items-center gap-2">
                                    <label for="from" class="sr-only">From</label>
                                    <input id="from" name="from" type="date" value="{{ $from?->toDateString() }}"
                                           class="rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                    <span class="text-sm text-gray-400">to</span>
                                    <label for="to" class="sr-only">To</label>
                                    <input id="to" name="to" type="date" value="{{ $to?->toDateString() }}"
                                           class="rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                </span>
                            </template>

                            <button class="rounded-md bg-gray-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-800">Apply</button>
                            @if ($head || $range !== 'all')
                                <a href="{{ route('businesses.expenses.index', $business) }}" class="text-sm text-gray-500 hover:text-gray-800">Clear</a>
                            @endif
                        </form>
                    </div>

                    <table class="w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                @foreach ([['Day', 'left'], ['Head', 'left'], ['What for', 'left'], ['Amount', 'right'], ['Day entered by', 'left']] as [$h, $align])
                                    <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first || $loop->last ? 'sm:px-6' : '' }}">{{ $h }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($expenses as $expense)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-2 sm:px-6">
                                        <a href="{{ route('businesses.daily.show', [$business, $expense->dailyEntry]) }}"
                                           class="font-medium text-gray-900 hover:text-emerald-700">
                                            {{ $expense->dailyEntry->business_date->format('D d M Y') }}
                                        </a>
                                        @if ($expense->dailyEntry->isEditable())
                                            <x-badge classes="ml-1 bg-gray-100 text-gray-600 ring-gray-500/20">draft</x-badge>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-gray-700">{{ $expense->category?->name ?? 'No head' }}</td>
                                    <td class="px-4 py-2 text-gray-600">{{ $expense->description ?: '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right font-medium tabular-nums text-gray-900">{{ $money($expense->amount) }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-gray-500 sm:px-6">{{ $expense->dailyEntry->creator?->name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-10 text-center text-gray-500">
                                        {{ $head || $range !== 'all' ? 'No expenses match these filters.' : 'No expenses entered yet. Add one above, or on a day\'s entry.' }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-panel>

                <div class="mt-4">{{ $expenses->links() }}</div>
            </div>

            {{-- The total per head. Picking one filters the list. --}}
            <x-panel title="By head" :description="'Spent under each head · ' . ($range === 'custom' ? 'chosen dates' : strtolower($ranges[$range])) . '. Posted days only. Pick one to filter the list.'">
                <ul class="divide-y divide-gray-100 text-sm">
                    @forelse ($categories as $category)
                        <li @class(['flex items-center justify-between gap-3 px-4 py-2 sm:px-6', 'bg-emerald-50' => $head?->is($category)])>
                            <a href="{{ request()->fullUrlWithQuery(['head' => $category->id, 'page' => null]) }}"
                               class="font-medium text-gray-900 hover:text-emerald-700">{{ $category->name }}</a>
                            <span class="flex items-center gap-3">
                                <span class="tabular-nums {{ $balances[$category->id]->isZero() ? 'text-gray-400' : 'text-gray-900' }}">
                                    {{ $money($balances[$category->id]) }}
                                </span>
                                <a href="{{ route('businesses.expenses.show', [$business, $category]) }}"
                                   class="text-xs text-gray-400 hover:text-emerald-700" title="This head's ledger">ledger</a>
                            </span>
                        </li>
                    @empty
                        <li class="px-6 py-8 text-center text-gray-500">No expense heads yet.</li>
                    @endforelse
                </ul>
            </x-panel>
        </div>
    </div>

    {{--
        Adding an expense: the daily entry's own expense drawer, plus the day it
        belongs to. Saving puts it on that day's entry — the day is amended and
        re-posted as if it had been typed there, and a closed day is refused.
    --}}
    @php($expenseErrors = $errors->hasAny(['business_date', 'category', 'amount', 'description']))
    <x-drawer name="add-expense" title="Add expense"
              subtitle="What it was spent on, which head it belongs under, and the day it was paid."
              :show="$expenseErrors">
        <form id="add-expense-form" method="POST" action="{{ route('businesses.expenses.add', $business) }}" class="space-y-5">
            @csrf
            <div class="sm:max-w-[12rem]">
                <x-input-label for="expense_date" value="Day" />
                <x-text-input id="expense_date" name="business_date" type="date" class="mt-1 block w-full"
                              :value="old('business_date', $business->today()->toDateString())"
                              min="{{ $earliestDay?->toDateString() }}" max="{{ $business->today()->toDateString() }}" required />
                <x-input-error :messages="$errors->get('business_date')" class="mt-2" />
                <p class="mt-1 text-xs text-gray-500">
                    It is added to this day's entry. A closed day has to be reopened first.
                </p>
            </div>

            <div>
                <x-input-label for="expense_head" value="Expense head" />
                <input id="expense_head" name="category" type="text" list="expense-heads"
                       value="{{ old('category', $head?->name) }}" placeholder="Freight" required maxlength="80"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                <datalist id="expense-heads">
                    @foreach ($categories as $category)
                        <option value="{{ $category->name }}"></option>
                    @endforeach
                </datalist>
                <x-input-error :messages="$errors->get('category')" class="mt-2" />
                <p class="mt-1 text-xs text-gray-500">
                    A head that does not exist yet is created with its own ledger when the expense is saved.
                </p>
            </div>

            <div>
                <x-input-label for="expense_description" value="Description" />
                <input id="expense_description" name="description" type="text" value="{{ old('description') }}"
                       placeholder="Delivery van to Sargodha" maxlength="255"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('description')" class="mt-2" />
            </div>

            <div class="sm:max-w-[12rem]">
                <x-input-label for="expense_amount" value="Amount" />
                <div class="relative mt-1">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">Rs.</span>
                    <input id="expense_amount" name="amount" type="text" inputmode="decimal" value="{{ old('amount') }}"
                           placeholder="0.00" required
                           class="block w-full rounded-md border-gray-300 pl-10 text-right tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                </div>
                <x-input-error :messages="$errors->get('amount')" class="mt-2" />
            </div>
        </form>

        <x-slot name="footer">
            <div class="flex items-center justify-end gap-3">
                <button type="button" x-on:click="$dispatch('close')"
                        class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" form="add-expense-form"
                        class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    Add expense
                </button>
            </div>
        </x-slot>
    </x-drawer>

    {{-- Adding a head: a drawer, reopened on its own if the name was refused. --}}
    <x-drawer name="add-expense-head" title="Add expense head"
              subtitle="A head groups expenses — Rent, Salaries, Freight — so you can see where money goes."
              :show="$errors->has('name')">
        <form id="add-expense-head-form" method="POST" action="{{ route('businesses.expenses.store', $business) }}" class="space-y-4">
            @csrf
            <div>
                <x-input-label for="name" value="Name" />
                <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                              :value="old('name')" placeholder="e.g. Bilti / Freight" required maxlength="80" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>
            <p class="text-sm text-gray-500">
                The new head appears on the daily entry's expense list straight away, and gets its own
                ledger so its total is kept separately.
            </p>
        </form>

        <x-slot name="footer">
            <div class="flex justify-end gap-2">
                <button type="button" x-on:click="$dispatch('close')"
                        class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" form="add-expense-head-form"
                        class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    Add head
                </button>
            </div>
        </x-slot>
    </x-drawer>
</x-workspace-layout>
