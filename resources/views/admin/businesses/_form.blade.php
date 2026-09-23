@php($b = $business ?? null)

<div class="grid gap-6 p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-3">
    <div class="sm:col-span-2 xl:col-span-3">
        <x-input-label for="name" value="Business name" />
        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                      :value="old('name', $b?->name)" required autofocus />
        <x-input-error :messages="$errors->get('name')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="business_type" value="Business type" />
        <select id="business_type" name="business_type"
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

    {{--
        A distributor sells the pack a company delivers; a pharmacy opens it and
        sells single tablets. Nothing about the money changes — only what every
        quantity on every screen is counting.
    --}}
    <div>
        <x-input-label for="stock_unit" value="How stock is counted" />
        <div class="mt-1 space-y-2">
            @foreach (\App\Enums\StockUnit::cases() as $unit)
                <label class="flex items-start gap-3 rounded-md border border-gray-200 px-3 py-2 hover:border-emerald-600">
                    <input type="radio" name="stock_unit" value="{{ $unit->value }}" class="mt-1 text-emerald-700 focus:ring-emerald-600"
                           @checked(old('stock_unit', $b?->stock_unit?->value ?? \App\Enums\StockUnit::defaultFor($b?->business_type ?? \App\Enums\BusinessType::Distributor)->value) === $unit->value)>
                    <span>
                        <span class="block text-sm font-medium text-gray-900">{{ $unit->label() }}</span>
                        <span class="block text-xs text-gray-500">{{ $unit->description() }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        <x-input-error :messages="$errors->get('stock_unit')" class="mt-2" />
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
