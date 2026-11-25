@props([
    'name',
    'id' => null,
    'value' => null,      // Y-m-d
    'min' => null,        // Y-m-d, earliest day that may be picked
    'max' => null,        // Y-m-d, latest day that may be picked
    'required' => false,
])

{{--
    Picking a day.

        <x-date-picker name="business_date" :value="$date" :min="$earliest" :max="$today" required />

    A button that shows the day in words, and a calendar that opens under it.
    The form receives an ordinary Y-m-d value under $name, exactly as a
    <input type="date"> would send it, so nothing behind the form changes.

    Days outside min–max are shown but cannot be picked. Arrow keys are not
    needed: Today and the month arrows cover what a day's work asks for.
--}}
@php($id = $id ?? 'date-' . $name)
<div class="relative" x-data="datePicker({ value: @js($value ? (string) $value : ''), min: @js($min ? (string) $min : ''), max: @js($max ? (string) $max : '') })"
     x-on:click.outside="open = false" x-on:keydown.escape="open = false" {{ $attributes->only('class') }}>
    <input type="hidden" name="{{ $name }}" :value="value" @if ($required) required @endif>

    <button type="button" id="{{ $id }}" x-on:click="toggle()" :aria-expanded="open"
            class="flex w-full items-center justify-between gap-3 rounded-md border border-gray-300 bg-white px-3 py-2 text-left text-sm shadow-sm hover:border-emerald-600 focus:border-emerald-600 focus:outline-none focus:ring-1 focus:ring-emerald-600">
        <span :class="value ? 'font-medium text-gray-900' : 'text-gray-400'" x-text="value ? words(value) : 'Select a date'"></span>
        <svg class="h-5 w-5 shrink-0 text-gray-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M5.75 2a.75.75 0 01.75.75V4h7V2.75a.75.75 0 011.5 0V4h.25A2.75 2.75 0 0118 6.75v8.5A2.75 2.75 0 0115.25 18H4.75A2.75 2.75 0 012 15.25v-8.5A2.75 2.75 0 014.75 4H5V2.75A.75.75 0 015.75 2zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75z" clip-rule="evenodd" />
        </svg>
    </button>

    <div x-show="open" x-cloak x-transition.opacity.duration.100ms
         class="absolute left-0 z-50 mt-1 w-72 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
        <div class="flex items-center justify-between">
            <button type="button" x-on:click="step(-1)" aria-label="Previous month"
                    class="flex h-8 w-8 items-center justify-center rounded-md text-gray-600 hover:bg-gray-100">‹</button>
            <p class="text-sm font-semibold text-gray-900" x-text="monthLabel"></p>
            <button type="button" x-on:click="step(1)" aria-label="Next month"
                    class="flex h-8 w-8 items-center justify-center rounded-md text-gray-600 hover:bg-gray-100">›</button>
        </div>

        <div class="mt-2 grid grid-cols-7 text-center font-mono text-[11px] uppercase tracking-wide text-gray-400">
            <template x-for="d in ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']" :key="d"><span class="py-1" x-text="d"></span></template>
        </div>

        <div class="grid grid-cols-7 gap-0.5">
            <template x-for="(day, i) in days" :key="i">
                <button type="button" x-on:click="pick(day)" :disabled="! day.iso || ! allowed(day.iso)"
                        :class="{
                            'invisible': ! day.iso,
                            'bg-emerald-700 font-semibold text-white': day.iso && day.iso === value,
                            'ring-1 ring-inset ring-emerald-600 text-emerald-800': day.iso && day.iso === today && day.iso !== value,
                            'text-gray-900 hover:bg-emerald-50': day.iso && day.iso !== value && allowed(day.iso),
                            'cursor-not-allowed text-gray-300': day.iso && ! allowed(day.iso),
                        }"
                        class="flex h-9 items-center justify-center rounded-md text-sm tabular-nums"
                        x-text="day.n"></button>
            </template>
        </div>

        <div class="mt-2 flex items-center justify-between border-t border-gray-100 pt-2">
            <button type="button" x-on:click="pick({ iso: today })" :disabled="! allowed(today)"
                    class="rounded-md px-2 py-1 text-sm font-medium text-emerald-700 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:text-gray-300">Today</button>
            <button type="button" x-on:click="pick({ iso: yesterday })" :disabled="! allowed(yesterday)"
                    class="rounded-md px-2 py-1 text-sm font-medium text-emerald-700 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:text-gray-300">Yesterday</button>
            <button type="button" x-on:click="open = false"
                    class="rounded-md px-2 py-1 text-sm font-medium text-gray-500 hover:bg-gray-100">Close</button>
        </div>
    </div>
</div>

@once
    @push('scripts')
        <script>
            /* Everything in Y-m-d strings and local calendar arithmetic, so a
               day never shifts by a timezone on its way to the form. */
            function datePicker({ value, min, max }) {
                const iso = (y, m, d) => y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
                const parts = s => s.split('-').map(Number);

                return {
                    value, min, max,
                    open: false,
                    year: 0,
                    month: 0,
                    // The business's own today, when the page says what it is.
                    today: max && max <= iso(new Date().getFullYear(), new Date().getMonth(), new Date().getDate())
                        ? max
                        : iso(new Date().getFullYear(), new Date().getMonth(), new Date().getDate()),

                    init() { this.show(this.value || this.today); },

                    get yesterday() {
                        const [y, m, d] = parts(this.today);
                        const before = new Date(y, m - 1, d - 1);
                        return iso(before.getFullYear(), before.getMonth(), before.getDate());
                    },
                    show(day) {
                        const [y, m] = parts(day);
                        this.year = y;
                        this.month = m - 1;
                    },
                    toggle() {
                        this.open = ! this.open;
                        if (this.open) this.show(this.value || this.today);
                    },
                    step(by) {
                        const next = new Date(this.year, this.month + by, 1);
                        this.year = next.getFullYear();
                        this.month = next.getMonth();
                    },
                    allowed(day) {
                        return (! this.min || day >= this.min) && (! this.max || day <= this.max);
                    },
                    pick(day) {
                        if (! day.iso || ! this.allowed(day.iso)) return;
                        this.value = day.iso;
                        this.open = false;
                        this.$nextTick(() => this.$dispatch('date-picked', this.value));
                    },
                    get monthLabel() {
                        return new Date(this.year, this.month, 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
                    },
                    /** The month as a grid starting on Monday, padded with blanks. */
                    get days() {
                        const lead = (new Date(this.year, this.month, 1).getDay() + 6) % 7;
                        const count = new Date(this.year, this.month + 1, 0).getDate();
                        const cells = Array.from({ length: lead }, () => ({ iso: '', n: '' }));
                        for (let d = 1; d <= count; d++) cells.push({ iso: iso(this.year, this.month, d), n: d });
                        return cells;
                    },
                    words(day) {
                        const [y, m, d] = parts(day);
                        const text = new Date(y, m - 1, d).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
                        return day === this.today ? 'Today · ' + text : (day === this.yesterday ? 'Yesterday · ' + text : text);
                    },
                };
            }
        </script>
    @endpush
@endonce
