{{--
    What the counter asked to see after a sale.

    One window, not two: with both switches on, the bill and the account belong
    to the same moment and stacking two dialogs on top of each other would make
    the operator dismiss twice before the next customer.

    Escape or "Next customer" closes it; nothing here changes anything, so
    closing is always safe.
--}}
@php($rs = fn ($m) => $m->format())
@php($bill = $saved['bill'])
@php($account = $saved['account'])

<div x-data="{ open: true }" x-show="open" x-cloak
     x-on:keydown.escape.window="open = false"
     class="print-scroll fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4">

    <div class="my-auto w-full {{ $saved['print'] ? 'max-w-2xl' : 'max-w-md' }} rounded-2xl bg-white shadow-xl">

        <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-3">
            <div>
                <p class="text-sm text-slate-500">Saved</p>
                <p class="text-lg font-semibold text-slate-900">
                    Bill {{ $bill->reference() }} · Rs. {{ $rs($bill->total) }}
                </p>
            </div>
            <button type="button" x-on:click="open = false"
                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Close">
                &times;
            </button>
        </div>

        <div class="print-scroll max-h-[70vh] overflow-y-auto px-5 py-4">

            @if ($account !== null)
                {{-- The account, as it stands and as it stood. --}}
                <div class="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <p class="text-sm font-semibold text-slate-900">{{ $account['customer']->name }}</p>
                    @if ($account['customer']->area)
                        <p class="text-xs text-slate-500">{{ $account['customer']->area }}</p>
                    @endif

                    <dl class="mt-3 space-y-1.5 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-600">Balance before this bill</dt>
                            <dd class="tabular-nums text-slate-900">Rs. {{ $rs($account['before']) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-600">This bill</dt>
                            <dd class="tabular-nums text-slate-900">Rs. {{ $rs($bill->total) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-600">Paid now</dt>
                            <dd class="tabular-nums text-slate-900">− Rs. {{ $rs($bill->received) }}</dd>
                        </div>
                        <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-bold">
                            <dt class="text-slate-900">Owes now</dt>
                            <dd class="tabular-nums {{ $account['owes']->isPositive() ? 'text-amber-800' : 'text-emerald-800' }}">
                                Rs. {{ $rs($account['owes']) }}
                            </dd>
                        </div>
                    </dl>

                    @unless ($account['unposted']->isZero())
                        {{-- Said plainly rather than folded in silently: a day
                             still being entered has not reached the ledger. --}}
                        <p class="mt-3 border-t border-slate-200 pt-2 text-xs leading-relaxed text-slate-500">
                            Rs. {{ $rs($account['posted']) }} is on the books; the remaining
                            Rs. {{ $rs($account['unposted']) }} is from bills on days not yet posted,
                            including this one.
                        </p>
                    @endunless

                    <a href="{{ route('businesses.pharmacies.show', [$business, $account['customer']]) }}"
                       class="mt-3 inline-block text-sm font-medium text-emerald-700 hover:text-emerald-800">
                        Their full account →
                    </a>
                </div>
            @elseif ($saved['account'] === null && ! $saved['print'])
                <p class="text-sm text-slate-500">
                    This bill has no customer account, so there is no balance to show.
                </p>
            @endif

            @if ($saved['print'])
                {{-- The bill itself, exactly as it will print. --}}
                <div class="{{ $account !== null ? 'mt-4 border-t border-slate-200 pt-4' : '' }}">
                    <div class="print-area">
                        @include('business.pos.slips.' . ($saved['net'] ? 'net' : $format->value), [
                            'customer' => $account['customer'] ?? null,
                            'owed' => $account['owes'] ?? null,
                        ])
                    </div>
                </div>
            @endif
        </div>

        <div class="flex items-center justify-between gap-3 border-t border-slate-200 px-5 py-3">
            @if ($saved['print'])
                <a href="{{ route('businesses.pos.receipt', array_filter([
                        'business' => $business, 'posBill' => $bill, 'net' => $saved['net'] ? 1 : null,
                   ])) }}"
                   class="text-sm font-medium text-slate-600 hover:text-slate-900">Open full bill</a>
            @else
                <span></span>
            @endif

            <div class="flex items-center gap-2">
                {{-- Only offered when the counter asked for the bill: printing
                     an account summary is not what the Print switch is for. --}}
                @if ($saved['print'])
                    <button type="button" onclick="window.printSlip()"
                            class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Print {{ $format->short() }}
                    </button>
                @endif
                <button type="button" x-on:click="open = false"
                        class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                    Next customer
                </button>
            </div>
        </div>
    </div>
</div>
