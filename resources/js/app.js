import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

/**
 * Save an element as a PNG.
 *
 * An order form is usually sent on WhatsApp, where a picture is opened and a
 * PDF is not. html2canvas is pulled in only when someone actually asks for an
 * image, so the 45 KB never loads on the pages that never need it.
 */
window.saveAsImage = async (selector, filename, button = null) => {
    const node = document.querySelector(selector);

    if (!node) {
        return;
    }

    const label = button?.textContent;

    try {
        if (button) {
            button.disabled = true;
            button.textContent = 'Rendering…';
        }

        const { default: html2canvas } = await import('html2canvas');

        const canvas = await html2canvas(node, {
            // Twice the pixels: the result is still legible when WhatsApp
            // compresses it and someone reads it on a phone.
            scale: 2,
            backgroundColor: '#ffffff',
            logging: false,
            useCORS: true,
        });

        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));

        if (!blob) {
            throw new Error('The image could not be produced.');
        }

        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } catch (error) {
        console.error('[pharmaco] saving the image failed', error);
        window.alert('The image could not be produced. Use Download PDF instead.');
    } finally {
        if (button) {
            button.disabled = false;
            button.textContent = label;
        }
    }
};
