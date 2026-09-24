{{--
    Printing exactly one slip, and nothing else.

    Hiding the rest of the page with CSS is the usual trick and it is the reason
    bills came out as several identical pages: the slip sits inside a
    full-screen flex layout — and, after a sale, inside a fixed overlay — and a
    fixed ancestor is painted onto every page the browser makes, while hidden
    siblings still take up room and push out blanks.

    So the slip is moved, for the moment of printing, to a container that is a
    direct child of <body> with nothing around it. Every other child of body is
    hidden outright. Afterwards it goes back where it was, so the screen is
    unchanged and Alpine's bindings inside it keep working.
--}}
<div id="print-sheet" class="hidden"></div>

@once
    @push('scripts')
        <script>
            (function () {
                let home = null;
                let moved = null;

                /*
                 * The container has to be a child of <body> itself: the print
                 * rule hides body's other children, and a sheet nested inside
                 * the layout would have its own ancestor hidden out from under
                 * it — printing nothing at all. Blade cannot place it there
                 * from inside a page, so it is lifted on load.
                 */
                document.addEventListener('DOMContentLoaded', function () {
                    const sheet = document.getElementById('print-sheet');

                    if (sheet && sheet.parentElement !== document.body) {
                        document.body.appendChild(sheet);
                    }
                });

                /** Moves the one printable slip out, prints, and puts it back. */
                window.printSlip = function () {
                    const slip = document.querySelector('.print-area');
                    const sheet = document.getElementById('print-sheet');

                    if (! slip || ! sheet) {
                        window.print();
                        return;
                    }

                    // Remembered precisely, so it returns to the same position
                    // among its siblings rather than to the end of its parent.
                    home = { parent: slip.parentNode, next: slip.nextSibling };
                    moved = slip;
                    sheet.appendChild(slip);

                    window.print();
                };

                // Chrome fires this once the dialog closes, whether the job was
                // sent or cancelled — either way the page has to be whole again.
                window.addEventListener('afterprint', function () {
                    if (moved && home) {
                        home.parent.insertBefore(moved, home.next);
                    }

                    moved = null;
                    home = null;
                });
            })();
        </script>
    @endpush
@endonce
