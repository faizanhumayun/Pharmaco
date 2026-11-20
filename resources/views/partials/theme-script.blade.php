{{--
    themeColor(): a colour from the theme file (resources/css/pharmaco/tokens.css)
    for code that cannot use a CSS class — the charts.

        themeColor('chart-1')        the colour
        themeColor('chart-1', 0.2)   the same, mostly transparent

    Inline and in <head> on purpose: the charts are drawn by plain scripts in
    the page, which run before the bundled JavaScript has loaded.
--}}
<script>
    window.themeColor = function (name, alpha) {
        var value = getComputedStyle(document.body || document.documentElement).getPropertyValue('--' + name).trim();

        return alpha === undefined || alpha >= 1 ? 'rgb(' + value + ')' : 'rgb(' + value + ' / ' + alpha + ')';
    };
</script>
