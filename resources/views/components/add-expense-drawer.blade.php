@props(['business', 'head' => null])

{{--
    Adding an expense, from wherever you happen to be.

    Opened by name from any page:

        <button x-on:click="$dispatch('open-drawer', 'add-expense')">Add an expense</button>
        <x-add-expense-drawer :business="$business" />

    It posts to the ordinary expenses.add route, which puts the expense on that
    day's entry — the day is amended and re-posted as if it had been typed
    there, and a closed day is refused — then comes back to the page you were
    on. If the expense is refused, the drawer reopens with what was typed.

    $head fills in the expense head, for a page that is already about one.
--}}
@php
    // The heads to choose from, and the earliest day an expense may go on.
    $heads = \App\Models\ExpenseCategory::forBusiness($business)->active()->orderBy('name')->pluck('name');
    $earliestDay = collect([
        $business->locked_through_date?->copy()->addDay(),
        $business->opening_date?->copy()->addDay(),
    ])->filter()->max();
@endphp
@php($expenseErrors = $errors->hasAny(['business_date', 'category', 'amount', 'description']))
<x-drawer name="add-expense" title="Add expense"
          subtitle="Enter the date paid, the expense head, a description and the amount."
          :show="$expenseErrors">
    <form id="add-expense-form" method="POST" action="{{ route('businesses.expenses.add', $business) }}" class="space-y-5">
        @csrf
        <div class="sm:max-w-xs">
            <x-input-label for="expense_date" value="Date" />
            <x-date-picker id="expense_date" name="business_date" class="mt-1" required
                           :value="old('business_date', $business->today()->toDateString())"
                           :min="$earliestDay?->toDateString()" :max="$business->today()->toDateString()" />
            <x-input-error :messages="$errors->get('business_date')" class="mt-2" />
            <p class="mt-1 text-xs text-gray-500">
                The expense is added to the daily entry of this date. A closed day must be reopened first.
            </p>
        </div>

        <div>
            <x-input-label for="expense_head" value="Expense head" />
            <input id="expense_head" name="category" type="text" list="expense-heads"
                   value="{{ old('category', $head) }}" placeholder="Freight" required maxlength="80"
                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
            <datalist id="expense-heads">
                @foreach ($heads as $name)
                    <option value="{{ $name }}"></option>
                @endforeach
            </datalist>
            <x-input-error :messages="$errors->get('category')" class="mt-2" />
            <p class="mt-1 text-xs text-gray-500">
                A new head is created with its own ledger when the expense is saved.
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
