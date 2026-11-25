/**
 * Every date in the product, picked from a proper calendar.
 *
 * Pages go on writing ordinary <input type="date">. This finds each one,
 * keeps it in the form out of sight — so it submits the same Y-m-d value and
 * x-model still works — and puts a button in front of it that says the date
 * in words and opens a calendar.
 *
 * A "From" and a "To" in the same form become one control: a single button
 * reading "1 Nov 2026 – 20 Nov 2026" that opens two months side by side with
 * the usual ranges down the left (today, last 7 days, this month…). Either
 * end may be left open.
 *
 * The calendar itself is Vanilla Calendar Pro (the one FlyonUI ships), in the
 * product's own colours — see the --vc-* settings in tokens.css.
 *
 * An <input type="month"> — the expiry printed on a carton — gets the same
 * treatment in its own shape: a box to type "08/27" into, as it is read off
 * the pack, and a button that opens a year of months to pick from. It goes on
 * submitting Y-m.
 *
 * To leave one as the browser draws it, mark it: <input type="date" data-native>.
 */
import { Calendar, months, motion } from 'vanilla-calendar-pro';
import 'vanilla-calendar-pro/styles/index.css';

const ICON = '<svg class="h-4 w-4 shrink-0 text-gray-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">'
    + '<path fill-rule="evenodd" d="M5.75 2a.75.75 0 01.75.75V4h7V2.75a.75.75 0 011.5 0V4h.25A2.75 2.75 0 0118 6.75v8.5A2.75 2.75 0 0115.25 18H4.75A2.75 2.75 0 012 15.25v-8.5A2.75 2.75 0 014.75 4H5V2.75A.75.75 0 015.75 2zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75z" clip-rule="evenodd" /></svg>';

const pad = n => String(n).padStart(2, '0');
const iso = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
const parse = s => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
const words = s => s ? parse(s).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
const shift = (days, from = new Date()) => { const d = new Date(from); d.setDate(d.getDate() + days); return d; };

/** The ranges asked for most, worked out from today. */
function presets() {
    const now = new Date();
    const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
    const lastMonthEnd = shift(-1, monthStart);

    return [
        ['Today', iso(now), iso(now)],
        ['Yesterday', iso(shift(-1)), iso(shift(-1))],
        ['Last 7 days', iso(shift(-6)), iso(now)],
        ['Last 30 days', iso(shift(-29)), iso(now)],
        ['This month', iso(monthStart), iso(now)],
        ['Last month', iso(new Date(lastMonthEnd.getFullYear(), lastMonthEnd.getMonth(), 1)), iso(lastMonthEnd)],
        ['Any time', '', ''],
    ];
}

let openPanel = null;

function close() {
    if (! openPanel) return;

    openPanel.panel.classList.add('hidden');
    openPanel.button.setAttribute('aria-expanded', 'false');
    openPanel = null;
}

