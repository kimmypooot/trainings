// Boot preloader timing, as in IMIS (new-imis resources/js/app.js). SHOW_DELAY
// must match the fade-in delay in resources/css/imis-loading.css.
const SHOW_DELAY = 120;
const MIN_VISIBLE = 400;
const FONT_WAIT = 1500;

/**
 * Removes the IMIS loading screen drawn by resources/views/partials/imis-boot
 * once the first page has rendered and the fonts are in, as IMIS does
 * (new-imis resources/js/app.js).
 *
 * The navy preloader fades in only after SHOW_DELAY, so a page that is ready
 * first never shows it: it is just removed. Once it has shown, it stays for
 * MIN_VISIBLE, so it reads as a deliberate hand-off rather than a flicker. The
 * "Opening <system>" card (data-instant) goes at once, because the page
 * re-draws it.
 */
export async function dismissBootPreloader() {
    const preloader = document.getElementById('boot-preloader');

    if (!preloader) return;

    // Already covered by a page-to-page veil (the Recruitment Portal's
    // navigateTo): showing the navy screen under it would be a second fade.
    if (document.getElementById('csc-page-veil')) {
        preloader.remove();
        return;
    }

    if (preloader.hasAttribute('data-instant')) {
        window.requestAnimationFrame(() => preloader.remove());
        return;
    }

    await Promise.race([document.fonts?.ready, new Promise((resolve) => window.setTimeout(resolve, FONT_WAIT))]);

    const shownFor = performance.now() - (window.__bootAt ?? 0) - SHOW_DELAY;

    if (shownFor < 0) {
        preloader.remove();
        return;
    }

    window.setTimeout(() => {
        preloader.classList.add('is-hidden');
        preloader.setAttribute('aria-busy', 'false');
        preloader.addEventListener('transitionend', (event) => event.target === preloader && preloader.remove());
        // Fallback in case transitionend never fires.
        window.setTimeout(() => preloader.remove(), 1000);
    }, Math.max(0, MIN_VISIBLE - shownFor));
}
