{{--
    The site's access gate: the switch, and the codes that open it.
    A code is switched off, not deleted, so one that was handed out can be
    brought back.
--}}
<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Security code</h1>
            <p class="mt-1 text-sm text-gray-500">
                A code that visitors must enter before they can use the site. The back office never requires it.
            </p>
        </div>
    </x-slot>

    <div class="w-full px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <x-panel title="Require a security code">
                    <form method="POST" action="{{ route('admin.security-code.update') }}" class="flex flex-wrap items-center justify-between gap-4 p-4 sm:p-6">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="security_code_enabled" value="{{ $enabled ? 0 : 1 }}">

                        <div class="flex items-center gap-3">
                            <x-badge :classes="$enabled ? 'bg-emerald-50 text-emerald-800 ring-emerald-600/20' : 'bg-gray-100 text-gray-700 ring-gray-500/20'">{{ $enabled ? 'On' : 'Off' }}</x-badge>
                            <p class="text-sm text-gray-600">
                                {{ $enabled
                                    ? 'Visitors must enter an active code before the landing page or any business screen opens. The sign-in page stays open.'
                                    : 'Anyone can open the landing page and sign in without a code.' }}
                            </p>
                        </div>

                        <button class="rounded-md px-4 py-2 text-sm font-semibold {{ $enabled ? 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50' : 'bg-emerald-700 text-white hover:bg-emerald-800' }}">
                            {{ $enabled ? 'Stop requiring a code' : 'Require a code' }}
                        </button>
                    </form>
                </x-panel>

                <x-panel title="Codes">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">Code</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Given to</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                                    <th class="px-4 py-3 sm:px-6"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse ($codes as $code)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-2.5 font-mono text-gray-900 sm:px-6">{{ $code->code }}</td>
                                        <td class="px-4 py-2.5 text-gray-600">{{ $code->label ?: '—' }}</td>
                                        <td class="px-4 py-2.5">
                                            <x-badge :classes="$code->status ? 'bg-emerald-50 text-emerald-800 ring-emerald-600/20' : 'bg-gray-100 text-gray-700 ring-gray-500/20'">{{ $code->status ? 'Active' : 'Inactive' }}</x-badge>
                                        </td>
                                        <td class="px-4 py-2.5 text-right sm:px-6">
                                            <form method="POST" action="{{ route('admin.security-code.codes.toggle', $code) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button class="text-sm font-medium {{ $code->status ? 'text-gray-600' : 'text-emerald-700' }} hover:underline">
                                                    {{ $code->status ? 'Deactivate' : 'Activate' }}
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-6 py-6 text-center text-gray-500">No codes yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-panel>
            </div>

            <div>
                <x-panel title="Add a code" description="The code is active immediately.">
                    <form method="POST" action="{{ route('admin.security-code.codes.store') }}" class="space-y-4 p-4 sm:p-6">
                        @csrf
                        <div>
                            <x-input-label for="code" value="Code (4 digits)" />
                            <x-text-input id="code" name="code" type="text" inputmode="numeric" class="mt-1 block w-full font-mono" :value="old('code')" required maxlength="4" autocomplete="off" />
                            <x-input-error :messages="$errors->get('code')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="label" value="Given to (optional)" />
                            <x-text-input id="label" name="label" type="text" class="mt-1 block w-full" :value="old('label')" maxlength="120" />
                            <x-input-error :messages="$errors->get('label')" class="mt-1" />
                        </div>
                        <button class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Add</button>
                    </form>
                    <p class="border-t border-gray-200 px-4 py-3 text-xs text-gray-500 sm:px-6">
                        A code is required once per visit and again after signing out. It does not replace a password.
                    </p>
                </x-panel>
            </div>
        </div>
    </div>
</x-app-layout>