function set(input, value) {
    if (input.value === value) return;

    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

function place(panel, button) {
    const at = button.getBoundingClientRect();
    const height = panel.offsetHeight;
    const room = window.innerHeight - at.bottom;
    const up = room < height + 12 && at.top > room;

    panel.style.top = Math.max(8, up ? at.top - height - 6 : at.bottom + 6) + 'px';
    panel.style.left = Math.max(8, Math.min(at.left, window.innerWidth - panel.offsetWidth - 8)) + 'px';
}

/**
 * One control over one date, or over a From and a To.
 *
 * @param {HTMLInputElement} from  the only input, or the start of a range
 * @param {HTMLInputElement|null} to  the end of a range
 */
function build(from, to = null) {
    const range = to !== null;
    const classes = [...from.classList];

    for (const input of [from, to].filter(Boolean)) {
        input.dataset.dateField = 'on';
        input.type = 'hidden';
    }

    const wrapper = document.createElement('span');
    wrapper.dataset.dateWrap = 'on';
    wrapper.className = ['relative block', ...classes.filter(c => /^(?:[a-z-]+:)*(mt-|w-|max-w-|min-w-)/.test(c))].join(' ');

    const button = document.createElement('button');
    button.type = 'button';
    button.setAttribute('aria-haspopup', 'dialog');
    button.setAttribute('aria-expanded', 'false');
    button.className = 'flex w-full items-center justify-between gap-3 whitespace-nowrap rounded-md border border-gray-300 bg-white py-2 pl-3 pr-2.5 text-left text-sm text-gray-900 '
        + 'hover:border-emerald-600 focus:border-emerald-600 focus:outline-none focus:ring-1 focus:ring-emerald-600';

    const text = document.createElement('span');
    text.className = 'min-w-0 truncate';
    button.append(text);
    button.insertAdjacentHTML('beforeend', ICON);

    // Drawn on the page itself, so no card or drawer can clip it.
    const panel = document.createElement('div');
    panel.dataset.dateWrap = 'on';
    panel.setAttribute('role', 'dialog');
    panel.className = 'fixed z-[90] hidden overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl';

    const body = document.createElement('div');
    body.className = 'flex';

    const quick = document.createElement('div');
    quick.className = 'flex w-36 shrink-0 flex-col gap-0.5 border-r border-gray-200 bg-gray-50 p-2';

    const grid = document.createElement('div');
    const foot = document.createElement('div');
    foot.className = 'flex items-center justify-between gap-3 border-t border-gray-200 bg-white px-3 py-2';

    const said = document.createElement('p');
    said.className = 'text-sm text-gray-600';

    const actions = document.createElement('div');
    actions.className = 'flex items-center gap-2';

    const clear = document.createElement('button');
    clear.type = 'button';
    clear.textContent = 'Clear';
    clear.className = 'rounded-md px-2.5 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-100';

    const done = document.createElement('button');
    done.type = 'button';
    done.textContent = 'Done';
    done.className = 'rounded-md bg-gray-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-800';

    actions.append(clear, done);
    foot.append(said, actions);
    body.append(quick, grid);
    panel.append(body, foot);

    const min = from.min || to?.min || undefined;
    const max = from.max || to?.max || undefined;
    let calendar = null;

    const label = () => {
        const a = from.value, b = to?.value ?? '';
        let now;

        if (! range) now = a ? words(a) : 'Choose a date';
        else if (a && b) now = a === b ? words(a) : words(a) + ' – ' + words(b);
        else if (a) now = 'From ' + words(a);
        else if (b) now = 'Up to ' + words(b);
        else now = 'Any time';

        if (text.textContent !== now) text.textContent = now;
        text.classList.toggle('text-gray-500', ! a && ! b);
        if (said.textContent !== now) said.textContent = now;
    };

    const show = () => {
        if (! calendar) return;

        const chosen = [from.value, to?.value].filter(Boolean);
        // With nothing chosen, a range opens on last month and this one —
        // the days that can actually be picked — not this month and the next.
        const today = new Date();
        const anchor = from.value || to?.value
            ? parse(from.value || to.value)
            : (range ? new Date(today.getFullYear(), today.getMonth() - 1, 1) : today);

        calendar.set({
            selectedDates: range && chosen.length === 2 ? [chosen[0] + ':' + chosen[1]] : chosen,
            selectedMonth: anchor.getMonth(),
            selectedYear: anchor.getFullYear(),
        });
    };

    const choose = (a, b) => {
        set(from, a);
        if (to) set(to, b);
        label();
        show();
    };

    for (const [name, a, b] of presets().filter(p => range || ['Today', 'Yesterday'].includes(p[0]))) {
        // A range outside what the field allows is not offered.
        if ((min && a && a < min) || (max && b && b > max)) continue;

        const item = document.createElement('button');
        item.type = 'button';
        item.textContent = name;
        item.className = 'rounded-md px-2.5 py-1.5 text-left text-sm text-gray-700 hover:bg-white hover:text-emerald-800';
        item.addEventListener('click', () => { choose(a, range ? b : a); if (! range) close(); });
        quick.append(item);
    }

    clear.addEventListener('click', () => choose('', ''));
    done.addEventListener('click', () => { close(); button.focus(); });

    button.addEventListener('click', () => {
        if (openPanel?.panel === panel) return close();

        close();
        panel.classList.remove('hidden');

        if (! calendar) {
            calendar = new Calendar(grid, {
                extensions: [months, motion],
                type: range ? 'multiple' : 'default',
                displayMonthsCount: range ? 2 : 1,
                monthsToSwitch: 1,
                selectionDatesMode: range ? 'multiple-ranged' : 'single',
                firstWeekday: 1,
                selectedTheme: 'light',
                enableJumpToSelectedDate: true,
                dateMin: min,
                dateMax: max,
                onClickDate(self) {
                    const picked = [...self.context.selectedDates].sort();

                    if (! range) {
                        choose(picked[0] ?? '', '');
                        if (picked.length) close();

                        return;
                    }

                    // One click is a start; the second closes the range.
                    set(from, picked[0] ?? '');
                    set(to, picked.length > 1 ? picked[picked.length - 1] : '');
                    label();
                },
            });
            calendar.init();
        }

        show();
        place(panel, button);
        button.setAttribute('aria-expanded', 'true');
        openPanel = { panel, button };
    });

    from.insertAdjacentElement('beforebegin', wrapper);
    wrapper.append(button);
    wrapper.append(from);
    if (to) wrapper.append(to);
    document.body.append(panel);
    // So whoever removes the field from the page can take its calendar with it.
    from._floating = panel;

    from._dateSync = label;
    if (to) to._dateSync = label;
    label();
}

/* ------------------------------------------------------------------ months */

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** "2027-08" as it is printed on a carton: 08/2027. */
const monthWords = value => value ? value.slice(5, 7) + '/' + value.slice(0, 4) : '';

/**
 * What someone typed, as Y-m — or '' when it is not a month yet.
 *
 * Read the way cartons print it, month first: 0827, 08/27, 8-2027, 08.2027,
 * aug 27. A year of two figures is this century.
 */
function readMonth(text) {
    const t = text.trim().toLowerCase();
    let month, year, found;

    if ((found = t.match(/^(\d{4})-(\d{1,2})$/))) [, year, month] = found;
    else if ((found = t.match(/^(\d{1,2})\s*[\/\-. ]\s*(\d{2}|\d{4})$/))) [, month, year] = found;
    else if ((found = t.match(/^(\d{2})(\d{2}|\d{4})$/))) [, month, year] = found;
    else if ((found = t.match(/^([a-z]{3})[a-z]*[\s\/\-.,]*(\d{2}|\d{4})$/))) {
        month = MONTHS.findIndex(m => m.toLowerCase() === found[1]) + 1;
        year = found[2];
    } else return '';

    month = Number(month);
    year = Number(year);
    if (year < 100) year += 2000;

    return month >= 1 && month <= 12 && year >= 2000 && year <= 2099 ? year + '-' + pad(month) : '';
}

/** One panel for every expiry on the page: only one is ever open. */
let monthPanel = null;

function monthPicker() {
    if (monthPanel) return monthPanel;

    const panel = document.createElement('div');
    panel.dataset.dateWrap = 'on';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Choose the expiry month');
    panel.className = 'fixed z-[90] hidden w-64 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl';

    const head = document.createElement('div');
    head.className = 'flex items-center justify-between border-b border-gray-200 bg-gray-50 px-2 py-1.5';

    const step = (label, title) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = label;
        b.title = title;
        b.className = 'flex h-8 w-8 items-center justify-center rounded-md text-lg leading-none text-gray-700 hover:bg-white hover:text-emerald-800';

        return b;
    };

    const back = step('‹', 'The year before');
    const on = step('›', 'The year after');
    const yearText = document.createElement('p');
    yearText.className = 'font-mono text-sm font-semibold tabular-nums text-gray-900';
    head.append(back, yearText, on);

    const grid = document.createElement('div');
    grid.className = 'grid grid-cols-4 gap-1 p-2';

    const foot = document.createElement('div');
    foot.className = 'flex items-center justify-between gap-3 border-t border-gray-200 px-3 py-2';
    const said = document.createElement('p');
    said.className = 'text-xs text-gray-500';
    const clear = document.createElement('button');
    clear.type = 'button';
    clear.textContent = 'Clear';
    clear.className = 'rounded-md px-2.5 py-1 text-sm font-medium text-gray-600 hover:bg-gray-100';
    foot.append(said, clear);

    panel.append(head, grid, foot);
    document.body.append(panel);

    monthPanel = { panel, year: new Date().getFullYear(), input: null, button: null };

    const draw = () => {
        const now = new Date();
        const thisMonth = now.getFullYear() + '-' + pad(now.getMonth() + 1);
        const chosen = monthPanel.input?.value ?? '';

        yearText.textContent = monthPanel.year;
        said.textContent = chosen ? 'Expires ' + MONTHS[Number(chosen.slice(5, 7)) - 1] + ' ' + chosen.slice(0, 4) : 'As printed on the pack';
        grid.replaceChildren();

        MONTHS.forEach((name, i) => {
            const value = monthPanel.year + '-' + pad(i + 1);
            const item = document.createElement('button');
            item.type = 'button';
            item.innerHTML = '<span class="block text-sm font-medium">' + name + '</span>'
                + '<span class="block font-mono text-[0.6875rem] tabular-nums opacity-70">' + pad(i + 1) + '</span>';
            item.className = 'rounded-md py-1.5 text-center '
                + (value === chosen
                    ? 'bg-emerald-700 text-white'
                    // Already gone by: still there to pick, but it says so.
                    : (value < thisMonth ? 'text-red-600 hover:bg-red-50' : 'text-gray-800 hover:bg-emerald-50 hover:text-emerald-800'));
            item.addEventListener('click', () => {
                const input = monthPanel.input;

                set(input, value);
                input._dateSync?.();
                close();
                input._monthText?.focus();
            });
            grid.append(item);
        });
    };

    back.addEventListener('click', () => { monthPanel.year--; draw(); });
    on.addEventListener('click', () => { monthPanel.year++; draw(); });
    clear.addEventListener('click', () => {
        set(monthPanel.input, '');
        monthPanel.input._dateSync?.();
        draw();
    });

    monthPanel.draw = draw;

    return monthPanel;
}

