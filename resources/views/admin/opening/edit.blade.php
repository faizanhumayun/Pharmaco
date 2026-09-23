<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">Opening balance — {{ $business->name }}</h1>
        <p class="mt-1 text-sm text-gray-500">
            The financial position of the business <em>before</em> it started using this system.
        </p>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        @include('admin.opening._steps', ['current' => 2])
        <x-flash />

        <div class="mb-6 rounded-md border border-gray-200 bg-white px-4 py-4 text-sm text-gray-600 shadow-sm xl:hidden">
            <p class="font-medium text-gray-900">Before you fill this in</p>
            <p class="mt-1">
                Every figure this business will ever report is <strong>opening + transactions</strong>.
                If the opening is wrong, every later figure is wrong — and because the error is constant
                rather than growing, it is very hard to find later.
            </p>
            <ul class="mt-3 list-disc space-y-1 pl-5">
                <li>Stock counted physically and valued <strong>at cost</strong>, not at sale price</li>
                <li>Receivables and payables agreed against statements, not estimated</li>
                <li>Cash physically counted</li>
                <li>Every figure measured as at the <strong>same date</strong></li>
            </ul>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
        <form method="POST" action="{{ route('admin.businesses.opening.update', $business) }}">
            @csrf
            @method('PUT')

            <x-panel>
                <div class="space-y-6 p-4 sm:p-6">
                    @php($defaultDate = old('opening_date', $opening?->opening_date?->toDateString() ?? $business->today()->subDay()->toDateString()))
                    <div x-data="{ date: @js($defaultDate), today: @js($business->today()->toDateString()) }">
                        <x-input-label for="opening_date" value="Opening date" />
                        <x-text-input id="opening_date" name="opening_date" type="date" class="mt-1 block"
                                      x-model="date" :value="$defaultDate" required />
                        <p class="mt-1 text-xs text-gray-500">
                            The position is measured as at the close of business on this date.
                        </p>
                        {{-- Trading starts the day after, and the date cannot be changed
                             once finalized — so this is the moment to get it right. --}}
                        <p class="mt-1 text-xs text-amber-700" x-show="date >= today" x-cloak>
                            Trading days start the <strong>day after</strong> this date, so an opening date of
                            today leaves nothing enterable until tomorrow. Pick yesterday if you want to record
                            today's business. This cannot be changed once finalized.
                        </p>
                        <x-input-error :messages="$errors->get('opening_date')" class="mt-2" />
                    </div>

                    <div class="grid gap-5 border-t border-gray-200 pt-5 md:grid-cols-2 2xl:grid-cols-3">
                        @foreach ($fields as $field)
                            <div>
                                <div class="flex items-baseline justify-between gap-3">
                                    <x-input-label :for="$field->key()" :value="$field->label" />
                                    <span class="font-mono text-xs text-gray-400">{{ $field->key() }}</span>
                                </div>
                                <div class="relative mt-1">
                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">Rs.</span>
                                    <x-text-input :id="$field->key()" :name="$field->key()" type="text"
                                                  inputmode="decimal"
                                                  class="block w-full pl-10 text-right font-mono tabular-nums"
                                                  :value="old($field->key(), $values[$field->key()])"
                                                  placeholder="0.00" />
                                </div>
                                @if ($field->hint)
                                    <p class="mt-1 text-xs text-gray-500">{{ $field->hint }}</p>
                                @endif
                                <x-input-error :messages="$errors->get($field->key())" class="mt-2" />
                            </div>
                        @endforeach
                    </div>

                    <div class="border-t border-gray-200 pt-5">
                        <x-input-label for="notes" value="Notes" />
                        <textarea id="notes" name="notes" rows="3"
                                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">{{ old('notes', $opening?->notes) }}</textarea>
                        <p class="mt-1 text-xs text-gray-500">
                            Anything unusual about this position, and where the figures came from.
                        </p>
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-gray-200 bg-gray-50 px-4 py-3 sm:px-6">
                    <a href="{{ route('admin.businesses.show', $business) }}" class="text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</a>
                    <button class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                        Save and review
                    </button>
                </div>
            </x-panel>
        </form>

        <aside class="hidden xl:block">
            <div class="sticky top-6 rounded-md border border-gray-200 bg-white px-4 py-4 text-sm text-gray-600 shadow-sm">
                <p class="font-medium text-gray-900">Before you fill this in</p>
                <p class="mt-1">
                    Every figure this business will ever report is
                    <strong>opening + transactions</strong>. If the opening is wrong, every later
                    figure is wrong — and because the error is constant rather than growing, it is
                    very hard to find later.
                </p>
                <ul class="mt-3 list-disc space-y-1 pl-5">
                    <li>Stock counted physically and valued <strong>at cost</strong>, not at sale price</li>
                    <li>Receivables and payables agreed against statements, not estimated</li>
                    <li>Cash physically counted</li>
                    <li>Every figure measured as at the <strong>same date</strong></li>
                </ul>
                <p class="mt-3 text-xs text-gray-500">
                    Optional figures default to zero — but leaving out a real asset does not make it
                    disappear, it pushes it into the balancing figure instead.
                </p>
            </div>
        </aside>
        </div>
    </div>
</x-app-layout>
