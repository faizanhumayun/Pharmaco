<x-workspace-layout :business="$business">
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-900">Team</h1>
        <p class="mt-1 text-sm text-gray-500">
            Who can sign in to {{ $business->name }}, and what each of them can do.
            Switching someone off keeps their name on everything they entered.
        </p>
    </x-slot>

    <div class="w-full space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <x-flash />

        <div class="grid gap-6 xl:grid-cols-3">
            {{-- The people, and what they can do. --}}
            <x-panel title="People" class="xl:col-span-2">
                <table class="w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach (['Person', 'Role and access', 'Last signed in'] as $h)
                                <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:px-6">{{ $h }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($members as $member)
                            @php($isMe = $member->is(auth()->user()))
                            <tr @class(['bg-gray-50/60 text-gray-500' => ! $member->pivot->is_active])>
                                <td class="px-4 py-3 align-top sm:px-6">
                                    <p class="font-medium {{ $member->pivot->is_active ? 'text-gray-900' : 'text-gray-500' }}">
                                        {{ $member->name }}
                                        @if ($isMe)
                                            <x-badge classes="bg-emerald-50 text-emerald-800 ring-emerald-600/20" class="ml-1">You</x-badge>
                                        @endif
                                    </p>
                                    <p class="text-xs text-gray-500">{{ $member->email }}</p>
                                </td>

                                <td class="px-4 py-3 align-top sm:px-6">
                                    @if ($isMe)
                                        <p class="text-gray-900">{{ $member->pivot->role->label() }}</p>
                                        <p class="text-xs text-gray-500">You cannot change your own role or access.</p>
                                    @else
                                        <form method="POST" action="{{ route('businesses.team.update', [$business, $member]) }}"
                                              class="flex flex-wrap items-center gap-2">
                                            @csrf
                                            @method('PATCH')

                                            <select name="role" aria-label="Role for {{ $member->name }}"
                                                    class="rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                @foreach ($roles as $role)
                                                    <option value="{{ $role->value }}" @selected($member->pivot->role === $role)>{{ $role->label() }}</option>
                                                @endforeach
                                            </select>

                                            <select name="is_active" aria-label="Access for {{ $member->name }}"
                                                    class="rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                <option value="1" @selected($member->pivot->is_active)>Can sign in</option>
                                                <option value="0" @selected(! $member->pivot->is_active)>Switched off</option>
                                            </select>

                                            <button class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                                Save
                                            </button>
                                        </form>
                                        <x-input-error :messages="$errors->get('member.' . $member->id)" class="mt-2" />
                                    @endif
                                </td>

                                <td class="whitespace-nowrap px-4 py-3 align-top text-gray-500 sm:px-6">
                                    {{ $member->last_login_at?->diffForHumans() ?? 'Never' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-6 py-10 text-center text-sm text-gray-500">
                                    Nobody has a login to this business yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-panel>

            {{-- Give someone a login. --}}
            <x-panel title="Add someone"
                     description="They sign in at the normal login page with this email and password. Tell them the password yourself.">
                <form method="POST" action="{{ route('businesses.team.store', $business) }}" class="space-y-4 p-4 sm:p-6">
                    @csrf

                    <div>
                        <x-input-label for="name" value="Name" />
                        <x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name')" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="email" value="Email" />
                        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="role" value="Role" />
                        <select id="role" name="role" required
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                            @foreach ($roles as $role)
                                <option value="{{ $role->value }}" @selected(old('role', \App\Enums\BusinessRole::Operator->value) === $role->value)>
                                    {{ $role->label() }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('role')" class="mt-2" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                        <div>
                            <x-input-label for="password" value="Password" />
                            <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="password_confirmation" value="Password again" />
                            <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
                        </div>
                    </div>

                    <p class="text-xs text-gray-500">
                        If this email already has a login — say they work for another business too — they are added
                        here with their existing password, and the one above is not used.
                    </p>

                    <button class="w-full rounded-md bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                        Add to team
                    </button>
                </form>
            </x-panel>
        </div>

        {{-- What each role means, in the words the owner would use. --}}
        <x-panel title="What each role can do">
            <dl class="grid divide-y divide-gray-100 sm:grid-cols-2 sm:divide-y-0 xl:grid-cols-4">
                @foreach ($roles as $role)
                    <div class="px-4 py-4 sm:px-6">
                        <dt class="text-sm font-semibold text-gray-900">{{ $role->label() }}</dt>
                        <dd class="mt-1 text-sm text-gray-600">{{ $role->description() }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-panel>
    </div>
</x-workspace-layout>
