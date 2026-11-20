{{--
    What this business decides for itself.

    Two things, kept apart because they are answered by different people at
    different times: who the business is on paper, and how its counter behaves.
--}}
<x-workspace-layout :business="$business">
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Settings</h1>
            <p class="mt-1 text-sm text-gray-500">
                Your details as they appear on a bill, and how the counter prints and counts.
            </p>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        <form method="POST" action="{{ route('businesses.settings.update', $business) }}" class="grid gap-6 xl:grid-cols-3">
            @csrf
            @method('PUT')

            <div class="space-y-6 xl:col-span-2">
                <x-panel title="Business details"
                         description="Printed at the top of every bill and order form you send out.">
                    <div class="grid gap-4 p-4 sm:grid-cols-2 sm:p-6">
                        <div class="sm:col-span-2">
                            <x-input-label for="address" value="Address" />
                            <x-text-input id="address" name="address" type="text" class="mt-1 block w-full"
                                          :value="old('address', $business->address)"
                                          placeholder="Shop and street, town" maxlength="255" />
                            <x-input-error :messages="$errors->get('address')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="phone" value="Phone" />
                            <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full"
                                          :value="old('phone', $business->phone)" placeholder="0300 1234567" maxlength="40" />
                            <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="email" value="Email" />
                            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full"
                                          :value="old('email', $business->email)" maxlength="160" />
                            <x-input-error :messages="$errors->get('email')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="ntn" value="NTN / tax number" />
                            <x-text-input id="ntn" name="ntn" type="text" class="mt-1 block w-full"
                                          :value="old('ntn', $business->ntn)" maxlength="40" />
                            <x-input-error :messages="$errors->get('ntn')" class="mt-1" />
                        </div>
                    </div>

                    {{-- A bill with nothing but a name on it is the thing people
                         notice only once it has gone out. --}}
                    @unless ($business->address || $business->phone || $business->email || $business->ntn)
                        <p class="border-t border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:px-6">
                            Your bills currently carry only your name — no address, phone or NTN.
                        </p>
                    @endunless
                </x-panel>

                <x-panel title="The counter" description="How bills print.">
                    <div class="space-y-6 p-4 sm:p-6">
                        <div>
                            <x-input-label value="Printer" />
                            <p class="text-xs text-gray-500">Which bill opens first. Either can be printed from any bill.</p>
                            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                @foreach ($formats as $format)
                                    <label class="flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2.5
                                                  {{ old('receipt_format', $business->receiptFormat()->value) === $format->value
                                                      ? 'border-emerald-600 bg-emerald-50/60 ring-1 ring-emerald-600'
                                                      : 'border-gray-200 hover:border-gray-300' }}">
                                        <input type="radio" name="receipt_format" value="{{ $format->value }}"
                                               @checked(old('receipt_format', $business->receiptFormat()->value) === $format->value)
                                               class="mt-0.5 text-emerald-700 focus:ring-emerald-600">
                                        <span>
                                            <span class="block text-sm font-medium text-gray-900">{{ $format->label() }}</span>
                                            <span class="block text-xs leading-snug text-gray-500">{{ $format->description() }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <x-input-error :messages="$errors->get('receipt_format')" class="mt-1" />
                        </div>

                        {{-- The colour of this business's own screens, from a fixed
                             set. Each swatch wears its own colour by carrying that
                             colour's class, so no colour is written here. --}}
                        <div x-data="{ accent: @js(old('accent', $business->accentColour()->value)) }">
                            <x-input-label value="Colour" />
                            <p class="text-xs text-gray-500">
                                Buttons, links and highlights across your screens, including the counter.
                                Red and amber are kept for warnings, and blue for receiving stock, so they are not offered.
                            </p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach (\App\Enums\AccentColour::cases() as $colour)
                                    <label class="accent-{{ $colour->value }} cursor-pointer">
                                        <input type="radio" name="accent" value="{{ $colour->value }}" x-model="accent" class="sr-only">
                                        <span class="flex items-center gap-2 rounded-md border bg-white px-3 py-2 text-sm"
                                              :class="accent === '{{ $colour->value }}' ? 'border-emerald-700 ring-1 ring-emerald-700 font-semibold text-emerald-800' : 'border-gray-300 text-gray-700 hover:border-gray-400'">
                                            <span class="h-5 w-5 rounded-full bg-emerald-700"></span>
                                            {{ $colour->label() }}
                                            @if ($colour === \App\Enums\AccentColour::defaultFor($business->business_type))
                                                <span class="text-xs font-normal text-gray-400">usual</span>
                                            @endif
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <x-input-error :messages="$errors->get('accent')" class="mt-1" />
                        </div>

                        {{-- The foot of a till slip, in the business's own words:
                             a small editor, shown at the width and size it prints. --}}
                        <div x-data="{
                                html: @js(old('receipt_footer', $business->receipt_footer) ?? ''),
                                init() { this.$refs.editor.innerHTML = this.html; },
                                sync() { this.html = this.$refs.editor.innerHTML; },
                                mark(command) { this.$refs.editor.focus(); document.execCommand(command, false); this.sync(); },
                                reset() { this.$refs.editor.innerHTML = ''; this.sync(); this.$refs.editor.focus(); },
                                get empty() { return this.html.replace(/<[^>]*>|&nbsp;|\s/g, '') === ''; },
                             }">
                            <x-input-label value="Foot of the bill" />
                            <p class="text-xs text-gray-500">
                                Printed at the bottom of every till slip — a thank-you, opening hours, a returns
                                policy. Leave it empty for the usual line.
                            </p>

                            <input type="hidden" name="receipt_footer" :value="html">

                            <div class="mt-2 w-full max-w-md overflow-hidden rounded-md border border-gray-300 bg-white shadow-sm focus-within:border-emerald-600 focus-within:ring-1 focus-within:ring-emerald-600">
                                <div class="flex items-center gap-1 border-b border-gray-200 bg-gray-50 px-2 py-1.5">
                                    @foreach (['bold' => ['B', 'font-bold', 'Bold'], 'italic' => ['I', 'italic', 'Italic'], 'underline' => ['U', 'underline', 'Underline']] as $command => [$letter, $style, $title])
                                        <button type="button" x-on:mousedown.prevent x-on:click="mark('{{ $command }}')" title="{{ $title }}"
                                                class="flex h-7 w-7 items-center justify-center rounded text-sm text-gray-700 hover:bg-gray-200 {{ $style }}">{{ $letter }}</button>
                                    @endforeach
                                    <span class="mx-1 h-4 w-px bg-gray-300"></span>
                                    <button type="button" x-on:mousedown.prevent x-on:click="mark('insertUnorderedList')" title="Bullet points — click again to turn them off"
                                            class="flex h-7 items-center gap-1 rounded px-2 text-xs font-medium text-gray-700 hover:bg-gray-200">
                                        <span class="text-base leading-none">•</span> Bullets
                                    </button>
                                    <button type="button" x-on:mousedown.prevent x-on:click="mark('insertLineBreak')" title="New line — the Enter key does the same"
                                            class="flex h-7 items-center gap-1 rounded px-2 text-xs font-medium text-gray-700 hover:bg-gray-200">
                                        <span class="text-base leading-none">↵</span> New line
                                    </button>
                                    <button type="button" x-on:click="reset()"
                                            class="ml-auto rounded px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-200 hover:text-gray-800">Clear</button>
                                </div>

                                {{-- As on the slip: 80mm wide, centred, small. --}}
                                <div class="relative bg-gray-50 px-3 py-3">
                                    <div x-ref="editor" contenteditable="true" x-on:input="sync()" x-on:blur="sync()"
                                         x-on:paste.prevent="document.execCommand('insertText', false, $event.clipboardData.getData('text/plain')); sync()"
                                         role="textbox" aria-multiline="true" aria-label="Foot of the bill"
                                         class="mx-auto min-h-[4.5rem] w-[80mm] max-w-full bg-white px-3 py-2 text-center text-[11px] font-medium leading-snug text-gray-900 shadow-sm ring-1 ring-slate-200 focus:outline-none [&_ul]:list-disc [&_ul]:pl-4 [&_ul]:text-left [&_ol]:list-decimal [&_ol]:pl-4 [&_ol]:text-left"></div>
                                    <p x-show="empty" class="pointer-events-none absolute inset-x-0 top-5 text-center text-[11px] font-medium text-gray-400">
                                        {{ \App\Models\Business::DEFAULT_RECEIPT_FOOTER }}
                                    </p>
                                </div>
                            </div>
                            <x-input-error :messages="$errors->get('receipt_footer')" class="mt-1" />
                        </div>

                        {{-- How the till starts each bill. The counter can still
                             switch any of these for one bill; the next bill starts
                             here again. --}}
                        <div>
                            <x-input-label value="At the counter, for every bill" />
                            <p class="text-xs text-gray-500">
                                What the till does unless told otherwise. Whoever is at the counter can turn any of
                                these on or off for a single bill with the switches at the top of the till.
                            </p>
                            <div class="mt-2 space-y-2">
                                @foreach ([
                                    'pos_print' => ['Print', 'After saving, show the bill ready to print.', true],
                                    'pos_net_bill' => ['Net ' . \App\Enums\ReceiptFormat::TRADE_DISCOUNT_PERCENT . '% bill', 'Quote prices before the ' . \App\Enums\ReceiptFormat::TRADE_DISCOUNT_PERCENT . '% trade discount; the amount payable is the same.', \App\Enums\ReceiptFormat::NET_BILL_AVAILABLE],
                                    'pos_balance' => ['Balance', 'After saving, show the customer\'s account.', true],
                                ] as $switch => [$title, $does, $offered])
                                    @continue(! $offered)
                                    <label class="flex items-start gap-3 rounded-md border border-gray-200 px-3 py-2">
                                        <input type="hidden" name="{{ $switch }}" value="0">
                                        <input type="checkbox" name="{{ $switch }}" value="1" @checked(old($switch, $business->{$switch}))
                                               class="mt-0.5 h-4 w-4 rounded border-gray-300 text-emerald-700 focus:ring-emerald-600">
                                        <span>
                                            <span class="block text-sm font-medium text-gray-900">{{ $title }}</span>
                                            <span class="block text-xs text-gray-500">{{ $does }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        {{-- How much notice the counter gives before a batch is out
                             of date. What "soon" means is a trade judgement: a
                             distributor will not take short-dated goods at all,
                             while a counter may only care weeks ahead. --}}
                        <div>
                            <x-input-label for="expiry_alert_months" value="Warn about expiry" />
                            <p class="text-xs text-gray-500">
                                Stock expiring within this long is coloured at the counter, so it can be
                                moved to the near-expiry shelf instead of being sold by mistake.
                            </p>
                            <select id="expiry_alert_months" name="expiry_alert_months"
                                    class="mt-2 block w-full max-w-xs rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                @foreach ([3 => '3 months ahead', 6 => '6 months ahead', 9 => '9 months ahead', 12 => '12 months ahead', 18 => '18 months ahead'] as $months => $label)
                                    <option value="{{ $months }}" @selected((string) old('expiry_alert_months', $business->expiryAlertMonths()) === (string) $months)>{{ $label }}</option>
                                @endforeach
                                <option value="" @selected(old('expiry_alert_months', $business->expiryAlertMonths()) === null)>Do not warn</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">
                                Stock already out of date is always shown in red, whatever this says.
                            </p>
                            <x-input-error :messages="$errors->get('expiry_alert_months')" class="mt-1" />
                        </div>

                    </div>

                    <div class="flex items-center justify-end gap-3 border-t border-gray-200 px-4 py-3 sm:px-6">
                        <button class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                            Save settings
                        </button>
                    </div>
                </x-panel>
            </div>

            {{-- What this screen deliberately cannot change. --}}
            <div class="xl:col-span-1">
                <x-panel title="Set in the back office"
                         description="These decide how the books are kept, so they are not changed from inside the business.">
                    <dl class="divide-y divide-gray-100 text-sm">
                        @foreach ([
                            'Name' => $business->name,
                            'Kind of business' => $business->business_type->label(),
                            // Not a choice: a distributor moves whole packs and
                            // cartons, a pharmacy breaks them open. It follows
                            // from the kind of business and nothing else.
                            'Stock counted in' => $business->unit()->many(),
                            'Currency' => $business->currency,
                            'Timezone' => $business->timezone,
                            'Opening date' => $business->opening_date?->format('j M Y') ?? 'not set',
                            'Closed through' => $business->locked_through_date?->format('j M Y') ?? '—',
                        ] as $label => $value)
                            <div class="flex items-baseline justify-between gap-3 px-4 py-2.5 sm:px-6">
                                <dt class="text-gray-500">{{ $label }}</dt>
                                <dd class="text-right font-medium text-gray-900">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="px-4 py-3 text-xs leading-relaxed text-gray-500 sm:px-6">
                        The timezone decides which day a sale falls on, so it is frozen once a
                        business has any history at all.
                    </p>
                </x-panel>
            </div>
        </form>
    
        {{-- A form of its own: which ways of being paid this business uses.
             The list is the platform's; the choice, and the account title that
             prints for each, are this business's. --}}
        <form method="POST" action="{{ route('businesses.settings.payment-methods', $business) }}" class="mt-6"
              x-data="{ bank: {{ $mine->filter(fn ($m) => $m->is_active && ($paymentMethods['bank'] ?? collect())->contains('id', $m->payment_method_id))->isNotEmpty() ? 'true' : 'false' }} }">
            @csrf
            @method('PUT')

            <x-panel title="Payment methods"
                     description="How customers can pay besides cash. Money taken this way is kept in its own account in your books, not in the drawer, so the cash count stays right.">
                <div class="space-y-5 p-4 sm:p-6">
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($paymentMethods['wallet'] ?? [] as $method)
                            @php($on = (bool) ($mine[$method->id]->is_active ?? false))
                            <label class="flex items-start gap-3 rounded-md border border-gray-200 px-3 py-2.5 hover:border-gray-300">
                                <input type="checkbox" name="methods[]" value="{{ $method->id }}" @checked($on)
                                       class="mt-1 rounded text-emerald-700 focus:ring-emerald-600">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-gray-900">{{ $method->name }}</span>
                                    <input type="text" name="titles[{{ $method->id }}]" value="{{ $mine[$method->id]->title ?? '' }}"
                                           placeholder="Account number (optional)" maxlength="160"
                                           class="mt-1 block w-full rounded-md border-gray-300 py-1 text-xs shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                </span>
                            </label>
                        @endforeach

                        <label class="flex items-start gap-3 rounded-md border border-gray-200 px-3 py-2.5 hover:border-gray-300">
                            <input type="checkbox" x-model="bank" class="mt-1 rounded text-emerald-700 focus:ring-emerald-600">
                            <span>
                                <span class="block text-sm font-medium text-gray-900">Bank transfer</span>
                                <span class="block text-xs text-gray-500">Choose the banks you hold accounts with.</span>
                            </span>
                        </label>
                    </div>

                    <div x-show="bank" x-cloak>
                        <p class="text-sm font-medium text-gray-900">Your banks</p>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach ($paymentMethods['bank'] ?? [] as $method)
                                @php($on = (bool) ($mine[$method->id]->is_active ?? false))
                                <label class="flex items-start gap-3 rounded-md border border-gray-200 px-3 py-2 hover:border-gray-300">
                                    {{-- Unticking "Bank transfer" switches every bank off. --}}
                                    <input type="checkbox" name="methods[]" value="{{ $method->id }}" @checked($on) :disabled="! bank"
                                           class="mt-1 rounded text-emerald-700 focus:ring-emerald-600">
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm text-gray-900">{{ $method->name }}</span>
                                        <input type="text" name="titles[{{ $method->id }}]" value="{{ $mine[$method->id]->title ?? '' }}"
                                               placeholder="Account title / number (optional)" maxlength="160"
                                               class="mt-1 block w-full rounded-md border-gray-300 py-1 text-xs shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3 border-t border-gray-200 px-4 py-3 sm:px-6">
                    <p class="text-xs text-gray-500">Switching one off hides it at the counter. What was taken through it stays in the books.</p>
                    <button class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                        Save payment methods
                    </button>
                </div>
            </x-panel>
        </form>
    </div>
</x-workspace-layout>
