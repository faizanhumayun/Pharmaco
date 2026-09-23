@php($u = $user ?? null)

<div class="grid gap-5 p-4 sm:p-6 md:grid-cols-2">
    <div class="md:col-span-2">
        <x-input-label for="name" value="Name" />
        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $u?->name)" required autofocus />
        <x-input-error :messages="$errors->get('name')" class="mt-2" />
    </div>

    <div class="md:col-span-2">
        <x-input-label for="email" value="Email" />
        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $u?->email)" required />
        <x-input-error :messages="$errors->get('email')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="password" :value="$u ? 'New password (leave blank to keep current)' : 'Password'" />
        <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" :required="! $u" autocomplete="new-password" />
        <x-input-error :messages="$errors->get('password')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="password_confirmation" value="Confirm password" />
        <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" :required="! $u" />
    </div>

    @if (! $u || auth()->user()->isNot($u))
        <label class="flex items-start gap-3 md:col-span-2">
            <input type="hidden" name="is_platform_admin" value="0">
            <input type="checkbox" name="is_platform_admin" value="1"
                   @checked(old('is_platform_admin', $u?->is_platform_admin))
                   class="mt-1 rounded border-gray-300 text-emerald-700 focus:ring-emerald-600">
            <span>
                <span class="block text-sm font-medium text-gray-900">App Owner</span>
                <span class="block text-xs text-gray-500">
                    Full back-office access across every business. App Owners belong to no business
                    and still cannot edit posted transactions.
                </span>
            </span>
        </label>
    @endif
</div>
