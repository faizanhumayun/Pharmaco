/**
 * Every dropdown in the product, drawn one way: a button showing what is
 * chosen with three dots beside it, and a menu that opens where there is room.
 *
 * Pages go on writing an ordinary <select>. This finds each one, keeps it in
 * the page out of sight — so forms submit it, "required" still works, and
 * x-model and onchange behave exactly as before — and puts the button and
 * menu in front of it. Choosing from the menu sets the real select and fires
 * its change and input events, which is all anything listening ever knew.
 *
 * To leave one as the browser draws it, mark it: <select data-native>.
 *
 * A long list can be searched: a box at the top of the menu narrows it as you
 * type, and Enter takes the first match.
 *
 * The keyboard works the whole menu: Down or Up on the button opens it, Down
 * and Up (Home and End) move through the choices, Enter takes the one that is
 * lit, Escape closes it. In a menu with a search box the cursor stays in the
 * box while the arrows move the list, so typing and choosing do not fight. It appears by itself once a select
 * has more than ten choices, or always on one marked <select data-search>.
 *
 * The classes here are Tailwind's, read from the same tokens as every page
 * (tailwind.config.js scans this file).
 */
const DOTS = '<svg class="h-4 w-4 shrink-0 text-gray-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">'
    + '<circle cx="10" cy="4" r="1.6" /><circle cx="10" cy="10" r="1.6" /><circle cx="10" cy="16" r="1.6" /></svg>';

// What the select was asked to be: its width and place in the row carry over
// to the wrapper, its size to the button.
const LAYOUT = /^(?:[a-z-]+:)*(w-|max-w-|min-w-|block$|inline-block$|mt-|mb-|ms-|me-|ml-|mr-|flex-|shrink|grow|col-span-)/;
const SIZE = /^(?:[a-z-]+:)*(py-|text-(xs|sm|base|lg)$|font-(mono|semibold|medium)$)/;

let openMenu = null;

function close() {
    if (! openMenu) return;

    openMenu.menu.classList.add('hidden');
    openMenu.button.setAttribute('aria-expanded', 'false');
    openMenu = null;
}

function label(select) {
    return select.options[select.selectedIndex]?.textContent.trim() || '—';
}

/** Rebuilds the list from the select as it is now: options can change under us. */
function fill(select, menu, button) {
    menu.replaceChildren();

    for (const option of select.options) {
        const item = document.createElement('button');
        item.type = 'button';
        item.disabled = option.disabled;
        item.setAttribute('role', 'option');
        item.setAttribute('aria-selected', option.selected ? 'true' : 'false');
        item.className = 'flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm disabled:cursor-not-allowed disabled:text-gray-300 '
            + (option.selected ? 'bg-emerald-50 font-semibold text-emerald-800' : 'text-gray-900 hover:bg-gray-50');

        const text = document.createElement('span');
        text.className = 'min-w-0 truncate';
        text.textContent = option.textContent.trim() || '—';
        item.append(text);

        if (option.selected) {
            const tick = document.createElement('span');
            tick.className = 'shrink-0 text-emerald-700';
            tick.textContent = '✓';
            item.append(tick);
        }

        item.addEventListener('click', () => {
            if (select.value !== option.value) {
                select.value = option.value;
                select.dispatchEvent(new Event('input', { bubbles: true }));
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }

            close();
            button.focus();
        });

        menu.append(item);
    }

    if ('search' in select.dataset || select.options.length > SEARCH_FROM) addSearch(menu);
}

/** The choices that can be taken right now: on show, and not switched off. */
function choices(menu) {
    return [...menu.querySelectorAll('[role="option"]')].filter(item => ! item.classList.contains('hidden') && ! item.disabled);
}

/** Lights one choice — the one Enter will take — and brings it into view. */
function light(menu, item) {
    menu.querySelectorAll('[data-lit]').forEach(old => {
        delete old.dataset.lit;
        old.classList.remove('ring-2', 'ring-inset', 'ring-emerald-600');
    });

    if (! item) return;

    item.dataset.lit = 'on';
    item.classList.add('ring-2', 'ring-inset', 'ring-emerald-600');
    item.scrollIntoView({ block: 'nearest' });
}