/** One expiry: a box to type the month into, and a calendar of months beside it. */
function buildMonth(input) {
    const classes = [...input.classList];

    input.dataset.dateField = 'on';
    input.type = 'hidden';

    const wrapper = document.createElement('span');
    wrapper.dataset.dateWrap = 'on';
    wrapper.className = ['relative block', ...classes.filter(c => /^(?:[a-z-]+:)*(mt-|w-|max-w-|min-w-)/.test(c))].join(' ');

    // The page's own sizes, so the field sits in its row as it did before.
    const sized = classes.filter(c => /^(?:[a-z-]+:)*(py-|text-(xs|sm|base|lg)$|rounded)/.test(c));

    const text = document.createElement('input');
    text.type = 'text';
    text.inputMode = 'numeric';
    text.autocomplete = 'off';
    text.maxLength = 14;
    text.placeholder = input.required || 'must' in input.dataset ? 'Expiry MM/YY *' : 'Expiry MM/YY';
    text.title = input.title || 'Expiry, as printed on the pack: month and year — 08/27';
    text.setAttribute('aria-label', 'Expiry month and year');

    const button = document.createElement('button');
    button.type = 'button';
    button.tabIndex = -1;
    button.title = 'Pick the month';
    button.setAttribute('aria-haspopup', 'dialog');
    button.setAttribute('aria-expanded', 'false');
    button.className = 'absolute inset-y-0 right-0 flex w-7 items-center justify-center rounded-r-md text-gray-500 hover:text-emerald-800';
    button.innerHTML = ICON;

    const paint = () => {
        const now = new Date();
        const thisMonth = now.getFullYear() + '-' + pad(now.getMonth() + 1);
        const soon = new Date(now.getFullYear(), now.getMonth() + 6, 1);
        const soonMonth = soon.getFullYear() + '-' + pad(soon.getMonth() + 1);
        const value = input.value;
        const unread = text.value.trim() !== '' && ! value;

        text.className = ['block w-full border pl-2 pr-7 font-mono tabular-nums placeholder:font-sans focus:border-emerald-700 focus:ring-emerald-600',
            ...(sized.length ? sized : ['rounded-md', 'py-1', 'text-sm']),
            unread || (value && value < thisMonth) ? 'border-red-500 bg-red-50 text-red-900'
                : (value && value <= soonMonth ? 'border-amber-400 bg-amber-50' : 'border-gray-300 bg-white'),
        ].join(' ');
    };

    // Called whenever the page may have changed the value behind the box.
    const sync = () => {
        // What is being typed is left alone, unless the value moved under it.
        if (document.activeElement !== text || readMonth(text.value) !== input.value) {
            text.value = monthWords(input.value);
        }

        paint();
    };

    text.addEventListener('input', () => {
        set(input, readMonth(text.value));
        paint();
        if (monthPanel?.input === input && openPanel) {
            if (input.value) monthPanel.year = Number(input.value.slice(0, 4));
            monthPanel.draw();
        }
    });

    // Leaving the box writes what was understood the way a carton prints it.
    text.addEventListener('blur', () => {
        if (input.value) text.value = monthWords(input.value);
        paint();
    });

    text.addEventListener('keydown', event => {
        if (event.key === 'ArrowDown' && event.altKey) { event.preventDefault(); button.click(); }
    });

    button.addEventListener('click', () => {
        const picker = monthPicker();

        if (openPanel?.panel === picker.panel && picker.input === input) return close();

        close();
        picker.input = input;
        picker.button = button;
        picker.year = input.value ? Number(input.value.slice(0, 4)) : new Date().getFullYear() + 1;
        picker.draw();
        picker.panel.classList.remove('hidden');
        place(picker.panel, wrapper);
        button.setAttribute('aria-expanded', 'true');
        openPanel = { panel: picker.panel, button };
    });

    input.insertAdjacentElement('beforebegin', wrapper);
    wrapper.append(text, button, input);

    input._monthText = text;
    input._dateSync = sync;
    text.value = monthWords(input.value);
    paint();
}

