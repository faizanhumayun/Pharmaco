<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">Review opening balance — {{ $business->name }}</h1>
        <p class="mt-1 text-sm text-gray-500">
            As at {{ $opening->opening_date->format('d F Y') }}. Nothing has been posted yet.
        </p>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        @include('admin.opening._steps', ['current' => 3])
        <x-flash />

        <div class="grid gap-6 xl:grid-cols-2">
        <div class="space-y-6">
        <x-panel>
            @include('admin.opening._position', ['position' => $position, 'fields' => $fields])
        </x-panel>

        @if ($position->isNegative())
            <div class="rounded-md border border-red-300 bg-red-50 px-4 py-4 text-sm text-red-900">
                <p class="font-semibold">This opening position is negative.</p>
                <p class="mt-1">
                    Liabilities exceed assets by <strong>{{ $position->netPosition()->absolute()->format(withCurrency: true) }}</strong>.
                    That is either a real finding the business owner needs to know about — trading
                    substantially on supplier credit — or the position is incomplete, most commonly
                    unrecorded fixed assets, cash held elsewhere, or stock valued below cost.
                </p>
                <p class="mt-2">Confirm it, or go back and revise the figures.</p>
            </div>
        @endif

        @if (! $position->balancingFigure()->isZero())
            <div class="rounded-md border {{ $position->needsExplanation() ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-gray-200 bg-white text-gray-600' }} px-4 py-4 text-sm">
                <p class="font-semibold">
                    Balancing figure: {{ $position->balancingFigure()->format(withCurrency: true) }}
                    @if ($position->balancingFigureRatio() !== null)
                        <span class="font-normal">({{ number_format($position->balancingFigureRatio() * 100, 1) }}% of total assets)</span>
                    @endif
                </p>
                <p class="mt-1">
                    The difference has to go somewhere for the entry to balance, so it posts to
                    <strong>3900 Opening Balance Equity</strong>.
                    @if ($position->needsExplanation())
                        It is large relative to the assets declared, so it needs an explanation before
                        this can be finalized — otherwise it becomes a silent plug that quietly distorts
                        the position on every screen from now on.
                    @else
                        It stays visible on the ledger until it is explained or corrected.
                    @endif
                </p>
            </div>
        @endif

        </div>

        <form method="POST" action="{{ route('admin.businesses.opening.finalize', $business) }}">
            @csrf
            <x-panel>
                <div class="space-y-5 p-4 sm:p-6">
                    <div>
                        <x-input-label for="notes" :value="$position->needsExplanation() ? 'Explanation (required)' : 'Notes'" />
                        <textarea id="notes" name="notes" rows="3"
                                  @required($position->needsExplanation())
                                  placeholder="e.g. Delivery van and shop deposit not included in the figures above"
                                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">{{ old('notes', $opening->notes) }}</textarea>
                        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                    </div>

                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="confirmed" value="1" required
                               class="mt-1 rounded border-gray-300 text-emerald-700 focus:ring-emerald-600">
                        <span class="text-sm text-gray-700">{{ $confirmation }}</span>
                    </label>
                    <x-input-error :messages="$errors->get('confirmed')" />

                    <p class="text-xs text-gray-500">
                        Finalizing posts the opening journal and activates the business.
                        There is no un-finalize: corrections afterwards are adjustment transactions,
                        recorded against whoever makes them.
                    </p>
                </div>

                <div class="flex items-center justify-between border-t border-gray-200 bg-gray-50 px-4 py-3 sm:px-6">
                    <a href="{{ route('admin.businesses.opening.edit', $business) }}"
                       class="text-sm font-medium text-gray-600 hover:text-gray-900">Revise figures</a>
                    <button class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                        Finalize opening balance
                    </button>
                </div>
            </x-panel>
        </form>
        </div>
    </div>
</x-app-layout>
