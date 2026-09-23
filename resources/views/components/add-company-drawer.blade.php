@props(['business'])

{{--
    Adding a company, from wherever you happen to need one.

    Opened by name from any page:

        <button x-on:click="$dispatch('open-drawer', 'add-company')">Add a company</button>
        <x-add-company-drawer :business="$business" />

    It posts to the ordinary companies.store route and that action redirects
    back, so the page you were on reloads with the new company already in it —
    no returning to a list and finding your way forward again.
--}}
<x-drawer name="add-company"
          title="Add a company"
          subtitle="It gets its own ledger under Company Payables."
          :show="$errors->hasAny(['name', 'code', 'credit_days', 'contact', 'phone'])">
    <form method="POST" action="{{ route('businesses.companies.store', $business) }}" class="space-y-4">
        @csrf

        @foreach ([
            ['name', 'Name', true, 'text'],
            ['code', 'Code', false, 'text'],
            ['contact', 'Contact', false, 'text'],
            ['phone', 'Phone', false, 'text'],
        ] as [$field, $label, $required, $type])
            <div>
                <x-input-label :for="'drawer-' . $field" :value="$label" />
                <x-text-input :id="'drawer-' . $field" :name="$field" :type="$type"
                              :value="old($field)" class="mt-1 block w-full" :required="$required" />
                <x-input-error :messages="$errors->get($field)" class="mt-2" />
            </div>
        @endforeach

        <div>
            <x-input-label for="drawer-credit_days" value="Credit days" />
            <x-text-input id="drawer-credit_days" name="credit_days" type="number" min="0" max="365"
                          :value="old('credit_days')" class="mt-1 block w-full" />
            <x-input-error :messages="$errors->get('credit_days')" class="mt-2" />
        </div>

        <button class="w-full rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
            Add company
        </button>

        <p class="text-xs text-gray-500">
            Adding the first company moves the existing total into an "Unallocated" ledger by a visible
            transfer — nothing in the history is rewritten.
        </p>
    </form>
</x-drawer>
