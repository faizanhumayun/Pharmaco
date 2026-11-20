import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/*
 * This file holds NO design values. Every colour, font and corner is defined
 * once, in resources/css/pharmaco/tokens.css, and read from there as a CSS
 * variable. To change how the product looks, change it there.
 *
 * What is here is only the wiring: which Tailwind utility reads which variable.
 * The screens were written with Tailwind's stock palette names, so those names
 * are pointed at the theme rather than every class on every screen rewritten:
 *
 *   gray, slate            → the neutrals (paper → ink)
 *   emerald, green, indigo → the accent, which follows the business's side
 *   red                    → the alert scale
 *
 * Amber is left as Tailwind's own: it is the warning state, and the design
 * system has no colour of its own for it.
 */

// A colour read from a theme variable, usable with opacity (bg-ink/60).
const token = (name) => `rgb(var(--${name}) / <alpha-value>)`;

const steps = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];
const scale = (prefix) => Object.fromEntries(steps.map((step) => [step, token(`${prefix}-${step}`)]));

const neutrals = scale('neutral');
const accent = scale('accent');

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        // The dropdown menus are built in script, with the same classes.
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            colors: {
                // The brand colours by name: bg-paper, text-ink, border-line…
                paper: token('paper-rgb'),
                surface: token('surface-rgb'),
                ink: token('ink-rgb'),
                muted: token('muted-rgb'),
                line: token('line-rgb'),
                pharmacy: { DEFAULT: token('pharmacy-rgb'), tint: token('pharmacy-tint-rgb') },
                distributor: { DEFAULT: token('distributor-rgb'), tint: token('distributor-tint-rgb') },
                alert: token('alert-rgb'),

                // The page behind every application screen: bg-app.
                app: token('app-background-rgb'),

                gray: neutrals,
                slate: neutrals,
                emerald: accent,
                green: accent,
                indigo: accent,
                red: scale('alert'),
            },
            fontFamily: {
                display: ['var(--font-display)'],
                sans: ['var(--font-sans)', ...defaultTheme.fontFamily.sans],
                mono: ['var(--font-mono)', ...defaultTheme.fontFamily.mono],
            },
            borderRadius: {
                // lg is left at Tailwind's 8px: the 16px radius is kept for the
                // landing page's two columns, which use the ph-column class.
                sm: 'var(--radius-sm)',
                md: 'var(--radius-md)',
            },
            boxShadow: {
                // Surfaces are separated by a hairline, not a shadow. Larger
                // shadows stay, for what floats: menus and dialogs.
                sm: '0 0 #0000',
            },
        },
    },

    plugins: [forms],
};
