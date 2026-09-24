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

        {{-- Who, where, which bill, and the clock. --}}
        <header class="flex shrink-0 flex-wrap items-center justify-between gap-x-6 gap-y-2 bg-slate-900 px-4 py-2.5 text-white sm:px-6">
            <div class="flex items-center gap-3">
                <span class="rounded bg-emerald-500 px-2 py-1 text-sm font-bold text-slate-900">Rx</span>
                <div>
                    <p class="text-base font-semibold leading-tight">{{ $business->name }}</p>
                    <p class="text-xs text-slate-400">Counter · {{ auth()->user()->name }}</p>
                </div>
            </div>

            <div class="flex items-center gap-6">
                <div class="text-right">
                    <p class="text-[11px] uppercase tracking-wide text-slate-400">Bill</p>
                    <p class="font-mono text-lg font-semibold leading-tight text-emerald-400">{{ $billReference }}</p>
                </div>
                <div class="text-right">
                    <p class="text-[11px] uppercase tracking-wide text-slate-400">{{ $today->format('D d M Y') }}</p>
                    <p class="font-mono text-lg font-semibold leading-tight tabular-nums" x-text="clock">&nbsp;</p>
                </div>
                {{-- The day's takings, covered until asked for.

                     A counter screen faces the shop, and the person being served
                     can read it as easily as the person serving. The number of
                     bills is no secret; the money is, so only the money is hidden
                     and it covers itself again shortly after. --}}
                <div class="text-right" x-data="{
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
                     }">
                    <p class="text-[11px] uppercase tracking-wide text-slate-400">Sold today</p>
                    <p class="flex items-center justify-end gap-2 text-lg font-semibold leading-tight tabular-nums">
                        <button type="button" x-on:click="reveal()"
                                class="rounded p-0.5 text-slate-400 hover:text-white"
                                :title="shown ? 'Hide the day\'s takings' : 'Show the day\'s takings for a moment'"
                                :aria-label="shown ? 'Hide takings' : 'Show takings'">
                            {{-- Open eye to reveal, struck through once showing. --}}
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                <path d="M1.5 10S4.5 4.5 10 4.5 18.5 10 18.5 10 15.5 15.5 10 15.5 1.5 10 1.5 10Z" />
                                <circle cx="10" cy="10" r="2.5" />
                                <path x-show="shown" x-cloak d="M3 17 17 3" stroke-linecap="round" />
                            </svg>
                        </button>

                        {{-- The mask is the same width as the figure, so the header
                             does not jump about as it is shown and hidden. --}}
                        <span x-show="! shown" class="tracking-widest text-slate-500">Rs. ••••••</span>
                        <span x-show="shown" x-cloak>Rs. {{ $rs(\App\Support\Money::sum($soldToday->pluck('total'))) }}</span>

                        <span class="text-xs font-normal text-slate-400">{{ $soldToday->count() }} {{ Str::plural('bill', $soldToday->count()) }}</span>
                    </p>
                </div>
                {{-- What happens after a sale. Both off by default: a till that
                     stops to show something after every bill slows the queue. --}}
                <div class="flex items-center gap-2">
                    <button type="button" x-on:click="wantPrint = ! wantPrint; remember()"
                            :class="wantPrint ? 'bg-emerald-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-slate-200'"
                            class="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium"
                            title="After saving, show the bill ready to print">
                        <span class="inline-block h-2 w-2 rounded-full"
                              :class="wantPrint ? 'bg-white' : 'bg-slate-600'"></span>
                        Print
                    </button>
                    {{-- Which bill to show, not what to charge: the net bill's
                         own total is this bill's total, to the paisa. --}}
                    @if (\App\Enums\ReceiptFormat::NET_BILL_AVAILABLE)
                    <button type="button" x-on:click="wantNet = ! wantNet; remember()"
                            :class="wantNet ? 'bg-emerald-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-slate-200'"
                            class="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium"
                            title="Show the bill quoted before the {{ \App\Enums\ReceiptFormat::TRADE_DISCOUNT_PERCENT }}% trade discount — the amount payable is the same">
                        <span class="inline-block h-2 w-2 rounded-full"
                              :class="wantNet ? 'bg-white' : 'bg-slate-600'"></span>
                        Net {{ \App\Enums\ReceiptFormat::TRADE_DISCOUNT_PERCENT }}%
                    </button>
                    @endif
                    <button type="button" x-on:click="wantBalance = ! wantBalance; remember()"
                            :class="wantBalance ? 'bg-emerald-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-slate-200'"
                            class="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium"
                            title="After saving, show the customer's account">
                        <span class="inline-block h-2 w-2 rounded-full"
                              :class="wantBalance ? 'bg-white' : 'bg-slate-600'"></span>
                        Balance
                    </button>
                </div>

                <a href="{{ route('businesses.show', $business) }}"
                   class="rounded-md border border-slate-600 px-3 py-1.5 text-sm font-medium text-slate-200 hover:bg-slate-800">
                    Leave counter
                </a>
            </div>
        </header>

        @if ($dayClosed)
            <div class="shrink-0 bg-red-600 px-4 py-2 text-center text-sm font-medium text-white sm:px-6">
                {{ $today->format('D d M Y') }} is closed — nothing can be sold onto it. Reopen the day first.
            </div>
        @endif

        @if (session('status'))
            <div class="shrink-0 bg-emerald-600 px-4 py-2 text-center text-sm font-medium text-white sm:px-6">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="shrink-0 bg-red-600 px-4 py-2 text-center text-sm font-medium text-white sm:px-6">{{ $errors->first() }}</div>
        @endif

        {{-- The form carries the bill; the screen never posts anything else. --}}
        <form method="POST" action="{{ route('businesses.pos.store', $business) }}" x-ref="form" class="hidden">
            @csrf
            <template x-for="(line, i) in cart" :key="line.id">
                <span>
                    <input type="hidden" :name="`items[${i}][product_id]`" :value="line.id">
                    <input type="hidden" :name="`items[${i}][quantity]`" :value="line.qty">
                    <input type="hidden" :name="`items[${i}][unit_price]`" :value="Number(line.price).toFixed(2)">
                </span>
            </template>
            <input type="hidden" name="received" :value="Number(received || 0).toFixed(2)">
            <input type="hidden" name="discount" :value="Number(discount || 0).toFixed(2)">
            <input type="hidden" name="customer_name" :value="customer">
            <input type="hidden" name="preview" :value="wantPrint ? 1 : 0">
            <input type="hidden" name="balance" :value="wantBalance ? 1 : 0">
            <input type="hidden" name="net" :value="wantNet ? 1 : 0">
        </form>

        {{-- Stock on the left, the bill on the right. Both scroll inside. --}}
        {{-- The two lists take what is left after the payment card, which is
             given a fifth of the screen: it holds the figures the counter
             reads out loud, so it never shrinks to fit a long bill. --}}
        <div class="grid min-h-0 flex-1 grid-cols-1 gap-4 p-4 lg:grid-cols-2 sm:px-6">

            <section class="flex min-h-0 flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                <div class="shrink-0 border-b border-slate-200 p-3">
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400"
                             viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="9" cy="9" r="6" /><path d="M14 14l4 4" stroke-linecap="round" />
                        </svg>
                        <input x-ref="search" x-model="query" type="text" autocomplete="off" autofocus
                               x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)"
                               x-on:keydown.enter.prevent="askHighlighted()" x-on:keydown.escape="query = ''"
                               placeholder="Scan a barcode, or type a product name…"
                               class="block w-full rounded-lg border-0 py-2.5 pl-10 pr-20 shadow-sm ring-1 ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-emerald-600">
                        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 rounded border border-slate-300 px-1.5 py-0.5 text-xs text-slate-400">F2</span>
                    </div>
                    <p class="mt-2 flex items-center justify-between text-xs text-slate-500">
                        <span>
                            <span class="font-medium text-slate-700" x-text="shown.length"></span>
                            of {{ $products->count() }} products
                            <span x-show="inStockOnly" x-cloak>· in stock</span>
                        </span>
                        <label class="flex items-center gap-1.5">
                            <input type="checkbox" x-model="inStockOnly" class="rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                            In stock only
                        </label>
                    </p>
                </div>

                {{-- The whole list, not only what was searched for. --}}
                <div class="min-h-0 flex-1 overflow-y-auto" x-ref="list">
                    <template x-for="(p, i) in shown" :key="p.id">
                        <div x-on:mouseenter="highlight = i"
                             :class="highlight === i ? 'bg-emerald-50' : ''"
                             class="grid w-full grid-cols-[minmax(0,1fr)_6rem_5rem_2.5rem] items-center gap-2 border-b border-slate-100 px-3 py-2 last:border-0 hover:bg-emerald-50">
                            {{-- The row asks how many; the + puts one on the bill and
                                 moves on, which is most of a counter's work. --}}
                            <button type="button" x-on:click="ask(p)" class="min-w-0 text-left">
                                <span class="block break-words text-sm font-medium leading-snug text-slate-900" x-text="p.name"></span>
                                <span class="block truncate text-xs text-slate-500" x-text="[p.company, p.code].filter(Boolean).join(' · ')"></span>
                            </button>
                            <button type="button" x-on:click="ask(p)" class="text-right text-sm font-semibold tabular-nums text-slate-900" x-text="'Rs. ' + money(p.price)"></button>
                            <button type="button" x-on:click="ask(p)" class="text-right text-xs tabular-nums"
                                    :class="p.stock > 0 ? 'text-slate-500' : 'font-semibold text-red-600'">
                                <span x-text="p.stock"></span> {{ $unit->many() }}
                            </button>
                            {{-- Amber, not green, when the books hold none of it: the
                                 button still works, but it should not look routine. --}}
                            <button type="button" x-on:click.stop="addOne(p)"
                                    class="h-8 w-8 justify-self-end rounded-lg text-lg font-semibold leading-none text-white"
                                    :class="p.stock > 0 ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-amber-500 hover:bg-amber-600'"
                                    :title="p.stock > 0
                                        ? 'Add one ' + @js($unit->one()) + ' of ' + p.name
                                        : 'No stock recorded — you will be asked to confirm'">+</button>
                        </div>
                    </template>

                    <p x-show="shown.length === 0" x-cloak class="px-4 py-10 text-center text-sm text-slate-500">
                        Nothing matches.
                    </p>
                    <p x-show="capped" x-cloak class="border-t border-slate-100 px-4 py-3 text-center text-xs text-slate-400">
                        Showing the first <span x-text="limit"></span>. Type to narrow the list.
                    </p>
                </div>
            </section>

            <section class="flex min-h-0 flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                <div class="grid shrink-0 grid-cols-[minmax(0,1fr)_8rem_6rem_6rem_2rem] gap-2 border-b border-slate-200 bg-slate-50 px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                    <span>On this bill</span>
                    <span class="text-center">{{ $unit->many() }}</span>
                    <span class="text-right">Price</span>
                    <span class="text-right">Total</span>
                    <span></span>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto" x-ref="bill">
                    <template x-for="(line, i) in cart" :key="line.id">
                        {{-- Once the bill is longer than the card, the line just
                             added is the one you need to see. It is scrolled to
                             and held green for a moment, so a long bill never
                             swallows the thing you just scanned. --}}
                        <div :data-line="line.id"
                             :class="flash === line.id ? 'bg-emerald-50' : ''"
                             class="grid grid-cols-[minmax(0,1fr)_8rem_6rem_6rem_2rem] items-center gap-2 border-b border-slate-100 px-3 py-2 transition-colors duration-500">
                            <span class="min-w-0">
                                <span class="block break-words text-sm font-medium leading-snug text-slate-900" x-text="line.name"></span>
                                <span x-show="line.qty > line.stock" x-cloak class="text-xs text-amber-700"
                                      x-text="'only ' + line.stock + ' {{ $unit->many() }} in stock'"></span>
                                <span x-show="belowCost(line.price, line.cost)" x-cloak class="block text-xs font-medium text-red-700"
                                      x-text="'below cost — lowest is Rs. ' + money(line.cost)"></span>
                            </span>
                            <span class="flex items-center justify-center gap-1">
                                <button type="button" x-on:click="line.qty = Math.max(1, line.qty - 1)"
                                        class="h-7 w-7 rounded-lg bg-slate-100 text-lg leading-none text-slate-700 hover:bg-slate-200">−</button>
                                <input type="text" inputmode="numeric" x-model.number="line.qty"
                                       class="w-12 rounded-lg border-slate-300 py-1 text-center text-sm tabular-nums">
                                <button type="button" x-on:click="line.qty++"
                                        class="h-7 w-7 rounded-lg bg-slate-100 text-lg leading-none text-slate-700 hover:bg-slate-200">+</button>
                            </span>
                            <input type="text" inputmode="decimal" x-model.number="line.price"
                                   :class="belowCost(line.price, line.cost)
                                       ? 'border-red-400 bg-red-50 text-red-800 focus:border-red-500 focus:ring-red-500'
                                       : 'border-slate-300'"
                                   class="w-full rounded-lg py-1 text-right text-sm tabular-nums">
                            <span class="text-right text-sm font-semibold tabular-nums text-slate-900" x-text="money(line.qty * line.price)"></span>
                            <button type="button" x-on:click="cart.splice(i, 1)"
                                    class="justify-self-end rounded-lg p-1 text-slate-400 hover:bg-red-50 hover:text-red-700" title="Remove">
                                &times;
                            </button>
                        </div>
                    </template>

                    <div x-show="cart.length === 0" class="flex h-full flex-col items-center justify-center gap-1 py-16 text-center">
                        <p class="text-lg font-medium text-slate-400">Bill is empty</p>
                        <p class="text-sm text-slate-400">Pick from the list, or scan a barcode.</p>
                    </div>
                </div>
            </section>
        </div>

        {{-- What is owed, and what was taken — across the foot of the screen. --}}
        {{-- The payment card. A card like the two above it, not a strip bolted
             to the bottom of the window: the bill, the money and the customer
             are three readings, so they are three panels with a rule between. --}}
        <footer class="h-[22vh] min-h-[180px] shrink-0 px-4 pb-4 sm:px-6">
            <div class="grid h-full grid-cols-1 divide-y divide-slate-200 rounded-xl bg-white shadow-sm ring-1 ring-slate-200 lg:grid-cols-[auto_1fr_auto] lg:divide-x lg:divide-y-0">

                {{-- What is on the bill. --}}
                <div class="flex items-center gap-6 px-5 py-3">
                    <div>
                        <p class="text-[11px] uppercase tracking-wide text-slate-400">Items</p>
                        <p class="text-xl font-semibold tabular-nums text-slate-900"
                           x-text="cart.length + ' · ' + cart.reduce((n, l) => n + Number(l.qty || 0), 0) + ' {{ $unit->many() }}'"
                           style="white-space: nowrap"></p>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-wide text-slate-400">Subtotal</p>
                        <p class="whitespace-nowrap text-xl font-semibold tabular-nums text-slate-900" x-text="'Rs. ' + money(subtotal)"></p>
                    </div>
                    <label class="block">
                        <span class="text-[11px] uppercase tracking-wide text-slate-400">Discount</span>
                        <input x-model.number="discount" type="text" inputmode="decimal" placeholder="0.00"
                               class="mt-0.5 block w-28 rounded-lg border-slate-300 py-1.5 text-right tabular-nums focus:border-emerald-600 focus:ring-emerald-600">
                    </label>
                </div>

                {{-- What is owed, and what was handed over. --}}
                <div class="flex items-center justify-center gap-4 px-5 py-3">
                    <div class="rounded-xl bg-slate-900 px-5 py-2.5 text-white">
                        <p class="text-[11px] uppercase tracking-wide text-slate-400">To pay</p>
                        <p class="whitespace-nowrap text-3xl font-bold leading-tight tabular-nums" x-text="'Rs. ' + money(total)"></p>
                    </div>

                    <div>
                        <label class="block">
                            <span class="text-[11px] uppercase tracking-wide text-slate-400">Cash received</span>
                            <input x-ref="received" x-model.number="received" type="text" inputmode="decimal" placeholder="0.00"
                                   class="mt-0.5 block w-40 rounded-lg border-slate-300 py-1.5 text-right text-lg tabular-nums focus:border-emerald-600 focus:ring-emerald-600">
                        </label>
                        <div class="mt-1.5 flex flex-nowrap gap-1">
                            <button type="button" x-on:click="received = total"
                                    class="whitespace-nowrap rounded-lg bg-emerald-600 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-700">Exact</button>
                            <button type="button" x-on:click="received = ''"
                                    class="whitespace-nowrap rounded-lg bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800 hover:bg-amber-200"
                                    title="Nothing paid now — the whole bill goes on the customer's account">On credit</button>
                            <template x-for="note in [100, 500, 1000, 5000]" :key="note">
                                <button type="button" x-on:click="received = (Number(received) || 0) + note"
                                        class="whitespace-nowrap rounded-lg bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200"
                                        x-text="'+' + note"></button>
                            </template>
                        </div>
                    </div>

                    <div class="w-40 shrink-0 rounded-lg px-3 py-2 text-center"
                         :class="Number(received || 0) > 0 ? (change >= 0 ? 'bg-emerald-50' : 'bg-amber-50') : 'bg-slate-50'">
                        <p class="text-[11px] uppercase tracking-wide"
                           :class="Number(received || 0) > 0 ? (change >= 0 ? 'text-emerald-700' : 'text-amber-700') : 'text-slate-400'"
                           x-text="Number(received || 0) === 0 ? 'Change' : (change >= 0 ? 'Change' : 'On credit')"></p>
                        <p class="whitespace-nowrap text-2xl font-bold leading-tight tabular-nums"
                           :class="Number(received || 0) > 0 ? (change >= 0 ? 'text-emerald-800' : 'text-amber-800') : 'text-slate-300'"
                           x-text="'Rs. ' + money(Math.abs(change))"></p>
                    </div>
                </div>

                {{-- Who it is for, and the button that ends the sale. --}}
                <div class="flex items-center gap-4 px-5 py-3">
                    <div class="min-w-0">
                        <p class="text-[11px] uppercase tracking-wide text-slate-400">Customer</p>
                        <button type="button" x-on:click="openCustomers()"
                                class="mt-0.5 flex w-full max-w-xs items-center justify-between gap-2 rounded-lg border border-slate-300 px-3 py-1.5 text-left hover:border-emerald-600">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium"
                                      :class="customer ? 'text-slate-900' : @js($walkIns ? 'text-slate-900' : 'text-amber-700')"
                                      x-text="customer || @js($walkIns ? 'Walk-in (cash)' : 'Choose a customer')"></span>
                                <span class="block truncate text-xs" :class="owes > 0 ? 'text-amber-700' : 'text-slate-400'"
                                      x-text="customer ? (owes > 0 ? 'owes Rs. ' + money(owes) : 'nothing outstanding') : 'no account'"></span>
                            </span>
                            <span class="shrink-0 text-slate-400">▾</span>
                        </button>
                        <p class="mt-1 text-xs font-medium text-red-700" x-show="underpriced.length > 0" x-cloak
                           x-text="underpriced.length + ' ' + (underpriced.length === 1 ? 'line is' : 'lines are') + ' priced below cost'"></p>
                        <p class="mt-1 truncate text-xs text-slate-500" x-show="customer && credit > 0" x-cloak>
                            Balance becomes <span class="font-semibold text-amber-700" x-text="'Rs. ' + money(owes + credit)"></span>
                        </p>
                    </div>

                    <div class="ml-auto flex shrink-0 items-center gap-2">
                        <button type="button" x-on:click="clear()" x-show="cart.length > 0" x-cloak
                                class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                            Clear
                        </button>
                        <button type="button" x-on:click="save()"
                                :disabled="cart.length === 0 || saving || underpriced.length > 0 || {{ $dayClosed ? 'true' : 'false' }}"
                                class="rounded-xl bg-emerald-600 px-7 py-3.5 text-lg font-bold text-white shadow-sm hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-40">
                            <span x-text="saving ? 'Saving…' : 'Save bill'"></span>
                            <span class="ml-1 text-sm font-normal text-emerald-100">F9</span>
                        </button>
                    </div>
                </div>
            </div>
        </footer>

        {{-- Who the bill is for: the customers this business sells to, with
             what each already owes. A name not on the list can be typed, and
             it opens an account of its own when the bill is saved. --}}
        <div x-show="picking" x-cloak class="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/60 p-4 pt-[8vh]"
             x-on:click.self="picking = false" x-on:keydown.escape.window="picking = false">
            <div class="flex max-h-[70vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl bg-white shadow-xl">
                <div class="shrink-0 border-b border-slate-200 p-4">
                    <p class="text-sm font-semibold text-slate-900">Who is this bill for?</p>
                    <div class="mt-2 flex gap-2">
                        <input x-ref="customerSearch" x-model="customerQuery" type="text" autocomplete="off"
                               x-on:keydown.enter.prevent="pickFirst()"
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

                <div class="min-h-0 flex-1 overflow-y-auto">
                    @if ($walkIns)
                        <button type="button" x-on:click="pick(null)"
                                class="flex w-full items-center justify-between gap-3 border-b border-slate-100 px-4 py-2.5 text-left hover:bg-emerald-50">
                            <span class="text-sm font-medium text-slate-900">Walk-in (cash)</span>
                            <span class="text-xs text-slate-400">no account kept</span>
                        </button>
                    @endif

                    <template x-for="c in customerMatches" :key="c.id">
                        <button type="button" x-on:click="pick(c)"
                                class="flex w-full items-center justify-between gap-3 border-b border-slate-100 px-4 py-2.5 text-left last:border-0 hover:bg-emerald-50">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-slate-900" x-text="c.name"></span>
                                <span class="block truncate text-xs text-slate-500" x-text="[c.area, c.phone].filter(Boolean).join(' · ') || '—'"></span>
                            </span>
                            <span class="shrink-0 text-right text-xs tabular-nums"
                                  :class="c.owes > 0 ? 'text-amber-700' : 'text-slate-400'"
                                  x-text="c.owes > 0 ? 'owes ' + money(c.owes) : 'clear'"></span>
                        </button>
                    </template>

                    <div x-show="customerQuery.trim() !== '' && customerMatches.length === 0" x-cloak class="p-4">
                        <p class="text-sm text-slate-500">
                            No customer called “<span x-text="customerQuery"></span>”.
                        </p>
                        <button type="button" x-on:click="pick({ id: 0, name: customerQuery.trim().toUpperCase(), owes: 0 })"
                                class="mt-2 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                            Bill “<span x-text="customerQuery.trim().toUpperCase()"></span>” — opens an account for them
                        </button>
                    </div>
                </div>

                <div class="shrink-0 border-t border-slate-200 px-4 py-2 text-xs text-slate-500">
                    <span x-text="customers.length"></span> customers · anything unpaid goes on their account
                </div>
            </div>
        </div>

        @include('business.partials.add-pharmacy-drawer', ['afterAdd' => 'select'])

        @if ($saved !== null)
            @include('business.pos.partials.after-sale')
        @endif

        @include('business.pos.partials.print-portal')

        {{-- How many? Asked every time, so a wrong quantity is never a surprise
             on the bill. Enter confirms, Escape cancels. --}}
        <div x-show="asking" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4"
             x-on:click.self="cancelAsk()" x-on:keydown.escape.window="cancelAsk()">
            <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl">
                <p class="text-sm text-slate-500">Add to the bill</p>
                <p class="mt-0.5 break-words text-lg font-semibold text-slate-900" x-text="asking?.name"></p>
                <p class="text-sm text-slate-500">
                    <span x-text="'Rs. ' + money(asking?.price)"></span> each ·
                    <span :class="asking?.stock > 0 ? '' : 'font-semibold text-red-600'">
                        <span x-text="asking?.stock"></span> {{ $unit->many() }} in stock
                    </span>
                </p>

                {{-- Shown when this quantity would go past what the books hold, and
                     only until it is answered for this product. Selling anyway is
                     allowed on purpose: the customer is at the counter and the
                     medicine is in their hand. What matters is that the sale says
                     so afterwards. --}}
                <div x-show="askShortfall > 0" x-cloak class="mt-4 rounded-xl bg-amber-50 p-3 ring-1 ring-amber-200">
                    <p class="text-sm font-semibold text-amber-900">
                        <span x-text="asking?.stock > 0 ? 'More than the books hold' : 'No stock recorded'"></span>
                    </p>
                    <p class="mt-1 text-sm leading-snug text-amber-800">
                        Selling this puts
                        <span class="font-semibold" x-text="askShortfall + ' {{ $unit->many() }}'"></span>
                        of <span class="font-semibold" x-text="asking?.name"></span> past the count, so stock goes
                        negative and the figures stop matching the shelf.
                        The sale will be marked <span class="font-semibold">sold below stock</span> so the count can
                        be put right.
                    </p>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-xs uppercase tracking-wide text-slate-400">How many</span>
                        <input x-ref="askQty" x-model.number="askQty" type="text" inputmode="numeric"
                               x-on:keydown.enter.prevent="confirmAsk()"
                               class="mt-1 block w-full rounded-lg border-slate-300 py-2 text-center text-2xl font-semibold tabular-nums focus:border-emerald-600 focus:ring-emerald-600">
                    </label>
                    <label class="block">
                        <span class="text-xs uppercase tracking-wide text-slate-400">Price each</span>
                        <input x-model.number="askPrice" type="text" inputmode="decimal"
                               x-on:keydown.enter.prevent="confirmAsk()"
                               class="mt-1 block w-full rounded-lg border-slate-300 py-2 text-right text-2xl font-semibold tabular-nums focus:border-emerald-600 focus:ring-emerald-600">
                    </label>
                </div>

                <div class="mt-3 flex flex-wrap gap-1">
                    <template x-for="n in [1, 2, 5, 10, 20]" :key="n">
                        <button type="button" x-on:click="askQty = n; confirmAsk()"
                                class="rounded-lg bg-slate-100 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-200"
                                x-text="n"></button>
                    </template>
                </div>

                {{-- Said before the item reaches the bill, not after. --}}
                <div x-show="askUnderpriced" x-cloak class="mt-3 rounded-xl bg-red-50 p-3 ring-1 ring-red-200">
                    <p class="text-sm font-semibold text-red-900">Below the purchase price</p>
                    <p class="mt-0.5 text-sm leading-snug text-red-800">
                        This cost <span class="font-semibold" x-text="'Rs. ' + money(asking?.cost)"></span> to buy.
                        Selling it for less loses money on every
                        {{ $unit->one() }}, so the bill will not take it.
                    </p>
                    <button type="button" x-on:click="askPrice = asking.cost"
                            class="mt-2 rounded-lg bg-red-700 px-2.5 py-1 text-xs font-semibold text-white hover:bg-red-800">
                        Use Rs. <span x-text="money(asking?.cost)"></span>
                    </button>
                </div>

                <p class="mt-3 flex items-baseline justify-between text-sm">
                    <span class="text-slate-500">Line total</span>
                    <span class="text-xl font-bold tabular-nums text-slate-900"
                          x-text="'Rs. ' + money((Number(askQty) || 0) * (Number(askPrice) || 0))"></span>
                </p>

                <div class="mt-4 flex gap-2">
                    <button type="button" x-on:click="cancelAsk()"
                            class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        Cancel
                    </button>
                    <button type="button" x-on:click="confirmAsk()" :disabled="askUnderpriced"
                            class="flex-1 rounded-lg px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300"
                            :class="askUnderpriced ? '' : (askShortfall > 0 ? 'bg-amber-600 hover:bg-amber-700' : 'bg-emerald-600 hover:bg-emerald-700')"
                            x-text="askUnderpriced ? 'Price too low' : (askShortfall > 0 ? 'Sell anyway' : 'Add to bill')">
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
                    customerQuery: '',
                    owes: 0,
                    query: '',
                    inStockOnly: false,
                    highlight: 0,
                    limit: 200,
                    discount: '',
                    received: '',
                    customer: '',
                    saving: false,

                    /*
                     * Products the operator has already agreed to sell below
                     * stock. Kept in memory and nowhere else on purpose: it
                     * lasts as long as this counter session does, so leaving
                     * the counter and coming back asks again.
                     */
                    okayed: [],

                    /*
                     * What to show after a sale. Remembered for this browser so
                     * a counter that always prints does not have to say so every
                     * morning; off when nothing has been remembered yet. Storage
                     * can be unavailable or refused, and the till must still open.
                     */
                    wantPrint: false,
                    wantBalance: false,
                    wantNet: false,
                    remember() {
                        try {
                            localStorage.setItem('pos.after-sale', JSON.stringify({
                                print: this.wantPrint,
                                balance: this.wantBalance,
                                net: this.wantNet,
                            }));
                        } catch (e) { /* private window, blocked storage — carry on */ }
                    },
                    recall() {
                        try {
                            const saved = JSON.parse(localStorage.getItem('pos.after-sale') || '{}');
                            this.wantPrint = saved.print === true;
                            this.wantBalance = saved.balance === true;
                            // Ignored while the net bill is withdrawn, so a till
                            // that remembered it on does not stay stuck on it.
                            this.wantNet = @js(\App\Enums\ReceiptFormat::NET_BILL_AVAILABLE) && saved.net === true;
                        } catch (e) { /* nothing remembered */ }
                    },

                    /** The line just added or topped up, held green briefly. */
                    flash: null,
                    flashTimer: null,
                    clock: '',
                    asking: null,
                    askQty: 1,
                    askPrice: 0,

                    init() {
                        this.recall();

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
                        return this.cart.reduce((n, l) => n + (Number(l.qty) || 0) * (Number(l.price) || 0), 0);
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
                        if (! p || this.okayed.includes(p.id)) return 0;
                        const already = this.cart.find(l => l.id === p.id)?.qty ?? 0;
                        const wanted = already + Math.max(1, Math.floor(Number(this.askQty) || 0));
                        return Math.max(0, wanted - (Number(p.stock) || 0));
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
                        this.$nextTick(() => this.$refs.customerSearch.focus());
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

                        // Going past the count is the one case the quick + does
                        // not do quietly — it opens the dialog so the warning is
                        // seen and answered. Once answered, + is quick again.
                        const already = line?.qty ?? 0;
                        if (! this.okayed.includes(p.id) && already + 1 > (Number(p.stock) || 0)) {
                            return this.ask(p);
                        }

                        if (line) {
                            line.qty++;
                        } else {
                            this.cart.push({ id: p.id, name: p.name, qty: 1, price: p.price, stock: p.stock, cost: p.cost });
                        }

                        this.showOnBill(p.id);
                        this.focusSearch();
                    },

                    // How many, and at what price — before it reaches the bill.
                    ask(p) {
                        this.asking = p;
                        this.askQty = 1;
                        this.askPrice = p.price;
                        this.$nextTick(() => { this.$refs.askQty.focus(); this.$refs.askQty.select(); });
                    },
                    cancelAsk() {
                        this.asking = null;
                        this.focusSearch();
                    },
                    confirmAsk() {
                        if (this.askUnderpriced) return;

                        const qty = Math.max(1, Math.floor(Number(this.askQty) || 0));
                        const price = Number(this.askPrice) || 0;
                        const p = this.asking;
                        if (! p) return;

                        // Answered once, for as long as this counter stays open.
                        if (this.askShortfall > 0) this.okayed.push(p.id);

                        const line = this.cart.find(l => l.id === p.id);
                        if (line) {
                            line.qty += qty;
                            line.price = price;
                        } else {
                            this.cart.push({ id: p.id, name: p.name, qty, price, stock: p.stock, cost: p.cost });
                        }

                        this.asking = null;
                        this.query = '';
                        this.highlight = 0;
                        this.showOnBill(p.id);
                        this.focusSearch();
                    },

                    clear() {
                        this.cart = [];
                        this.discount = this.received = this.customer = '';
                        this.owes = 0;
                        this.focusSearch();
                    },
                    save() {
                        if (this.cart.length === 0 || this.saving) return;
                        if (this.underpriced.length > 0) return;
                        @unless ($walkIns)
                            if (! this.customer) {
                                this.openCustomers();
                                return;
                            }
                        @endunless
                        this.saving = true;
                        // Hidden inputs are bound to the bill; let them settle first.
                        this.$nextTick(() => this.$refs.form.submit());
                    },
                };
            }
        </script>
    @endpush
</x-pos-layout>
