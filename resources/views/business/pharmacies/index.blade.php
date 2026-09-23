<x-workspace-layout :business="$business">
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">Pharmacies</h1>
        <p class="mt-1 text-sm text-gray-500">
            Each pharmacy gets its own ledger under Market Receivables.
        </p>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        <div class="mb-6 flex flex-wrap items-center gap-3 rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Total receivable</span>
            <span class="font-mono text-lg font-semibold tabular-nums text-gray-900">{{ $total->format() }}</span>
            <x-badge :classes="$reconciles ? 'bg-emerald-50 text-emerald-800 ring-emerald-600/20' : 'bg-red-50 text-red-800 ring-red-600/20'">
                {{ $reconciles ? 'Equals the sum of pharmacy ledgers ✓' : 'Does not reconcile' }}
            </x-badge>
            @unless ($unallocated->isZero())
                <span class="text-sm text-gray-500">
                    of which <span class="font-mono tabular-nums">{{ $unallocated->format() }}</span> unallocated
                </span>
            @endunless
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="xl:col-span-2">
                <x-panel title="Pharmacy ledgers">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    @foreach (['Pharmacy', 'Account', 'Area', 'Credit days', 'Owes'] as $h)
                                        <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 {{ $h === 'Owes' ? 'text-right' : 'text-left' }} {{ $loop->first ? 'sm:px-6' : '' }}">{{ $h }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($pharmacies as $pharmacy)
                                    <tr>
                                        <td class="px-4 py-2 sm:px-6">
                                            <a href="{{ route('businesses.pharmacies.show', [$business, $pharmacy]) }}"
                                               class="font-medium text-gray-900 hover:text-emerald-700">{{ $pharmacy->name }}</a>
                                        </td>
                                        <td class="px-4 py-2 font-mono text-xs text-gray-500">{{ $pharmacy->account?->code ?? '—' }}</td>
                                        <td class="px-4 py-2 text-gray-600">{{ $pharmacy->area ?? '—' }}</td>
                                        <td class="px-4 py-2 text-gray-600">{{ $pharmacy->credit_days ?? '—' }}</td>
                                        {{-- A pharmacy in credit has paid ahead; showing it red would read as a problem. --}}
                                        <td class="px-4 py-2 text-right font-mono tabular-nums {{ $balances[$pharmacy->id]->isNegative() ? 'text-emerald-800' : 'text-gray-900' }}">
                                            {{ $balances[$pharmacy->id]->format() }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-6 py-10 text-center text-gray-500">
                                            No pharmacies yet. Until one exists, receivables are tracked as a single total.
                                            Naming a pharmacy on a daily entry creates it here.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-panel>
            </div>

            <x-panel title="Add a pharmacy">
                <form method="POST" action="{{ route('businesses.pharmacies.store', $business) }}" class="space-y-4 p-4 sm:p-6">
                    @csrf
                    @foreach ([['name','Name',true],['code','Code',false],['area','Area',false],['contact','Contact',false],['phone','Phone',false]] as [$field,$label,$required])
                        <div>
                            <x-input-label :for="$field" :value="$label" />
                            <x-text-input :id="$field" :name="$field" type="text" class="mt-1 block w-full" :required="$required" />
                            <x-input-error :messages="$errors->get($field)" class="mt-2" />
                        </div>
                    @endforeach
                    <div>
                        <x-input-label for="credit_days" value="Credit days" />
                        <x-text-input id="credit_days" name="credit_days" type="number" min="0" max="365" class="mt-1 block w-full" />
                    </div>
                    <button class="w-full rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                        Add pharmacy
                    </button>
                    <p class="text-xs text-gray-500">
                        Adding the first pharmacy moves the existing total into an "Unallocated" ledger by a
                        visible transfer — nothing in the history is rewritten.
                    </p>
                </form>
            </x-panel>
        </div>
    </div>
</x-workspace-layout>
