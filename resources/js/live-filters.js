/**
 * Lists that filter as you type.
 *
 * Every list in the product filters through the one filter bar (the
 * x-filter-bar component), which is an ordinary form: fill it in, press the
 * button, the page comes back filtered. This keeps that form and removes the
 * button press. Typing in a box, choosing from a dropdown or picking a date
 * asks the server for the same page with those filters, and puts the answer
 * in place of what is on the screen — the heading, the count, the list, the
 * page numbers — without the page blinking or the cursor leaving the box.
 *
 * The server still does the filtering, exactly as it does for the button, so
 * a list of ten thousand rows is searched properly and not just the page that
 * happens to be on screen. The address bar follows, so a filtered list can
 * still be bookmarked, reloaded or sent to someone.
 *
 * If anything goes wrong — the session has ended, the server is unreachable —
 * the browser is simply sent to the filtered address the ordinary way.
 */
const TYPING_PAUSE = 300;

let timer = null;
let inFlight = null;

function addressFor(form) {
    const params = new URLSearchParams();

    // Blank boxes are left out, so the address stays as short as the filter.
    for (const [name, value] of new FormData(form)) {
        if (typeof value === 'string' && value.trim() !== '') params.append(name, value);
    }

    const base = (form.getAttribute('action') || window.location.pathname).split('?')[0];
    const query = params.toString();

    return query === '' ? base : base + '?' + query;
}

/** Menus and calendars drawn outside the list for fields that are about to go. */
function clearFloating(root) {
    root.querySelectorAll('select, input').forEach(field => field._floating?.remove());
}

async function refresh(form) {
    const url = addressFor(form);
    const main = form.closest('main');

    if (! main) {
        window.location.assign(url);
        return;
    }

    inFlight?.abort();
    inFlight = new AbortController();

    // Where the cursor is, so it can be put back in the box that replaces this one.
    const forms = [...main.querySelectorAll('form[data-live-filter]')];
    const active = document.activeElement;
    const typing = active && form.contains(active) && active.name
        ? { name: active.name, start: active.selectionStart, end: active.selectionEnd, value: active.value }
        : null;
    const index = forms.indexOf(form);

    let page;

    try {
        const response = await fetch(url, {
            signal: inFlight.signal,
            credentials: 'same-origin',
            headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        });

        // Sent somewhere else (signed out, a security check): go there properly.
        if (! response.ok || new URL(response.url).pathname !== new URL(url, window.location.origin).pathname) {
            window.location.assign(url);
            return;
        }

        page = new DOMParser().parseFromString(await response.text(), 'text/html');
    } catch (error) {
        if (error.name !== 'AbortError') window.location.assign(url);

        return;
    }

    const fresh = page.querySelector('main');

    if (! fresh) {
        window.location.assign(url);
        return;
    }

    // What has been typed since the request left is newer than its answer.
    const typedSince = typing && document.activeElement === active ? active.value : null;

    clearFloating(main);
    main.innerHTML = fresh.innerHTML;

    // The heading above the list often says what is being shown.
    const heading = document.querySelector('[data-live-header]');
    const freshHeading = page.querySelector('[data-live-header]');

    if (heading && freshHeading) heading.innerHTML = freshHeading.innerHTML;

    if (page.title) document.title = page.title;

    window.history.replaceState(null, '', url);

    if (typing) {
        const again = [...main.querySelectorAll('form[data-live-filter]')][index];
        const field = again ? [...again.elements].find(element => element.name === typing.name) : null;

        if (field) {
            if (typedSince !== null && typedSince !== typing.value) field.value = typedSince;

            field.focus({ preventScroll: true });

            try {
                const end = field.value.length;
                field.setSelectionRange(Math.min(typing.start ?? end, end), Math.min(typing.end ?? end, end));
            } catch {
                // Not every kind of field has a cursor to place.
            }

            // Caught up with what was typed while the first answer was on its way.
            if (typedSince !== null && typedSince !== typing.value) schedule(again, TYPING_PAUSE);
        }
    }
}

function schedule(form, wait) {
    clearTimeout(timer);
    timer = setTimeout(() => refresh(form), wait);
}

export function startLiveFilters() {
    // The button is not needed once filtering answers each keystroke.
    document.documentElement.classList.add('live-filters');

    document.addEventListener('input', event => {
        const form = event.target.closest?.('form[data-live-filter]');

        if (! form || ! event.target.name) return;

        // A box being typed in waits for a pause; anything chosen goes at once.
        const typed = event.target.matches('input:not([type=checkbox]):not([type=radio]):not([type=date]):not([type=month]):not([type=hidden]), textarea');

        schedule(form, typed ? TYPING_PAUSE : 0);
    });

    document.addEventListener('change', event => {
        const form = event.target.closest?.('form[data-live-filter]');

        if (form && event.target.name) schedule(form, 0);
    });

    // Enter still works, and answers at once.
    document.addEventListener('submit', event => {
        const form = event.target.closest?.('form[data-live-filter]');

        if (! form) return;

        event.preventDefault();
        schedule(form, 0);
    });
}
