{{--
    A company audit being counted: one line per product, as Add stock lays a
    delivery out.

    Each line is saved on its own as it is counted — "Matches", or the figures
    on the rack, batch by batch where the audit counts by batch — so nothing
    is lost if the counter stops halfway, and the totals at the top follow.
    The records each line is checked against are read at the moment it is
    saved, so the till can stay open throughout.
--}}
<x-workspace-layout :business="$business">
    <div class="w-full px-4 py-5 sm:px-6 lg:px-8"
         x-data="auditSheet({ rows: @js($rows), totals: @js($totals), byBatch: @js($audit->by_batch), canEditProducts: @js($canEditProducts), sellsLoose: @js($business->sellsLoose()), forms: @js($forms), catalogue: @js($catalogue), addUrl: @js($addUrl), companyId: @js($audit->company_id) })"
         x-on:product-added.window="if (Number($event.detail.company_id) === companyId) addProduct($event.detail.id)"
         x-on:keydown.window="onKey($event)">
        <x-flash :keys="['audit']" />

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-gray-200 px-4 py-2.5 ring-1 ring-gray-300">
            <div class="min-w-0">
                <x-back-link :href="route('businesses.company-audits.index', $business)" label="Company stock audit" />
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-lg font-semibold text-gray-900">Audit · {{ $audit->company->name }}</h1>
                    <x-badge :classes="$audit->statusClasses()">{{ $audit->statusLabel() }}</x-badge>
                </div>
                <p class="text-sm text-gray-600">
                    Started {{ $audit->created_at->timezone($business->timezone)->format('j M Y, h:i A') }} by {{ $audit->starter?->name }}{{ $audit->device ? ' on ' . $audit->device->name : '' }}
                    · {{ $audit->by_batch ? 'counted by batch' : 'counted by product' }} · each line saves as you go
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('businesses.company-audits.index', $business) }}"
                   class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50">Continue later</a>
                <form method="POST" action="{{ route('businesses.company-audits.finish', [$business, $audit]) }}">
                    @csrf
                    <button class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-40"
                            :disabled="totals.counted === 0"
                            :title="totals.counted < totals.products ? `${totals.products - totals.counted} not counted yet. They will show as not counted on the report.` : ''">
                        Finish and see report
                    </button>
                </form>
            </div>
        </div>

        {{-- Laid out as Settings and the Daily Log: the sections down the side,
             one shown at a time. Counted products move out of To count into Done. --}}
        <div class="flex flex-wrap items-start gap-6">
            <div class="flex w-full flex-col gap-3 lg:w-60 lg:shrink-0">
                <nav aria-label="Products on the sheet"
                     class="flex flex-col gap-0.5 rounded-lg border border-line bg-white p-2">
                    <template x-for="t in tabs()" :key="t.key">
                        <a href="#" x-on:click.prevent="show = t.key" :aria-current="show === t.key ? 'page' : null"
                           class="flex items-center justify-between gap-3 rounded-md px-3 py-2 text-sm"
                           :class="show === t.key ? 'bg-emerald-50 font-semibold text-emerald-800' : 'text-gray-700 hover:bg-gray-50'">
                            <span x-text="t.label"></span>
                            <span class="rounded-full px-2 py-0.5 text-xs tabular-nums"
                                  :class="show === t.key ? 'bg-emerald-100 text-emerald-800' : (t.key === 'diff' && t.count > 0 ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-600')"
                                  x-text="t.count"></span>
                        </a>
                    </template>
                </nav>

                {{-- How far the count has got, kept in view beside the list. --}}
                <div class="rounded-lg border border-line bg-white px-3 py-3 text-sm">
                    <p class="flex justify-between gap-2 text-gray-700">
                        <span>Counted</span>
                        <span class="font-mono font-semibold tabular-nums text-gray-900"><span x-text="totals.counted"></span> / <span x-text="totals.products"></span></span>
                    </p>
                    <div class="mt-1.5 h-1.5 rounded-full bg-gray-100"><div class="h-1.5 rounded-full bg-emerald-700" :style="`width: ${totals.products ? Math.round(totals.counted / totals.products * 100) : 0}%`"></div></div>
                    <p class="mt-2 text-xs text-gray-500">↑ ↓ to move · Enter to count the line · Esc to close</p>
                </div>
            </div>

            <div class="min-w-0 flex-1">
            {{-- Where the audit stands, each card saying what it means and what
                 approving will do. Nothing here has changed the stock yet. "All
                 counted" and "check the prices" come up as toasts (notices()). --}}
            <div class="mb-4 grid gap-3 sm:grid-cols-2 2xl:grid-cols-5">
                {{-- 1. How far the count has got. --}}
                <div class="rounded-md border border-gray-200 bg-white px-4 py-3">
                    <p class="text-xs font-semibold text-gray-700">Products counted</p>
                    <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-gray-900"><span x-text="totals.counted"></span> <span class="text-sm font-normal text-gray-500">of <span x-text="totals.products"></span></span></p>
                    <div class="mt-1.5 h-1.5 rounded-full bg-gray-100"><div class="h-1.5 rounded-full bg-emerald-700" :style="`width: ${totals.products ? Math.round(totals.counted / totals.products * 100) : 0}%`"></div></div>
                    <p class="mt-1.5 text-xs text-gray-600">
                        <span x-text="totals.matched"></span> matched the records<span x-show="totals.short_count + totals.extra_count > 0">, <span x-text="totals.short_count + totals.extra_count"></span> different</span>.
                        <span x-show="totals.counted < totals.products"><span x-text="totals.products - totals.counted"></span> still to count.</span>
                    </p>
                </div>

                {{-- 2. What the stock is worth: the records against the rack. --}}
                <div class="rounded-md border border-gray-200 bg-white px-4 py-3">
                    <p class="text-xs font-semibold text-gray-700">Stock value, at purchase price</p>
                    <dl class="mt-1 space-y-0.5 text-sm">
                        <div class="flex justify-between gap-2"><dt class="text-gray-500">In the records</dt><dd class="font-mono font-semibold tabular-nums text-gray-900" x-text="totals.records"></dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-gray-500">On the rack</dt><dd class="font-mono font-semibold tabular-nums text-gray-900" x-text="totals.found"></dd></div>
                    </dl>
                    <p class="mt-1 text-xs text-gray-500">Of the products counted so far.</p>
                </div>

                {{-- 3. What approving would book. --}}
                <div class="rounded-md border px-4 py-3"
                     :class="totals.change_sign < 0 ? 'border-red-200 bg-red-50' : (totals.change_sign > 0 ? 'border-sky-200 bg-sky-50' : 'border-emerald-200 bg-emerald-50')">
                    <p class="text-xs font-semibold" :class="totals.change_sign < 0 ? 'text-red-800' : (totals.change_sign > 0 ? 'text-sky-900' : 'text-emerald-800')">If approved, stock value</p>
                    <p class="mt-1 font-mono text-2xl font-semibold tabular-nums" :class="totals.change_sign < 0 ? 'text-red-700' : (totals.change_sign > 0 ? 'text-sky-800' : 'text-emerald-800')"
                       x-text="totals.change_sign === 0 ? 'No change' : (totals.change_sign < 0 ? '−' : '+') + totals.change"></p>
                    <p class="text-xs" :class="totals.change_sign < 0 ? 'text-red-800' : (totals.change_sign > 0 ? 'text-sky-900' : 'text-emerald-800')"
                       x-text="totals.change_sign < 0 ? `Goes down ${totals.change_percent ?? 0}%: a loss to the business, off net profit.`
                            : (totals.change_sign > 0 ? `Goes up ${totals.change_percent ?? 0}%: added to stock and to net profit.` : 'The rack matches the records.')"></p>
                </div>

                {{-- 4. Missing from the rack. --}}
                <div class="rounded-md border border-red-200 bg-white px-4 py-3">
                    <p class="text-xs font-semibold text-red-800">Missing from the rack</p>
                    <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-red-700" x-text="totals.short_count ? '−' + totals.short : '0.00'"></p>
                    <p class="text-xs text-gray-600">
                        <span x-text="totals.short_count"></span> <span x-text="totals.short_count === 1 ? 'product' : 'products'"></span> fewer than recorded, at purchase price.
                        <span x-show="totals.short_count">Would have sold for <span class="font-mono" x-text="totals.short_mrp"></span>.</span>
                    </p>
                </div>

                {{-- 5. Extra on the rack. --}}
                <div class="rounded-md border border-sky-200 bg-white px-4 py-3">
                    <p class="text-xs font-semibold text-sky-900">Extra on the rack</p>
                    <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-sky-800" x-text="totals.extra_count ? '+' + totals.extra : '0.00'"></p>
                    <p class="text-xs text-gray-600">
                        <span x-text="totals.extra_count"></span> <span x-text="totals.extra_count === 1 ? 'product' : 'products'"></span> more than recorded, at purchase price.
                        <span x-show="totals.extra_count">Sells for <span class="font-mono" x-text="totals.extra_mrp"></span>.</span>
                    </p>
                </div>
            </div>

            {{-- The same bar as every list, answered in the page: the sheet is all here. --}}
            <div class="mb-3 flex flex-wrap items-end gap-3 rounded-md border border-line bg-white px-4 py-3">
                <div class="min-w-[11rem] flex-1">
                    <x-input-label for="find" value="Product" />
                    <input id="find" type="search" x-model="find" placeholder="Search the sheet" x-ref="find"
                           class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                </div>
                <button type="button" x-show="find !== ''" x-cloak x-on:click="find = ''"
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-900 bg-white px-4 py-2 text-sm font-semibold text-gray-900 hover:bg-gray-100">
                    <span aria-hidden="true">&times;</span> Clear
                </button>
                {{-- On the same line: a product on the rack that the sheet does not list. --}}
                <button type="button" x-on:click="adding ? (adding = false) : openAdd()" :aria-expanded="adding"
                        class="ml-auto inline-flex items-center gap-1.5 rounded-md border px-4 py-2 text-sm font-semibold"
                        :class="adding ? 'border-emerald-700 bg-emerald-50 text-emerald-800' : 'border-dashed border-gray-400 bg-white text-gray-700 hover:border-emerald-600 hover:text-emerald-800'">
                    + Product found on the rack
                </button>
            </div>
            {{-- A product on the rack that the sheet does not list: the records
                 put it at nothing, so it was left off. Found here, it goes on
                 the sheet and is counted like the rest. --}}
            <div class="mb-3" x-on:keydown.escape.stop="adding = false">
                <div x-show="adding" x-cloak class="rounded-md border border-emerald-300 bg-white p-3 shadow-sm">
                    <div class="flex flex-wrap items-center gap-3">
                        <label for="add-find" class="text-sm font-medium text-gray-700">Product found on the rack</label>
                        <input id="add-find" type="search" x-ref="addFind" x-model="addQuery" autocomplete="off"
                               placeholder="Name or code of a {{ $audit->company->name }} product"
                               x-on:keydown.arrow-down.prevent="addCursor = Math.min(addCursor + 1, addMatches().length - 1)"
                               x-on:keydown.arrow-up.prevent="addCursor = Math.max(addCursor - 1, 0)"
                               x-on:keydown.enter.prevent="addMatches()[addCursor] && addProduct(addMatches()[addCursor].id)"
                               class="min-w-[16rem] flex-1 rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                        {{-- Not in the catalogue at all: made here, and it lands on this sheet. --}}
                        <button type="button" title="Not in the catalogue? It goes onto this sheet once it is added."
                                x-on:click="$dispatch('add-product', { company_id: companyId, name: addQuery.trim() })"
                                class="rounded-md border border-emerald-700 bg-white px-3 py-1.5 text-sm font-semibold text-emerald-800 hover:bg-emerald-50">+ Add a new product</button>
                        <button type="button" x-on:click="adding = false" class="text-sm text-gray-500 hover:text-gray-800">Close</button>
                    </div>
                    <ul class="mt-2 max-h-64 divide-y divide-gray-100 overflow-y-auto rounded-md border border-gray-100" x-show="addMatches().length">
                        <template x-for="(p, k) in addMatches()" :key="p.id">
                            <li>
                                <button type="button" x-on:click="addProduct(p.id)" x-on:mouseenter="addCursor = k"
                                        class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm"
                                        :class="k === addCursor ? 'bg-emerald-50 text-emerald-900' : 'text-gray-800 hover:bg-gray-50'">
                                    <span>
                                        <span class="font-medium" x-text="p.name"></span>
                                        <span class="ml-1 font-mono text-xs text-gray-500" x-show="p.code" x-text="p.code"></span>
                                        <span class="ml-1 text-xs text-gray-400" x-show="! p.active">inactive</span>
                                    </span>
                                    <span class="text-xs text-gray-500">0 in records · add and count</span>
                                </button>
                            </li>
                        </template>
                    </ul>
                    <p class="mt-2 text-sm text-gray-500" x-show="addQuery.trim() !== '' && addMatches().length === 0">
                        No {{ $audit->company->name }} product matches “<span x-text="addQuery"></span>”.
                    </p>
                    <p class="mt-1 text-xs text-red-700" x-show="addError" x-text="addError"></p>
                </div>
            </div>

            <p class="mb-3 px-1 text-sm text-gray-500">
                <span x-text="shown().length"></span> of <span x-text="rows.length"></span> products
            </p>

            {{-- Its own keys (onKey above), so the page-wide ones stay off it. --}}
            <x-panel data-no-list-keys>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                @foreach ([['#', 'left'], ['Product', 'left'], ['Batch · expiry', 'left'], ['In records', 'right'], ['On the rack', 'right'], ['Difference', 'right'], ['At cost', 'right'], ['', 'right']] as [$h, $align])
                                    <th class="whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 text-{{ $align }} {{ $loop->first ? 'sm:pl-6' : '' }} {{ $loop->last ? 'sm:pr-6' : '' }}">{{ $h }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <template x-for="(row, i) in shown()" :key="row.id">
                            <tbody class="border-b border-gray-100"
                                   :class="row.counted ? (row.diff < 0 ? 'bg-red-50/40' : (row.diff > 0 ? 'bg-sky-50/40' : '')) : ''">
                                <tr class="align-top" :data-row="row.id" x-on:click="cursor = i"
                                    :class="i === cursor ? 'bg-emerald-100' : ''">
                                    <td class="border-l-4 px-3 py-2.5 text-gray-500 sm:pl-6" x-text="rows.indexOf(row) + 1"
                                        :class="i === cursor ? 'border-emerald-700 font-semibold text-emerald-800' : 'border-transparent'"></td>
                                    <td class="min-w-[15rem] px-3 py-2.5">
                                        <span class="font-medium text-gray-900" x-text="row.name"></span>
                                        <span class="block text-xs text-gray-500">
                                            <span x-show="! noPack(row.pack_raw)" x-text="row.pack"></span>
                                            <span x-show="noPack(row.pack_raw)" class="font-semibold text-amber-700" title="No pack size: enter it in Different">Pack 0</span>
                                            · <span class="text-amber-700">Purchase <span class="font-mono font-semibold" x-text="row.rate"></span></span>
                                            · <span class="text-emerald-700">Sale (MRP) <span class="font-mono font-semibold" x-text="row.mrp"></span></span>
                                        </span>
                                        <span class="block text-xs text-gray-400" x-show="row.sold" x-text="`Sold ${row.sold} since last audit` + (row.last_sold ? ` · last ${row.last_sold}` : '')"></span>
                                        <span class="block text-xs text-gray-500" x-show="row.note" x-text="`Note: ${row.note}`"></span>
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <template x-for="b in row.batches">
                                            <span class="block whitespace-nowrap text-xs"
                                                  :class="b.state === 'expired' ? 'text-red-700' : (b.state === 'expiring' ? 'text-amber-700' : 'text-gray-700')">
                                                <span class="font-mono" x-text="b.batch_no || (b.month ? 'No batch no.' : 'Undated')"></span>
                                                <span x-show="b.month" x-text="` · ${b.month}`"></span>
                                                <span class="text-gray-500" x-text="` · ${say(row, b.packs, b.loose)}`"></span>
                                            </span>
                                        </template>
                                        <span x-show="row.batches.length === 0" class="text-xs text-gray-400">None in records</span>
                                    </td>
                                    {{-- Packs first, loose underneath. --}}
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono tabular-nums text-gray-900">
                                        <span class="block" x-text="(row.counted ? row.expected_split : row.now_split).packs"></span>
                                        <span class="block text-xs text-gray-500" x-show="(row.counted ? row.expected_split : row.now_split).loose"
                                              x-text="(row.counted ? row.expected_split : row.now_split).loose"></span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono tabular-nums">
                                        <template x-if="row.counted">
                                            <span>
                                                <span class="block text-gray-900" x-text="row.found_split.packs"></span>
                                                <span class="block text-xs text-gray-500" x-show="row.found_split.loose" x-text="row.found_split.loose"></span>
                                            </span>
                                        </template>
                                        <template x-if="! row.counted"><span class="text-gray-400">—</span></template>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right">
                                        <template x-if="! row.counted"><span class="text-xs text-gray-400">Not counted</span></template>
                                        <template x-if="row.counted && row.diff === 0">
                                            <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-800">Matches</span>
                                        </template>
                                        <template x-if="row.counted && row.diff !== 0">
                                            <span class="font-mono font-semibold" :class="row.diff < 0 ? 'text-red-700' : 'text-sky-800'" x-text="row.diff_label"></span>
                                        </template>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono font-semibold tabular-nums"
                                        :class="row.diff < 0 ? 'text-red-700' : 'text-sky-800'" x-text="row.diff_value || ''"></td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right sm:pr-6">
                                        <span class="inline-flex items-center gap-2" x-show="! row.editing && ! row.confirmDelete">
                                            <button type="button" x-show="! row.counted" x-on:click="matches(row)" :disabled="row.busy"
                                                    class="rounded-md bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">Matches</button>
                                            <button type="button" x-on:click="edit(row)" :disabled="row.busy"
                                                    class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                                    x-text="row.counted ? 'Change' : 'Different'"></button>
                                            {{-- None of it on the rack: counted as nothing, every batch. --}}
                                            <button type="button" x-show="(hasStock(row) && ! (row.counted && row.diff === 0)) || emptyLine(row)" x-on:click="rows.forEach(r => r.confirmDelete = false); row.confirmDelete = true" :disabled="row.busy"
                                                    class="rounded-md border border-red-300 bg-white px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">Delete</button>
                                        </span>
                                        <span class="inline-flex items-center gap-2 whitespace-normal text-left" x-show="row.confirmDelete" x-cloak>
                                            <template x-if="! emptyLine(row)">
                                                <span class="inline-flex items-center gap-2">
                                                    <span class="text-xs text-gray-700">Delete <span class="font-semibold" x-text="row.now"></span>? Removed from stock when the audit is approved.</span>
                                                    <button type="button" x-on:click="row.confirmDelete = false; save(row, { batches: [], note: 'Not on the rack' })" :disabled="row.busy"
                                                            class="rounded-md bg-red-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-red-800 disabled:opacity-50">Delete stock</button>
                                                </span>
                                            </template>
                                            {{-- Nothing in the records and nothing counted: off the sheet, nothing to book. --}}
                                            <template x-if="emptyLine(row)">
                                                <span class="inline-flex items-center gap-2">
                                                    <span class="text-xs text-gray-700">No stock in records. Take it off this sheet?</span>
                                                    <button type="button" x-on:click="row.confirmDelete = false; removeLine(row)" :disabled="row.busy"
                                                            class="rounded-md bg-red-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-red-800 disabled:opacity-50">Remove</button>
                                                </span>
                                            </template>
                                            <button type="button" x-on:click="row.confirmDelete = false" class="text-sm text-gray-500 hover:text-gray-800">Cancel</button>
                                        </span>
                                        <p x-show="row.error && ! row.editing" x-text="row.error" class="mt-1 max-w-[16rem] whitespace-normal text-left text-xs text-red-700"></p>
                                    </td>
                                </tr>

                                {{-- The rack, typed in: by batch, as Add stock checks a delivery in. --}}
                                <tr x-show="row.editing" x-cloak>
                                    <td></td>
                                    <td colspan="7" class="px-3 pb-3 sm:pr-6">
                                        <div class="rounded-md border border-gray-200 bg-white p-3" :data-editor="row.id"
                                             x-on:keydown.enter="stepOn($event, row)"
                                             x-on:keydown.escape.stop="row.editing = false; $nextTick(() => $el.closest('tbody')?.querySelector('[data-row]')?.scrollIntoView({ block: 'nearest' }))">
                                            {{-- The pack as the carton shows it, as on Add stock. "Nos" is
                                                 no pack size at all: shown as 0, to be filled in. --}}
                                            <div class="mb-3 flex flex-wrap items-end gap-3 border-b border-gray-100 pb-3">
                                                {{-- The product as named on the pack, as on Add product. --}}
                                                <label class="block min-w-[14rem] flex-1">
                                                    <span class="block text-[11px] uppercase tracking-wide text-gray-500">Name</span>
                                                    <input type="text" x-model="row.draftName" data-step maxlength="160" :disabled="! canEditProducts"
                                                           class="mt-0.5 w-full rounded-md border-gray-300 py-1 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600 disabled:bg-gray-50 disabled:text-gray-500">
                                                </label>
                                                <label class="block">
                                                    <span class="block text-[11px] uppercase tracking-wide text-gray-500">Strength</span>
                                                    <input type="text" x-model="row.draftStrength" data-step maxlength="60" placeholder="500mg" :disabled="! canEditProducts"
                                                           class="mt-0.5 w-24 rounded-md border-gray-300 py-1 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600 disabled:bg-gray-50 disabled:text-gray-500">
                                                </label>
                                                <label class="block">
                                                    <span class="block text-[11px] uppercase tracking-wide text-gray-500">Type</span>
                                                    <select x-model="row.draftForm" data-step :disabled="! canEditProducts"
                                                            class="mt-0.5 w-40 rounded-md border-gray-300 py-1 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                        @foreach ($forms as $form)
                                                            <option value="{{ $form }}">{{ $form }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                                <label class="block">
                                                    <span class="block text-[11px] uppercase tracking-wide text-gray-500">Pack size</span>
                                                    <input type="text" x-model="row.draftPack" data-step maxlength="60" placeholder="e.g. 10x10, 28's" :disabled="! canEditProducts"
                                                           class="mt-0.5 w-32 rounded-md py-1 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600 disabled:bg-gray-50 disabled:text-gray-500"
                                                           :class="noPack(row.draftPack) ? 'border-amber-400 bg-amber-50' : 'border-gray-300'">
                                                </label>
                                                <span class="pb-1.5 text-xs font-semibold text-amber-700" x-show="noPack(row.draftPack) && ! row.error">Enter pack size</span>
                                                {{-- Why it did not save, beside what to fix. --}}
                                                <span class="pb-1.5 text-sm font-semibold text-red-700" x-show="row.error" x-text="row.error" role="alert"></span>
                                                <p class="pb-1.5 text-xs text-gray-500">
                                                    <span x-show="canEditProducts">Each batch is valued at its own purchase rate. A batch found on the rack sets the product's prices from now on.</span>
                                                    <span x-show="! canEditProducts">Only someone who may edit products can change the pack size and prices.</span>
                                                </p>
                                            </div>
                                            <table class="min-w-full text-sm">
                                                <thead>
                                                    <tr class="text-left text-[11px] uppercase tracking-wide text-gray-500">
                                                        <th class="py-1 pr-3 font-semibold" x-show="byBatch">Batch</th>
                                                        <th class="py-1 pr-3 font-semibold" x-show="byBatch">Expiry</th>
                                                        <th class="py-1 pr-3 text-right font-semibold text-amber-700">Purchase</th>
                                                        <th class="py-1 pr-3 text-right font-semibold text-emerald-700">Sale (MRP)</th>
                                                        <th class="py-1 pr-3 text-right font-semibold">In records</th>
                                                        <th class="py-1 pr-3 text-right font-semibold">Packs</th>
                                                        <th class="py-1 pr-3 text-right font-semibold" x-show="perOf(row) > 1">Loose</th>
                                                        <th class="py-1 pr-3 text-right font-semibold">Loss / gain at cost</th>
                                                        <th></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <template x-for="(line, j) in row.lines" :key="line.key">
                                                        <tr>
                                                            <td class="py-1 pr-3" x-show="byBatch">
                                                                <template x-if="! line.isNew"><span class="font-mono text-gray-900" x-text="line.batch_no || (line.expires_on ? 'No batch no.' : 'Undated stock (no batch in records)')"></span></template>
                                                                <template x-if="line.isNew">
                                                                    <input type="text" x-model="line.batch_no" data-step maxlength="60" placeholder="Batch no." :data-batch-for="row.id"
                                                                           class="w-32 rounded-md border-gray-300 py-1 font-mono text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                                </template>
                                                            </td>
                                                            <td class="py-1 pr-3" x-show="byBatch">
                                                                <template x-if="! line.isNew"><span class="text-gray-700" x-text="line.month || '—'"></span></template>
                                                                <template x-if="line.isNew">
                                                                    <input type="month" x-model="line.expires_on" data-step class="rounded-md border-gray-300 py-1 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                                </template>
                                                            </td>
                                                            <td class="py-1 pr-3 text-right">
                                                                <input type="text" inputmode="decimal" x-model="line.rate" data-step :disabled="! canEditProducts" aria-label="Purchase rate of this batch"
                                                                       class="w-24 rounded-md border-amber-300 bg-amber-50 py-1 text-right font-mono text-sm text-amber-900 shadow-sm focus:border-amber-500 focus:ring-amber-500 disabled:opacity-70">
                                                            </td>
                                                            <td class="py-1 pr-3 text-right">
                                                                <input type="text" inputmode="decimal" x-model="line.mrp" data-step :disabled="! canEditProducts" aria-label="Sale price (MRP) of this batch"
                                                                       class="w-24 rounded-md border-emerald-300 bg-emerald-50 py-1 text-right font-mono text-sm text-emerald-900 shadow-sm focus:border-emerald-600 focus:ring-emerald-600 disabled:opacity-70">
                                                            </td>
                                                            <td class="py-1 pr-3 text-right font-mono text-gray-600" x-text="line.isNew ? 'not in records' : say(row, line.heldPacks, line.heldLoose)"></td>
                                                            <td class="py-1 pr-3 text-right">
                                                                <input type="number" min="0" step="any" x-model="line.packs" data-step :aria-label="`Packs on the rack`" :title="perOf(row) > 1 ? `Part of a pack is fine: 0.5 is ${Math.round(perOf(row) / 2)} loose` : 'Whole packs'"
                                                                       class="w-20 rounded-md border-gray-300 py-1 text-right font-mono shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                            </td>
                                                            <td class="py-1 pr-3 text-right" x-show="perOf(row) > 1">
                                                                <input type="number" min="0" :max="perOf(row) - 1" x-model.number="line.loose" data-step :title="`Loose in an open pack. ${perOf(row)} make one pack.`"
                                                                       class="w-16 rounded-md border-gray-300 py-1 text-right font-mono shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                            </td>
                                                            <td class="whitespace-nowrap py-1 pr-3 text-right font-mono text-sm font-semibold"
                                                                :class="lineDiff(row, line) < 0 ? 'text-red-700' : (lineDiff(row, line) > 0 ? 'text-sky-800' : 'text-gray-400')"
                                                                x-text="lineDiff(row, line) === 0 ? '—' : (lineDiff(row, line) < 0 ? '−' : '+') + money(Math.abs(lineDiff(row, line)) * price(line.rate) / perOf(row))"></td>
                                                            <td class="py-1 text-right">
                                                                <button type="button" x-show="line.isNew || line.undated" x-on:click="row.lines.splice(j, 1)" class="text-gray-400 hover:text-red-700"
                                                                        :aria-label="line.undated ? 'None of the undated stock is on the rack' : 'Remove this batch'"
                                                                        :title="line.undated ? 'None of the undated stock is on the rack' : 'Remove this batch'">&times;</button>
                                                            </td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                            <div class="mt-3 flex flex-wrap items-center gap-3">
                                                <button type="button" x-show="byBatch" x-on:click="addLine(row)"
                                                        class="rounded-md border border-dashed border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:border-emerald-600 hover:text-emerald-800">
                                                    + Batch found on the rack
                                                </button>
                                                <input type="text" x-model="row.draftNote" maxlength="255" placeholder="Note (optional), e.g. two packs damaged"
                                                       class="min-w-[14rem] flex-1 rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                <span class="text-sm"
                                                      :class="draftDiff(row) < 0 ? 'text-red-700' : (draftDiff(row) > 0 ? 'text-sky-800' : 'text-emerald-700')">
                                                    <span class="font-mono font-semibold" x-text="draftDiff(row) === 0 ? 'Matches the records' : (draftDiff(row) < 0 ? '−' : '+') + sayPieces(row, Math.abs(draftDiff(row)))"></span>
                                                    <template x-if="draftDiff(row) !== 0">
                                                        <span>
                                                            · <span class="font-semibold" x-text="draftValue(row, 'rate') < 0 ? 'Loss' : 'Gain'"></span>
                                                            <span class="font-mono font-semibold" x-text="money(Math.abs(draftValue(row, 'rate')))"></span> at cost
                                                            · <span class="font-mono" x-text="money(Math.abs(draftValue(row, 'mrp')))"></span> at MRP
                                                        </span>
                                                    </template>
                                                </span>
                                                <span class="text-xs text-red-700" x-show="partOfPackRefused(row)">Not sold loose: count whole packs, or set the pack size.</span>
                                                <button type="button" x-on:click="row.editing = false" class="text-sm text-gray-500 hover:text-gray-800">Cancel</button>
                                                <button type="button" x-on:click="saveLines(row)" :disabled="row.busy" data-save
                                                        class="rounded-md bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">Save</button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </template>
                        <tbody x-show="shown().length === 0">
                            <tr><td colspan="8" class="px-6 py-10 text-center text-gray-500"
                                    x-text="find !== '' ? 'Nothing on the sheet matches.'
                                        : (show === 'todo' ? 'Every product has been counted. Finish the audit to see the report.'
                                        : (show === 'diff' ? 'No differences so far.' : 'Nothing counted yet.'))"></td></tr>
                        </tbody>
                    </table>
                </div>
            </x-panel>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            /* How many pieces a printed pack size means. The same rule as
               App\Support\PackSize and Add stock; keep them in step. */
            function piecesFromPack(text) {
                const t = String(text || '').trim().toLowerCase();
                let m = t.match(/^(\d+)\s*[\/\\x×*]\s*(\d+)/);
                if (m) return Math.max(1, Number(m[1]) * Number(m[2]));
                m = t.match(/^(\d+)\s*('s|s\b|tab|cap|pcs|pieces?)/);
                if (m) return Math.max(1, Number(m[1]));
                return 1;
            }

            function auditSheet({ rows, totals, byBatch, canEditProducts, sellsLoose, forms, catalogue, addUrl, companyId }) {
                return {
                    forms,
                    // The company's products not on the sheet, for one found on the rack.
                    catalogue,
                    addUrl,
                    companyId,
                    adding: false,
                    addQuery: '',
                    addCursor: 0,
                    addError: null,
                    openAdd() {
                        this.adding = true;
                        this.addQuery = '';
                        this.addError = null;
                        this.addCursor = 0;
                        this.$nextTick(() => this.$refs.addFind.focus());
                    },
                    addMatches() {
                        const q = this.addQuery.trim().toLowerCase();
                        if (q === '') return [];
                        return this.catalogue.filter(p => p.name.toLowerCase().includes(q) || String(p.code || '').toLowerCase().includes(q)).slice(0, 20);
                    },
                    /* Onto the sheet, then straight to counting it: the records
                       hold none, so its window opens on a new batch to type. */
                    async addProduct(id) {
                        this.addError = null;
                        try {
                            const res = await fetch(this.addUrl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                                           'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                body: JSON.stringify({ company_product_id: id }),
                            });
                            const data = await res.json();
                            if (! res.ok) {
                                this.addError = Object.values(data.errors || {}).flat()[0] || data.message || 'The product could not be added.';
                                return;
                            }
                            this.catalogue = this.catalogue.filter(p => p.id !== id);
                            if (! this.rows.some(r => r.id === data.row.id)) {
                                // At the top, where it was asked for, not at the end of a long list.
                                this.rows.unshift({ ...data.row, editing: false, confirmDelete: false, busy: false, error: null, lines: [],
                                                    draftNote: '', draftPack: '', draftName: '', draftStrength: '', draftForm: '' });
                            }
                            // The line as the page holds it — the watched copy — so
                            // opening its window shows; a plain copy would open unseen.
                            const row = this.rows.find(r => r.id === data.row.id);
                            this.totals = data.totals;
                            this.adding = false;
                            this.find = '';
                            this.show = 'todo';
                            this.$nextTick(() => {
                                this.edit(row);
                                document.querySelector(`[data-row="${row.id}"]`)?.scrollIntoView({ block: 'nearest' });
                            });
                        } catch (e) {
                            this.addError = 'The product could not be added. Check the connection and try again.';
                        }
                    },
                    rows: rows.map(r => ({ ...r, editing: false, confirmDelete: false, busy: false, error: null, lines: [], draftNote: '', draftPack: '', draftName: '', draftStrength: '', draftForm: '' })),
                    totals,
                    byBatch,
                    canEditProducts,
                    sellsLoose,
                    find: '',
                    // What is still to be counted, first; counted lines move to Done.
                    show: 'todo',
                    next: 0,
                    // The line the arrow keys are on, as a place in what is shown.
                    cursor: 0,
                    init() {
                        // A new filter starts at the top of what it shows.
                        this.$watch('find', () => this.cursor = 0);
                        this.$watch('show', () => this.cursor = 0);
                        // Notices as toasts: on opening, and whenever one becomes true.
                        this.$nextTick(() => this.notices(null));
                        this.$watch('totals', (now, before) => this.notices(before));
                    },
                    /* "All counted" and "check the prices", each said once when it
                       becomes true — not again on every save while it stays so. */
                    notices(before) {
                        const done = t => t && t.products > 0 && t.counted === t.products;
                        if (done(this.totals) && ! done(before)) {
                            this.$dispatch('toast', { kind: 'ok', title: 'Everything is counted.',
                                message: 'Press Finish and see report at the top. The owner then approves it, and only then does the stock change.' });
                        }
                        if (this.totals.cost_above_mrp && ! before?.cost_above_mrp) {
                            this.$dispatch('toast', { kind: 'warn', title: 'Check the prices before finishing.',
                                message: 'Some of these goods are worth more at purchase price than they sell for, which usually means a purchase rate was typed wrong. Open the Differences tab and check their purchase rate and MRP.' });
                        }
                    },
                    /* ↑ ↓ move along the list, Enter counts the line. Not while
                       typing in a field, and not while a line is open. */
                    onKey(e) {
                        if (e.target.closest('input, textarea, select, [contenteditable]')) return;
                        if (this.rows.some(r => r.editing || r.confirmDelete)) return;
                        const list = this.shown();
                        if (list.length === 0) return;
                        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                            e.preventDefault();
                            this.moveTo(this.cursor + (e.key === 'ArrowDown' ? 1 : -1));
                        } else if (e.key === 'Enter' && ! e.target.closest('button, a')) {
                            const row = list[this.cursor];
                            if (row) { e.preventDefault(); this.edit(row); }
                        }
                    },
                    moveTo(i) {
                        const list = this.shown();
                        this.cursor = Math.max(0, Math.min(list.length - 1, i));
                        const row = list[this.cursor];
                        this.$nextTick(() => document.querySelector(`[data-row="${row?.id}"]`)?.scrollIntoView({ block: 'nearest' }));
                    },
                    inTab(r, tab) {
                        return tab === 'all'
                            || (tab === 'todo' && ! r.counted)
                            || (tab === 'done' && r.counted)
                            || (tab === 'diff' && r.counted && r.diff !== 0);
                    },
                    tabs() {
                        return [['todo', 'To count'], ['done', 'Done'], ['diff', 'Differences'], ['all', 'All']]
                            .map(([key, label]) => ({ key, label, count: this.rows.filter(r => this.inTab(r, key)).length }));
                    },
                    shown() {
                        const q = this.find.trim().toLowerCase();
                        // A line open for counting stays where it is until it is saved.
                        return this.rows.filter(r => (q === '' || r.name.toLowerCase().includes(q))
                            && (this.inTab(r, this.show) || r.editing || r.confirmDelete));
                    },
                    edit(row) {
                        // The line being counted is the one the keys are on.
                        const at = this.shown().indexOf(row);
                        if (at >= 0) this.cursor = at;
                        // One line open at a time: opening this one closes the other.
                        this.rows.forEach(r => { r.confirmDelete = false; if (r !== row) r.editing = false; });
                        row.draftNote = row.note || '';
                        // "Nos" is no pack size: shown as 0, to be filled in.
                        row.draftPack = this.noPack(row.pack_raw) ? '0' : row.pack_raw;
                        row.draftName = row.brand_raw;
                        row.draftStrength = row.strength_raw;
                        // A type written as the list writes it, in any capitals, is that
                        // type; one the list does not have ("SYUP") starts as Other.
                        row.draftForm = this.forms.find(f => f.toLowerCase() === String(row.form_raw).trim().toLowerCase()) ?? 'Other';
                        row.error = null;
                        row.editing = true;

                        if (! this.byBatch) {
                            // One figure for the product: start at the records.
                            row.lines = [{ key: this.next++, isNew: false, batch_no: null, expires_on: null, month: null,
                                           rate: row.rate_raw, mrp: row.mrp_raw,
                                           heldPacks: row.now_packs, heldLoose: row.now_loose, packs: row.now_packs, loose: row.now_loose, whole: true }];
                            this.$nextTick(() => this.focusFirst(row));
                            return;
                        }

                        // Start at what the records hold now, so only the wrong figure is typed.
                        row.lines = row.batches.map(b => ({ key: this.next++, isNew: false, undated: ! b.batch_no && ! b.expires_on,
                                                            batch_no: b.batch_no, expires_on: b.expires_on, month: b.month,
                                                            // Each batch at what it came in at; the MRP is the product's for now.
                                                            rate: b.unit_cost ?? row.rate_raw, mrp: row.mrp_raw,
                                                            heldPacks: b.packs, heldLoose: b.loose, packs: b.packs, loose: b.loose }));

                        /*
                         * Nothing dated on record — nothing at all, or only stock
                         * from before dates were kept: open a new batch to type,
                         * carrying the undated quantity across, so the batch and
                         * expiry on the carton are what goes on the books.
                         */
                        if (row.lines.every(l => l.undated)) {
                            const held = row.lines[0];
                            row.lines.forEach(l => { l.packs = 0; l.loose = 0; });
                            row.lines.push({ key: this.next++, isNew: true, batch_no: '', expires_on: '', month: null, rate: row.rate_raw, mrp: row.mrp_raw,
                                             heldPacks: 0, heldLoose: 0, packs: held ? held.heldPacks : 0, loose: held ? held.heldLoose : 0 });
                        }
                        this.$nextTick(() => this.focusFirst(row));
                    },
                    addLine(row) {
                        row.lines.push({ key: this.next++, isNew: true, batch_no: '', expires_on: '', month: null, rate: row.rate_raw, mrp: row.mrp_raw,
                                         heldPacks: 0, heldLoose: 0, packs: 0, loose: 0 });
                    },
                    /* Pieces in a pack: the pack size being typed, where it changes
                       it and packs are opened here; else the product's own. */
                    /* The window's fields in the order Enter walks them: pack size,
                       then each batch line left to right. Hidden and locked ones
                       are skipped. */
                    focusFirst(row) {
                        // The dropdowns show what the window was opened with.
                        document.querySelectorAll(`[data-editor="${row.id}"] select`).forEach(sel => sel._kebabSync?.());
                        const first = this.fields(row)[0];
                        first?.focus();
                        first?.select?.();
                    },
                    fields(row) {
                        const box = document.querySelector(`[data-editor="${row.id}"]`);
                        return box ? [...box.querySelectorAll('[data-step]')].filter(el => ! el.disabled && el.offsetParent !== null) : [];
                    },
                    /* Enter: on to the next field, and from the last one to Save.
                       On Save itself Enter is left alone, so it presses it. */
                    stepOn(event, row) {
                        if (event.target.matches('[data-save]')) return;
                        // A dropdown's own menu is open: Enter there picks the choice.
                        if (event.target.closest('[data-kebab-wrap]') && ! event.target.matches('[data-step]')) return;
                        if (event.target.matches('button:not([data-step])')) return;
                        event.preventDefault();
                        const list = this.fields(row);
                        const next = list[list.indexOf(event.target) + 1];
                        if (next) {
                            next.focus();
                            next.select?.();
                        } else {
                            document.querySelector(`[data-editor="${row.id}"] [data-save]`)?.focus();
                        }
                    },
                    // Nothing in the records and not yet counted: a line that can simply go.
                    emptyLine(row) {
                        return ! row.counted && row.now_packs === 0 && row.now_loose === 0;
                    },
                    async removeLine(row) {
                        row.busy = true;
                        row.error = null;
                        try {
                            const res = await fetch(row.remove_url, {
                                method: 'POST',
                                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                            });
                            const data = await res.json();
                            if (! res.ok) {
                                row.error = Object.values(data.errors || {}).flat()[0] || data.message || 'This line could not be removed.';
                                return;
                            }
                            this.rows.splice(this.rows.indexOf(row), 1);
                            this.totals = data.totals;
                            this.moveTo(this.cursor);
                        } catch (e) {
                            row.error = 'This line could not be removed. Check the connection and try again.';
                        } finally {
                            row.busy = false;
                        }
                    },
                    // Something to delete: stock in the records, or a count that found some.
                    hasStock(row) {
                        return row.now_packs > 0 || row.now_loose > 0 || (row.counted && row.found && ! /^0 packs?$/.test(row.found));
                    },
                    noPack(text) { return /^(0|nos\.?)?$/i.test(String(text ?? '').trim()); },
                    price(v) { return Number(String(v ?? '').replace(/,/g, '')) || 0; },
                    perOf(row) {
                        if (! row.editing || this.noPack(row.draftPack) || row.draftPack === (row.pack_raw || '')) return row.per;
                        const p = piecesFromPack(row.draftPack);
                        return this.sellsLoose && p > 1 ? p : row.per;
                    },
                    /* Packs (part of one allowed where packs are opened) and loose
                       pieces, as pieces: 0.7 of a pack of 28 is 20. */
                    pieces(row, packs, loose, per = null) {
                        per ??= this.perOf(row);
                        const p = Number(packs) || 0;
                        const whole = per > 1 ? Math.round(p * per) : Math.round(p);
                        return whole + (per > 1 ? (Number(loose) || 0) : 0);
                    },
                    partOfPackRefused(row) {
                        return this.perOf(row) < 2 && row.lines.some(l => Number(l.packs) % 1 !== 0);
                    },
                    // One batch line: found less held, in pieces.
                    lineDiff(row, l) {
                        return this.pieces(row, l.packs, l.loose) - (l.heldPacks * this.perOf(row) + (this.perOf(row) > 1 ? l.heldLoose : 0));
                    },
                    draftDiff(row) {
                        return row.lines.reduce((t, l) => t + this.lineDiff(row, l), 0);
                    },
                    /* What the difference is worth, each batch at its own price
                       ('rate' or 'mrp'), signed. Shown while typing; the figure
                       kept is worked out on the server. */
                    draftValue(row, which) {
                        return row.lines.reduce((t, l) => t + this.lineDiff(row, l) * this.price(l[which]) / this.perOf(row), 0);
                    },
                    money(n) {
                        return n.toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    },
                    say(row, packs, loose) {
                        const p = `${packs} ${Math.abs(packs) === 1 ? 'pack' : 'packs'}`;
                        if (! loose) return p;
                        const l = row.noun === 'piece' ? `${loose} loose` : `${loose} loose ${loose === 1 ? row.noun : row.noun + 's'}`;
                        return packs ? `${p} and ${l}` : l;
                    },
                    sayPieces(row, n) { const per = this.perOf(row); return this.say(row, Math.floor(n / per), n % per); },
                    /* Matches. A product with no pack size cannot be counted until it
                       has one, so instead of saving, its window opens at the pack
                       size with the reason; the rest of the line works as usual. */
                    matches(row) {
                        if (this.noPack(row.pack_raw)) {
                            this.edit(row);
                            row.error = 'Enter the pack size first. A pack size of 0 cannot be saved.';
                            return;
                        }
                        this.save(row, { agrees: true });
                    },
                    saveLines(row) {
                        const nothing = row.lines.every(l => (Number(l.packs) || 0) === 0 && (Number(l.loose) || 0) === 0);
                        if (this.noPack(row.draftPack) && ! nothing) {
                            row.error = 'Enter the pack size first. A pack size of 0 cannot be saved.';
                            this.$nextTick(() => this.focusFirst(row));
                            return;
                        }
                        if (this.partOfPackRefused(row)) {
                            row.error = 'This product is not sold loose. Count whole packs, or set its pack size.';
                            return;
                        }
                        const body = { note: row.draftNote.trim() || null };
                        const plain = v => String(v ?? '').replace(/,/g, '').trim() || null;
                        // The pack as found on the carton, for whoever may change it. 0 is no pack size.
                        if (this.canEditProducts && ! this.noPack(row.draftPack)) body.pack_size = row.draftPack.trim();
                        if (this.canEditProducts) {
                            if (row.draftName.trim() === '') {
                                row.error = 'Enter the product name.';
                                return;
                            }
                            if (row.draftName.trim() !== row.brand_raw) body.brand_name = row.draftName.trim();
                            if (row.draftStrength.trim() !== row.strength_raw) body.strength = row.draftStrength.trim();
                            if (row.draftForm && row.draftForm !== row.form_raw) body.dosage_form = row.draftForm;
                        }
                        // Quantities go as typed — 0.7 included — and are made
                        // into packs and pieces on the server.
                        if (! this.byBatch || row.lines.every(l => l.whole)) {
                            const l = row.lines[0];
                            // Counted as one figure: its prices are the product's.
                            if (this.canEditProducts) { body.purchase_rate = plain(l.rate); body.mrp = plain(l.mrp); }
                            return this.save(row, { ...body, packs: String(l.packs === '' ? 0 : l.packs), loose: Number(l.loose) || 0 });
                        }
                        this.save(row, { ...body, batches: row.lines.map(l => ({
                            batch_no: l.batch_no || null, expires_on: l.expires_on || null, is_new: !! l.isNew,
                            packs: String(l.packs === '' ? 0 : l.packs), loose: Number(l.loose) || 0,
                            ...(this.canEditProducts ? { unit_cost: plain(l.rate), mrp: plain(l.mrp) } : {}),
                        })) });
                    },
                    async save(row, body) {
                        row.busy = true;
                        row.error = null;
                        try {
                            const res = await fetch(row.url, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                                           'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                body: JSON.stringify(body),
                            });
                            const data = await res.json();
                            if (! res.ok) {
                                row.error = Object.values(data.errors || {}).flat()[0] || data.message || 'This line could not be saved.';
                                return;
                            }
                            Object.assign(row, data.row, { editing: false });
                            this.totals = data.totals;
                            // On to the next line, ready for ↓ or Enter. In To count the
                            // saved line has gone to Done, so the next is already here.
                            const at = this.shown().indexOf(row);
                            if (at >= 0 && at === this.cursor) this.moveTo(at + 1);
                            else if (at < 0) this.moveTo(this.cursor);
                            document.activeElement?.blur?.();
                        } catch (e) {
                            row.error = 'This line could not be saved. Check the connection and try again.';
                        } finally {
                            row.busy = false;
                        }
                    },
                };
            }
        </script>
    @endpush
</x-workspace-layout>
