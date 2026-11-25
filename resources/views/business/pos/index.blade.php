{{--
    The counter.

    The page never scrolls: header, two columns, totals bar. The stock list and
    the bill each scroll inside their own column, so the totals and the Save
    button are always where the hand expects them.

    Everything is reachable from the keyboard — type or scan, Enter asks how
    many, Enter again adds it, F9 saves — because a hand on the mouse is a
    queue at the till.

    The whole catalogue is sent with the page: searching has to answer between
    keystrokes, and a round trip per letter cannot.
--}}
@php($rs = fn ($m) => $m->format())
@php($unit = $business->unit())
{{-- A pharmacy opens packs, so its stock is counted in packs and loose pieces
     and the bill's column is simply "Qty": packs on top, loose beneath. --}}
@php($loose = $business->sellsLoose())
@php($many = $loose ? 'packs' : $unit->many())
@php($one = $loose ? 'pack' : $unit->one())
{{--
    A distributor sells to pharmacies it invoices by name — there is no such
    thing as a walk-in, and a bill with no customer would leave credit with
    nobody to collect it from. A pharmacy's own counter is the opposite: most
    of its customers are strangers.
--}}
@php($walkIns = $business->business_type !== \App\Enums\BusinessType::Distributor)

<x-pos-layout :business="$business">
    {{-- Printing from the counter prints the slip in the window and nothing
         else — not the product list, not the bill being built behind it. --}}
    @push('head')
        <style>
            @media print {
                @page { size: {{ $format->pageSize() }}; margin: {{ $format === \App\Enums\ReceiptFormat::A4 ? '14mm' : '3mm' }}; }
                html, body { height: auto !important; overflow: visible !important; background: #fff !important; }
                /* The slip is moved to #print-sheet for the duration of the
                   print, so hiding is a matter of hiding body's other children
                   rather than every element on the page — which left blanks
                   behind, and repeated the fixed overlay on every sheet. */
                body > *:not(#print-sheet) { display: none !important; }
                #print-sheet { display: block !important; }
                #print-sheet .slip { width: 100% !important; max-width: none !important; margin: 0 !important; padding: 0 !important; box-shadow: none !important; }
            }
        </style>
    @endpush

    <div class="flex h-full flex-col" x-data="counter()"
         x-on:pharmacy-added.window="customerAdded($event.detail)"
         x-on:keydown.window.f2.prevent="focusSearch()" x-on:keydown.window.f9.prevent="save()">

        {{-- Who and where, the clock, the day so far, and what happens after a
             sale. The bill's own number sits on the bill, not up here. --}}
        @php($label = 'font-mono text-[11px] uppercase tracking-widest')
        <header class="flex shrink-0 flex-wrap items-center justify-between gap-x-6 gap-y-2 bg-slate-900 px-4 py-3 text-white sm:px-6">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-600 text-sm font-bold text-white">Rx</span>
                <div>
                    <p class="text-lg font-semibold leading-tight">{{ $business->name }}</p>
                    <p class="text-xs text-slate-400">POS · {{ auth()->user()->name }}</p>
                </div>
            </div>

            <div class="text-center">
                <p class="{{ $label }} text-slate-400">{{ $today->format('D d M Y') }}</p>
                <p class="font-mono text-xl font-semibold leading-tight tabular-nums" x-text="clock">&nbsp;</p>
            </div>

            {{-- The day's takings, covered until asked for.

                 A counter screen faces the shop, and the person being served
                 can read it as easily as the person serving. The number of
                 bills is no secret; the money is, so only the money is hidden
                 and it covers itself again shortly after. --}}
            <button type="button" class="text-center"
                    x-data="{
                        shown: false,
                        timer: null,
                        reveal() {
                            this.shown = ! this.shown;
                            clearTimeout(this.timer);

                            // Covered again on its own: a till left showing the
                            // day's takings is the thing this avoids.
                            if (this.shown) {
                                this.timer = setTimeout(() => { this.shown = false; }, 5000);
                            }
                        },
                    }"
                    x-on:click="reveal()"
                    :title="shown ? 'Hide today\'s sales total' : 'Show today\'s sales total'">
                <span class="{{ $label }} block text-slate-400">Sold today · {{ $soldToday->count() }} {{ Str::plural('bill', $soldToday->count()) }}</span>
                {{-- The mask is about the width of the figure, so the header
                     does not jump about as it is shown and hidden. --}}
                <span x-show="! shown" class="block font-mono text-xl font-semibold leading-tight tracking-widest text-slate-500">Rs. ••••••</span>
                <span x-show="shown" x-cloak class="block font-mono text-xl font-semibold leading-tight tabular-nums">Rs. {{ $rs(\App\Support\Money::sum($soldToday->pluck('total'))) }}</span>
            </button>

            {{-- What happens after a sale. All off by default: a till that
                 stops to show something after every bill slows the queue. --}}
            <div class="flex items-center gap-2">
                <button type="button" x-on:click="wantPrint = ! wantPrint"
                        :class="wantPrint ? 'bg-emerald-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-slate-200'"
                        class="flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold"
                        title="After saving, show the bill ready to print">
                    <span class="inline-block h-2 w-2 rounded-full" :class="wantPrint ? 'bg-white' : 'bg-slate-600'"></span>
                    Print
                </button>
                {{-- Which bill to show, not what to charge: the net bill's
                     own total is this bill's total, to the paisa. --}}
                @if (\App\Enums\ReceiptFormat::NET_BILL_AVAILABLE)
                <button type="button" x-on:click="wantNet = ! wantNet"
                        :class="wantNet ? 'bg-emerald-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-slate-200'"
                        class="flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold"
                        title="Show the bill before the {{ \App\Enums\ReceiptFormat::TRADE_DISCOUNT_PERCENT }}% trade discount. The amount payable is the same.">
                    <span class="inline-block h-2 w-2 rounded-full" :class="wantNet ? 'bg-white' : 'bg-slate-600'"></span>
                    Net {{ \App\Enums\ReceiptFormat::TRADE_DISCOUNT_PERCENT }}%
                </button>
                @endif
                <button type="button" x-on:click="wantBalance = ! wantBalance"
                        :class="wantBalance ? 'bg-emerald-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-slate-200'"
                        class="flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold"
                        title="After saving, show the customer's account">
                    <span class="inline-block h-2 w-2 rounded-full" :class="wantBalance ? 'bg-white' : 'bg-slate-600'"></span>
                    Balance
                </button>
            </div>

            <div class="flex items-center gap-3">
                {{-- Opens the list behind the figure in its own tab, so the
                     sale in progress stays where it is. --}}
                @if ($expiryMonths !== null && auth()->user()->can('viewProducts', $business))
                    <a href="{{ route('businesses.stock.expiring', $business) }}" target="_blank" rel="noopener"
                       x-show="nearExpiry > 0" x-cloak
                       title="View stock near expiry. Opens in a new tab."
                       class="rounded-lg border border-amber-500/60 px-4 py-1.5 hover:border-amber-400 hover:bg-amber-500/10">
                        <p class="{{ $label }} text-amber-400">Near expiry</p>
                        <p class="font-mono text-base font-semibold leading-tight tabular-nums text-amber-300"
                           x-text="nearExpiry + ' of ' + products.length"></p>
                    </a>
                @endif

                {{-- Today's bills, for when a customer comes back with one. --}}
                <button type="button" x-on:click="bills = true"
                        class="flex items-center gap-2 rounded-lg border border-slate-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">
                    Bill history
                    <span class="rounded bg-slate-700 px-1.5 py-0.5 font-mono text-xs">{{ $soldToday->count() }}</span>
                </button>

                {{-- A shop's own computer: internet, and the online copy. --}}
                @include('partials.sync-status', ['dark' => true])

                {{-- Which computer this is; every bill rung up here says so. --}}
                @include('partials.device-chip', ['dark' => true])

                <a href="{{ route('businesses.show', $business) }}"
                   class="rounded-lg border border-slate-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">
                    Exit POS
                </a>
            </div>
        </header>

        @if ($dayClosed)
            <div class="shrink-0 bg-red-600 px-4 py-2 text-center text-sm font-medium text-white sm:px-6">
                {{ $today->format('D d M Y') }} is closed. No sale can be recorded on this date. Reopen the day first.
            </div>
        @endif

        {{-- One till at a time. A browser will not let one tab bring another
             to the front, so the till moves instead: a tab that opens takes
             the bill in progress over from the tab already open, and that
             tab closes — or, where the browser will not let it close, stands
             down behind this notice. --}}
        <div x-show="moved" x-cloak class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-900/80 p-4">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 text-center shadow-xl">
                <p class="text-lg font-semibold text-slate-900">POS is now open in another tab</p>
                <p class="mt-2 text-sm text-slate-600">
                    POS was opened in another tab and the current bill was moved there.
                    This tab is no longer in use. You can close it.
                </p>
                <div class="mt-5 flex items-center justify-center gap-3">
                    <button type="button" x-on:click="window.close()"
                            class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Close this tab</button>
                    <button type="button" x-on:click="window.location.reload()"
                            class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Use POS here</button>
                </div>
            </div>
        </div>

        {{-- The other tab is doing something this one cannot simply take
             over — one is correcting a bill and the other is not — so it asks. --}}
        <div x-show="elsewhere" x-cloak class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-900/80 p-4">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 text-center shadow-xl">
                <p class="text-lg font-semibold text-slate-900">POS is already open in another tab</p>
                <p class="mt-2 text-sm text-slate-600">
                    <span x-text="elsewhereEditing ? 'A bill is being corrected in that tab.' : 'A sale is in progress in that tab' + (elsewhereLines > 0 ? ', with ' + elsewhereLines + (elsewhereLines === 1 ? ' item' : ' items') + ' on the bill.' : '.')"></span>
                    If you use POS here, it will close in that tab and any unsaved work there will be lost.
                </p>
                <div class="mt-5 flex items-center justify-center gap-3">
                    <button type="button" x-on:click="window.close()"
                            class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Close this tab</button>
                    <button type="button" x-on:click="takeOver()"
                            class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Use POS here</button>
                </div>
            </div>
        </div>

        {{-- A saved bill, back on the till to be put right. --}}
        @if ($editing)
            <div class="flex shrink-0 flex-wrap items-center justify-center gap-x-4 gap-y-1 bg-amber-500 px-4 py-2 text-sm font-medium text-white sm:px-6">
                <span>
                    Correcting bill {{ $editing->reference() }} of {{ $editing->business_date->format('D d M Y') }}.
                    Make the changes, enter the reason and save. The old version is kept in the bill history.
                </span>
                <a href="{{ route('businesses.pos.index', $business) }}"
                   class="rounded-md bg-white px-2.5 py-1 text-xs font-semibold text-amber-800 hover:bg-amber-50">Cancel changes</a>
            </div>
        @endif

        {{-- "Bill saved", or why it was refused, as a toast. --}}
        <x-flash any />

        {{-- The form carries the bill; the screen never posts anything else. --}}
        <form method="POST" x-ref="form" class="hidden"
              action="{{ $editing ? route('businesses.pos.bills.update', [$business, $editing]) : route('businesses.pos.store', $business) }}">
            @csrf
            @if ($editing)
                @method('PUT')
                <input type="hidden" name="from" value="pos">
                <input type="hidden" name="reason" :value="reason">
            @endif
            <template x-for="(line, i) in cart" :key="line.id">
                <span>
                    <input type="hidden" :name="`items[${i}][product_id]`" :value="line.id">
                    <input type="hidden" :name="`items[${i}][quantity]`" :value="line.qty">
                    <input type="hidden" :name="`items[${i}][loose]`" :value="line.loose || 0">
                    <input type="hidden" :name="`items[${i}][unit_price]`" :value="Number(line.price).toFixed(2)">
                </span>
            </template>
            <input type="hidden" name="received" :value="Number(received || 0).toFixed(2)">
            <input type="hidden" name="discount" :value="Number(discount || 0).toFixed(2)">
            <input type="hidden" name="customer_name" :value="customer">
            <input type="hidden" name="customer_phone" :value="customerPhone">
            <input type="hidden" name="via" :value="via">
            <input type="hidden" name="preview" :value="wantPrint ? 1 : 0">
            <input type="hidden" name="balance" :value="wantBalance ? 1 : 0">
            <input type="hidden" name="net" :value="wantNet ? 1 : 0">
        </form>

        {{-- Stock on the left, the bill on the right. Both scroll inside.
             Not halves: the bill carries columns of figures and the search
             carries a name and a price, so the room goes where it is needed.
             minmax(0,…) so a long medicine name cannot push the column wider. --}}
        {{-- A pharmacy sells at the MRP, so its bill has one price column, not
             the MRP and the same figure again beside it. A distributor keeps
             both: the MRP on the pack, and the trade price it charges. --}}
        @php($cols = $business->sellsAtMrp()
            ? 'grid-cols-[minmax(9rem,1fr)_10rem_6rem_7rem_7rem_2rem]'
            : 'grid-cols-[minmax(9rem,1fr)_10rem_6rem_6rem_7rem_7rem_2rem]')
        <div class="till-panes grid min-h-0 flex-1 grid-cols-1 gap-4 p-4 lg:grid-cols-[minmax(0,5fr)_minmax(0,6.5fr)] sm:px-6">

            {{-- The shelf. --}}
            <section class="flex min-h-0 flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                <div class="shrink-0 border-b border-slate-200 p-4">
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-500"
                             viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="9" cy="9" r="6" /><path d="M14 14l4 4" stroke-linecap="round" />
                        </svg>
                        <input x-ref="search" x-model="query" type="text" autocomplete="off" autofocus
                               x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)"
                               x-on:focus="$el.select()"
                               x-on:keydown.enter.prevent="askHighlighted()" x-on:keydown.escape="query = ''"
                               placeholder="Scan a barcode, or type a product name"
                               class="block w-full rounded-lg border-2 border-emerald-700 py-3 pl-12 pr-16 text-base placeholder:text-slate-400 focus:border-emerald-700 focus:ring-2 focus:ring-emerald-600/30">
                        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 rounded border border-slate-300 px-1.5 py-0.5 font-mono text-xs text-slate-500">F2</span>
                    </div>
                    <p class="mt-3 flex items-center justify-between text-sm text-slate-600">
                        <span>
                            <span x-text="shown.length"></span> of {{ $products->count() }} products
                            <span x-show="inStockOnly" x-cloak>· in stock</span>
                        </span>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" x-model="inStockOnly" class="h-4 w-4 rounded border-slate-400 text-emerald-700 focus:ring-emerald-600">
                            In stock only
                        </label>
                    </p>
                    {{-- What the two ways of picking a product do. --}}
                    <p class="till-hint mt-2 text-xs text-slate-500">
                        <span class="font-semibold text-slate-700">+</span> adds one full pack.
                        Click the product name to sell loose pieces, a different quantity or a different price.
                    </p>
                </div>

                {{-- The whole list, not only what was searched for. --}}
                <div class="till-rows min-h-0 flex-1 overflow-y-auto" x-ref="list">
                    <template x-for="(p, i) in shown" :key="p.id">
                        {{-- Short-dated goods are coloured so they can be taken off
                             the shelf rather than sold by accident: amber while the
                             clock is running, red once it has run out. --}}
                        <div x-on:mouseenter="highlight = i"
                             :class="{
                                 'bg-emerald-50': highlight === i && ! p.expiry_state,
                                 'bg-amber-50 ring-1 ring-inset ring-amber-200': p.expiry_state === 'expiring',
                                 'bg-red-50 ring-1 ring-inset ring-red-200': p.expiry_state === 'expired',
                             }"
                             class="grid w-full grid-cols-[minmax(0,1fr)_auto_7.5rem_6.5rem_2.75rem] items-center gap-3 border-b border-slate-200 px-4 py-2.5 last:border-0">
                            {{-- The row asks how many; the + puts one on the bill and
                                 moves on, which is most of a counter's work. --}}
                            <button type="button" x-on:click="ask(p)" class="min-w-0 text-left">
                                <span class="block break-words text-base font-medium leading-snug"
                                      :class="piecesHeld(p) > 0 ? 'text-slate-900' : 'text-slate-600'">
                                    <span x-text="p.form ? p.short : p.name"></span>
                                    {{-- Tablet, syrup, injection: told apart at a glance. --}}
                                    <span x-show="p.form" x-text="p.form"
                                          class="ml-1 inline-block rounded-md bg-slate-100 px-1.5 py-0.5 align-middle text-[11px] font-semibold uppercase tracking-wide text-slate-600 ring-1 ring-inset ring-slate-200"></span>
                                </span>
                                <span x-show="p.pack" class="block truncate text-xs text-slate-500" x-text="'Pack ' + p.pack"></span>
                                <template x-if="p.expiry_state">
                                    <span class="mt-0.5 inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-semibold"
                                          :class="p.expiry_state === 'expired' ? 'bg-red-600 text-white' : 'bg-amber-500 text-white'"
                                          x-text="(p.expiry_state === 'expired' ? 'EXPIRED ' : 'Expires ') + p.expires_label"></span>
                                </template>
                            </button>

                            <span class="{{ $label }} font-semibold text-emerald-700" x-show="onBill(p.id)" x-cloak>On bill</span>
                            <span x-show="! onBill(p.id)"></span>

                            <button type="button" x-on:click="ask(p)" class="text-right font-mono text-sm font-semibold tabular-nums"
                                    :class="piecesHeld(p) > 0 ? 'text-slate-900' : 'text-slate-600'"
                                    x-text="'Rs. ' + money(p.price)"></button>

                            <button type="button" x-on:click="ask(p)" class="text-right">
                                <span x-show="piecesHeld(p) > 0" class="font-mono text-sm tabular-nums text-slate-600" x-text="stockLabel(p)"></span>
                                <span x-show="piecesHeld(p) <= 0" x-cloak
                                      class="{{ $label }} inline-block rounded bg-amber-100 px-1.5 py-0.5 text-center font-semibold leading-tight text-amber-800">Out of stock</span>
                            </button>

                            {{-- Outlined, not filled, when the books hold none of it:
                                 the button still works, but it should not look routine. --}}
                            <button type="button" x-on:click.stop="addOne(p)"
                                    class="flex h-11 w-11 items-center justify-center justify-self-end rounded-lg text-xl font-semibold leading-none"
                                    :class="piecesHeld(p) > 0
                                        ? 'bg-emerald-700 text-white hover:bg-emerald-800'
                                        : 'border border-amber-600 bg-white text-amber-700 hover:bg-amber-50'"
                                    :title="piecesHeld(p) > 0
                                        ? 'Add one full pack of ' + p.name + '. Click the name for loose pieces or a different price.'
                                        : 'No stock recorded. This item cannot be added to the bill.'">+</button>
                        </div>
                    </template>

                    <p x-show="shown.length === 0" x-cloak class="px-4 py-10 text-center text-sm text-slate-500">
                        No products found.
                    </p>
                    <p x-show="capped" x-cloak class="border-t border-slate-200 px-4 py-3 text-center text-xs text-slate-400">
                        Showing the first <span x-text="limit"></span>. Type to search the list.
                    </p>
                </div>
            </section>

            {{-- The bill. --}}
            <section class="flex min-h-0 flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                {{-- Which bill, and who it is for. --}}
                <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <p class="flex items-baseline gap-3">
                        <span class="{{ $label }} text-slate-500">{{ $editing ? 'Correcting bill' : 'Bill' }}</span>
                        <span class="font-mono text-xl font-semibold tabular-nums text-emerald-700">{{ $editing?->reference() ?? $billReference }}</span>
                    </p>

                    <button type="button" x-on:click="openCustomers()"
                            class="flex max-w-full items-center gap-3 rounded-lg border border-slate-300 px-4 py-2.5 text-left hover:border-emerald-700">
                        <span class="{{ $label }} text-slate-500">Customer</span>
                        <span class="truncate text-sm font-semibold"
                              :class="customer ? 'text-slate-900' : @js($walkIns ? 'text-slate-900' : 'text-amber-700')"
                              x-text="customer || @js($walkIns ? 'Walk-in customer' : 'Select a customer')"></span>
                        <span class="truncate text-sm" :class="owes > 0 || credit > 0 ? 'text-amber-700' : 'text-slate-500'"
                              x-text="customer
                                  ? (credit > 0 ? 'New pending Rs. ' + money(owes + credit) : (owes > 0 ? 'Pending Rs. ' + money(owes) : 'No pending amount'))
                                  : 'No account'"></span>
                        <span class="shrink-0 text-xs text-slate-500">▾</span>
                    </button>
                </div>

                <div class="{{ $label }} {{ $cols }} grid shrink-0 items-center gap-3 border-y border-slate-200 bg-slate-50 px-4 py-2.5 text-slate-600">
                    <span>On this bill</span>
                    <span class="text-center">Qty</span>
                    @unless ($business->sellsAtMrp())
                        <span class="text-right">MRP</span>
                    @endunless
                    <span class="text-right">Each</span>
                    <span class="text-right">Pack price</span>
                    <span class="text-right">Total</span>
                    <span></span>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto" x-ref="bill">
                    <template x-for="(line, i) in cart" :key="line.id">
                        {{-- Once the bill is longer than the card, the line just
                             added is the one you need to see. It is scrolled to
                             and held green for a moment, so a long bill never
                             swallows the thing you just scanned. --}}
                        <div :data-line="line.id" :class="flash === line.id ? 'bg-emerald-50' : ''"
                             class="{{ $cols }} grid items-center gap-3 border-b border-slate-200 px-4 py-2.5 transition-colors duration-500">
                            <span class="min-w-0">
                                <span class="block break-words text-base font-medium leading-snug text-slate-900" x-text="line.name"></span>
                                <span class="block truncate text-xs text-slate-500" x-text="lineNote(line)"></span>
                                <span x-show="shortOnLine(line) > 0" x-cloak class="block text-xs font-medium text-red-700"
                                      x-text="'Only ' + stockLabel(line) + ' in stock. The bill cannot be saved.'"></span>
                                <span x-show="belowCost(line.price, line.cost)" x-cloak class="block text-xs font-medium text-red-700"
                                      x-text="'Below cost. Minimum price is Rs. ' + money(line.cost)"></span>
                                <span x-show="line.raised" x-cloak class="block text-xs font-medium text-amber-700"
                                      x-text="'Raised to the purchase price, Rs. ' + money(line.cost) + ' a pack'"></span>
                            </span>

                            {{-- A pharmacy counts in pieces: 20 is twenty tablets,
                                 whatever the pack holds. A distributor counts packs. --}}
                            <span class="flex items-center justify-center gap-1">
                                <button type="button" x-on:click="looseMode ? setPieces(line, pieces(line) - 1) : (line.qty = Math.max(1, line.qty - 1))"
                                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white text-lg leading-none text-slate-900 hover:border-emerald-700">−</button>
                                <input type="text" inputmode="numeric" :value="looseMode ? pieces(line) : line.qty"
                                       x-on:change="looseMode ? setPieces(line, $event.target.value) : (line.qty = Math.max(1, Math.floor(Number($event.target.value) || 1)))"
                                       class="w-12 border-0 bg-transparent p-0 text-center font-mono text-lg font-semibold tabular-nums text-slate-900 focus:ring-2 focus:ring-emerald-600">
                                <button type="button" x-on:click="looseMode ? setPieces(line, pieces(line) + 1) : line.qty++"
                                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white text-lg leading-none text-slate-900 hover:border-emerald-700">+</button>
                            </span>

                            {{-- Read only: the pack price is the manufacturer's, not
                                 something the counter sets. --}}
                            @unless ($business->sellsAtMrp())
                                <span class="text-right font-mono text-sm tabular-nums text-slate-500"
                                      x-text="line.mrp > 0 ? money(line.mrp) : '—'"></span>
                            @endunless

                            {{-- What one piece sells for, typed over like the pack
                                 price: change either, the other follows. A product
                                 sold singly has only its one price. --}}
                            <span>
                                <template x-if="Number(line.per) > 1">
                                    <input type="text" inputmode="decimal" :value="piece(line.price, line.per).toFixed(2)"
                                           x-on:focus="$el.select()" x-on:change="setEach(line, $event.target.value); raiseToCost(line)"
                                           x-on:keydown.enter.prevent="$event.target.blur()"
                                           :title="'Price of one of ' + line.per + '. Type to change it; the pack price follows.'"
                                           :class="belowCost(line.price, line.cost)
                                               ? 'border-red-400 bg-red-50 text-red-800 focus:border-red-500 focus:ring-red-500'
                                               : 'border-transparent bg-transparent text-slate-900 hover:border-slate-300 focus:border-emerald-700 focus:ring-emerald-600'"
                                           class="w-full rounded-lg px-1 py-1.5 text-right font-mono text-sm tabular-nums">
                                </template>
                                <template x-if="! (Number(line.per) > 1)">
                                    <span class="block text-right font-mono text-sm tabular-nums text-slate-400">—</span>
                                </template>
                            </span>

                            {{-- The price can be typed over; it only looks like a
                                 box once the pointer or the cursor is on it. --}}
                            <input type="text" inputmode="decimal" x-model.number="line.price" x-on:focus="$el.select()"
                                   x-on:change="raiseToCost(line)" x-on:keydown.enter.prevent="$event.target.blur()"
                                   :title="line.mrp > 0 && Number(line.price) !== Number(line.mrp)
                                       ? 'MRP is ' + money(line.mrp) + '. Type to change the price.'
                                       : 'Price of one pack. Type to change it.'"
                                   :class="belowCost(line.price, line.cost)
                                       ? 'border-red-400 bg-red-50 text-red-800 focus:border-red-500 focus:ring-red-500'
                                       : 'border-transparent bg-transparent text-slate-900 hover:border-slate-300 focus:border-emerald-700 focus:ring-emerald-600'"
                                   class="w-full rounded-lg px-1 py-1.5 text-right font-mono text-sm tabular-nums">

                            <span class="text-right font-mono text-sm font-bold tabular-nums text-slate-900" x-text="money(lineTotal(line))"></span>

                            <button type="button" x-on:click="cart.splice(i, 1)"
                                    class="justify-self-end rounded-lg p-1 text-lg leading-none text-slate-500 hover:bg-red-50 hover:text-red-700" title="Remove">
                                &times;
                            </button>
                        </div>
                    </template>

                    <div x-show="cart.length === 0" class="flex h-full flex-col items-center justify-center gap-1 py-16 text-center">
                        <p class="text-lg font-medium text-slate-400">Bill is empty</p>
                        <p class="text-sm text-slate-400">Select a product from the list or scan a barcode.</p>
                    </div>
                </div>

                {{-- The foot of the bill: how much is on it, and the discount. --}}
                <div class="flex shrink-0 flex-wrap items-center justify-between gap-x-6 gap-y-2 border-t border-slate-200 px-4 py-3">
                    <p class="flex items-center gap-3 text-sm text-slate-600">
                        <span x-text="cart.length + (cart.length === 1 ? ' item' : ' items') + ' · ' + (looseMode
                            ? cart.reduce((n, l) => n + pieces(l), 0) + ' qty'
                            : cart.reduce((n, l) => n + Number(l.qty || 0), 0) + ' {{ $many }}')"></span>
                        <button type="button" x-on:click="clear()" x-show="cart.length > 0" x-cloak
                                class="text-sm font-medium text-slate-500 underline hover:text-red-700">Clear</button>
                        <span class="font-medium text-red-700" x-show="underpriced.length > 0" x-cloak
                              x-text="underpriced.length + ' ' + (underpriced.length === 1 ? 'item is' : 'items are') + ' priced below cost'"></span>
                    </p>

                    <div class="flex flex-wrap items-center justify-end gap-x-6 gap-y-1">
                        <p class="flex items-baseline gap-3">
                            <span class="{{ $label }} text-slate-500">Subtotal</span>
                            <span class="font-mono text-base font-semibold tabular-nums text-slate-900" x-text="'Rs. ' + money(subtotal)"></span>
                        </p>
<label class="flex items-center gap-3">
                            <span class="{{ $label }} text-slate-500">Discount</span>
                            {{-- Typed as a percentage of the bill; what it comes
                                 to in rupees is shown beside it and is what is saved. --}}
                            <span class="relative block">
                                <input x-model="discountPct" x-on:input="applyDiscount()" type="text" inputmode="decimal" placeholder="0"
                                       class="block w-20 rounded-lg border-slate-300 py-2 pl-2 pr-7 text-right font-mono text-base tabular-nums focus:border-emerald-700 focus:ring-emerald-600">
                                <span class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 font-mono text-sm text-slate-500">%</span>
                            </span>
                            <span class="w-24 whitespace-nowrap font-mono text-sm tabular-nums"
                                  :class="Number(discount) > 0 ? 'text-slate-900' : 'text-slate-300'"
                                  x-text="'− ' + money(discount)"></span>
                        </label>
                        <p x-show="discountCapped" x-cloak class="basis-full text-right text-xs font-medium text-amber-700"
                           x-text="'Discount limited to Rs. ' + money(maxDiscount) + ': more would sell below the purchase price.'"></p>
                    </div>
                </div>
            </section>
        </div>

        {{-- The foot of the screen, read left to right the way a sale ends:
             what to pay, what was handed over, what goes back, and the button
             that finishes it. --}}
        @if ($editing)
            {{-- A correction always carries its reason. On a strip of its
                 own across the screen: beside the figures it pushed the foot
                 onto a second row and off the bottom of a small screen. --}}
            <label class="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-1 border-t border-amber-200 bg-amber-50 px-4 py-2 sm:px-6">
                <span class="{{ $label }} shrink-0 text-amber-800">Reason for change</span>
                <input x-ref="reason" x-model="reason" type="text" maxlength="255" placeholder="Wrong quantity, item added…"
                       x-on:keydown.enter.prevent="save()"
                       class="block min-w-[14rem] flex-1 rounded-lg border-amber-300 bg-white py-2 text-sm focus:border-emerald-700 focus:ring-emerald-600">
            </label>
        @endif

        <footer class="till-foot flex shrink-0 flex-wrap items-end justify-between gap-x-6 gap-y-3 border-t border-slate-300 bg-white px-4 py-5 sm:px-6">
            {{-- The figure everything else is measured against, so it
                 stands on a block of its own. --}}
            <div class="rounded-xl bg-slate-900 px-5 py-3">
                <p class="{{ $label }} text-slate-300">Total bill</p>
                <p class="till-total mt-1 whitespace-nowrap font-mono text-5xl font-bold leading-none tabular-nums text-white" x-text="'Rs. ' + money(total)"></p>
            </div>

            {{-- What the customer hands over. The usual answers come first,
                 one press each; the box is for anything between. --}}
            <div class="rounded-xl bg-emerald-700 px-4 py-3">
                <p class="{{ $label }} text-white">Received</p>
                <div class="mt-1 flex flex-wrap items-stretch gap-2">
                    {{-- How it was paid. Anything but cash goes to that account
                         in the books, not into the drawer. --}}
                    @if ($methods->isNotEmpty())
                        {{-- Chosen from a menu that opens upward: at the foot
                             of the screen there is no room below it. --}}
                        <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                            <button type="button" x-on:click="open = ! open" :aria-expanded="open" title="How the customer paid"
                                    class="flex h-full items-center gap-2 rounded-lg border border-slate-300 bg-white py-2.5 pl-3 pr-2 font-mono text-sm font-semibold text-slate-900 hover:border-emerald-700">
                                <span class="max-w-[10rem] truncate" x-text="viaLabel"></span>
                                {{-- Kebab: three dots, one above another. --}}
                                <svg class="h-5 w-5 shrink-0 text-slate-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <circle cx="10" cy="4" r="1.6" /><circle cx="10" cy="10" r="1.6" /><circle cx="10" cy="16" r="1.6" />
                                </svg>
                            </button>

                            <div x-show="open" x-cloak x-transition.opacity.duration.100ms
                                 class="absolute bottom-full left-0 z-40 mb-2 max-h-72 w-60 overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
                                <p class="{{ $label }} px-3 py-1.5 text-slate-500">Paid by</p>
                                <template x-for="option in viaOptions" :key="option.id">
                                    <button type="button" x-on:click="via = option.id; open = false; $refs.received.focus()"
                                            :class="via === option.id ? 'bg-emerald-50 font-semibold text-emerald-800' : 'text-slate-900 hover:bg-slate-50'"
                                            class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm">
                                        <span class="truncate" x-text="option.label"></span>
                                        <span x-show="via === option.id" class="shrink-0 text-emerald-700">✓</span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    @else
                        <span class="flex items-center rounded-lg bg-white px-3 font-mono text-sm font-semibold text-slate-900">Cash</span>
                    @endif

                    <button type="button" x-on:click="received = total"
                            class="rounded-lg bg-white px-4 font-mono text-sm font-semibold text-emerald-800 hover:bg-emerald-50"
                            title="The customer paid the exact bill amount">Exact</button>
                    {{-- The notes a customer is most likely to hand over. --}}
                    <template x-for="note in likelyNotes" :key="note">
                        <button type="button" x-on:click="received = note"
                                class="rounded-lg bg-white px-4 font-mono text-sm font-semibold tabular-nums text-emerald-800 hover:bg-emerald-50"
                                x-text="note.toLocaleString('en-US')"></button>
                    </template>
                    <button type="button" x-on:click="received = ''"
                            class="rounded-lg bg-emerald-900 px-4 font-mono text-sm font-semibold text-white hover:bg-emerald-950"
                            title="No payment now. The full bill is added to the customer account.">On credit</button>

                    <input x-ref="received" x-model.number="received" type="text" inputmode="decimal" placeholder="0.00"
                           class="block w-44 rounded-lg border-2 border-white bg-white py-2 text-right font-mono text-2xl font-semibold tabular-nums text-slate-900 focus:border-white focus:ring-2 focus:ring-white/60">
                </div>
            </div>

            <div>
                <p class="{{ $label }}" :class="Number(received || 0) > 0 && change < 0 ? 'text-amber-700' : 'text-slate-500'"
                   x-text="Number(received || 0) > 0 && change < 0 ? 'Pending customer bill' : 'Change to give back'"></p>
                <p class="till-figure mt-1 whitespace-nowrap font-mono text-3xl font-semibold leading-tight tabular-nums"
                   :class="Number(received || 0) > 0 ? (change >= 0 ? 'text-emerald-700' : 'text-amber-700') : 'text-slate-300'"
                   x-text="'Rs. ' + money(Number(received || 0) > 0 ? Math.abs(change) : 0)"></p>
            </div>

            <div class="flex items-end gap-3">
                <button type="button" x-on:click="save()"
                        :disabled="cart.length === 0 || saving || underpriced.length > 0 || overStock.length > 0 || {{ $dayClosed ? 'true' : 'false' }}"
                        class="till-save flex items-center gap-3 rounded-xl bg-emerald-700 px-10 py-4 text-xl font-bold text-white shadow-sm hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-40">
                    <span x-text="saving ? 'Saving…' : @js($editing ? 'Save changes' : 'Save bill')"></span>
                    <span class="rounded border border-white/60 px-1.5 py-0.5 font-mono text-xs font-semibold">F9</span>
                </button>
            </div>
        </footer>

        {{-- Who the bill is for: the customers this business sells to, with
             what each already owes. A name not on the list can be typed, and
             it opens an account of its own when the bill is saved. --}}
        <div x-show="picking" x-cloak class="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/60 p-4 pt-[8vh]"
             x-on:click.self="picking = false" x-on:keydown.escape.window="picking = false">
            <div class="flex max-h-[70vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl bg-white shadow-xl">
                <div class="shrink-0 border-b border-slate-200 p-4">
                    <p class="text-sm font-semibold text-slate-900">Select the customer for this bill</p>
                    <div class="mt-2 flex gap-2">
                        <input x-ref="customerSearch" x-model="customerQuery" type="text" autocomplete="off"
                               x-on:keydown.arrow-down.prevent="moveCustomer(1)" x-on:keydown.arrow-up.prevent="moveCustomer(-1)"
                               x-on:keydown.enter.prevent="pickHighlighted()" x-on:input="customerAt = -1"
                               placeholder="Search by name, area or phone…"
                               class="block w-full rounded-lg border-slate-300 py-2 focus:border-emerald-600 focus:ring-emerald-600">
                        @can('configure', $business)
                            <button type="button" x-on:click="$dispatch('open-drawer', 'add-pharmacy')"
                                    class="shrink-0 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                                + New
                            </button>
                        @endcan
                    </div>
                </div>

                {{-- Up and Down in the search box move through the list; Enter
                     takes the lit one, or the first match when none is lit. --}}
                <div class="min-h-0 flex-1 overflow-y-auto" x-ref="customerList">
                    @if ($walkIns)
                        <button type="button" data-pick x-on:click="pick(null)" :class="customerAt === 0 ? 'bg-emerald-50' : ''"
                                class="flex w-full items-center justify-between gap-3 border-b border-slate-100 px-4 py-2.5 text-left hover:bg-emerald-50">
                            <span class="text-sm font-medium text-slate-900">Walk-in customer</span>
                            <span class="text-xs text-slate-400">No account</span>
                        </button>
                    @endif

                    <template x-for="(c, i) in customerMatches" :key="c.id">
                        <button type="button" data-pick x-on:click="pick(c)" :class="customerAt === i + {{ $walkIns ? 1 : 0 }} ? 'bg-emerald-50' : ''"
                                class="flex w-full items-center justify-between gap-3 border-b border-slate-100 px-4 py-2.5 text-left last:border-0 hover:bg-emerald-50">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-slate-900" x-text="c.name"></span>
                                <span class="block truncate text-xs text-slate-500" x-text="[c.area, c.phone].filter(Boolean).join(' · ') || '—'"></span>
                            </span>
                            <span class="shrink-0 text-right text-xs tabular-nums"
                                  :class="c.owes > 0 ? 'text-amber-700' : 'text-slate-400'"
                                  x-text="c.owes > 0 ? 'Pending ' + money(c.owes) : 'No pending amount'"></span>
                        </button>
                    </template>

                    <div x-show="customerQuery.trim() !== '' && customerMatches.length === 0" x-cloak class="p-4">
                        <p class="text-sm text-slate-500">
                            No customer found with the name “<span x-text="customerQuery"></span>”.
                        </p>
                        <button type="button" x-on:click="pick({ id: 0, name: customerQuery.trim().toUpperCase(), owes: 0 })"
                                class="mt-2 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                            Bill “<span x-text="customerQuery.trim().toUpperCase()"></span>” as a new customer
                        </button>
                    </div>
                </div>

                <div class="shrink-0 border-t border-slate-200 px-4 py-2 text-xs text-slate-500">
                    <span x-text="customers.length"></span> customers · Any unpaid amount is added to the customer account
                </div>
            </div>
        </div>

        {{-- A bill with no customer on it that is not fully paid. A walk-in
             is a stranger, so before anything is left owing the bill is
             given a name: one already on the list, or a new one with just a
             name and a phone number. --}}
        <div x-show="creditAsk" x-cloak class="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/60 p-4 pt-[6vh]"
             x-on:click.self="creditAsk = false" x-on:keydown.escape.window="creditAsk = false">
            <div class="flex max-h-[84vh] w-full max-w-xl flex-col overflow-hidden rounded-2xl bg-white shadow-xl">
                <div class="shrink-0 border-b border-slate-200 p-4">
                    <p class="text-base font-semibold text-slate-900">Select the customer for this credit bill</p>
                    <p class="mt-1 text-sm text-slate-600">
                        <span class="font-mono font-semibold tabular-nums text-amber-700" x-text="'Rs. ' + money(credit)"></span>
                        of this bill is not paid. A walk-in bill cannot be left unpaid. Select or add the customer.
                    </p>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    {{-- Someone already on the list. --}}
                    <div class="border-b border-slate-200 p-4">
                        <p class="{{ $label }} text-slate-500">Existing customer</p>
                        <input x-ref="creditSearch" x-model="creditQuery" type="text" autocomplete="off"
                               x-on:keydown.enter.prevent="creditMatches[0] && creditPick(creditMatches[0])"
                               placeholder="Search by name or phone…"
                               class="mt-1 block w-full rounded-lg border-slate-300 py-2 focus:border-emerald-600 focus:ring-emerald-600">
                        <div class="mt-2 max-h-48 overflow-y-auto rounded-lg ring-1 ring-slate-200" x-show="creditMatches.length > 0">
                            <template x-for="c in creditMatches" :key="c.id">
                                <button type="button" x-on:click="creditPick(c)"
                                        class="flex w-full items-center justify-between gap-3 border-b border-slate-100 px-3 py-2 text-left last:border-0 hover:bg-emerald-50">
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-medium text-slate-900" x-text="c.name"></span>
                                        <span class="block truncate text-xs text-slate-500" x-text="[c.area, c.phone].filter(Boolean).join(' · ') || '—'"></span>
                                    </span>
                                    <span class="shrink-0 text-right text-xs tabular-nums"
                                          :class="c.owes > 0 ? 'text-amber-700' : 'text-slate-400'"
                                          x-text="c.owes > 0 ? 'Pending ' + money(c.owes) : 'No pending amount'"></span>
                                </button>
                            </template>
                        </div>
                        <p class="mt-2 text-sm text-slate-500" x-show="creditMatches.length === 0" x-cloak
                           x-text="customers.length === 0 ? 'No customers yet. Add this customer below.' : 'No customer found with this name. Add the customer below.'"></p>
                    </div>

                    {{-- Someone new: a name and a phone number, nothing else. --}}
                    <div class="p-4">
                        <p class="{{ $label }} text-slate-500">Or a new customer</p>
                        <div class="mt-1 grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">Name</span>
                                <input x-ref="creditName" x-model="creditName" type="text" maxlength="120" autocomplete="off"
                                       x-on:keydown.enter.prevent="creditNew()"
                                       class="mt-1 block w-full rounded-lg border-slate-300 py-2 uppercase focus:border-emerald-600 focus:ring-emerald-600">
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">Phone number</span>
                                <input x-model="creditPhone" type="text" inputmode="tel" maxlength="40" autocomplete="off"
                                       x-on:keydown.enter.prevent="creditNew()" placeholder="0300 1234567"
                                       class="mt-1 block w-full rounded-lg border-slate-300 py-2 font-mono focus:border-emerald-600 focus:ring-emerald-600">
                            </label>
                        </div>
                        <p class="mt-2 text-sm font-medium text-red-700" x-show="creditError" x-cloak x-text="creditError"></p>
                        <button type="button" x-on:click="creditNew()"
                                class="mt-3 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                            Add customer and save bill
                        </button>
                    </div>
                </div>

                <div class="flex shrink-0 items-center justify-between gap-3 border-t border-slate-200 px-4 py-3">
                    <button type="button" x-on:click="received = total; creditAsk = false; $refs.received.focus()"
                            class="rounded-lg bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-200">
                        Paid in full
                    </button>
                    <button type="button" x-on:click="creditAsk = false"
                            class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100">Back to bill</button>
                </div>
            </div>
        </div>

        @include('business.partials.add-pharmacy-drawer', ['afterAdd' => 'select'])

        @if ($saved !== null)
            @include('business.pos.partials.after-sale')
        @endif

        {{-- Everything rung up today. A bill is a record: correcting one keeps
             what it said before, and goods coming back are a return beside it. --}}
        <div x-show="bills" x-cloak x-on:keydown.escape.window="bills = false"
             class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4">
            <div class="my-auto flex max-h-[85vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-white shadow-xl">
                <div class="flex shrink-0 items-center justify-between gap-4 border-b border-slate-200 px-5 py-3">
                    <div>
                        <p class="text-lg font-semibold text-slate-900">Bill history</p>
                        <p class="text-sm text-slate-500">
                            {{ $today->format('l j F Y') }} ·
                            {{ $soldToday->count() }} {{ Str::plural('bill', $soldToday->count()) }} ·
                            Rs. {{ $rs(\App\Support\Money::sum($soldToday->pluck('total'))) }}
                        </p>
                    </div>
                    <button type="button" x-on:click="bills = false"
                            class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700">&times;</button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="sticky top-0 bg-slate-50">
                            <tr>
                                @foreach (['Bill', 'Time', 'Customer', 'Items', 'Total', 'Pending', ''] as $h)
                                    <th class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 {{ in_array($h, ['Bill','Time','Customer']) ? 'text-left' : 'text-right' }} {{ $loop->first || $loop->last ? 'sm:px-5' : '' }}">{{ $h }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($soldToday as $bill)
                                <tr class="hover:bg-slate-50">
                                    <td class="whitespace-nowrap px-3 py-2 font-mono text-xs font-semibold text-slate-900 sm:px-5">{{ $bill->reference() }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ $bill->created_at->timezone($business->timezone)->format('h:i A') }}</td>
                                    <td class="px-3 py-2">
                                        {{-- The buyer opens their statement: what they have
                                             bought and paid, beside the till rather than over it. --}}
                                        @if ($bill->customer_name !== null && isset($statementFor[$bill->customer_name]))
                                            <a href="{{ route('businesses.pharmacies.show', [$business, $statementFor[$bill->customer_name]]) }}"
                                               target="_blank" rel="noopener"
                                               title="Open {{ $bill->customer_name }}'s payment history in a new tab"
                                               class="inline-flex max-w-full items-center gap-1 truncate font-medium text-emerald-700 hover:text-emerald-800 hover:underline">
                                                <span class="truncate">{{ $bill->customer_name }}</span>
                                                <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path d="M11 3a1 1 0 100 2h2.59l-6.3 6.29a1 1 0 101.42 1.42L15 6.41V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z" />
                                                    <path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z" />
                                                </svg>
                                            </a>
                                        @else
                                            <span class="block truncate text-slate-900">{{ $bill->customer_name ?? 'Walk-in customer' }}</span>
                                        @endif
                                        <span class="block truncate text-xs text-slate-400">{{ $bill->creator->name }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-600">{{ $bill->lines_count }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums text-slate-900">{{ $rs($bill->total) }}</td>
                                    {{-- What is still to come in, not what was left unpaid
                                         at the time: money brought back since is taken off.
                                         Receive takes the rest in cash, in two presses — the
                                         bill itself is never changed, the money is entered as
                                         a collection on today's entry. --}}
                                    @php($pending = $stillOwed[$bill->id] ?? null)
                                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                                        @if ($pending)
                                            <span class="font-medium text-amber-700">{{ $rs($pending['owed']) }}</span>
                                        @elseif ($bill->credit()->isPositive() && $bill->pharmacy_id !== null)
                                            <span class="text-xs font-medium text-emerald-700" title="The pending amount has been received">Received ✓</span>
                                        @elseif ($bill->credit()->isPositive())
                                            <span class="font-medium text-amber-700">{{ $rs($bill->credit()) }}</span>
                                        @else
                                            <span class="text-slate-300">—</span>
                                        @endif
                                    </td>
                                    {{-- Both open the bill in its own tab, so the counter
                                         keeps the sale in progress. Print asks that tab to
                                         print the moment it has loaded. --}}
                                    <td class="whitespace-nowrap px-3 py-2 text-right sm:px-5">
                                        <div class="inline-flex items-center gap-1">
                                            {{-- First in the row: taking the pending money is
                                                 what the counter comes here for most. --}}
                                            @if ($pending)
                                            @can('create', [\App\Models\DailyEntry::class, $business])
                                                <form method="POST" action="{{ route('businesses.collections.store', $business) }}"
                                                      x-data="{ sure: false }" x-on:click.outside="sure = false" class="mr-1">
                                                    @csrf
                                                    <input type="hidden" name="pharmacy_id" value="{{ $pending['pharmacy_id'] }}">
                                                    <input type="hidden" name="pos_bill_id" value="{{ $bill->id }}">
                                                    <input type="hidden" name="amount" value="{{ $pending['owed']->toDecimal() }}">
                                                    <input type="hidden" name="note" value="Received at the POS against bill {{ $bill->reference() }}">
                                                    <button type="button" x-show="! sure" x-on:click="sure = true" @disabled($dayClosed)
                                                            title="Receive the whole pending amount in cash"
                                                            class="rounded-md bg-emerald-700 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-40">
                                                        Receive amount
                                                    </button>
                                                    <button type="submit" x-show="sure" x-cloak
                                                            class="rounded-md bg-amber-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-amber-700">
                                                        Yes, received Rs. {{ $rs($pending['owed']) }}
                                                    </button>
                                                </form>
                                            @endcan
                                            @endif
                                            {{-- The bill's own page: its history, and for those
                                                 allowed, correcting it or taking goods back. --}}
                                            @can('returnAtPos', $business)
                                                <a href="{{ route('businesses.pos.bills.return', [$business, $bill]) }}" target="_blank" rel="noopener"
                                                   class="rounded-md px-1.5 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-emerald-700">Return</a>
                                            @endcan
                                            {{-- Edit puts the bill back on this till; History
                                                 is the bill's own page, with every change to it. --}}
                                            @can('editPosBills', $business)
                                                <a href="{{ route('businesses.pos.index', [$business, 'edit' => $bill->id]) }}"
                                                   class="rounded-md px-1.5 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-emerald-700">Edit</a>
                                            @endcan
                                            <a href="{{ route('businesses.pos.bills.edit', [$business, $bill]) }}" target="_blank" rel="noopener"
                                               class="rounded-md px-1.5 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-emerald-700">History</a>
                                            <a href="{{ route('businesses.pos.receipt', [$business, $bill]) }}"
                                               target="_blank" rel="noopener"
                                               title="Preview {{ $bill->reference() }}" aria-label="Preview {{ $bill->reference() }}"
                                               class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-emerald-700">
                                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                                    <path d="M1.5 10S4.5 4.5 10 4.5 18.5 10 18.5 10 15.5 15.5 10 15.5 1.5 10 1.5 10Z" />
                                                    <circle cx="10" cy="10" r="2.5" />
                                                </svg>
                                            </a>
                                            <a href="{{ route('businesses.pos.receipt', [$business, $bill, 'print' => 1]) }}"
                                               target="_blank" rel="noopener"
                                               title="Print {{ $bill->reference() }} again" aria-label="Print {{ $bill->reference() }} again"
                                               class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-emerald-700">
                                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true">
                                                    <path d="M5.5 7.5V2.5h9v5" />
                                                    <path d="M5.5 14.5h-2a1 1 0 01-1-1v-5a1 1 0 011-1h13a1 1 0 011 1v5a1 1 0 01-1 1h-2" />
                                                    <path d="M5.5 11.5h9v6h-9z" />
                                                </svg>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-5 py-12 text-center text-slate-500">
                                        No bills today.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="flex shrink-0 items-center justify-end border-t border-slate-200 px-5 py-3">
                    <button type="button" x-on:click="bills = false"
                            class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                        Back to POS
                    </button>
                </div>
            </div>
        </div>

        @include('business.pos.partials.print-portal')

        {{-- How many? Asked every time, so a wrong quantity is never a surprise
             on the bill. Enter confirms, Escape cancels. --}}
        <div x-show="asking" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4"
             x-on:click.self="cancelAsk()" x-on:keydown.escape.window="cancelAsk()">
            <div class="w-full max-w-2xl rounded-2xl bg-white p-6 shadow-xl">
                <p class="text-sm text-slate-500">Add to the bill</p>
                <p class="mt-0.5 break-words text-lg font-semibold text-slate-900" x-text="asking?.name"></p>
                <p class="text-sm text-slate-500">
                    <span :class="(asking?.stock > 0 || asking?.loose_stock > 0) ? '' : 'font-semibold text-red-600'">
                        <span x-show="asking?.pack" x-text="'Pack ' + asking?.pack + ' · '"></span><span x-text="asking ? stockLabel(asking) : ''"></span> in stock
                    </span>
                </p>

                {{-- The three figures that matter, in the order they happen: what
                     it cost us, what we sell it at, and what the pack says. A
                     blank one is a figure nobody has recorded yet, which is worth
                     seeing before the price is typed. --}}
                <template x-if="asking?.expiry_state">
                    <p class="mt-3 rounded-xl p-3 text-sm font-semibold"
                       :class="asking.expiry_state === 'expired' ? 'bg-red-50 text-red-900 ring-1 ring-red-200' : 'bg-amber-50 text-amber-900 ring-1 ring-amber-200'"
                       x-text="asking.expiry_state === 'expired'
                           ? 'This stock expired in ' + asking.expires_label + '. Do not sell it. Remove it from the shelf.'
                           : 'The oldest stock of this product expires ' + asking.expires_label + '. Move it to the near-expiry shelf.'"></p>
                </template>

                {{-- A pharmacy sells at the MRP, so it has no trade price to show. --}}
                <dl class="mt-3 grid {{ $business->sellsAtMrp() ? 'grid-cols-2' : 'grid-cols-3' }} gap-2 rounded-xl bg-slate-50 p-2 text-center">
                    <div>
                        <dt class="text-[11px] uppercase tracking-wide text-slate-400">Purchase</dt>
                        <dd class="font-mono text-sm font-semibold tabular-nums text-slate-900"
                            x-text="asking?.cost > 0 ? money(asking.cost) : '—'"></dd>
                    </div>
                    @unless ($business->sellsAtMrp())
                        <div>
                            <dt class="text-[11px] uppercase tracking-wide text-slate-400">Trade</dt>
                            <dd class="font-mono text-sm font-semibold tabular-nums text-emerald-800"
                                x-text="asking?.trade > 0 ? money(asking.trade) : '—'"></dd>
                        </div>
                    @endunless
                    <div>
                        <dt class="text-[11px] uppercase tracking-wide text-slate-400">MRP</dt>
                        <dd class="font-mono text-sm font-semibold tabular-nums text-slate-900"
                            x-text="asking?.mrp > 0 ? money(asking.mrp) : '—'"></dd>
                    </div>
                </dl>

                {{-- Stock never goes below zero. A sale of more than the books
                     hold is refused here, before it reaches the bill: if the goods
                     really are on the shelf, the count is wrong and is put right
                     first — a delivery recorded, or the stock adjusted. --}}
                <div x-show="askShortfall > 0" x-cloak class="mt-4 rounded-xl bg-red-50 p-3 ring-1 ring-red-200">
                    <p class="text-sm font-semibold text-red-900">
                        <span x-text="(asking?.stock > 0 || asking?.loose_stock > 0) ? 'More than is in stock' : 'None in stock'"></span>
                    </p>
                    <p class="mt-1 text-sm leading-snug text-red-800">
                        Recorded stock is <span class="font-semibold" x-text="asking ? stockLabel(asking) : ''"></span>
                        of <span class="font-semibold" x-text="asking?.name"></span>.
                        Record the delivery or correct the stock first, then sell it.
                    </p>
                </div>

                {{-- How many: typed, or one tap below — the whole pack, or a
                     usual count. A tap adds it straight to the bill. --}}
                <label class="mt-4 block">
                    <span class="text-xs uppercase tracking-wide text-slate-400"
                          x-text="looseMode && asking?.per > 1 ? 'Quantity (pieces)' : 'Quantity'">Quantity</span>
                    <input x-ref="askQty" x-model.number="askQty" type="text" inputmode="numeric"
                           x-on:keydown.enter.prevent="askFloor() || confirmAsk()" x-on:focus="$el.select()"
                           class="mt-1 block w-full rounded-lg border-slate-300 py-2 text-center text-3xl font-semibold tabular-nums focus:border-emerald-600 focus:ring-emerald-600">
                </label>

                <div class="mt-2 flex gap-1.5">
                    <template x-if="looseMode && asking?.per > 1">
                        <button type="button" x-on:click="askQty = asking.per; confirmAsk()" :disabled="askUnderpriced"
                                :class="Number(askQty) === Number(asking?.per) ? 'bg-emerald-700 text-white' : 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-600/30 hover:bg-emerald-100'"
                                class="shrink-0 basis-32 rounded-lg px-3 py-2 text-sm font-semibold leading-tight disabled:opacity-50">
                            Full pack
                            <span class="block text-[0.6875rem] font-medium opacity-80" x-text="asking?.per + ' pieces'"></span>
                        </button>
                    </template>
                    <template x-for="n in [1, 2, 5, 10, 20]" :key="n">
                        <button type="button" x-on:click="askQty = n; confirmAsk()" :disabled="askUnderpriced"
                                :class="Number(askQty) === n ? 'bg-emerald-700 text-white' : 'bg-slate-100 text-slate-800 hover:bg-slate-200'"
                                class="min-w-0 flex-1 rounded-lg py-2 text-base font-semibold tabular-nums disabled:opacity-50"
                                x-text="n"></button>
                    </template>
                </div>

                {{-- The price two ways, kept in step: what one piece sells for,
                     and the whole pack. Change either; the other follows. A
                     product sold singly has just the one price. --}}
                <div class="mt-4 grid gap-3" :class="asking?.per > 1 ? 'grid-cols-2' : 'grid-cols-1'">
                    <label class="block" x-show="asking?.per > 1" x-cloak>
                        <span class="text-xs uppercase tracking-wide text-slate-400">Price each</span>
                        <input x-model="askEach" x-on:input="askPrice = packFromEach(askEach, asking?.per); askRaised = false"
                               x-on:change="askFloor()"
                               type="text" inputmode="decimal" x-on:keydown.enter.prevent="askFloor() || confirmAsk()" x-on:focus="$el.select()"
                               class="mt-1 block w-full rounded-lg border-slate-300 py-2 text-right text-2xl font-semibold tabular-nums focus:border-emerald-600 focus:ring-emerald-600">
                        <span class="mt-0.5 block text-right text-[0.6875rem] text-slate-400" x-text="'1 of ' + asking?.per"></span>
                    </label>
                    <label class="block">
                        <span class="text-xs uppercase tracking-wide text-slate-400" x-text="asking?.per > 1 ? 'Whole pack' : 'Price each'">Price each</span>
                        <input x-model="askPrice" x-on:input="askEach = piece(askPrice, asking?.per); askRaised = false"
                               x-on:change="askFloor()"
                               type="text" inputmode="decimal" x-on:keydown.enter.prevent="askFloor() || confirmAsk()" x-on:focus="$el.select()"
                               class="mt-1 block w-full rounded-lg border-slate-300 py-2 text-right text-2xl font-semibold tabular-nums focus:border-emerald-600 focus:ring-emerald-600">
                        <span x-show="asking?.per > 1" class="mt-0.5 block text-right text-[0.6875rem] text-slate-400" x-text="asking?.per + ' pieces'"></span>
                    </label>
                </div>

                <p x-show="askRaised" x-cloak class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">
                    The price was raised to the purchase price, <span class="font-semibold" x-text="'Rs. ' + money(asking?.cost)"></span> a pack.
                    It cannot be sold for less. Press Enter to add it.
                </p>

                {{-- Said before the item reaches the bill, not after. --}}
                <div x-show="askUnderpriced" x-cloak class="mt-3 rounded-xl bg-red-50 p-3 ring-1 ring-red-200">
                    <p class="text-sm font-semibold text-red-900">Below the purchase price</p>
                    <p class="mt-0.5 text-sm leading-snug text-red-800">
                        The purchase price is <span class="font-semibold" x-text="'Rs. ' + money(asking?.cost)"></span>.
                        A lower price is a loss on every
                        {{ $one }}, so it cannot be added to the bill.
                    </p>
                    <button type="button" x-on:click="askPrice = asking.cost; askEach = piece(asking.cost, asking.per)"
                            class="mt-2 rounded-lg bg-red-700 px-2.5 py-1 text-xs font-semibold text-white hover:bg-red-800">
                        Use Rs. <span x-text="money(asking?.cost)"></span>
                    </button>
                </div>

                <p class="mt-3 flex items-baseline justify-between text-sm">
                    <span class="text-slate-500">Line total</span>
                    <span class="text-xl font-bold tabular-nums text-slate-900"
                          x-text="'Rs. ' + money(asking ? lineTotal({ ...askSplit, price: askPrice, per: asking.per }) : 0)"></span>
                </p>

                <div class="mt-4 flex gap-2">
                    <button type="button" x-on:click="cancelAsk()"
                            class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        Cancel
                    </button>
                    <button type="button" x-on:click="confirmAsk()" :disabled="askUnderpriced || askShortfall > 0"
                            class="flex-1 rounded-lg px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300"
                            :class="(askUnderpriced || askShortfall > 0) ? '' : 'bg-emerald-600 hover:bg-emerald-700'"
                            x-text="askUnderpriced ? 'Price too low' : (askShortfall > 0 ? 'Not in stock' : 'Add to bill')">
                    </button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            function counter() {
                return {
                    products: @json($products),
                    customers: @json($customers),
                    cart: [],
                    picking: false,

                    // Asking who an unpaid bill with no customer on it is for.
                    creditAsk: false,
                    creditQuery: '',
                    creditName: '',
                    creditPhone: '',
                    creditError: '',
                    // The phone number of a customer added at the till, sent with the bill.
                    customerPhone: '',
                    get creditMatches() {
                        const q = this.creditQuery.trim().toLowerCase();

                        return (q === '' ? this.customers : this.customers.filter(c => c.name.toLowerCase().includes(q)
                            || (c.phone || '').includes(q) || (c.area || '').toLowerCase().includes(q))).slice(0, 50);
                    },
                    openCredit() {
                        this.creditAsk = true;
                        this.creditQuery = this.creditName = this.creditPhone = this.creditError = '';
                        this.$nextTick(() => this.$refs.creditSearch.focus());
                    },
                    /** The bill goes on someone already on the list, and is saved. */
                    creditPick(customer) {
                        this.customer = customer.name;
                        this.owes = customer.owes ?? 0;
                        this.customerPhone = '';
                        this.creditAsk = false;
                        this.save();
                    },
                    /** A new customer, by name and phone; their account opens with the bill. */
                    creditNew() {
                        const name = this.creditName.trim().toUpperCase();
                        const phone = this.creditPhone.trim();

                        if (name === '') {
                            this.creditError = 'Enter the customer\'s name.';
                            this.$refs.creditName.focus();

                            return;
                        }

                        if (phone === '') {
                            this.creditError = 'Enter the customer\'s phone number.';

                            return;
                        }

                        // Typed again rather than found: it is the same customer.
                        const known = this.customers.find(c => c.name.toLowerCase() === name.toLowerCase());

                        if (known) return this.creditPick(known);

                        this.customer = name;
                        this.owes = 0;
                        this.customerPhone = phone;
                        this.creditAsk = false;
                        this.save();
                    },
                    customerQuery: '',
                    owes: 0,
                    query: '',
                    inStockOnly: false,
                    highlight: 0,
                    limit: 200,
                    // The discount in rupees — what is saved with the bill — and
                    // the percentage typed at the till that it is worked out from.
                    discount: '',
                    discountPct: '',
                    discountCapped: false,
                    /*
                     * Never so much that the bill sells below what its goods
                     * cost: the most it can be is the bill less that cost. The
                     * server refuses the same, whatever this sends.
                     */
                    applyDiscount() {
                        const pct = Math.min(100, Math.max(0, Number(this.discountPct) || 0));
                        let discount = pct > 0 ? Math.round(this.subtotal * pct + Number.EPSILON) / 100 : 0;

                        this.discountCapped = discount > this.maxDiscount + 0.001;

                        if (this.discountCapped) {
                            discount = this.maxDiscount;
                        }

                        this.discount = discount > 0 ? discount : '';
                    },
                    /** What the goods on the bill cost, at the purchase price. */
                    get billCost() {
                        return this.cart.reduce((n, l) => n + (Math.floor(Number(l.qty) || 0)) * (Number(l.cost) || 0)
                            + (Math.floor(Number(l.loose) || 0)) * this.piece(l.cost, l.per), 0);
                    },
                    get maxDiscount() {
                        return Math.max(0, Math.floor((this.subtotal - this.billCost) * 100 + Number.EPSILON) / 100);
                    },
                    /** A bill line typed below cost: put back up to it, and said so. */
                    raiseToCost(l) {
                        l.raised = this.belowCost(l.price, l.cost);
                        if (l.raised) l.price = Number(l.cost);
                    },
                    received: '',
                    customer: '',
                    saving: false,

                    // How the customer paid: '' is cash, otherwise a payment method id.
                    via: '',
                    viaOptions: [{ id: '', label: 'Cash' }, ...@json($methods->map(fn ($m) => ['id' => (string) $m->id, 'label' => $m->label()])->values())],
                    get viaLabel() {
                        return this.viaOptions.find(o => o.id === String(this.via))?.label ?? 'Cash';
                    },

                    /*
                     * What to do after a sale — print, net bill, balance — starts
                     * each bill as the business's settings say. The switches at
                     * the top change it for this bill only; nothing is kept in
                     * the browser, so no till drifts from the settings.
                     */
                    bills: false,

                    /** Products whose oldest stock is short-dated or already out. */
                    get nearExpiry() {
                        return this.products.filter(p => p.expiry_state && p.stock > 0).length;
                    },
                    wantPrint: @js((bool) $business->pos_print),
                    wantBalance: @js((bool) $business->pos_balance),
                    wantNet: @js(\App\Enums\ReceiptFormat::NET_BILL_AVAILABLE && ($editing ? (bool) $editing->as_net_bill : (bool) $business->pos_net_bill)),
                    /** The line just added or topped up, held green briefly. */
                    flash: null,
                    flashTimer: null,
                    clock: '',
                    asking: null,
                    askQty: 1,
                    askPrice: 0,
                    askEach: 0,
                    askRaised: false,
                    askLoose: 0,

                    // Why a saved bill is being corrected; only asked while editing.
                    reason: '',

                    // Another tab of this till that this one could not simply
                    // take over from, and whether this tab has stood down.
                    elsewhere: false,
                    elsewhereLines: 0,
                    elsewhereEditing: false,
                    moved: false,
                    takeOver() {},

                    /*
                     * One till, one tab.
                     *
                     * A tab that opens announces itself. The till already open
                     * answers with the bill it has in progress; the new tab
                     * takes that bill over and says so, and the old tab stands
                     * down and closes itself if the browser allows. So opening
                     * the till again always lands on a working till with the
                     * sale intact, whichever tab the browser shows.
                     *
                     * A correction is not handed over — a bill being corrected
                     * must not turn into a new sale — so when either tab is
                     * correcting one, the new tab asks first.
                     *
                     * Not every browser has the channel, and the till must
                     * still open without it.
                     */
                    oneTab() {
                        if (! ('BroadcastChannel' in window)) return;

                        const me = Math.random().toString(36).slice(2);
                        const mode = @js($editing ? 'edit' : 'sale');
                        const channel = new BroadcastChannel('pharmaco-till-' + @js($business->id));
                        let answered = false;

                        const claim = () => channel.postMessage({ type: 'moved', from: me });

                        this.takeOver = () => { this.elsewhere = false; claim(); this.focusSearch(); };

                        channel.onmessage = ({ data }) => {
                            if (data.from === me || this.moved) return;

                            // Another tab has just opened: say what is here.
                            if (data.type === 'opened') {
                                channel.postMessage({
                                    type: 'here', from: me, to: data.from, mode,
                                    lines: this.cart.length,
                                    state: JSON.parse(JSON.stringify({
                                        cart: this.cart, discount: this.discount, discountPct: this.discountPct, received: this.received,
                                        customer: this.customer, via: this.via, owes: this.owes,
                                    })),
                                });
                            }

                            // This tab has just opened and the till is elsewhere.
                            if (data.type === 'here' && data.to === me && ! answered) {
                                answered = true;

                                // A tab still running an older copy of this
                                // page does not say what it is doing; it is
                                // taken to be an ordinary sale.
                                const theirs = data.mode ?? 'sale';

                                // Straight after a save this tab is showing
                                // the bill it just saved: it is the till, and
                                // nothing may be put in front of that.
                                const justSaved = @js($saved !== null);

                                if (justSaved || (mode === 'sale' && theirs === 'sale')) {
                                    if (! justSaved && data.state && data.lines > 0 && this.cart.length === 0) Object.assign(this, data.state);
                                    claim();
                                } else {
                                    this.elsewhere = true;
                                    this.elsewhereLines = data.lines || 0;
                                    this.elsewhereEditing = data.mode === 'edit';
                                }
                            }

                            // The till is now in another tab: stand down. The
                            // bill went with it, so it is not kept here to be
                            // saved a second time.
                            if (data.type === 'moved') {
                                this.moved = true;
                                this.elsewhere = false;
                                this.cart = [];
                                window.close();
                            }
                        };

                        channel.postMessage({ type: 'opened', from: me, mode });
                    },

                    init() {
                        this.oneTab();

                        // A percentage follows the bill: as lines are added
                        // or changed, the discount in rupees is worked out again.
                        this.$watch('subtotal', () => { if (this.discountPct !== '') this.applyDiscount(); });


                        @if ($editing)
                            // The saved bill, back on the till as it stands.
                            this.cart = @json($editingLines).map(l => {
                                const p = this.products.find(p => p.id === l.id);
                                return p ? { id: p.id, name: p.name, qty: l.qty, loose: l.loose, per: p.per, price: l.price, stock: p.stock, loose_stock: p.loose_stock, cost: p.cost, mrp: p.mrp } : null;
                            }).filter(Boolean);
                            this.discount = @json((float) $editing->discount->toDecimal()) || '';
                            // The bill kept its discount in rupees; shown here as
                            // the percentage it comes to. The rupee figure stands
                            // until the percentage or the bill itself is changed.
                            this.discountPct = this.discount && this.subtotal > 0
                                ? String(Math.round(this.discount / this.subtotal * 10000) / 100) : '';
                            this.received = @json((float) $editing->received->toDecimal()) || '';
                            this.customer = @json($editing->customer_name ?? '');
                            this.via = @json((string) ($editing->business_payment_method_id ?? ''));
                            // What they owe apart from this bill, so it is not counted twice.
                            this.owes = Math.max(0, (this.customers.find(c => c.name === this.customer)?.owes ?? 0)
                                - Math.max(0, @json((float) $editing->total->toDecimal()) - (Number(this.received) || 0)));
                        @endif

                        // The counter's own clock, in the business's timezone.
                        const tick = () => {
                            this.clock = new Date().toLocaleTimeString('en-US', {
                                timeZone: @js($business->timezone),
                                hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true,
                            });
                        };
                        tick();
                        setInterval(tick, 1000);
                    },

                    money(v) {
                        return (Number(v) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    },

                    // The whole list by default; a search narrows it. A scanner
                    // sends the barcode and an Enter, so an exact code match is
                    // the only thing shown.
                    get found() {
                        const q = this.query.trim().toLowerCase();
                        let list = this.inStockOnly ? this.products.filter(p => p.stock > 0) : this.products;
                        if (q === '') return list;
                        const exact = list.filter(p => (p.code || '').toLowerCase() === q);
                        if (exact.length) return exact;
                        return list.filter(p => p.name.toLowerCase().includes(q) || (p.code || '').toLowerCase().includes(q));
                    },
                    get shown() { return this.found.slice(0, this.limit); },
                    get capped() { return this.found.length > this.limit; },

                    get subtotal() {
                        return this.cart.reduce((n, l) => n + this.lineTotal(l), 0);
                    },

                    /**
                     * One loose piece: the pack price over what is in the pack,
                     * to the paisa. The server works it out the same way, so
                     * the till and the saved bill agree.
                     */
                    piece(price, per) {
                        per = Number(per) || 1;
                        return per <= 1 ? (Number(price) || 0) : Math.round(((Number(price) || 0) / per) * 100 + Number.EPSILON) / 100;
                    },
                    /** The pack price for a price each: what is typed, times the pack. */
                    packFromEach(each, per) {
                        per = Number(per) || 1;
                        each = Number(String(each ?? '').replace(/,/g, '')) || 0;
                        return Math.round(each * per * 100 + Number.EPSILON) / 100;
                    },
                    /** A bill line priced by the piece: the pack follows. */
                    setEach(l, each) {
                        l.price = this.packFromEach(each, l.per);
                    },
                    lineTotal(l) {
                        return (Math.floor(Number(l.qty) || 0)) * (Number(l.price) || 0)
                            + (Math.floor(Number(l.loose) || 0)) * this.piece(l.price, l.per);
                    },
                    /** Whether a product is already on the bill being built. */
                    onBill(id) {
                        return this.cart.some(l => l.id === id);
                    },
                    /** Under a bill line's name: its pack, and what the quantity comes to. */
                    lineNote(l) {
                        const pack = this.products.find(p => p.id === l.id)?.pack;
                        const parts = [pack ? 'Pack ' + pack : ''];
                        if (this.looseMode && Number(l.per) > 1) {
                            parts.push(this.splitLabel(l), 'Rs. ' + this.money(this.piece(l.price, l.per)) + ' each');
                        }
                        return parts.filter(Boolean).join(' · ');
                    },
                    /** The next round sums above the bill: 1,350 offers 1,500 and 2,000. */
                    get likelyNotes() {
                        const total = this.total;
                        if (total <= 0) return [];
                        const up = step => Math.ceil((total + 0.001) / step) * step;
                        return [...new Set([up(500), up(1000), up(5000)])].slice(0, 2);
                    },
                    /** A pharmacy types pieces; a distributor types packs. */
                    looseMode: @json($loose),
                    pieces(l) {
                        return Math.floor(Number(l.qty) || 0) * (Number(l.per) || 1) + Math.floor(Number(l.loose) || 0);
                    },
                    /** So many pieces, as whole packs and what is left over. */
                    split(n, per) {
                        per = Number(per) || 1;
                        n = Math.max(0, Math.floor(Number(n) || 0));
                        return { qty: Math.floor(n / per), loose: n % per, per };
                    },
                    setPieces(l, n) {
                        const s = this.split(Math.max(1, Math.floor(Number(n) || 1)), l.per);
                        l.qty = s.qty;
                        l.loose = s.loose;
                    },
                    splitLabel(l) {
                        const q = Math.floor(Number(l.qty) || 0), x = Math.floor(Number(l.loose) || 0);
                        return [q > 0 ? q + (q === 1 ? ' pack' : ' packs') : '', x > 0 ? x + ' loose' : ''].filter(Boolean).join(' + ') || '0';
                    },
                    /** What the dialog's figures come to, as packs and loose. */
                    get askSplit() {
                        const per = Number(this.asking?.per) || 1;
                        return this.looseMode
                            ? this.split(this.askQty, per)
                            : { qty: Math.max(0, Math.floor(Number(this.askQty) || 0)), loose: 0, per };
                    },
                    /** Everything held of a product, in pieces. */
                    piecesHeld(p) {
                        return (Number(p.stock) || 0) * (Number(p.per) || 1) + (Number(p.loose_stock) || 0);
                    },
                    stockLabel(p) {
                        const loose = Number(p.loose_stock) || 0;
                        return (Number(p.stock) || 0) + ' {{ $many }}' + (loose > 0 ? ' + ' + loose + ' loose' : '');
                    },
                    /** How many pieces past the stock a line on the bill is. */
                    shortOnLine(l) {
                        const wanted = Math.floor(Number(l.qty) || 0) * (Number(l.per) || 1) + Math.floor(Number(l.loose) || 0);
                        return Math.max(0, wanted - this.piecesHeld(l));
                    },
                    get overStock() {
                        return this.cart.filter(l => this.shortOnLine(l) > 0);
                    },
                    get total() {
                        return Math.max(0, this.subtotal - (Number(this.discount) || 0));
                    },
                    get change() {
                        return (Number(this.received) || 0) - this.total;
                    },
                    /**
                     * How far past the recorded stock this dialog would take
                     * the product — counting what is already on the bill, and
                     * zero once it has been agreed to for this session.
                     */
                    get askShortfall() {
                        const p = this.asking;
                        if (! p) return 0;
                        const per = Number(p.per) || 1;
                        const line = this.cart.find(l => l.id === p.id);
                        const already = (line?.qty ?? 0) * per + (line?.loose ?? 0);
                        const wanted = already + this.pieces(this.askSplit);
                        return Math.max(0, wanted - this.piecesHeld(p));
                    },

                    /**
                     * A price below what the goods cost.
                     *
                     * Products imported without a purchase rate cost nothing on
                     * paper, and a floor of zero is no floor — those are left
                     * alone rather than being given a made-up one.
                     */
                    belowCost(price, cost) {
                        const floor = Number(cost) || 0;
                        return floor > 0 && (Number(price) || 0) < floor;
                    },

                    /** Lines that cannot be billed as priced. */
                    get underpriced() {
                        return this.cart.filter(l => this.belowCost(l.price, l.cost));
                    },

                    /** The dialog's own price, against the same floor. */
                    get askUnderpriced() {
                        return this.asking !== null && this.belowCost(this.askPrice, this.asking.cost);
                    },

                    /** What this bill leaves on the customer's account. */
                    get credit() {
                        return Math.max(0, this.total - (Number(this.received) || 0));
                    },

                    // Name or area, so "Bahtar" finds everyone on that road.
                    get customerMatches() {
                        const q = this.customerQuery.trim().toLowerCase();
                        const list = q === ''
                            ? this.customers
                            : this.customers.filter(c => c.name.toLowerCase().includes(q)
                                || (c.area || '').toLowerCase().includes(q)
                                || (c.phone || '').includes(q));
                        return list.slice(0, 100);
                    },
                    // A customer added from the drawer is the one being billed.
                    customerAdded(pharmacy) {
                        this.customers.push(pharmacy);
                        this.customers.sort((a, b) => a.name.localeCompare(b.name));
                        this.pick(pharmacy);
                    },
                    openCustomers() {
                        this.picking = true;
                        this.customerQuery = '';
                        this.customerAt = -1;
                        this.$nextTick(() => this.$refs.customerSearch.focus());
                    },
                    // Where the arrow keys are in the customer list; -1 is nowhere yet.
                    customerAt: -1,
                    moveCustomer(step) {
                        const rows = this.$refs.customerList.querySelectorAll('[data-pick]');

                        if (rows.length === 0) return;

                        this.customerAt = this.customerAt < 0
                            ? (step > 0 ? 0 : rows.length - 1)
                            : (this.customerAt + step + rows.length) % rows.length;
                        rows[this.customerAt]?.scrollIntoView({ block: 'nearest' });
                    },
                    pickHighlighted() {
                        const row = this.customerAt >= 0
                            ? this.$refs.customerList.querySelectorAll('[data-pick]')[this.customerAt]
                            : null;

                        this.customerAt = -1;

                        return row ? row.click() : this.pickFirst();
                    },
                    pickFirst() {
                        const first = this.customerMatches[0];
                        if (first) return this.pick(first);
                        if (this.customerQuery.trim() !== '') {
                            this.pick({ id: 0, name: this.customerQuery.trim().toUpperCase(), owes: 0 });
                        }
                    },
                    pick(customer) {
                        this.customer = customer?.name ?? '';
                        this.customerPhone = '';
                        this.owes = customer?.owes ?? 0;
                        this.picking = false;
                        this.focusSearch();
                    },

                    focusSearch() { this.$refs.search.focus(); this.$refs.search.select(); },
                    move(step) {
                        if (! this.shown.length) return;
                        this.highlight = (this.highlight + step + this.shown.length) % this.shown.length;
                        this.$refs.list.children[this.highlight]?.scrollIntoView({ block: 'nearest' });
                    },
                    askHighlighted() {
                        const p = this.shown[this.highlight] ?? this.shown[0];
                        if (p) this.ask(p);
                    },

                    /**
                     * Bring the line that just changed into view and mark it.
                     * Called by every path that puts something on the bill.
                     */
                    showOnBill(id) {
                        this.flash = id;
                        clearTimeout(this.flashTimer);
                        this.flashTimer = setTimeout(() => { this.flash = null; }, 1200);

                        this.$nextTick(() => {
                            this.$refs.bill
                                ?.querySelector('[data-line="' + id + '"]')
                                ?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                        });
                    },

                    /** One more of it, at its own price, without being asked. */
                    addOne(p) {
                        const line = this.cart.find(l => l.id === p.id);

                        // The + is always a whole pack. Tablets out of a pack
                        // are asked for by clicking the product, which opens
                        // the dialog. Past the count, the + opens that dialog
                        // too, which says why the sale cannot go on the bill.
                        const per = Number(p.per) || 1;
                        const step = per;
                        if ((line?.qty ?? 0) * per + (line?.loose ?? 0) + step > this.piecesHeld(p)) {
                            return this.ask(p);
                        }

                        if (line) {
                            this.setPieces(line, this.pieces(line) + step);
                        } else {
                            const first = this.split(step, per);
                            this.cart.push({ id: p.id, name: p.name, qty: first.qty, loose: first.loose, per: p.per, price: p.price, stock: p.stock, loose_stock: p.loose_stock, cost: p.cost, mrp: p.mrp });
                        }

                        this.showOnBill(p.id);
                        this.focusSearch();
                    },

                    // How many, and at what price — before it reaches the bill.
                    ask(p) {
                        this.asking = p;
                        this.askQty = 1;
                        this.askLoose = 0;
                        this.askPrice = p.price;
                        this.askEach = this.piece(p.price, p.per);
                        this.askRaised = false;
                        this.$nextTick(() => { this.$refs.askQty.focus(); this.$refs.askQty.select(); });
                    },
                    /**
                     * The dialog's price below cost: raised to it, and said so.
                     * True when it raised it, so Enter stops there once and the
                     * next Enter adds the item at the raised price.
                     */
                    askFloor() {
                        if (! this.askUnderpriced) return false;

                        this.askPrice = Number(this.asking.cost);
                        this.askEach = this.piece(this.asking.cost, this.asking.per);
                        this.askRaised = true;

                        return true;
                    },
                    cancelAsk() {
                        this.asking = null;
                        this.focusSearch();
                    },
                    confirmAsk() {
                        if (this.askUnderpriced || this.askShortfall > 0) return;

                        let { qty, loose } = this.askSplit;
                        if (qty + loose === 0) ({ qty, loose } = this.split(this.looseMode ? 1 : (Number(this.asking?.per) || 1), this.asking?.per));
                        const price = Number(this.askPrice) || 0;
                        const p = this.asking;
                        if (! p) return;

                        const line = this.cart.find(l => l.id === p.id);
                        if (line) {
                            this.setPieces(line, this.pieces(line) + qty * (Number(p.per) || 1) + loose);
                            line.price = price;
                        } else {
                            this.cart.push({ id: p.id, name: p.name, qty, loose, per: p.per, price, stock: p.stock, loose_stock: p.loose_stock, cost: p.cost, mrp: p.mrp });
                        }

                        this.asking = null;
                        this.query = '';
                        this.highlight = 0;
                        this.showOnBill(p.id);
                        this.focusSearch();
                    },

                    clear() {
                        this.cart = [];
                        this.discount = this.discountPct = this.received = this.customer = this.customerPhone = this.via = '';
                        this.owes = 0;
                        this.focusSearch();
                    },
                    save() {
                        if (this.cart.length === 0 || this.saving) return;
                        if (this.underpriced.length > 0 || this.overStock.length > 0) return;
                        @unless ($walkIns)
                            if (! this.customer) {
                                this.openCustomers();
                                return;
                            }
                        @endunless
                        // Not fully paid, and nobody named to owe the rest.
                        if (! this.customer && this.credit > 0.004) {
                            this.openCredit();
                            return;
                        }
                        @if ($editing)
                            if (! this.reason.trim()) {
                                this.$refs.reason.focus();
                                return;
                            }
                        @endif
                        this.saving = true;
                        // Hidden inputs are bound to the bill; let them settle first.
                        this.$nextTick(() => this.$refs.form.submit());
                    },
                };
            }
        </script>
    @endpush
</x-pos-layout>
