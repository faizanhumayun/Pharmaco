{{--
    Taking the money in.

    Whatever came back goes in the box — less than the bill, the bill exactly,
    or more because old dues came with it. What it settles is shown as it is
    typed, worked out the same way the server will, so nothing is a surprise
    after saving.
--}}
<div x-show="taking" x-cloak x-on:keydown.escape.window="taking = null"
     class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-gray-900/50 p-4">
    <form method="POST" action="{{ route('businesses.collections.store', $business) }}"
          class="my-auto w-full max-w-lg rounded-xl bg-white shadow-xl">
        @csrf
        <input type="hidden" name="pharmacy_id" :value="taking?.pharmacy_id">
        <input type="hidden" name="pos_bill_id" :value="target?.id">

        <div class="border-b border-gray-200 px-5 py-4">
            <p class="text-sm text-gray-500">Collect from</p>
            <p class="text-lg font-semibold text-gray-900" x-text="taking?.name"></p>
            <p class="text-sm text-gray-500">
                owes <span class="font-medium text-gray-900" x-text="'Rs. ' + money(taking?.owed)"></span>
                on <span x-text="taking?.bills?.length"></span>
                <span x-text="taking?.bills?.length === 1 ? 'bill' : 'bills'"></span>
            </p>
        </div>

        <div class="px-5 py-4">
            <label class="block">
                <span class="text-sm font-medium text-gray-700">Amount received</span>
                <input x-ref="amount" name="amount" x-model="amount" type="text" inputmode="decimal" required
                       placeholder="0.00"
                       class="mt-1 block w-full rounded-md border-gray-300 py-2.5 text-right text-2xl font-semibold tabular-nums shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
            </label>

            <div class="mt-2 flex flex-wrap gap-1.5">
                <button type="button" x-on:click="amount = String(target?.owed ?? 0)"
                        class="rounded-md bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200">
                    This bill <span x-text="money(target?.owed)"></span>
                </button>
                <button type="button" x-on:click="amount = String(taking?.owed ?? 0)"
                        class="rounded-md bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200">
                    Everything <span x-text="money(taking?.owed)"></span>
                </button>
            </div>

            {{-- What it clears, as it is typed. --}}
            <div x-show="Number(amount) > 0" x-cloak class="mt-4 rounded-lg bg-gray-50 p-3 ring-1 ring-gray-200">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Applied to</p>
                <ul class="mt-1.5 space-y-1 text-sm">
                    <template x-for="row in applied" :key="row.id">
                        <li class="flex items-center justify-between gap-3">
                            <span class="font-mono text-xs text-gray-600" x-text="row.ref"></span>
                            <span class="flex items-center gap-2">
                                <span class="tabular-nums text-gray-900" x-text="'Rs. ' + money(row.applied)"></span>
                                <span class="text-xs" :class="row.settled ? 'text-emerald-700' : 'text-amber-700'"
                                      x-text="row.settled ? 'settled' : 'part paid'"></span>
                            </span>
                        </li>
                    </template>
                </ul>

                <p x-show="onAccount > 0" x-cloak class="mt-2 border-t border-gray-200 pt-2 text-sm text-gray-600">
                    <span class="font-medium text-gray-900" x-text="'Rs. ' + money(onAccount)"></span>
                    more than every open bill — it stays on their account as credit.
                </p>

                <p class="mt-2 border-t border-gray-200 pt-2 text-sm">
                    <span class="text-gray-600">Still owes after this</span>
                    <span class="float-right font-semibold tabular-nums"
                          :class="remaining > 0 ? 'text-amber-800' : 'text-emerald-800'"
                          x-text="'Rs. ' + money(remaining)"></span>
                </p>
            </div>

            <label class="mt-4 block">
                <span class="text-sm font-medium text-gray-700">Note <span class="font-normal text-gray-400">optional</span></span>
                <input name="note" type="text" maxlength="255" placeholder="e.g. collected on delivery"
                       class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
            </label>

            <p class="mt-3 text-xs leading-relaxed text-gray-500">
                Recorded on today's entry — {{ $today->format('l j F Y') }} — because that is when the
                money arrived. The bills it pays off keep the figures they were sold with.
            </p>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-200 px-5 py-3">
            <button type="button" x-on:click="taking = null"
                    class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Cancel
            </button>
            <button type="submit" :disabled="! (Number(amount) > 0)"
                    class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-40">
                Record collection
            </button>
        </div>
    </form>
</div>
