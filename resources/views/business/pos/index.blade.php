{{--
    The counter.

    One screen, filling the display: search at the top, the bill in the middle,
    what is owed on the right. Everything is reachable from the keyboard —
    type or scan, Enter adds, F9 saves — because a hand on the mouse is a queue
    at the till.

    The whole catalogue is sent with the page: searching has to answer between
    keystrokes, and a round trip per letter cannot.
--}}
@php($rs = fn ($m) => $m->format())

<x-pos-layout :business="$business">
    <div class="flex h-full flex-col" x-data="counter()"
         x-on:keydown.window.f2.prevent="focusSearch()" x-on:keydown.window.f9.prevent="save()">

        {{-- Who, where, which bill, and the clock. --}}
        <header class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 bg-slate-900 px-4 py-3 text-white sm:px-6">
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
                <div class="text-right">
                    <p class="text-[11px] uppercase tracking-wide text-slate-400">Sold today</p>
                    <p class="text-lg font-semibold leading-tight tabular-nums">
                        Rs. {{ $rs(\App\Support\Money::sum($soldToday->pluck('total'))) }}
                        <span class="text-xs font-normal text-slate-400">{{ $soldToday->count() }} {{ Str::plural('bill', $soldToday->count()) }}</span>
                    </p>
                </div>
                <a href="{{ route('businesses.show', $business) }}"
                   class="rounded-md border border-slate-600 px-3 py-1.5 text-sm font-medium text-slate-200 hover:bg-slate-800">
                    Leave counter
                </a>
            </div>
        </header>

        @if ($dayClosed)
            <div class="bg-red-600 px-4 py-2 text-center text-sm font-medium text-white sm:px-6">
                {{ $today->format('D d M Y') }} is closed — nothing can be sold onto it. Reopen the day first.
            </div>
        @endif

        @if (session('status'))
            <div class="bg-emerald-600 px-4 py-2 text-center text-sm font-medium text-white sm:px-6">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="bg-red-600 px-4 py-2 text-center text-sm font-medium text-white sm:px-6">{{ $errors->first() }}</div>
        @endif

        {{-- The form carries the cart; the screen never posts anything else. --}}
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
        </form>

        <div class="grid min-h-0 flex-1 grid-cols-1 gap-4 p-4 lg:grid-cols-[1fr_22rem] sm:p-6">

            {{-- Search, then the bill. --}}
            <div class="flex min-h-0 flex-col gap-4">
                <div class="relative shrink-0">
                    <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400"
                         viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="9" cy="9" r="6" /><path d="M14 14l4 4" stroke-linecap="round" />
                    </svg>
                    <input x-ref="search" x-model="query" type="text" autocomplete="off" autofocus
                           x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)"
                           x-on:keydown.enter.prevent="addHighlighted()" x-on:keydown.escape="query = ''"
                           placeholder="Scan a barcode, or type a product name…"
                           class="block w-full rounded-xl border-0 py-4 pl-12 pr-24 text-lg shadow-sm ring-1 ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-emerald-600">
                    <span class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 rounded border border-slate-300 px-1.5 py-0.5 text-xs text-slate-400">F2</span>

                    <div x-show="query.trim() !== ''" x-cloak
                         class="absolute z-20 mt-2 max-h-96 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl">
                        <template x-for="(p, i) in matches" :key="p.id">
                            <button type="button" x-on:click="add(p)" x-on:mouseenter="highlight = i"
                                    :class="highlight === i ? 'bg-emerald-50' : ''"
                                    class="flex w-full items-center justify-between gap-4 border-b border-slate-100 px-4 py-3 text-left last:border-0">
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-slate-900" x-text="p.name"></span>
                                    <span class="block truncate text-xs text-slate-500" x-text="[p.company, p.code].filter(Boolean).join(' · ')"></span>
                                </span>
                                <span class="shrink-0 text-right">
                                    <span class="block font-semibold tabular-nums text-slate-900" x-text="'Rs. ' + money(p.price)"></span>
                                    <span class="block text-xs tabular-nums" :class="p.stock > 0 ? 'text-slate-500' : 'text-red-600'"
                                          x-text="p.stock + ' in stock'"></span>
                                </span>
                            </button>
                        </template>
                        <p x-show="matches.length === 0" class="px-4 py-4 text-slate-500">
                            Nothing matches “<span x-text="query"></span>”.
                        </p>
                    </div>
                </div>

                <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                    <div class="grid shrink-0 grid-cols-[1fr_9rem_7rem_7rem_2.5rem] gap-2 border-b border-slate-200 bg-slate-50 px-4 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                        <span>Item</span><span class="text-center">Quantity</span><span class="text-right">Price</span><span class="text-right">Total</span><span></span>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <template x-for="(line, i) in cart" :key="line.id">
                            <div class="grid grid-cols-[1fr_9rem_7rem_7rem_2.5rem] items-center gap-2 border-b border-slate-100 px-4 py-2">
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-slate-900" x-text="line.name"></span>
                                    <span x-show="line.qty > line.stock" x-cloak class="text-xs text-amber-700"
                                          x-text="'only ' + line.stock + ' in stock'"></span>
                                </span>
                                <span class="flex items-center justify-center gap-1">
                                    <button type="button" x-on:click="line.qty = Math.max(1, line.qty - 1)"
                                            class="h-8 w-8 rounded-lg bg-slate-100 text-lg leading-none text-slate-700 hover:bg-slate-200">−</button>
                                    <input type="text" inputmode="numeric" x-model.number="line.qty"
                                           class="w-14 rounded-lg border-slate-300 py-1 text-center tabular-nums">
                                    <button type="button" x-on:click="line.qty++"
                                            class="h-8 w-8 rounded-lg bg-slate-100 text-lg leading-none text-slate-700 hover:bg-slate-200">+</button>
                                </span>
                                <input type="text" inputmode="decimal" x-model.number="line.price"
                                       class="w-full rounded-lg border-slate-300 py-1 text-right tabular-nums">
                                <span class="text-right font-semibold tabular-nums text-slate-900" x-text="money(line.qty * line.price)"></span>
                                <button type="button" x-on:click="cart.splice(i, 1)"
                                        class="justify-self-end rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-700" title="Remove">
                                    &times;
                                </button>
                            </div>
                        </template>

                        <div x-show="cart.length === 0" class="flex h-full flex-col items-center justify-center gap-2 py-16 text-center">
                            <p class="text-lg font-medium text-slate-400">Bill is empty</p>
                            <p class="text-sm text-slate-400">Scan a barcode or search to add the first item.</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- What is owed, and what was taken. --}}
            <div class="flex min-h-0 flex-col gap-3 overflow-y-auto rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <div class="flex items-baseline justify-between text-sm">
                    <span class="text-slate-500">Items</span>
                    <span class="tabular-nums text-slate-900" x-text="cart.reduce((n, l) => n + Number(l.qty || 0), 0)"></span>
                </div>
                <div class="flex items-baseline justify-between text-sm">
                    <span class="text-slate-500">Subtotal</span>
                    <span class="tabular-nums text-slate-900" x-text="'Rs. ' + money(subtotal)"></span>
                </div>

                <label class="block">
                    <span class="text-sm text-slate-500">Discount</span>
                    <input x-model.number="discount" type="text" inputmode="decimal" placeholder="0.00"
                           class="mt-1 block w-full rounded-lg border-slate-300 py-1.5 text-right tabular-nums focus:border-emerald-600 focus:ring-emerald-600">
                </label>

                <div class="rounded-xl bg-slate-900 px-4 py-3 text-white">
                    <p class="text-xs uppercase tracking-wide text-slate-400">To pay</p>
                    <p class="text-3xl font-bold tabular-nums" x-text="'Rs. ' + money(total)"></p>
                </div>

                <label class="block">
                    <span class="text-sm text-slate-500">Cash received</span>
                    <input x-model.number="received" type="text" inputmode="decimal" placeholder="0.00"
                           class="mt-1 block w-full rounded-lg border-slate-300 py-2 text-right text-xl tabular-nums focus:border-emerald-600 focus:ring-emerald-600">
                </label>

                <div class="flex flex-wrap gap-1">
                    <button type="button" x-on:click="received = total"
                            class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Exact</button>
                    <template x-for="note in [100, 500, 1000, 5000]" :key="note">
                        <button type="button" x-on:click="received = (Number(received) || 0) + note"
                                class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-200"
                                x-text="'+' + note"></button>
                    </template>
                    <button type="button" x-on:click="received = ''"
                            class="rounded-lg px-2 py-1.5 text-xs text-slate-500 hover:text-slate-800">clear</button>
                </div>

                <div x-show="Number(received || 0) > 0" x-cloak
                     class="flex items-baseline justify-between rounded-lg px-3 py-2"
                     :class="change >= 0 ? 'bg-emerald-50' : 'bg-amber-50'">
                    <span class="text-sm font-medium" :class="change >= 0 ? 'text-emerald-800' : 'text-amber-800'"
                          x-text="change >= 0 ? 'Change' : 'On credit'"></span>
                    <span class="text-xl font-bold tabular-nums" :class="change >= 0 ? 'text-emerald-800' : 'text-amber-800'"
                          x-text="'Rs. ' + money(Math.abs(change))"></span>
                </div>

                <label class="block" x-show="change < 0 || Number(received || 0) === 0" x-cloak>
                    <span class="text-sm text-slate-500">Customer (for what is owed)</span>
                    <input x-model="customer" type="text" maxlength="120" placeholder="Name"
                           class="mt-1 block w-full rounded-lg border-slate-300 py-1.5 focus:border-emerald-600 focus:ring-emerald-600">
                </label>

                <div class="mt-auto space-y-2 pt-2">
                    <button type="button" x-on:click="save()"
                            :disabled="cart.length === 0 || saving || {{ $dayClosed ? 'true' : 'false' }}"
                            class="w-full rounded-xl bg-emerald-600 px-4 py-4 text-lg font-bold text-white shadow-sm hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-40">
                        <span x-text="saving ? 'Saving…' : 'Save bill'"></span>
                        <span class="ml-1 text-sm font-normal text-emerald-100">F9</span>
                    </button>
                    <button type="button" x-on:click="clear()" x-show="cart.length > 0" x-cloak
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        Clear the bill
                    </button>
                </div>
            </div>
        </div>

        {{-- Last bills, and the way back to the day they are on. --}}
        <footer class="flex shrink-0 items-center gap-4 overflow-x-auto border-t border-slate-200 bg-white px-4 py-2 text-sm sm:px-6">
            <span class="shrink-0 text-xs font-semibold uppercase tracking-wide text-slate-400">Last bills</span>
            @forelse ($recent as $bill)
                <a href="{{ route('businesses.pos.receipt', [$business, $bill]) }}"
                   class="shrink-0 rounded-lg border border-slate-200 px-3 py-1 hover:border-emerald-600 hover:text-emerald-700">
                    <span class="font-mono font-medium">{{ $bill->reference() }}</span>
                    <span class="ml-1 tabular-nums text-slate-500">Rs. {{ $rs($bill->total) }}</span>
                    <span class="ml-1 text-xs text-slate-400">{{ $bill->created_at->timezone($business->timezone)->format('h:i A') }}</span>
                </a>
            @empty
                <span class="text-slate-400">No bills yet today.</span>
            @endforelse
            <a href="{{ route('businesses.daily.index', $business) }}"
               class="ml-auto shrink-0 text-slate-500 hover:text-emerald-700">Today's entry →</a>
        </footer>
    </div>

    @push('scripts')
        <script>
            function counter() {
                return {
                    products: @json($products),
                    cart: [],
                    query: '',
                    highlight: 0,
                    discount: '',
                    received: '',
                    customer: '',
                    saving: false,
                    clock: '',

                    init() {
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

                    // Name first, then barcode. A scanner sends the code and an
                    // Enter, so an exact code match jumps straight onto the bill.
                    get matches() {
                        const q = this.query.trim().toLowerCase();
                        if (q === '') return [];
                        const exact = this.products.filter(p => (p.code || '').toLowerCase() === q);
                        if (exact.length) return exact;
                        return this.products
                            .filter(p => p.name.toLowerCase().includes(q) || (p.code || '').toLowerCase().includes(q))
                            .slice(0, 25);
                    },

                    get subtotal() {
                        return this.cart.reduce((n, l) => n + (Number(l.qty) || 0) * (Number(l.price) || 0), 0);
                    },
                    get total() {
                        return Math.max(0, this.subtotal - (Number(this.discount) || 0));
                    },
                    get change() {
                        return (Number(this.received) || 0) - this.total;
                    },

                    focusSearch() { this.$refs.search.focus(); this.$refs.search.select(); },
                    move(step) {
                        if (! this.matches.length) return;
                        this.highlight = (this.highlight + step + this.matches.length) % this.matches.length;
                    },
                    addHighlighted() {
                        const p = this.matches[this.highlight] ?? this.matches[0];
                        if (p) this.add(p);
                    },
                    add(p) {
                        const line = this.cart.find(l => l.id === p.id);
                        if (line) {
                            line.qty++;
                        } else {
                            this.cart.push({ id: p.id, name: p.name, qty: 1, price: p.price, stock: p.stock });
                        }
                        this.query = '';
                        this.highlight = 0;
                        this.focusSearch();
                    },
                    clear() {
                        this.cart = [];
                        this.discount = this.received = this.customer = '';
                        this.focusSearch();
                    },
                    save() {
                        if (this.cart.length === 0 || this.saving) return;
                        this.saving = true;
                        // Hidden inputs are bound to the cart; let them settle first.
                        this.$nextTick(() => this.$refs.form.submit());
                    },
                };
            }
        </script>
    @endpush
</x-pos-layout>
