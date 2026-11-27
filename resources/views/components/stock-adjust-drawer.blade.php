{{--
    Changing a product's stock by hand, in a drawer that any page can open:

        <button x-on:click="$dispatch('open-drawer', 'adjust-stock-{{ $product->id }}')">Adjust stock</button>
        <x-stock-adjust-drawer :business="$business" :product="$product" />

    Laid out as Settings: the sections down the side, one shown at a time.

    - Count by batch: the shelf counted as a company audit counts it — each
      batch the records hold beside what is on the shelf, a batch found that
      the records did not have, and the loss or gain at cost as it is typed.
      Saved as a stock count, approved at once, so the value follows the
      quantity into the books.
    - Add or remove: a quantity in or out with a reason, as before — opening
      stock, or a correction that is not a count.
--}}
@props(['business', 'product', 'name' => null])

@php
    $name ??= 'adjust-stock-' . $product->id;
    $stock = app(\App\Domain\Stock\StockLedger::class);
    $per = max(1, (int) $product->units_per_pack);
    $packs = $stock->onHand($business, $product);
    $loose = $stock->looseOnHand($business, $product);
    $held = app(\App\Domain\Stock\ExpiryWatch::class)->heldBatchesByProduct($business, [$product->id])->get($product->id, []);
    $rate = $product->purchase_rate ?? $product->trade_price;

    $lines = collect($held)->map(fn ($b) => [
        'batch_no' => $b['batch_no'],
        'expires_on' => $b['expires_on']->format('Y-m'),
        'month' => $b['expires_on']->format('M Y'),
        'held' => $b['packs'] * $per + $b['loose'],
        'rate' => (float) (($b['unit_cost'] ?? $rate)?->toDecimal() ?? 0),
        'is_new' => false,
    ])->values();

    // On the books without a date: stock from before dates were kept.
    $undated = max(0, $packs * $per + $loose - $lines->sum('held'));
    if ($undated > 0) {
        $lines->push(['batch_no' => null, 'expires_on' => null, 'month' => null, 'held' => $undated,
            'rate' => (float) ($rate?->toDecimal() ?? 0), 'is_new' => false]);
    }

    $section = $errors->has('packs') || $errors->has('expires_on') ? 'move' : 'count';
@endphp

