@php
    $amending = $entry->exists && ! $entry->isEditable();

    $saleTotal = old('sale_total', $entry->exists && ! $entry->totalSales()->isZero()
        ? $entry->totalSales()->toDecimal()
        : '');

    $purchaseRows = old('purchases', $entry->exists
        ? $entry->purchaseLines->map(fn ($l) => [
            'company' => $l->company?->name ?? $l->company_name ?? '',
            'order_id' => $l->order_id,
            'invoice_no' => $l->invoice_no ?? '',
            'amount' => $l->amount->toDecimal(),
            'paid' => $l->paid->toDecimal(),
        ])->values()->all()
        : []);

    $saleRows = old('sales', $entry->exists
        ? $entry->saleLines->map(fn ($l) => [
            'pharmacy' => $l->pharmacy?->name ?? $l->pharmacy_name ?? '',
            'invoice_no' => $l->invoice_no ?? '',
            'amount' => $l->amount->toDecimal(),
            'received' => $l->received->toDecimal(),
        ])->values()->all()
        : []);

    $expenseRows = old('expenses', $entry->exists
        ? $entry->expenseLines->map(fn ($l) => [
            'category' => $l->category?->name ?? '',
            'description' => $l->description ?? '',
            'amount' => $l->amount->toDecimal(),
        ])->values()->all()
        : []);

    /*
     * Every stored figure, as the form starts. Handed over directly rather than
     * read back out of the page: several inputs never rendered their saved
     * value, so an amended day reopened with cash sales and gross profit blank
     * — and saving it again would have turned every sale into a credit sale.
     * Covers the hidden fields too, which have no input to read at all.
     */
    $initialFigures = collect(\App\Models\DailyEntry::MONEY_FIELDS)
        ->mapWithKeys(fn (string $field) => [$field => (string) old($field,
            $entry->exists && $entry->{$field} && ! $entry->{$field}->isZero()
                ? $entry->{$field}->toDecimal()
                : '')])
        ->all();
@endphp