/** Arrow keys, Home, End and Enter inside an open menu. */
function steer(menu, event) {
    const list = choices(menu);

    if (list.length === 0) return;

    const at = list.indexOf(menu.querySelector('[data-lit]'));
    const to = {
        ArrowDown: at < 0 ? 0 : (at + 1) % list.length,
        ArrowUp: at < 0 ? list.length - 1 : (at - 1 + list.length) % list.length,
        Home: 0,
        End: list.length - 1,
    }[event.key];

    if (to !== undefined) {
        // Home and End belong to the text while there is a search box.
        if ((event.key === 'Home' || event.key === 'End') && event.target.matches('input')) return;

        event.preventDefault();
        light(menu, list[to]);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        (list[at] ?? list[0]).click();
    }
}

// More choices than this and the menu gets a search box of its own.
const SEARCH_FROM = 10;

/**
 * A search box pinned to the top of an open menu. It lives in the menu, which
 * is outside every form, so what is typed is never submitted and nothing
 * bound to the page hears it.
 */
function addSearch(menu) {
    const items = [...menu.children];

    const bar = document.createElement('div');
    bar.className = 'sticky top-0 z-10 border-b border-gray-200 bg-white p-2';

    const input = document.createElement('input');
    input.type = 'search';
    input.placeholder = 'Search…';
    input.autocomplete = 'off';
    input.setAttribute('aria-label', 'Search this list');
    input.className = 'block w-full rounded-md border-gray-300 py-1.5 text-sm focus:border-emerald-600 focus:ring-emerald-600';

    const none = document.createElement('p');
    none.className = 'hidden px-3 py-2 text-sm text-gray-500';
    none.textContent = 'Nothing matches.';

    input.addEventListener('input', event => {
        // Kept to the menu: the page's own listeners have nothing to do with it.
        event.stopPropagation();

        const words = input.value.trim().toLowerCase().split(/\s+/).filter(Boolean);

        for (const item of items) {
            const text = item.textContent.toLowerCase();
            item.classList.toggle('hidden', ! words.every(word => text.includes(word)));
        }

        none.classList.toggle('hidden', items.some(item => ! item.classList.contains('hidden')));
    });

    // Whatever was lit may have just been filtered away: light the first match.
    input.addEventListener('input', () => light(menu, choices(menu)[0] ?? null));

    bar.append(input);
    menu.prepend(bar);
    menu.append(none);

    // Ready to type as soon as the menu is on the screen.
    setTimeout(() => input.focus());
}

