@props(['any' => false, 'keys' => [], 'notes' => []])

{{--
    What just happened, as a toast.

    Every page already carries <x-flash />, so this is the one place the
    message is drawn: a card at the top right of the window — pale green for
    good news, pale red for a refusal, each with a solid edge in its own
    colour — over whatever is there, that takes no room in the page. Pale
    rather than dark, so it stands out against the till's dark header as
    well as against a white page. Every toast leaves on its own after five
    seconds, or sooner with its ×.

    $any: also show the first validation error, for the screens that have no
    field to put one beside (the till, receiving stock).
    $keys: error keys a page refuses an action under (not a field's), each as
    a toast — 'decision', 'audit'.
    $notes: notices the page wants said, [kind, message] — kind 'ok', 'warn'
    or 'bad'.

    A page can raise one without reloading, the same look in the same place:
        $dispatch('toast', { kind: 'ok' | 'warn' | 'bad', message: '…', title: '…' })
    It leaves on its own after five seconds, like the rest.
--}}
@php
    $toasts = array_values(array_filter([
        session('status') && is_string(session('status')) ? ['ok', session('status')] : null,
        $errors->has('status') ? ['bad', $errors->first('status')] : null,
        $any && $errors->any() && ! $errors->has('status') ? ['bad', $errors->first()] : null,
        ...collect($keys)->flatMap(fn ($key) => $errors->get($key))->unique()->map(fn ($m) => ['bad', $m])->all(),
        ...collect($notes)->filter()->values()->all(),
    ]));
@endphp

@if ($toasts !== [])
    <div class="pointer-events-none fixed inset-x-0 top-4 z-[80] flex flex-col items-end gap-2 px-4 print:hidden sm:px-6" aria-live="polite">
        @foreach ($toasts as [$kind, $message])
            <div x-data="{ open: false }"
                 x-init="$nextTick(() => open = true); setTimeout(() => open = false, 5000)"
                 x-show="open" x-cloak
                 x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-x-4 opacity-0" x-transition:enter-end="translate-x-0 opacity-100"
                 x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 role="{{ $kind === 'ok' ? 'status' : 'alert' }}"
                 @class([
                     'pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border border-l-4 px-4 py-3 shadow-xl',
                     'border-emerald-600 bg-emerald-50' => $kind === 'ok',
                     'border-amber-500 bg-amber-50' => $kind === 'warn',
                     'border-red-600 bg-red-50' => $kind === 'bad',
                 ])>
                <span @class([
                    'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white',
                    'bg-emerald-600' => $kind === 'ok',
                    'bg-amber-500' => $kind === 'warn',
                    'bg-red-600' => $kind === 'bad',
                ]) aria-hidden="true">{{ $kind === 'ok' ? '✓' : '!' }}</span>
                <p @class(['min-w-0 flex-1 text-sm font-medium leading-snug', 'text-emerald-900' => $kind === 'ok', 'text-amber-900' => $kind === 'warn', 'text-red-900' => $kind === 'bad'])>{{ $message }}</p>
                <button type="button" x-on:click="open = false" title="Close"
                        class="-mr-1 shrink-0 rounded p-0.5 text-lg leading-none text-gray-500 hover:bg-white hover:text-gray-900">&times;</button>
            </div>
        @endforeach
    </div>
@endif

{{-- Toasts raised by the page itself (the 'toast' event). --}}
<div class="pointer-events-none fixed inset-x-0 top-4 z-[80] flex flex-col items-end gap-2 px-4 print:hidden sm:px-6" aria-live="polite"
     x-data="{
         items: [],
         next: 0,
         add(d) {
             const item = { id: this.next++, kind: d.kind || 'ok', title: d.title || '', message: d.message || '', open: false };
             this.items.push(item);
             this.$nextTick(() => item.open = true);
             setTimeout(() => this.drop(item), 5000);
         },
         drop(item) {
             item.open = false;
             setTimeout(() => this.items = this.items.filter(i => i !== item), 200);
         },
     }"
     x-on:toast.window="add($event.detail)">
    <template x-for="item in items" :key="item.id">
        <div x-show="item.open"
             x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-x-4 opacity-0" x-transition:enter-end="translate-x-0 opacity-100"
             x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             :role="item.kind === 'ok' ? 'status' : 'alert'"
             class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border border-l-4 px-4 py-3 shadow-xl"
             :class="{ 'border-emerald-600 bg-emerald-50': item.kind === 'ok', 'border-amber-500 bg-amber-50': item.kind === 'warn', 'border-red-600 bg-red-50': item.kind === 'bad' }">
            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white" aria-hidden="true"
                  :class="{ 'bg-emerald-600': item.kind === 'ok', 'bg-amber-500': item.kind === 'warn', 'bg-red-600': item.kind === 'bad' }"
                  x-text="item.kind === 'ok' ? '✓' : '!'"></span>
            <div class="min-w-0 flex-1 text-sm leading-snug"
                 :class="{ 'text-emerald-900': item.kind === 'ok', 'text-amber-900': item.kind === 'warn', 'text-red-900': item.kind === 'bad' }">
                <p class="font-semibold" x-show="item.title" x-text="item.title"></p>
                <p class="font-medium" x-text="item.message"></p>
            </div>
            <button type="button" x-on:click="drop(item)" title="Close"
                    class="-mr-1 shrink-0 rounded p-0.5 text-lg leading-none text-gray-500 hover:bg-white hover:text-gray-900">&times;</button>
        </div>
    </template>
</div>
