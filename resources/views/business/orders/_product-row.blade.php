{{--
    One product, as it appears in the picker.

    A grid rather than a flex row: fixed track widths are what make the packs
    and the rates line up down the column instead of each row sizing itself to
    its own contents.

    Rendered by Blade into the list rather than built as an HTML string in
    JavaScript — the names come out of a supplier's PDF, and x-text escapes them
    where an x-html of a hand-built string would not.
--}}
<div class="grid grid-cols-[minmax(0,1fr)_4.5rem_6rem_2.5rem] items-start gap-2">
    <div class="min-w-0">
        {{-- Never truncated. A half-shown name is a product you cannot identify,
             and these run long: "AZI-ONCE DRY SUSPENSION 200mg". The row grows
             to fit instead. --}}
        <p class="break-words text-sm font-medium leading-snug text-gray-900" x-text="p.label"></p>
        <p class="break-words text-xs leading-snug text-gray-500" x-text="p.generic_name || ''"></p>
    </div>

    <div class="text-right text-xs leading-tight text-gray-500">
        <p x-text="p.pack_size || '—'"></p>
        <p class="text-gray-400" x-text="p.case_size ? 'case ' + p.case_size : ''"></p>
    </div>

    <div class="text-right leading-tight">
        <p class="font-mono text-sm tabular-nums text-gray-700" x-text="p.rate ? p.rate : '—'"></p>
        {{-- The rate the discount actually brings it to, called out rather than
             shown as a correction to the quoted one. --}}
        <template x-if="discountedRate(p)">
            <p class="font-mono text-xs font-semibold tabular-nums text-yellow-600" x-text="fmt(discountedRate(p))"></p>
        </template>
    </div>

    <div class="text-right">
        <template x-if="onOrder(p)">
            <span class="inline-block rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-medium text-emerald-800"
                  x-text="onOrder(p)"></span>
        </template>
        <template x-if="! onOrder(p)">
            <span class="text-gray-300">+</span>
        </template>
    </div>
</div>