function enhance(select) {
    if (select.dataset.kebab || select.multiple || 'native' in select.dataset) return;

    select.dataset.kebab = 'on';

    const wrapper = document.createElement('span');
    const classes = [...select.classList];
    const fullWidth = classes.some(c => /(^|:)w-full$/.test(c));

    wrapper.dataset.kebabWrap = 'on';
    wrapper.className = ['relative', fullWidth ? 'block' : 'inline-block', 'align-middle', ...classes.filter(c => LAYOUT.test(c))].join(' ');

    const button = document.createElement('button');
    // A page that walks its fields with Enter (data-step) walks this button,
    // not the hidden select behind it.
    if ('step' in select.dataset) button.dataset.step = '';
    button.type = 'button';
    button.setAttribute('aria-haspopup', 'listbox');
    button.setAttribute('aria-expanded', 'false');
    button.className = [
        'flex w-full items-center justify-between gap-2 rounded-md border border-gray-300 bg-white pl-3 pr-2 text-left text-gray-900',
        'hover:border-emerald-600 focus:border-emerald-600 focus:outline-none focus:ring-1 focus:ring-emerald-600',
        'disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-400',
        classes.some(c => /(^|:)py-/.test(c)) ? '' : 'py-2',
        classes.some(c => /(^|:)text-(xs|sm|base|lg)$/.test(c)) ? '' : 'text-sm',
        ...classes.filter(c => SIZE.test(c)),
    ].join(' ');

    const text = document.createElement('span');
    text.className = 'min-w-0 truncate';
    button.append(text);
    button.insertAdjacentHTML('beforeend', DOTS);

    const menu = document.createElement('div');
    menu.setAttribute('role', 'listbox');
    // The menu is drawn on the page itself, not inside the card the dropdown
    // sits in: a card that clips its contents, or a drawer that slides, would
    // otherwise cut the menu off or put it in the wrong place.
    menu.dataset.kebabWrap = 'on';
    menu.className = 'fixed z-[90] hidden max-h-72 w-max max-w-xs overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg focus:outline-none';
    // Able to hold the keyboard itself, for a menu with no search box.
    menu.tabIndex = -1;
    menu.addEventListener('keydown', event => steer(menu, event));

    // Written only when it has changed: this runs often, and writing the
    // same text again would be a change to the page that nothing needs — and
    // one the watcher below would see and answer, without end.
    const sync = () => {
        const now = label(select);

        if (text.textContent !== now) text.textContent = now;
        if (button.disabled !== select.disabled) button.disabled = select.disabled;
    };

    // The real select stays for the form, the validator and anything bound to
    // it — only out of sight, and out of the tab order.
    select.insertAdjacentElement('beforebegin', wrapper);
    wrapper.append(select, button);
    document.body.append(menu);
    // So whoever removes the select from the page can take its menu with it.
    select._floating = menu;
    select.classList.add('sr-only');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');

    // A label's "for" points at the select: clicking it should open the menu.
    select.addEventListener('focus', () => button.focus());

    button.addEventListener('click', () => {
        if (openMenu?.menu === menu) return close();

        close();
        fill(select, menu, button);
        menu.classList.remove('hidden');

        // Placed against the button: under it, or above when the foot of the
        // window is nearer than the menu is tall; never off either side.
        const at = button.getBoundingClientRect();
        const height = Math.min(menu.scrollHeight, 288);
        const room = window.innerHeight - at.bottom;
        const up = room < height + 12 && at.top > room;

        menu.style.minWidth = at.width + 'px';
        menu.style.top = (up ? Math.max(8, at.top - height - 4) : at.bottom + 4) + 'px';
        menu.style.left = Math.max(8, Math.min(at.left, window.innerWidth - menu.offsetWidth - 8)) + 'px';

        button.setAttribute('aria-expanded', 'true');
        openMenu = { menu, button };
        // The keyboard starts on what is already chosen. With a search box
        // the cursor is in the box (and nothing is scrolled, which would push
        // the box out of sight); without one the menu itself takes the keys.
        if (menu.querySelector('input')) {
            light(menu, choices(menu)[0] ?? null);
        } else {
            light(menu, menu.querySelector('[aria-selected="true"]:not(:disabled)') ?? choices(menu)[0] ?? null);
            menu.focus({ preventScroll: true });
        }
    });

    // Down or Up on the closed button opens it, as a native select would.
    button.addEventListener('keydown', event => {
        if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && openMenu?.menu !== menu) {
            event.preventDefault();
            button.click();
        }
    });

    select.addEventListener('change', sync);
    select._kebabSync = sync;
    sync();
}

function enhanceAll(root = document) {
    root.querySelectorAll?.('select').forEach(enhance);
}

/** Values set from script (x-model, a form reset) fire no event, so labels are re-read. */
function resync() {
    document.querySelectorAll('select[data-kebab]').forEach(select => select._kebabSync?.());
}

export function startKebabSelects() {
    enhanceAll();

    // Selects that arrive later: a dialog opening, a row added, a template shown.
    // What this script draws itself — the button's label, the menu's rows — is
    // never looked at again, or drawing would be answered by more drawing.
    new MutationObserver(records => {
        let fresh = false;

        for (const record of records) {
            if (record.target.nodeType === 1 && record.target.closest('[data-kebab-wrap], [data-date-wrap]')) continue;

            for (const node of record.addedNodes) {
                if (node.nodeType !== 1 || node.closest('[data-kebab-wrap], [data-date-wrap]')) continue;

                if (node.matches('select')) enhance(node);
                enhanceAll(node);
                fresh = true;
            }
        }

        if (fresh) queueMicrotask(resync);
    }).observe(document.body, { childList: true, subtree: true });

    document.addEventListener('click', event => {
        if (openMenu && ! openMenu.menu.contains(event.target) && ! openMenu.button.contains(event.target)) close();
        queueMicrotask(resync);
    });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
    // The menu is pinned to where the button was: once the page moves, it goes.
    window.addEventListener('scroll', event => { if (openMenu && ! openMenu.menu.contains(event.target)) close(); }, true);
    window.addEventListener('resize', close);
    document.addEventListener('input', () => queueMicrotask(resync));
    document.addEventListener('reset', () => setTimeout(resync));
}
