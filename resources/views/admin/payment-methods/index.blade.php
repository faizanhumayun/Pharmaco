{{--
    The platform's payment methods. Businesses choose from this list; they do
    not add to it, so the same bank is never spelled five ways.
--}}
<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Payment methods</h1>
            <p class="mt-1 text-sm text-gray-500">
                The wallets and banks a business can be paid through. Each business switches on the ones it uses, in its own settings.
            </p>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                @foreach (['wallet' => 'Mobile wallets', 'bank' => 'Banks'] as $kind => $title)
                    <x-panel :title="$title">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Name</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Used by</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">State</th>
                                        <th class="px-4 py-3 sm:px-6"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    @forelse ($methods[$kind] ?? [] as $method)
                                        <tr>
                                            <td class="px-4 py-2.5 sm:px-6">
                                                <form method="POST" action="{{ route('admin.payment-methods.update', $method) }}" class="flex items-center gap-2">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="text" name="name" value="{{ $method->name }}" required maxlength="120"
                                                           class="w-64 rounded-md border-gray-300 py-1 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                    <button class="text-xs font-medium text-emerald-700 hover:underline">Rename</button>
                                                </form>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-2.5 text-gray-600">
                                                {{ ($n = (int) ($inUse[$method->id] ?? 0)) === 0 ? '—' : $n.' '.Str::plural('business', $n) }}
                                            </td>
                                            <td class="px-4 py-2.5">
                                                <x-badge :classes="$method->is_active ? 'bg-emerald-50 text-emerald-800 ring-emerald-600/20' : 'bg-gray-100 text-gray-700 ring-gray-500/20'">{{ $method->is_active ? 'Offered' : 'Switched off' }}</x-badge>
                                            </td>
                                            <td class="px-4 py-2.5 text-right sm:px-6">
                                                <form method="POST" action="{{ route('admin.payment-methods.update', $method) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="is_active" value="{{ $method->is_active ? 0 : 1 }}">
                                                    <button class="text-sm font-medium {{ $method->is_active ? 'text-gray-600' : 'text-emerald-700' }} hover:underline">
                                                        {{ $method->is_active ? 'Switch off' : 'Offer again' }}
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="px-6 py-6 text-center text-gray-500">None yet.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </x-panel>
                @endforeach
            </div>

            <div>
                <x-panel title="Add a payment method" description="It becomes available to every business straight away.">
                    <form method="POST" action="{{ route('admin.payment-methods.store') }}" class="space-y-4 p-4 sm:p-6">
                        @csrf
                        <div>
                            <x-input-label for="name" value="Name" />
                            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required maxlength="120" />
                            <x-input-error :messages="$errors->get('name')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="kind" value="Kind" />
                            <select id="kind" name="kind" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                <option value="bank" @selected(old('kind') === 'bank')>Bank</option>
                                <option value="wallet" @selected(old('kind') === 'wallet')>Mobile wallet</option>
                            </select>
                            <x-input-error :messages="$errors->get('kind')" class="mt-1" />
                        </div>
                        <button class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Add</button>
                    </form>
                    <p class="border-t border-gray-200 px-4 py-3 text-xs text-gray-500 sm:px-6">
                        A method is never deleted: money a business took through it stays in its books. Switch it off instead and it stops being offered.
                    </p>
                </x-panel>
            </div>
        </div>
    </div>
</x-app-layout>
