{{--
    pharmacoScreen(): this computer's screen setting, 'standard' or 'strong'
    (Strong colours, for a dull or washed-out display). Set from the device
    chip; pharmacoScreen('strong') switches it, pharmacoScreen() reads it.

    themeColor(): a colour from the theme file (resources/css/pharmaco/tokens.css)
    for code that cannot use a CSS class — the charts.

        themeColor('chart-1')        the colour
        themeColor('chart-1', 0.2)   the same, mostly transparent

    Inline and in <head> on purpose: the charts are drawn by plain scripts in
    the page, which run before the bundled JavaScript has loaded.
--}}
<script>
    /* Strong colours, for a dull screen: a choice of this computer, kept in
       its browser, applied before anything is drawn (tokens.css). */
    window.pharmacoScreen = function (mode) {
        try {
            if (mode !== undefined) localStorage.setItem('pharmaco-screen', mode);
            mode = localStorage.getItem('pharmaco-screen') || 'standard';
        } catch (e) { mode = mode || 'standard'; }

        document.documentElement.classList.toggle('screen-strong', mode === 'strong');

        return mode;
    };
    window.pharmacoScreen();

    window.themeColor = function (name, alpha) {
        var value = getComputedStyle(document.body || document.documentElement).getPropertyValue('--' + name).trim();

        return alpha === undefined || alpha >= 1 ? 'rgb(' + value + ')' : 'rgb(' + value + ' / ' + alpha + ')';
    };
</script>
