/**
 * Every list answers the arrow keys.
 *
 * ↑ and ↓ move a selected line through the list on screen, Enter opens it
 * (its first link, or its first field where the line is a form), Esc lets go.
 * A click on a line selects it too, and on a page with more than one list
 * the keys follow the one last clicked in.
 *
 * One script for every list, so each page gets it for nothing: it works on
 * any <table> inside an element marked data-list-keys (the workspace and back
 * office <main>). A list that handles the keys itself is marked
 * data-no-list-keys and left alone. The keys are ignored while something is
 * being typed, and with Ctrl, Alt or ⌘ held.
 *
 * The look is in app.css ("SELECTED LINE"): the accent tint and a bar on the
 * left, so it follows the business colour and Strong colours.
 */

const SELECTED = 'data-line-selected';

let active = null; // the table the keys are on

function tables() {
    return [...document.querySelectorAll('[data-list-keys] table')]
        .filter((t) => ! t.closest('[data-no-list-keys]') && t.offsetParent !== null);
}

/** The lines that can be selected: shown, and not a lone "nothing found" cell. */
function lines(table) {
    return [...table.querySelectorAll(':scope > tbody > tr')].filter((tr) => {
        if (tr.offsetParent === null) return false;
        const cells = tr.children;
        return ! (cells.length === 1 && cells[0].colSpan > 1);
    });
}

function current(table) {
    return table?.querySelector(`:scope > tbody > tr[${SELECTED}]`) ?? null;
}

function select(table, tr) {
    document.querySelectorAll(`tr[${SELECTED}]`).forEach((el) => el.removeAttribute(SELECTED));
    active = table;
    if (! tr) return;
    tr.setAttribute(SELECTED, '');
    tr.scrollIntoView({ block: 'nearest' });
}

/** The list the keys are on: the one chosen, else the first on the page. */
function target() {
    if (active && document.contains(active) && active.offsetParent !== null) return active;
    return tables().find((t) => lines(t).length > 0) ?? null;
}

function typing(el) {
    return el?.closest?.('input, textarea, select, [contenteditable=""], [contenteditable="true"]');
}

/** Something open over the page: a window, a menu, a calendar. Keys are its. */
function overlayOpen() {
    return [...document.querySelectorAll('[role="dialog"], [aria-modal="true"], .fixed.inset-0')]
        .some((el) => el.getClientRects().length > 0);
}

function onKey(e) {
    if (e.ctrlKey || e.altKey || e.metaKey || typing(e.target) || overlayOpen()) return;
    if (! ['ArrowDown', 'ArrowUp', 'Enter', 'Escape'].includes(e.key)) return;

    const table = target();
    if (! table) return;

    const list = lines(table);
    const at = list.indexOf(current(table));

    if (e.key === 'Escape') {
        if (at >= 0) select(table, null);
        return;
    }

    if (e.key === 'Enter') {
        // A focused button or link already does what Enter means.
        if (at < 0 || e.target.closest?.('a, button')) return;
        const line = list[at];
        const link = line.querySelector('a[href]');
        const field = line.querySelector('input:not([type=hidden]), select, textarea');
        if (link) {
            e.preventDefault();
            link.click();
        } else if (field) {
            e.preventDefault();
            field.focus();
        }
        return;
    }

    if (list.length === 0) return;
    e.preventDefault();
    const next = at < 0
        ? (e.key === 'ArrowDown' ? 0 : list.length - 1)
        : Math.max(0, Math.min(list.length - 1, at + (e.key === 'ArrowDown' ? 1 : -1)));
    select(table, list[next]);
}

function onClick(e) {
    const tr = e.target.closest?.('[data-list-keys] table > tbody > tr');
    if (! tr) return;
    const table = tr.closest('table');
    if (table.closest('[data-no-list-keys]') || ! lines(table).includes(tr)) return;
    select(table, tr);
}

export function startListKeys() {
    if (window.__listKeys) return;
    window.__listKeys = true;
    document.addEventListener('keydown', onKey);
    document.addEventListener('click', onClick);
}
