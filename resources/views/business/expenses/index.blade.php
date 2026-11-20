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

        {{-- Above the two cards, across the page: choosing a custom period adds two
             date boxes, and here they have room without reshaping a card. --}}
        <x-filter-bar class="mb-4" submit="Apply" :active="$head || $range !== 'all'" clear="{{ route('businesses.expenses.index', $business) }}" x-data="{ range: {{ Illuminate\Support\Js::from($range) }} }">
            <div>
                <x-input-label for="head" value="Head" />
                <select id="head" name="head"
                    class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                <option value="">All heads</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected($head?->is($category))>{{ $category->name }}</option>
                @endforeach
            </select>
            </div>

            <div>
                <x-input-label for="range" value="Period" />
                <select id="range" name="range" x-model="range"
                    class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                @foreach ($ranges as $value => $label)
                    <option value="{{ $value }}" @selected($range === $value)>{{ $label }}</option>
                @endforeach
            </select>
            </div>

            <template x-if="range === 'custom'">
                <span class="flex flex-wrap items-end gap-3">
                    <div>
                        <x-input-label for="from" value="From" />
                        <input id="from" name="from" type="date" value="{{ $from?->toDateString() }}"
                           class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <x-input-label for="to" value="To" />
                        <input id="to" name="to" type="date" value="{{ $to?->toDateString() }}"
                           class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                </span>
            </template>
        </x-filter-bar>

        <div class="grid gap-6 xl:grid-cols-3">
            {{-- Every expense entered, newest first. --}}
            <div class="xl:col-span-2">
                <x-panel>
                    {{-- What is listed, and what it comes to. --}}
                    <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-b border-gray-200 px-4 py-3 sm:px-6">
                        <p class="text-sm">
                            <span class="text-base font-semibold text-gray-900">{{ $head ? $head->name . ' expenses' : 'All expenses' }}</span>
                            <span class="text-gray-500">· {{ $range === 'custom'
                                ? ($from?->format('d M Y') ?? 'start') . ' – ' . ($to?->format('d M Y') ?? 'today')
                                : $ranges[$range] }}</span>
                            <span class="text-gray-300">|</span>
                            <span class="text-gray-500">{{ $rows->total() }} {{ Str::plural('expense', $rows->total()) }} ·</span>
                            <span class="font-semibold tabular-nums text-gray-900">Rs. {{ $money($shownTotal->plus($unfiledTotal)) }}</span>
                            @unless ($unfiledTotal->isZero())
                                <span class="text-amber-700">· {{ $money($unfiledTotal) }} not under a head</span>
                            @endunless
                        </p>

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
                            @forelse ($rows as $row)
                                @php($entry = $row['entry'])
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-2 sm:px-6">
                                        <a href="{{ route('businesses.daily.show', [$business, $entry]) }}"
                                           class="font-medium text-gray-900 hover:text-emerald-700">
                                            {{ $entry->business_date->format('D d M Y') }}
                                        </a>
                                        @if ($entry->isEditable())
                                            <x-badge classes="ml-1 bg-gray-100 text-gray-600 ring-gray-500/20">draft</x-badge>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        @if ($row['head'])
                                            <span class="text-gray-700">{{ $row['head'] }}</span>
                                        @else
                                            {{-- Entered as the day's total, so there is no head on it. --}}
                                            <x-badge classes="bg-amber-50 text-amber-800 ring-amber-600/20">Miscellaneous</x-badge>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-gray-600">
                                        {{ $row['what'] ?: ($row['head'] ? '—' : 'Entered as the day\'s total') }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right font-medium tabular-nums text-gray-900">{{ $money($row['amount']) }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-gray-500 sm:px-6">{{ $entry->creator?->name ?? '—' }}</td>
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

                <div class="mt-4">{{ $rows->links() }}</div>

            </div>

            {{-- The total per head. Picking one filters the list. --}}
            <x-panel title="By head" :description="'Spent under each head · ' . ($range === 'custom' ? 'chosen dates' : strtolower($ranges[$range])) . '. Posted days only. Pick one to filter the list.'">
                <ul class="divide-y divide-gray-100 text-sm">
                    @unless ($unfiledTotal->isZero())
                        <li class="flex items-center justify-between gap-3 bg-amber-50/60 px-4 py-2 sm:px-6">
                            <span class="font-medium text-amber-900">Miscellaneous</span>
                            <span class="tabular-nums text-amber-900">{{ $money($unfiledTotal) }}</span>
                        </li>
                    @endunless

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

    {{-- Adding an expense: the shared drawer, the same one the dashboard opens. --}}
    <x-add-expense-drawer :business="$business" :head="$head?->name" />

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
