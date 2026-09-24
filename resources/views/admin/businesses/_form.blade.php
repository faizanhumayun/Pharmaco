@php($b = $business ?? null)

{{--
    The kind of business and how it counts stock are one decision made twice:
    picking Pharmacy moves the counting to loose items, until someone says
    otherwise — after which their choice stands.
--}}
<div class="grid gap-6 p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-3"
     x-data="{
         type: @js(old('business_type', $b?->business_type?->value ?? 'distributor')),
         unit: @js(old('stock_unit', $b?->stock_unit?->value ?? \App\Enums\StockUnit::defaultFor($b?->business_type ?? \App\Enums\BusinessType::Distributor)->value)),
         paper: @js(old('receipt_format', $b?->receipt_format?->value ?? \App\Enums\ReceiptFormat::defaultFor($b?->business_type ?? \App\Enums\BusinessType::Distributor)->value)),
         chosen: false,
         chosePaper: false,
         follow() {
             if (! this.chosen) this.unit = this.type === 'pharmacy' ? 'item' : 'pack';
             if (! this.chosePaper) this.paper = this.type === 'pharmacy' ? 'thermal' : 'a4';
         },
     }">
    <div class="sm:col-span-2 xl:col-span-3">
        <x-input-label for="name" value="Business name" />
        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                      :value="old('name', $b?->name)" required autofocus />
        <x-input-error :messages="$errors->get('name')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="business_type" value="Business type" />
        <select id="business_type" name="business_type" x-model="type" x-on:change="follow()"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
            @foreach ($types as $type)
                <option value="{{ $type->value }}"
                        @disabled(! $type->isAvailable())
                        @selected(old('business_type', $b?->business_type?->value ?? 'distributor') === $type->value)>
                    {{ $type->label() }}@unless ($type->isAvailable()) — coming soon @endunless
                </option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('business_type')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="currency" value="Currency" />
        <x-text-input id="currency" name="currency" type="text" maxlength="3"
                      class="mt-1 block w-full uppercase"
                      :value="old('currency', $b?->currency ?? 'PKR')" required
                      :disabled="$b?->opening_date !== null" />
        @if ($b?->opening_date !== null)
            <p class="mt-1 text-xs text-gray-500">Locked once the business has financial history.</p>
        @endif
        <x-input-error :messages="$errors->get('currency')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="timezone" value="Timezone" />
        <select id="timezone" name="timezone" @disabled($b?->opening_date !== null)
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600 disabled:bg-gray-50">
            @foreach ($timezones as $tz)
                <option value="{{ $tz }}" @selected(old('timezone', $b?->timezone ?? 'Asia/Karachi') === $tz)>{{ $tz }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-gray-500">
            Decides which calendar day a transaction belongs to.
            @if ($b?->opening_date !== null) Locked once the business has financial history. @endif
        </p>
        <x-input-error :messages="$errors->get('timezone')" class="mt-2" />
    </div>

    {{--
        A distributor sells the pack a company delivers; a pharmacy opens it and
        sells single tablets. Nothing about the money changes — only what every
        quantity on every screen is counting. Its own row: two choices side by
        side read as a pair, where a tall block wedged into one column of three
        leaves the other two stranded above a gap.
    --}}
    <div class="sm:col-span-2 xl:col-span-3">
        <x-input-label for="stock_unit" value="How stock is counted" />
        <div class="mt-1 grid gap-3 sm:grid-cols-2">
            @foreach (\App\Enums\StockUnit::cases() as $unit)
                {{-- The chosen card is marked as you pick it, not only after saving. --}}
                <label class="flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2.5"
                       :class="unit === @js($unit->value)
                           ? 'border-emerald-600 bg-emerald-50/60 ring-1 ring-emerald-600'
                           : 'border-gray-200 hover:border-gray-300'">
                    <input type="radio" name="stock_unit" value="{{ $unit->value }}" x-model="unit"
                           x-on:change="chosen = true"
                           class="mt-0.5 text-emerald-700 focus:ring-emerald-600">
                    <span>
                        <span class="block text-sm font-medium text-gray-900">{{ $unit->label() }}</span>
                        <span class="block text-xs leading-snug text-gray-500">{{ $unit->description() }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        <x-input-error :messages="$errors->get('stock_unit')" class="mt-2" />
    </div>

    {{-- What a bill prints on. Follows the business type the same way, and for
         the same reason: a distributor's bill is filed, a counter's is pocketed. --}}
    <div class="sm:col-span-2 xl:col-span-3">
        <x-input-label for="receipt_format" value="What a bill prints on" />
        <div class="mt-1 grid gap-3 sm:grid-cols-2">
            @foreach (\App\Enums\ReceiptFormat::cases() as $paper)
                <label class="flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2.5"
                       :class="paper === @js($paper->value)
                           ? 'border-emerald-600 bg-emerald-50/60 ring-1 ring-emerald-600'
                           : 'border-gray-200 hover:border-gray-300'">
                    <input type="radio" name="receipt_format" value="{{ $paper->value }}" x-model="paper"
                           x-on:change="chosePaper = true"
                           class="mt-0.5 text-emerald-700 focus:ring-emerald-600">
                    <span>
                        <span class="block text-sm font-medium text-gray-900">{{ $paper->label() }}</span>
                        <span class="block text-xs leading-snug text-gray-500">{{ $paper->description() }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        <p class="mt-1 text-xs text-gray-500">
            Either can be printed from any bill — this is only which one opens first.
        </p>
        <x-input-error :messages="$errors->get('receipt_format')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="ntn" value="NTN / tax number" />
        <x-text-input id="ntn" name="ntn" type="text" class="mt-1 block w-full" :value="old('ntn', $b?->ntn)" />
        <p class="mt-1 text-xs text-gray-500">Optional — this business is not sales-tax registered.</p>
    </div>

    <div>
        <x-input-label for="phone" value="Phone" />
        <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full" :value="old('phone', $b?->phone)" />
    </div>

    <div>
        <x-input-label for="email" value="Email" />
        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $b?->email)" />
        <x-input-error :messages="$errors->get('email')" class="mt-2" />
    </div>

    <div class="sm:col-span-2 xl:col-span-3">
        <x-input-label for="address" value="Address" />
        <x-text-input id="address" name="address" type="text" class="mt-1 block w-full" :value="old('address', $b?->address)" />
    </div>

    <div class="sm:col-span-2 xl:col-span-3">
        <x-input-label for="notes" value="Notes" />
        <textarea id="notes" name="notes" rows="3"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">{{ old('notes', $b?->notes) }}</textarea>
    </div>
</div>