<x-drawer :name="$name" max-width="5xl" :show="$errors->has('count') || $errors->has('packs') || $errors->has('expires_on')"
          :title="'Adjust stock · ' . $product->label()"
          :subtitle="'In the records: ' . $product->stockLabel($packs, $loose) . ($per > 1 ? ' · ' . $per . ' pieces in a pack' : '')">
    <div x-data="stockAdjust({ lines: @js($lines), per: @js($per), rate: @js((float) ($rate?->toDecimal() ?? 0)), section: @js($section) })"
         class="flex flex-wrap items-start gap-6">

        {{-- The sections, as on Settings. --}}
        <nav aria-label="Ways to change the stock"
             class="flex w-full flex-col gap-0.5 rounded-lg border border-line bg-white p-2 lg:w-52 lg:shrink-0">
            @foreach (['count' => ['Count by batch', $lines->count()], 'move' => ['Add or remove', null]] as $key => [$label, $count])
                <a href="#" x-on:click.prevent="section = @js($key)"
                   :aria-current="section === @js($key) ? 'page' : null"
                   class="flex items-center justify-between gap-3 rounded-md px-3 py-2 text-sm"
                   :class="section === @js($key) ? 'bg-emerald-50 font-semibold text-emerald-800' : 'text-gray-700 hover:bg-gray-50'">
                    <span>{{ $label }}</span>
                    @if ($count !== null)
                        <span class="rounded-full px-2 py-0.5 text-xs tabular-nums"
                              :class="section === @js($key) ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600'">{{ $count }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

        <div class="min-w-0 flex-1">
            {{-- Count by batch --}}
            <form x-show="section === 'count'" method="POST" action="{{ route('businesses.products.count-stock', [$business, $product]) }}"
                  class="rounded-lg border border-line bg-white">
                @csrf
                <div class="border-b border-gray-200 px-4 py-3">
                    <h3 class="text-sm font-semibold text-gray-900">Count by batch</h3>
                    <p class="mt-0.5 text-xs text-gray-500">Enter what is on the shelf for each batch. A batch left at 0 was not found. What is short is booked as a loss, what is over goes back into stock.</p>
                </div>

                <div class="overflow-x-auto px-4 py-3">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wide text-gray-500">
                                <th class="py-1 pr-3 font-semibold">Batch</th>
                                <th class="py-1 pr-3 font-semibold">Expiry</th>
                                <th class="py-1 pr-3 text-right font-semibold text-amber-700">Purchase</th>
                                <th class="py-1 pr-3 text-right font-semibold">In records</th>
                                <th class="py-1 pr-3 text-right font-semibold">Packs</th>
                                <th class="py-1 pr-3 text-right font-semibold" x-show="per > 1">Loose</th>
                                <th class="py-1 pr-3 text-right font-semibold">Loss / gain at cost</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(line, j) in lines" :key="line.key">
                                <tr class="border-t border-gray-100">
                                    <td class="py-1.5 pr-3">
                                        <input type="hidden" :name="`batches[${j}][is_new]`" :value="line.is_new ? 1 : 0">
                                        <template x-if="! line.is_new">
                                            <span>
                                                <span class="font-mono text-gray-900" x-text="line.batch_no || (line.expires_on ? 'No batch no.' : 'Undated stock')"></span>
                                                <input type="hidden" :name="`batches[${j}][batch_no]`" :value="line.batch_no ?? ''">
                                            </span>
                                        </template>
                                        <template x-if="line.is_new">
                                            <input type="text" :name="`batches[${j}][batch_no]`" x-model="line.batch_no" maxlength="60" placeholder="Batch no."
                                                   class="w-32 rounded-md border-gray-300 py-1 font-mono text-sm uppercase shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                        </template>
                                    </td>
                                    <td class="py-1.5 pr-3">
                                        <template x-if="! line.is_new">
                                            <span>
                                                <span class="text-gray-700" x-text="line.month || '—'"></span>
                                                <input type="hidden" :name="`batches[${j}][expires_on]`" :value="line.expires_on ?? ''">
                                            </span>
                                        </template>
                                        <template x-if="line.is_new">
                                            <input type="month" :name="`batches[${j}][expires_on]`" x-model="line.expires_on"
                                                   class="rounded-md border-gray-300 py-1 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                        </template>
                                    </td>
                                    <td class="py-1.5 pr-3 text-right font-mono text-amber-800" x-text="money(line.rate)"></td>
                                    <td class="py-1.5 pr-3 text-right font-mono text-gray-600" x-text="line.is_new ? 'not in records' : say(line.held)"></td>
                                    <td class="py-1.5 pr-3 text-right">
                                        <input type="number" min="0" step="1" :name="`batches[${j}][packs]`" x-model.number="line.packs"
                                               class="w-20 rounded-md border-gray-300 py-1 text-right font-mono shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                    </td>
                                    <td class="py-1.5 pr-3 text-right" x-show="per > 1">
                                        <input type="number" min="0" :max="per - 1" step="1" :name="`batches[${j}][loose]`" x-model.number="line.loose"
                                               class="w-16 rounded-md border-gray-300 py-1 text-right font-mono shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                    </td>
                                    <td class="whitespace-nowrap py-1.5 pr-3 text-right font-mono font-semibold"
                                        :class="diff(line) < 0 ? 'text-red-700' : (diff(line) > 0 ? 'text-sky-800' : 'text-gray-400')"
                                        x-text="diff(line) === 0 ? '—' : (diff(line) < 0 ? '−' : '+') + money(Math.abs(diff(line)) * line.rate / per)"></td>
                                    <td class="py-1.5 text-right">
                                        <button type="button" x-show="line.is_new" x-on:click="lines.splice(j, 1)"
                                                class="text-gray-400 hover:text-red-700" aria-label="Remove this batch">&times;</button>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="lines.length === 0">
                                <td colspan="8" class="py-4 text-center text-sm text-gray-500">None in the records. Add a batch found on the shelf.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 px-4 py-3">
                    <button type="button" x-on:click="addLine()"
                            class="rounded-md border border-dashed border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:border-emerald-600 hover:text-emerald-800">
                        + Batch found on the shelf
                    </button>
                    <input type="text" name="note" required maxlength="255" value="{{ old('note') }}" placeholder="Reason, e.g. shelf counted, two packs damaged"
                           class="min-w-[14rem] flex-1 rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 bg-gray-50 px-4 py-3">
                    <span class="text-sm" :class="total() < 0 ? 'text-red-700' : (total() > 0 ? 'text-sky-800' : 'text-emerald-700')">
                        <span class="font-mono font-semibold" x-text="total() === 0 ? 'Matches the records' : (total() < 0 ? '−' : '+') + say(Math.abs(total()))"></span>
                        <template x-if="total() !== 0">
                            <span> · <span class="font-semibold" x-text="value() < 0 ? 'Loss' : 'Gain'"></span>
                                <span class="font-mono font-semibold" x-text="money(Math.abs(value()))"></span> at cost</span>
                        </template>
                    </span>
                    <span class="flex items-center gap-3">
                        <x-input-error :messages="$errors->get('count')" />
                        <button type="button" x-on:click="$dispatch('close-drawer', @js($name))" class="text-sm text-gray-500 hover:text-gray-800">Cancel</button>
                        <button class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Save count</button>
                    </span>
                </div>
            </form>

            {{-- Add or remove: a quantity in or out, with a reason. What is
                 added says when it expires, like a delivery. --}}
            <form x-show="section === 'move'" x-cloak method="POST" action="{{ route('businesses.products.adjust-stock', [$business, $product]) }}"
                  x-data="{ packs: @js(old('packs', '')) }" class="rounded-lg border border-line bg-white">
                @csrf
                <div class="border-b border-gray-200 px-4 py-3">
                    <h3 class="text-sm font-semibold text-gray-900">Add or remove</h3>
                    <p class="mt-0.5 text-xs text-gray-500">Opening stock, or a correction that is not a count. A minus removes.</p>
                </div>
                <div class="grid gap-4 px-4 py-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="type-{{ $product->id }}" value="Kind" />
                        <select id="type-{{ $product->id }}" name="type"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="adjustment">Adjustment</option>
                            <option value="opening" @selected(old('type') === 'opening')>Opening stock</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="packs-{{ $product->id }}" :value="ucfirst($business->unit()->many()) . ' (− to remove)'" />
                        <x-text-input id="packs-{{ $product->id }}" name="packs" type="number" step="1" required x-model="packs"
                                      class="mt-1 block w-full text-right font-mono" placeholder="e.g. -12" />
                    </div>
                    <div x-show="Number(packs) > 0" x-cloak>
                        <x-input-label value="Expiry (required)" />
                        <input type="month" name="expires_on" value="{{ old('expires_on') }}" :disabled="! (Number(packs) > 0)"
                               class="mt-1 block w-full rounded-md border-gray-300 py-2 text-sm">
                    </div>
                    <div x-show="Number(packs) > 0" x-cloak>
                        <x-input-label for="batch-{{ $product->id }}" value="Batch no." />
                        <x-text-input id="batch-{{ $product->id }}" name="batch_no" type="text" maxlength="60" :value="old('batch_no')"
                                      x-bind:disabled="! (Number(packs) > 0)" class="mt-1 block w-full font-mono uppercase" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="note-{{ $product->id }}" value="Reason" />
                        <x-text-input id="note-{{ $product->id }}" name="note" type="text" class="mt-1 block w-full"
                                      placeholder="e.g. Breakage, expiry write-off, opening count" :value="old('note')" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-error :messages="$errors->get('packs')" />
                        <x-input-error :messages="$errors->get('expires_on')" />
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-gray-200 bg-gray-50 px-4 py-3">
                    <button type="button" x-on:click="$dispatch('close-drawer', @js($name))" class="text-sm text-gray-500 hover:text-gray-800">Cancel</button>
                    <button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">Save</button>
                </div>
            </form>
        </div>
    </div>
</x-drawer>

@once
    @push('scripts')
        <script>
            function stockAdjust({ lines, per, rate, section }) {
                let n = 0;
                return {
                    per, rate, section,
                    // Each batch starts at what the records hold, so only the
                    // ones that differ need typing.
                    lines: lines.map(l => ({ ...l, key: n++, packs: Math.floor(l.held / per), loose: l.held % per })),
                    addLine() {
                        this.lines.push({ key: n++, batch_no: '', expires_on: '', month: null, held: 0, rate: this.rate, is_new: true, packs: 0, loose: 0 });
                    },
                    found(l) { return (Number(l.packs) || 0) * this.per + (this.per > 1 ? (Number(l.loose) || 0) : 0); },
                    diff(l) { return this.found(l) - l.held; },
                    total() { return this.lines.reduce((t, l) => t + this.diff(l), 0); },
                    value() { return this.lines.reduce((t, l) => t + this.diff(l) * l.rate / this.per, 0); },
                    say(pieces) {
                        if (this.per <= 1) return `${pieces} ${pieces === 1 ? 'pack' : 'packs'}`;
                        const p = Math.floor(pieces / this.per), l = pieces % this.per;
                        return `${p} ${p === 1 ? 'pack' : 'packs'}` + (l ? ` + ${l} loose` : '');
                    },
                    money(v) { return Number(v).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                };
            }
        </script>
    @endpush
@endonce