/** The block a field sits in: a label above an input, and nothing else. */
function fieldOf(input) {
    const box = input.parentElement;

    return box && box.children.length <= 2 && box.querySelector('label') ? box : null;
}

function enhanceWithin(root) {
    const scope = root.closest?.('form') ?? root;
    const fresh = [...scope.querySelectorAll('input[type="date"]')].filter(i => ! i.dataset.dateField && ! ('native' in i.dataset));

    const from = fresh.find(i => i.name === 'from');
    const to = fresh.find(i => i.name === 'to');

    // A From and a To, each in its own labelled block: one control for both.
    if (from && to && fieldOf(from) && fieldOf(to) && fieldOf(from) !== fieldOf(to)) {
        const label = fieldOf(from).querySelector('label');
        if (label) { label.textContent = 'Dates'; label.removeAttribute('for'); }

        fieldOf(to).classList.add('hidden');
        build(from, to);
    }

    for (const input of fresh) {
        if (! input.dataset.dateField) build(input);
    }

    for (const input of scope.querySelectorAll('input[type="month"]')) {
        if (! input.dataset.dateField && ! ('native' in input.dataset)) buildMonth(input);
    }
}

function resync() {
    document.querySelectorAll('input[data-date-field]').forEach(input => input._dateSync?.());
}

export function startDateFields() {
    enhanceWithin(document);

    new MutationObserver(records => {
        let fresh = false;

        for (const record of records) {
            if (record.target.nodeType === 1 && record.target.closest('[data-date-wrap], [data-kebab-wrap]')) continue;

            for (const node of record.addedNodes) {
                if (node.nodeType !== 1 || node.closest('[data-date-wrap], [data-kebab-wrap]')) continue;

                if (node.matches('input[type="date"], input[type="month"]') || node.querySelector('input[type="date"], input[type="month"]')) {
                    enhanceWithin(node);
                    fresh = true;
                }
            }
        }

        if (fresh) queueMicrotask(resync);
    }).observe(document.body, { childList: true, subtree: true });

    document.addEventListener('click', event => {
        if (openPanel && ! openPanel.panel.contains(event.target) && ! openPanel.button.contains(event.target)) close();
        queueMicrotask(resync);
    });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
    window.addEventListener('scroll', event => { if (openPanel && ! openPanel.panel.contains(event.target)) close(); }, true);
    window.addEventListener('resize', close);
}