<x-workspace-layout :business="$business">
    <div class="w-full px-4 py-8 sm:px-6 lg:px-8" x-data="dailyEntry()">
        <x-flash />
        <x-input-error :messages="$errors->get('status')" class="mb-4" />

        <form method="POST" action="{{ route('businesses.daily.store', $business) }}">
            @csrf

            @if ($amending)
                <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <strong>This day is already posted.</strong>
                    Saving reverses what it posted and posts the new figures in its place. Both stay
                    visible on the day, so the change is on the record. Once the day is closed it can
                    no longer be edited.
                </div>
            @endif

            <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div class="flex flex-wrap items-end gap-4">
                    <div>
                        {{--
                            The figures below belong to the date the form was opened
                            for, so picking another date reopens the form for that
                            day instead of carrying these figures across to it.
                        --}}
                        @php($loadedFor = $entry->business_date?->toDateString() ?? $date->toDateString())
                        <input type="hidden" name="loaded_for" value="{{ $loadedFor }}">
                        <x-input-label for="business_date" value="Business date" />
                        <x-text-input id="business_date" name="business_date" type="date" class="mt-1 block"
                                      :value="old('business_date', $loadedFor)"
                                      x-on:change="if ($event.target.value && $event.target.value !== '{{ $loadedFor }}') window.location = '{{ route('businesses.daily.create', $business) }}?date=' + $event.target.value"
                                      required />
                        <x-input-error :messages="$errors->get('business_date')" class="mt-2" />
                    </div>
                    <p class="pb-2 text-xs text-gray-500">
                        The day this activity belongs to, in {{ $business->timezone }}.
                    </p>
                </div>

                <a href="{{ route('businesses.daily.index', $business) }}"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    All days
                </a>
            </div>

            <div class="grid gap-6 xl:grid-cols-2">

                {{-- Purchases: invoice by invoice where you have the detail,
                     plain totals where you do not. --}}
                <x-panel title="Purchases">
                    <div class="space-y-4 p-4 sm:p-6">
                        <div class="space-y-2" x-show="purchases.length > 0" x-cloak>
                            <template x-for="(row, i) in purchases" :key="i">
                                <div class="flex items-center gap-3 rounded-md border border-gray-200 px-3 py-2">
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-gray-900"
                                           x-text="row.company || 'Unnamed company'"></p>
                                        <p class="truncate text-xs text-gray-500" x-text="purchaseNote(row)"></p>
                                    </div>

                                    <div class="shrink-0 text-right">
                                        <p class="font-mono text-sm tabular-nums text-gray-900" x-text="fmt(n(row.amount))"></p>
                                        <p class="font-mono text-xs tabular-nums text-gray-500"
                                           x-text="fmt(n(row.paid)) + ' paid'"></p>
                                    </div>

                                    <button type="button" @click="editPurchase(i)" title="Edit"
                                            class="shrink-0 rounded p-1 text-xs font-medium text-gray-500 hover:text-emerald-700">
                                        Edit
                                    </button>
                                    <button type="button" @click="purchases.splice(i, 1)" title="Remove"
                                            class="shrink-0 rounded p-1 text-gray-400 hover:text-red-700">&times;</button>

                                    <input type="hidden" :name="`purchases[${i}][company]`" :value="row.company">
                                    <input type="hidden" :name="`purchases[${i}][order_id]`" :value="row.order_id ?? ''">
                                    <input type="hidden" :name="`purchases[${i}][invoice_no]`" :value="row.invoice_no">
                                    <input type="hidden" :name="`purchases[${i}][amount]`" :value="row.amount">
                                    <input type="hidden" :name="`purchases[${i}][paid]`" :value="row.paid">
                                </div>
                            </template>
                        </div>

                        <datalist id="company-names">
                            @foreach ($companies as $name)
                                <option value="{{ $name }}"></option>
                            @endforeach
                        </datalist>

                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" @click="newPurchase()"
                                    class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                + Add an invoice
                            </button>
                            <p class="text-xs text-gray-500">
                                Optional. A new company name is created with its own ledger. Paying more than
                                an invoice is worth settles that company's earlier bills — leave the amount
                                blank to record a payment on its own.
                            </p>
                        </div>

                        {{-- Plain totals when no invoices were listed. --}}
                        <div class="grid gap-4 border-t border-gray-200 pt-4 sm:grid-cols-2" x-show="purchases.length === 0" x-cloak>
                            <div>
                                <x-input-label for="purchase_total" value="Total purchased stock" />
                                <div class="relative mt-1">
                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">Rs.</span>
                                    <input type="text" inputmode="decimal" id="purchase_total" name="purchase_total"
                                           x-model="f.purchase_total" placeholder="0.00"
                                           value="{{ old('purchase_total', $entry->purchase_total?->toDecimal() ?? '') }}"
                                           class="block w-full rounded-md border-gray-300 pl-10 text-right font-mono tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                                <x-input-error :messages="$errors->get('purchase_total')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="purchase_paid" value="Paid amount" />
                                <div class="relative mt-1">
                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">Rs.</span>
                                    <input type="text" inputmode="decimal" id="purchase_paid" name="purchase_paid"
                                           x-model="f.purchase_paid" placeholder="0.00"
                                           value="{{ old('purchase_paid', $entry->purchase_paid?->toDecimal() ?? '') }}"
                                           class="block w-full rounded-md border-gray-300 pl-10 text-right font-mono tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                                <x-input-error :messages="$errors->get('purchase_paid')" class="mt-2" />
                            </div>
                        </div>

                        {{-- Hidden for now, but carried through so re-saving a draft
                             does not silently zero a figure that was already entered. --}}
                        <input type="hidden" name="purchase_discount" x-model="f.purchase_discount">
                        <input type="hidden" name="purchase_return" x-model="f.purchase_return">
                        <input type="hidden" name="company_note"
                               value="{{ old('company_note', $entry->company_note) }}">
                        <input type="hidden" name="discount_received" x-model="f.discount_received">
                        <input type="hidden" name="company_payment_cash" x-model="f.company_payment_cash">

                        <dl class="space-y-1 border-t border-gray-200 pt-3 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Total purchased</dt>
                                <dd class="font-mono tabular-nums text-gray-900" x-text="fmt(totalPurchases)"></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Paid now</dt>
                                <dd class="font-mono tabular-nums text-gray-900" x-text="fmt(paidNow)"></dd>
                            </div>
                            <div class="flex justify-between font-semibold" x-show="excess === 0">
                                <dt class="text-gray-700">Pending — company payable</dt>
                                <dd class="font-mono tabular-nums text-gray-900" x-text="fmt(pending)"></dd>
                            </div>
                            {{-- Paid more than the goods cost: the rest comes off
                                 what was already owed. --}}
                            <div class="flex justify-between font-semibold text-emerald-800" x-show="excess > 0" x-cloak>
                                <dt>Paid against earlier bills</dt>
                                <dd class="font-mono tabular-nums" x-text="fmt(excess)"></dd>
                            </div>
                            <div class="flex justify-between" x-show="n(f.purchase_discount) > 0" x-cloak>
                                <dt class="text-gray-500">Cost added to stock</dt>
                                <dd class="font-mono tabular-nums text-gray-900" x-text="fmt(costToStock)"></dd>
                            </div>
                        </dl>
                    </div>
                </x-panel>

                {{-- Sales: pharmacy by pharmacy where you have the detail,
                     plain totals where you do not. Deliberately the mirror of
                     Purchases, because it is the same transaction seen from the
                     other side. --}}
                <x-panel title="Sales">
                    <div class="space-y-4 p-4 sm:p-6">
                        <div class="space-y-2" x-show="sales.length > 0" x-cloak>
                            <template x-for="(row, i) in sales" :key="i">
                                <div class="flex items-center gap-3 rounded-md border border-gray-200 px-3 py-2">
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-gray-900"
                                           x-text="row.pharmacy || 'Unnamed pharmacy'"></p>
                                        <p class="truncate text-xs text-gray-500" x-text="saleNote(row)"></p>
                                    </div>

                                    <div class="shrink-0 text-right">
                                        <p class="font-mono text-sm tabular-nums text-gray-900" x-text="fmt(n(row.amount))"></p>
                                        <p class="font-mono text-xs tabular-nums text-gray-500"
                                           x-text="fmt(n(row.received)) + ' received'"></p>
                                    </div>

                                    <button type="button" @click="editSale(i)" title="Edit"
                                            class="shrink-0 rounded p-1 text-xs font-medium text-gray-500 hover:text-emerald-700">
                                        Edit
                                    </button>
                                    <button type="button" @click="sales.splice(i, 1)" title="Remove"
                                            class="shrink-0 rounded p-1 text-gray-400 hover:text-red-700">&times;</button>

                                    <input type="hidden" :name="`sales[${i}][pharmacy]`" :value="row.pharmacy">
                                    <input type="hidden" :name="`sales[${i}][invoice_no]`" :value="row.invoice_no">
                                    <input type="hidden" :name="`sales[${i}][amount]`" :value="row.amount">
                                    <input type="hidden" :name="`sales[${i}][received]`" :value="row.received">
                                </div>
                            </template>
                        </div>

                        <datalist id="pharmacy-names">
                            @foreach ($pharmacies as $name)
                                <option value="{{ $name }}"></option>
                            @endforeach
                        </datalist>

                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" @click="newSale()"
                                    class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                + Add a pharmacy sale
                            </button>
                            <p class="text-xs text-gray-500">
                                Optional. A new pharmacy name is created with its own ledger. Taking more
                                than an invoice is worth recovers that pharmacy's earlier credit — leave the
                                amount blank to record a recovery on its own.
                            </p>
                        </div>

                        {{-- Plain totals when no pharmacy was named. --}}
                        <div class="grid gap-4 border-t border-gray-200 pt-4 sm:grid-cols-2" x-show="sales.length === 0" x-cloak>
                            <div>
                                <x-input-label for="sale_total" value="Total sales" />
                                <div class="relative mt-1">
                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">Rs.</span>
                                    <input type="text" inputmode="decimal" id="sale_total" name="sale_total"
                                           x-model="f.sale_total" placeholder="0.00"
                                           class="block w-full rounded-md border-gray-300 pl-10 text-right font-mono tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                                <x-input-error :messages="$errors->get('sale_total')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="sale_cash" value="Cash received on today's sales" />
                                <div class="relative mt-1">
                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">Rs.</span>
                                    <input type="text" inputmode="decimal" id="sale_cash" name="sale_cash"
                                           x-model="f.sale_cash" placeholder="0.00"
                                           :class="creditSales < 0 ? 'border-red-400' : 'border-gray-300'"
                                           class="block w-full rounded-md pl-10 text-right font-mono tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                                <p class="mt-1 text-xs text-gray-500">Paid at the time of sale. The rest becomes credit.</p>
                                <x-input-error :messages="$errors->get('sale_cash')" class="mt-2" />
                            </div>
                        </div>

                        {{-- Derived, not typed: what the market still owes for today. --}}
                        <dl class="space-y-1 rounded-md bg-gray-50 px-3 py-2 text-sm">
                            <div class="flex items-baseline justify-between">
                                <dt class="font-medium text-gray-700">Total sales</dt>
                                <dd class="font-mono tabular-nums text-gray-900" x-text="fmt(totalSales)"></dd>
                            </div>
                            <div class="flex items-baseline justify-between">
                                <dt class="text-gray-500">Cash sales</dt>
                                <dd class="font-mono tabular-nums text-gray-900" x-text="fmt(saleCash)"></dd>
                            </div>
                            <div class="flex items-baseline justify-between">
                                <dt class="font-medium text-gray-700">Credit sales</dt>
                                <dd class="font-mono tabular-nums" :class="creditSales < 0 ? 'text-red-700' : 'text-gray-900'"
                                    x-text="fmt(creditSales)"></dd>
                            </div>
                            <div class="flex items-baseline justify-between text-emerald-800" x-show="saleRecovered > 0" x-cloak>
                                <dt class="font-medium">Recovered against earlier credit</dt>
                                <dd class="font-mono tabular-nums" x-text="fmt(saleRecovered)"></dd>
                            </div>
                            <p class="pt-0.5 text-xs text-gray-500">Credit sales go to market receivables</p>
                        </dl>

                        <div>
                            <x-input-label for="gross_profit" value="Gross profit" />
                            <div class="relative mt-1">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">Rs.</span>
                                <input type="text" inputmode="decimal" id="gross_profit" name="gross_profit"
                                       x-model="f.gross_profit" placeholder="0.00"
                                       class="block w-full rounded-md border-gray-300 pl-10 text-right font-mono tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <p class="mt-1 text-xs text-gray-500">Sales minus cost of goods, before expenses</p>
                            <x-input-error :messages="$errors->get('gross_profit')" class="mt-2" />
                        </div>

                        {{-- Recovery against credit given on earlier days. Not a sale,
                             which is why it sits below them rather than among them. --}}
                        <div class="border-t border-gray-200 pt-4">
                            <x-input-label for="collection_cash" value="Collected from market (earlier credit)" />
                            <div class="relative mt-1">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">Rs.</span>
                                <input type="text" inputmode="decimal" id="collection_cash" name="collection_cash"
                                       x-model="f.collection_cash" placeholder="0.00"
                                       class="block w-full rounded-md border-gray-300 pl-10 text-right font-mono tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                Recovery on credit given before today. Reduces market receivables.
                            </p>
                            <x-input-error :messages="$errors->get('collection_cash')" class="mt-2" />
                        </div>

                        <input type="hidden" name="sales_return" x-model="f.sales_return">
                        <input type="hidden" name="discount_allowed" x-model="f.discount_allowed">
                        <input type="hidden" name="bad_debt" x-model="f.bad_debt">
                        {{-- Owner drawings and capital have no card for now. An
                             unrecorded withdrawal still surfaces as a cash difference
                             at the daily close, where it can be attributed. --}}
                        <input type="hidden" name="owner_drawing" x-model="f.owner_drawing">
                        <input type="hidden" name="owner_capital" x-model="f.owner_capital">

                        <dl class="space-y-1 border-t border-gray-200 pt-3 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Cost of goods sold</dt>
                                <dd class="font-mono tabular-nums text-gray-900" x-text="fmt(cogs)"></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Margin</dt>
                                <dd class="font-mono tabular-nums" :class="marginOk ? 'text-gray-900' : 'text-amber-700'"
                                    x-text="marginLabel"></dd>
                            </div>
                            <div class="flex justify-between font-semibold">
                                <dt class="text-gray-700">Net profit</dt>
                                <dd class="font-mono tabular-nums" :class="netProfit < 0 ? 'text-red-700' : 'text-gray-900'"
                                    x-text="fmt(netProfit)"></dd>
                            </div>
                        </dl>
                    </div>
                </x-panel>

                {{-- Expenses: head by head where the day names them, a plain
                     total where it does not. The third of the same shape. --}}
                <x-panel title="Operating expenses" class="xl:col-span-2">
                    <div class="space-y-4 p-4 sm:p-6">
                        <div class="space-y-2" x-show="expenses.length > 0" x-cloak>
                            <template x-for="(row, i) in expenses" :key="i">
                                <div class="flex items-center gap-3 rounded-md border border-gray-200 px-3 py-2">
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-gray-900" x-text="row.category"></p>
                                        <p class="truncate text-xs text-gray-500" x-text="row.description || 'No description'"></p>
                                    </div>

                                    <p class="shrink-0 font-mono text-sm tabular-nums text-gray-900" x-text="fmt(n(row.amount))"></p>

                                    <button type="button" @click="editExpense(i)" title="Edit"
                                            class="shrink-0 rounded p-1 text-xs font-medium text-gray-500 hover:text-emerald-700">
                                        Edit
                                    </button>
                                    <button type="button" @click="expenses.splice(i, 1)" title="Remove"
                                            class="shrink-0 rounded p-1 text-gray-400 hover:text-red-700">&times;</button>

                                    <input type="hidden" :name="`expenses[${i}][category]`" :value="row.category">
                                    <input type="hidden" :name="`expenses[${i}][description]`" :value="row.description">
                                    <input type="hidden" :name="`expenses[${i}][amount]`" :value="row.amount">
                                </div>
                            </template>
                        </div>

                        <datalist id="expense-heads">
                            @foreach ($expenseCategories as $name)
                                <option value="{{ $name }}"></option>
                            @endforeach
                        </datalist>

                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" @click="newExpense()"
                                    class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                + Add an expense
                            </button>
                            <p class="text-xs text-gray-500">
                                Optional. Freight, fuel, salaries, rent. A head that does not exist yet is
                                created with its own ledger, so nothing has to be filed under "Other".
                            </p>
                        </div>

                        {{-- Plain total when no head was named. --}}
                        <div class="border-t border-gray-200 pt-4 sm:max-w-xs" x-show="expenses.length === 0" x-cloak>
                            <x-input-label for="expenses_cash" value="Total operating expenses" />
                            <div class="relative mt-1">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">Rs.</span>
                                <input type="text" inputmode="decimal" id="expenses_cash" name="expenses_cash"
                                       x-model="f.expenses_cash" placeholder="0.00"
                                       class="block w-full rounded-md border-gray-300 pl-10 text-right font-mono tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <x-input-error :messages="$errors->get('expenses_cash')" class="mt-2" />
                        </div>

                        <dl class="space-y-1 border-t border-gray-200 pt-3 text-sm">
                            <div class="flex justify-between font-semibold">
                                <dt class="text-gray-700">Total expenses</dt>
                                <dd class="font-mono tabular-nums text-gray-900" x-text="fmt(expensesTotal)"></dd>
                            </div>
                            <p class="text-xs font-normal text-gray-500">Comes off net profit, never off gross profit</p>
                        </dl>
                    </div>
                </x-panel>



            </div>

            <x-panel title="Notes" class="mt-6">
                    <div class="p-4 sm:p-6">
                        <textarea name="notes" rows="3"
                                  class="block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"
                                  placeholder="Anything unusual about this day">{{ old('notes', $entry->notes) }}</textarea>
                    </div>
                </x-panel>

            <div class="mt-6 flex items-center justify-between rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-sm text-gray-500">
                    {{ $amending
                        ? 'Saving replaces this day\'s postings straight away.'
                        : 'Saving keeps this as a draft. It has no effect on any balance until you post it.' }}
                </p>
                <button class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    {{ $amending ? 'Save and re-post' : 'Save and review' }}
                </button>
            </div>

            {{-- The buying side of the same panel. --}}
            <x-drawer name="purchase-invoice" max-width="md"
                      title="Purchase invoice"
                      subtitle="One invoice. Whatever is not paid today is left owing to that company.">
                <div class="space-y-5" @keydown.enter.prevent="commitPurchase()">
                    <div>
                        <x-input-label for="draft_company" value="Company" />
                        <input id="draft_company" type="text" list="company-names" x-model="purchaseDraft.company"
                               placeholder="Getz Pharma"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                        <p class="mt-1 text-xs text-gray-500">
                            A name that does not exist yet is created with its own ledger when the day is saved.
                        </p>
                    </div>

                    <div>
                        <x-input-label for="draft_purchase_invoice" value="Invoice no." />
                        <input id="draft_purchase_invoice" type="text" x-model="purchaseDraft.invoice_no" placeholder="INV-8821"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="draft_purchase_amount" value="Invoice amount" />
                            <div class="relative mt-1">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">Rs.</span>
                                <input id="draft_purchase_amount" type="text" inputmode="decimal" x-model="purchaseDraft.amount" placeholder="0.00"
                                       class="block w-full rounded-md border-gray-300 pl-10 text-right font-mono tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <p class="mt-1 text-xs text-gray-500">Leave blank to record a payment on its own.</p>
                        </div>
                        <div>
                            <x-input-label for="draft_purchase_paid" value="Paid now" />
                            <div class="relative mt-1">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">Rs.</span>
                                <input id="draft_purchase_paid" type="text" inputmode="decimal" x-model="purchaseDraft.paid" placeholder="0.00"
                                       class="block w-full rounded-md border-gray-300 pl-10 text-right font-mono tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                        </div>
                    </div>

                    <dl class="space-y-1 rounded-md bg-gray-50 px-3 py-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Left owing</dt>
                            <dd class="font-mono tabular-nums text-gray-900" x-text="fmt(purchaseDraftPending)"></dd>
                        </div>
                        <div class="flex justify-between text-emerald-800" x-show="purchaseDraftExcess > 0" x-cloak>
                            <dt>Pays off earlier bills</dt>
                            <dd class="font-mono tabular-nums" x-text="fmt(purchaseDraftExcess)"></dd>
                        </div>
                    </dl>
                </div>

                <x-slot name="footer">
                    <div class="flex items-center justify-end gap-3">
                        <button type="button" @click="$dispatch('close-drawer', 'purchase-invoice')"
                                class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="button" @click="commitPurchase()"
                                class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50"
                                :disabled="n(purchaseDraft.amount) === 0 && n(purchaseDraft.paid) === 0">
                            <span x-text="editingPurchase === null ? 'Add invoice' : 'Save changes'"></span>
                        </button>
                    </div>
                </x-slot>
            </x-drawer>

            {{-- The one place a sale line is typed. It uses the shared drawer,
                 so the next screen that needs a record entered gets the same
                 panel by naming it. --}}
            <x-drawer name="pharmacy-sale" max-width="md"
                      title="Pharmacy sale"
                      subtitle="One invoice. Whatever is not received today becomes that pharmacy's credit.">
                <div class="space-y-5" @keydown.enter.prevent="commitSale()">
                    <div>
                        <x-input-label for="draft_pharmacy" value="Pharmacy" />
                        <input id="draft_pharmacy" type="text" list="pharmacy-names" x-model="draft.pharmacy"
                               placeholder="Al-Shifa Medical Store"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                        <p class="mt-1 text-xs text-gray-500">
                            A name that does not exist yet is created with its own ledger when the day is saved.
                        </p>
                    </div>

                    <div>
                        <x-input-label for="draft_invoice" value="Invoice no." />
                        <input id="draft_invoice" type="text" x-model="draft.invoice_no" placeholder="INV-0421"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="draft_amount" value="Invoice amount" />
                            <div class="relative mt-1">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">Rs.</span>
                                <input id="draft_amount" type="text" inputmode="decimal" x-model="draft.amount" placeholder="0.00"
                                       class="block w-full rounded-md border-gray-300 pl-10 text-right font-mono tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                        </div>
                        <div>
                            <x-input-label for="draft_received" value="Received now" />
                            <div class="relative mt-1">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">Rs.</span>
                                <input id="draft_received" type="text" inputmode="decimal" x-model="draft.received" placeholder="0.00"
                                       class="block w-full rounded-md border-gray-300 pl-10 text-right font-mono tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                        </div>
                    </div>

                    <dl class="space-y-1 rounded-md bg-gray-50 px-3 py-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Goes on credit</dt>
                            <dd class="font-mono tabular-nums text-gray-900" x-text="fmt(draftPending)"></dd>
                        </div>
                        <div class="flex justify-between text-emerald-800" x-show="draftExcess > 0" x-cloak>
                            <dt>Recovers earlier credit</dt>
                            <dd class="font-mono tabular-nums" x-text="fmt(draftExcess)"></dd>
                        </div>
                    </dl>
                </div>

                <x-slot name="footer">
                    <div class="flex items-center justify-end gap-3">
                        <button type="button" @click="$dispatch('close-drawer', 'pharmacy-sale')"
                                class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="button" @click="commitSale()"
                                class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50"
                                :disabled="draftPending + draftExcess + Math.min(n(draft.amount), n(draft.received)) === 0">
                            <span x-text="editing === null ? 'Add sale' : 'Save changes'"></span>
                        </button>
                    </div>
                </x-slot>
            </x-drawer>


            {{-- Same drawer, different record. Naming it is the whole API. --}}
            <x-drawer name="expense-line" max-width="md"
                      title="Operating expense"
                      subtitle="What it was spent on, and under which head it belongs.">
                <div class="space-y-5" @keydown.enter.prevent="commitExpense()">
                    <div>
                        <x-input-label for="draft_head" value="Expense head" />
                        <input id="draft_head" type="text" list="expense-heads" x-model="expenseDraft.category"
                               placeholder="Freight"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                        <p class="mt-1 text-xs text-gray-500">
                            A head that does not exist yet is created with its own ledger when the day is saved.
                        </p>
                    </div>

                    <div>
                        <x-input-label for="draft_description" value="Description" />
                        <input id="draft_description" type="text" x-model="expenseDraft.description"
                               placeholder="Delivery van to Sargodha"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    </div>

                    <div class="sm:max-w-[12rem]">
                        <x-input-label for="draft_expense_amount" value="Amount" />
                        <div class="relative mt-1">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">Rs.</span>
                            <input id="draft_expense_amount" type="text" inputmode="decimal" x-model="expenseDraft.amount"
                                   placeholder="0.00"
                                   class="block w-full rounded-md border-gray-300 pl-10 text-right font-mono tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                        </div>
                    </div>
                </div>

                <x-slot name="footer">
                    <div class="flex items-center justify-end gap-3">
                        <button type="button" @click="$dispatch('close-drawer', 'expense-line')"
                                class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="button" @click="commitExpense()"
                                class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50"
                                :disabled="n(expenseDraft.amount) === 0 || ! expenseDraft.category.trim()">
                            <span x-text="editingExpense === null ? 'Add expense' : 'Save changes'"></span>
                        </button>
                    </div>
                </x-slot>
            </x-drawer>

        </form>
    </div>

    @push('scripts')
        <script>
            function dailyEntry() {
                // The saved figures, straight from the server — see $initialFigures.
                const initial = @json($initialFigures);

                // Not a stored column: the day's total is typed and credit sales
                // fall out of it, the same way purchases work.
                initial.sale_total = @js($saleTotal);

                return {
                    f: initial,
                    purchases: @json($purchaseRows),
                    purchaseDraft: { company: '', invoice_no: '', amount: '', paid: '' },
                    editingPurchase: null,
                    sales: @json($saleRows),
                    draft: { pharmacy: '', invoice_no: '', amount: '', received: '' },
                    editing: null,
                    expenses: @json($expenseRows),
                    expenseDraft: { category: '', description: '', amount: '' },
                    editingExpense: null,
                    n(v) {
                        const parsed = parseFloat(String(v ?? '').replace(/,/g, ''));
                        return isNaN(parsed) ? 0 : parsed;
                    },
                    fmt(v) {
                        return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    },
                    // Listed invoices win over the plain totals.
                    get totalPurchases() {
                        return this.purchases.length
                            ? this.purchases.reduce((t, r) => t + this.n(r.amount), 0)
                            : this.n(this.f.purchase_total);
                    },
                    get paidNow() {
                        return this.purchases.length
                            ? this.purchases.reduce((t, r) => t + this.n(r.paid), 0)
                            : this.n(this.f.purchase_paid);
                    },
                    get netPurchases() { return this.totalPurchases - this.n(this.f.purchase_discount); },
                    get pending() { return Math.max(0, this.netPurchases - this.paidNow); },
                    get excess() { return Math.max(0, this.paidNow - this.netPurchases); },
                    get costToStock() { return this.totalPurchases - this.n(this.f.purchase_discount); },
                    // --- Purchase lines ---------------------------------
                    newPurchase() {
                        this.purchaseDraft = { company: '', invoice_no: '', amount: '', paid: '' };
                        this.editingPurchase = null;
                        this.$dispatch('open-drawer', 'purchase-invoice');
                    },
                    editPurchase(i) {
                        this.purchaseDraft = { ...this.purchases[i] };
                        this.editingPurchase = i;
                        this.$dispatch('open-drawer', 'purchase-invoice');
                    },
                    commitPurchase() {
                        if (this.n(this.purchaseDraft.amount) === 0 && this.n(this.purchaseDraft.paid) === 0) {
                            return;
                        }

                        if (this.editingPurchase === null) {
                            this.purchases.push({ ...this.purchaseDraft });
                        } else {
                            this.purchases[this.editingPurchase] = { ...this.purchaseDraft };
                        }

                        this.$dispatch('close-drawer', 'purchase-invoice');
                    },
                    purchaseNote(row) {
                        const parts = [];
                        if (row.invoice_no) parts.push(row.invoice_no);

                        const owing = this.n(row.amount) - this.n(row.paid);
                        if (owing > 0) parts.push(this.fmt(owing) + ' left owing');
                        else if (owing < 0) parts.push(this.fmt(-owing) + ' off earlier bills');
                        else parts.push('paid in full');

                        return parts.join(' · ');
                    },
                    get purchaseDraftPending() { return Math.max(0, this.n(this.purchaseDraft.amount) - this.n(this.purchaseDraft.paid)); },
                    get purchaseDraftExcess() { return Math.max(0, this.n(this.purchaseDraft.paid) - this.n(this.purchaseDraft.amount)); },

                    // --- Sale lines -------------------------------------
                    newSale() {
                        this.draft = { pharmacy: '', invoice_no: '', amount: '', received: '' };
                        this.editing = null;
                        this.$dispatch('open-drawer', 'pharmacy-sale');
                    },
                    editSale(i) {
                        this.draft = { ...this.sales[i] };
                        this.editing = i;
                        this.$dispatch('open-drawer', 'pharmacy-sale');
                    },
                    commitSale() {
                        if (this.n(this.draft.amount) === 0 && this.n(this.draft.received) === 0) {
                            return;
                        }

                        if (this.editing === null) {
                            this.sales.push({ ...this.draft });
                        } else {
                            this.sales[this.editing] = { ...this.draft };
                        }

                        this.$dispatch('close-drawer', 'pharmacy-sale');
                    },
                    saleNote(row) {
                        const parts = [];
                        if (row.invoice_no) parts.push(row.invoice_no);

                        const credit = this.n(row.amount) - this.n(row.received);
                        if (credit > 0) parts.push(this.fmt(credit) + ' on credit');
                        else if (credit < 0) parts.push(this.fmt(-credit) + ' against earlier credit');
                        else parts.push('paid in full');

                        return parts.join(' · ');
                    },
                    get draftPending() { return Math.max(0, this.n(this.draft.amount) - this.n(this.draft.received)); },
                    get draftExcess() { return Math.max(0, this.n(this.draft.received) - this.n(this.draft.amount)); },

                    // Listed pharmacies win over the plain totals, as invoices do.
                    get totalSales() {
                        return this.sales.length
                            ? this.sales.reduce((t, r) => t + this.n(r.amount), 0)
                            : this.n(this.f.sale_total);
                    },
                    // Only what today's invoices actually covered is a cash sale.
                    get saleCash() {
                        return this.sales.length
                            ? this.sales.reduce((t, r) => t + Math.min(this.n(r.received), this.n(r.amount)), 0)
                            : this.n(this.f.sale_cash);
                    },
                    // Taken beyond the invoice: recovery, not revenue.
                    get saleRecovered() {
                        return this.sales.reduce((t, r) => t + Math.max(0, this.n(r.received) - this.n(r.amount)), 0);
                    },
                    get creditSales() { return this.totalSales - this.saleCash; },

                    // --- Expense lines ----------------------------------
                    newExpense() {
                        this.expenseDraft = { category: '', description: '', amount: '' };
                        this.editingExpense = null;
                        this.$dispatch('open-drawer', 'expense-line');
                    },
                    editExpense(i) {
                        this.expenseDraft = { ...this.expenses[i] };
                        this.editingExpense = i;
                        this.$dispatch('open-drawer', 'expense-line');
                    },
                    commitExpense() {
                        if (this.n(this.expenseDraft.amount) === 0 || ! this.expenseDraft.category.trim()) {
                            return;
                        }

                        if (this.editingExpense === null) {
                            this.expenses.push({ ...this.expenseDraft });
                        } else {
                            this.expenses[this.editingExpense] = { ...this.expenseDraft };
                        }

                        this.$dispatch('close-drawer', 'expense-line');
                    },
                    // Heads named win over the plain total, as invoices do.
                    get expensesTotal() {
                        return this.expenses.length
                            ? this.expenses.reduce((t, r) => t + this.n(r.amount), 0)
                            : this.n(this.f.expenses_cash);
                    },
                    get netSales() { return this.totalSales - this.n(this.f.sales_return); },
                    get cogs() { return this.netSales - this.n(this.f.gross_profit); },
                    get margin() { return this.netSales === 0 ? null : (this.n(this.f.gross_profit) / this.netSales) * 100; },
                    get marginOk() { return this.margin === null || (this.margin >= 0 && this.margin <= 40); },
                    get marginLabel() { return this.margin === null ? '—' : this.margin.toFixed(2) + '%'; },
                    get netProfit() {
                        return this.n(this.f.gross_profit) - this.expensesTotal - this.n(this.f.bad_debt);
                    },
                };
            }
        </script>
    @endpush
</x-workspace-layout>
