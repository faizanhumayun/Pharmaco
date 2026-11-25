{{-- The page is the window: the two lists take whatever height is left and
     scroll inside themselves, so the page itself does not scroll. Below the
     two-column width the page scrolls as usual. --}}
<x-workspace-layout :business="$business" fill>
    {{-- No heading band above the page. The company's name and what can be
         done with it sit on one grey band, as on the lists. --}}
    <div class="flex h-full min-h-0 w-full flex-col overflow-y-auto px-4 py-5 sm:px-6 lg:px-8 xl:overflow-hidden">
        <x-flash />

        <div class="mb-4 flex shrink-0 flex-wrap items-center justify-between gap-3 rounded-lg bg-gray-200 px-4 py-2.5 ring-1 ring-gray-300">
            <div class="min-w-0">
                <x-back-link :href="route('businesses.companies.index', $business)" label="All companies" />
                <h1 class="truncate text-lg font-semibold text-gray-900">{{ $company->name }}
                    <span class="ml-1 font-mono text-xs font-normal text-gray-500">Account {{ $company->account?->code }}</span>
                </h1>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                {{-- Paying what is owed: offered only while something is. --}}
                @can('create', [\App\Models\DailyEntry::class, $business])
                    @if ($balance->isPositive())
                        <button type="button" x-data x-on:click="$dispatch('open-modal', 'pay-company')" @disabled($dayClosed)
                                title="{{ $dayClosed ? 'Today is closed. Reopen the day to record a payment.' : 'Record a payment to this company' }}"
                                class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-40">
                            Pay this company
                        </button>
                    @endif
                @endcan
                <a href="{{ route('businesses.products.index', ['business' => $business, 'company' => $company->id]) }}"
                   class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50">
                    Products ({{ number_format($productCount) }})
                </a>
                <a href="{{ route('businesses.companies.imports.index', [$business, $company]) }}"
                   class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50">
                    Price lists
                </a>
                @can('importProducts', $business)
                    <a href="{{ route('businesses.companies.imports.create', [$business, $company]) }}"
                       class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                        Import price list
                    </a>
                @endcan
            </div>
        </div>

        @if ($draftImport)
            <div class="mb-4 flex shrink-0 flex-wrap items-center gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <span>
                    <span class="font-semibold">{{ $draftImport->original_filename }}</span>
                    was uploaded {{ $draftImport->created_at->diffForHumans() }} and is waiting for review.
                    No products have been added from it yet.
                </span>
                <a href="{{ route('businesses.companies.imports.show', [$business, $company, $draftImport]) }}"
                   class="font-semibold underline">Review</a>
            </div>
        @endif

        {{-- The account in five figures: what is still to pay first, and
             last their stock against it. Not a rule, a reading: stock worth
             less than the pending balance usually means the goods were sold
             and the company has not been paid yet. --}}
        @php($gap = $stock['value']->minus($balance))
        <div class="mb-4 grid shrink-0 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            <div class="rounded-md border px-4 py-3 shadow-sm {{ $balance->isPositive() ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50' }}">
                <p class="text-xs uppercase tracking-wide {{ $balance->isPositive() ? 'text-amber-700' : 'text-emerald-700' }}">Pending balance</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums {{ $balance->isPositive() ? 'text-amber-900' : 'text-emerald-800' }}">{{ $balance->absolute()->format() }}</p>
                <p class="mt-0.5 text-xs {{ $balance->isPositive() ? 'text-amber-700' : 'text-emerald-700' }}">
                    {{ $balance->isPositive() ? 'still to be paid to this company' : ($balance->isNegative() ? 'paid in advance to this company' : 'nothing to pay') }}
                </p>
            </div>

            <div class="rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-gray-400">Total purchased</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-gray-900">{{ $purchases['billed']->format() }}</p>
                <p class="mt-0.5 text-xs text-gray-500">
                    {{ number_format($purchases['invoices']) }} {{ Str::plural('invoice', $purchases['invoices']) }}{{ $purchases['last'] ? ' · last ' . $purchases['last'] : '' }}
                </p>
            </div>

            <div class="rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-gray-400">Total paid</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-gray-900">{{ $purchases['paid']->format() }}</p>
                <p class="mt-0.5 text-xs text-gray-500">{{ $purchases['outstanding']->format() }} pending on invoices</p>
            </div>

            <div class="rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-gray-400">Their stock in hand</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-gray-900">{{ $stock['value']->format() }}</p>
                <p class="mt-0.5 text-xs text-gray-500">
                    {{ number_format($stock['packs']) }} {{ $business->unit()->many() }} at purchase price
                </p>
            </div>

            <div class="rounded-md border px-4 py-3 shadow-sm {{ $gap->isNegative() ? 'border-red-200 bg-red-50' : 'border-emerald-200 bg-emerald-50' }}"
                 title="Their stock in hand, less the pending balance">
                <p class="text-xs uppercase tracking-wide {{ $gap->isNegative() ? 'text-red-700' : 'text-emerald-700' }}">Stock less pending</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums {{ $gap->isNegative() ? 'text-red-800' : 'text-emerald-800' }}">{{ $gap->format() }}</p>
                <p class="mt-0.5 text-xs {{ $gap->isNegative() ? 'text-red-700' : 'text-emerald-700' }}">
                    {{ $gap->isNegative() ? 'pending is more than the stock in hand' : 'stock in hand covers the pending balance' }}
                </p>
            </div>
        </div>

        {{-- Two columns: the account on the left, their goods on the right. --}}
        <div class="grid items-start gap-6 xl:min-h-0 xl:flex-1 xl:grid-cols-2 xl:grid-rows-[minmax(0,1fr)] xl:items-stretch">
            <div class="min-w-0 xl:flex xl:min-h-0 xl:flex-col">
        {{-- Every bill and every payment, in the order they happened, with
             what was left to pay after each. --}}
        <x-panel class="xl:flex xl:min-h-0 xl:flex-1 xl:flex-col">
            {{-- Title and what it is on one line; the list scrolls inside
                 the card, under headings that stay put. --}}
            <div class="flex shrink-0 flex-wrap items-baseline gap-x-3 gap-y-0.5 border-b border-gray-200 px-4 py-3 sm:px-6">
                <h2 class="text-base font-semibold text-gray-900">Account statement</h2>
                <p class="text-sm text-gray-500">Every purchase and payment, with the pending balance after each one.</p>
                {{-- Corrections are hidden, not deleted. --}}
                <a href="{{ route('businesses.companies.show', array_filter([$business, $company, 'corrections' => $showReversals ? null : 1])) }}"
                   class="ml-auto rounded-md border px-2.5 py-1 text-xs font-medium {{ $showReversals ? 'border-emerald-700 bg-emerald-50 text-emerald-800' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                    {{ $showReversals ? 'Hide corrections' : 'Show corrections' }}
                </a>
            </div>
            <div class="max-h-[34rem] overflow-auto xl:max-h-none xl:min-h-0 xl:flex-1">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="sticky top-0 z-10 bg-gray-50">
                        <tr>
                            @foreach (['Date', 'Details', 'Paid', 'Purchased', 'Pending balance'] as $h)
                                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ in_array($h, ['Date','Details']) ? 'text-left' : 'text-right' }} {{ $loop->first || $loop->last ? 'sm:px-6' : '' }}">{{ $h }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            @php($entry = $row['entry'])
                            <tr>
                                <td class="whitespace-nowrap px-4 py-2 text-gray-600 sm:px-6">{{ $entry->business_date->format('d M Y') }}</td>
                                <td class="px-4 py-2">
                                    <span class="font-medium text-gray-900">{{ $entry->transaction->type->label() }}</span>
                                    <p class="text-xs text-gray-500">
                                        @if ($entry->transaction->narration) {{ $entry->transaction->narration }} · @endif
                                        by {{ $entry->transaction->creator->name }}
                                        <span class="text-gray-400">· #{{ $entry->transaction->id }}</span>
                                    </p>
                                </td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums text-emerald-700">{{ $entry->debit->isZero() ? '' : '− ' . $entry->debit->format() }}</td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900">{{ $entry->credit->isZero() ? '' : '+ ' . $entry->credit->format() }}</td>
                                <td class="px-4 py-2 text-right font-mono font-semibold tabular-nums sm:px-6">{{ $row['running']->format() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-10 text-center text-gray-500">No transactions for this company yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-panel>
            </div>

            <div class="min-w-0 xl:flex xl:min-h-0 xl:flex-col">
                <x-panel class="xl:flex xl:min-h-0 xl:flex-1 xl:flex-col">
                    {{-- The title with its figures beside it as chips. The value
                         is not repeated here: it is a card at the top. --}}
                    <div class="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-2 border-b border-gray-200 px-4 py-3 sm:px-6">
                        <h2 class="text-base font-semibold text-gray-900">Stock from this company</h2>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800 ring-1 ring-inset ring-emerald-600/20">
                            In stock <span class="font-mono tabular-nums">{{ number_format($stock['packs']) }}</span> {{ $business->unit()->many() }}
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700 ring-1 ring-inset ring-gray-500/20">
                            <span class="font-mono tabular-nums">{{ number_format($stock['products']) }}</span> {{ Str::plural('product', $stock['products']) }}
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset {{ $stock['out'] > 0 ? 'bg-amber-50 text-amber-800 ring-amber-600/20' : 'bg-gray-100 text-gray-700 ring-gray-500/20' }}">
                            <span class="font-mono tabular-nums">{{ number_format($stock['out']) }}</span> out of stock
                        </span>
                        @if ($stock['below'] > 0)
                            <a href="{{ route('businesses.products.index', [$business, 'short' => 1]) }}" title="View and correct"
                               class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-3 py-1 text-xs font-semibold text-red-800 ring-1 ring-inset ring-red-600/20 hover:bg-red-100">
                                <span class="font-mono tabular-nums">{{ number_format($stock['below']) }}</span> negative stock · correct
                            </a>
                        @endif
                    </div>

                    <div class="max-h-[26rem] overflow-auto xl:max-h-none xl:min-h-0 xl:flex-1">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="sticky top-0 z-10 bg-gray-50">
                                <tr>
                                    @foreach (['Product', Str::ucfirst($business->unit()->many()), 'Cost rate', 'Value'] as $h)
                                        <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ $loop->first ? 'text-left sm:px-6' : 'text-right' }} {{ $loop->last ? 'sm:px-6' : '' }}">{{ $h }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($stock['rows'] as $row)
                                    <tr>
                                        <td class="px-4 py-2 text-gray-900 sm:px-6">{{ $row['product']->label() }}</td>
                                        <td class="px-4 py-2 text-right font-mono tabular-nums {{ $row['packs'] < 0 ? 'font-semibold text-red-700' : ($row['packs'] === 0 ? 'text-gray-400' : 'text-gray-900') }}">
                                            {{ number_format($row['packs']) }}
                                        </td>
                                        <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-600">
                                            {{ ($row['product']->purchase_rate ?? $row['product']->trade_price)?->format() ?? '—' }}
                                        </td>
                                        <td class="px-4 py-2 text-right font-mono tabular-nums text-gray-900 sm:px-6">{{ $row['value']->format() }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500">
                                            No products for this company yet.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($stock['products'] > $stock['rows']->count())
                        <div class="shrink-0 border-t border-gray-100 px-4 py-3 text-sm sm:px-6">
                            <a href="{{ route('businesses.products.index', ['business' => $business, 'company' => $company->id]) }}"
                               class="font-medium text-emerald-700 hover:text-emerald-800">
                                View all {{ number_format($stock['products']) }} products →
                            </a>
                        </div>
                    @endif
                </x-panel>

            </div>
        </div>
    </div>

    {{-- Paying the company. The amount starts at the whole pending balance;
         less can be typed. It goes on today's entry as cash paid out. --}}
    @can('create', [\App\Models\DailyEntry::class, $business])
        @if ($balance->isPositive())
            <x-modal name="pay-company" maxWidth="md" :show="$errors->has('amount')" focusable>
                <form method="POST" action="{{ route('businesses.companies.pay', [$business, $company]) }}" class="p-6"
                      x-data="{ amount: @js(old('amount', $balance->toDecimal())), pending: @js((float) $balance->toDecimal()) }">
                    @csrf
                    <h2 class="text-lg font-semibold text-gray-900">Pay {{ $company->name }}</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Pending balance: <span class="font-mono font-semibold tabular-nums text-amber-700">Rs. {{ $balance->format() }}</span>
                    </p>

                    <div class="mt-4">
                        <x-input-label for="pay_amount" value="Amount paid now" />
                        <div class="mt-1 flex items-stretch gap-2">
                            <input id="pay_amount" name="amount" type="text" inputmode="decimal" x-model="amount" required
                                   x-on:focus="$el.select()"
                                   class="block w-full rounded-md border-gray-300 text-right font-mono text-lg font-semibold tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                            <button type="button" x-on:click="amount = pending.toFixed(2)"
                                    class="whitespace-nowrap rounded-md bg-gray-100 px-3 text-sm font-semibold text-gray-900 hover:bg-gray-200">In full</button>
                        </div>
                        <x-input-error :messages="$errors->get('amount')" class="mt-2" />
                        <p class="mt-1 text-sm text-gray-600" x-show="Number(String(amount).replace(/,/g, '')) > 0 && Number(String(amount).replace(/,/g, '')) < pending" x-cloak>
                            Pending after this payment:
                            <span class="font-mono font-semibold tabular-nums" x-text="'Rs. ' + (pending - Number(String(amount).replace(/,/g, ''))).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></span>
                        </p>
                    </div>

                    <div class="mt-4">
                        <x-input-label for="pay_reference" value="Reference (optional)" />
                        <x-text-input id="pay_reference" name="reference" type="text" maxlength="60" class="mt-1 block w-full"
                                      :value="old('reference')" placeholder="Receipt number, cheque number or invoice paid" />
                    </div>

                    <p class="mt-4 text-xs text-gray-500">
                        The payment is recorded as cash paid today, {{ $business->today()->format('j M Y') }}, on the daily entry.
                        The company's bills are not changed.
                    </p>

                    <div class="mt-5 flex items-center justify-end gap-3">
                        <button type="button" x-on:click="$dispatch('close-modal', 'pay-company')"
                                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                        <button type="submit"
                                class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Record payment</button>
                    </div>
                </form>
            </x-modal>
        @endif
    @endcan
</x-workspace-layout>
