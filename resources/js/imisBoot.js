/**
 * Removes the IMIS loading screen drawn by resources/views/partials/imis-boot
 * once the first page has rendered, as IMIS does (new-imis resources/js/app.js):
 * the navy preloader fades out after a short beat; the "Opening <system>"
 * card (data-instant) goes at once, because the page re-draws it.
 */
export function dismissBootPreloader() {
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

    window.setTimeout(() => {
        preloader.classList.add('is-hidden');
        preloader.setAttribute('aria-busy', 'false');
        preloader.addEventListener('transitionend', (event) => event.target === preloader && preloader.remove());
        // Fallback in case transitionend never fires.
        window.setTimeout(() => preloader.remove(), 1200);
    }, 300);
}
