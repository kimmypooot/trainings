/**
 * Laravel's CSRF cookie, read fresh from the browser.
 *
 * Inertia's own visits (and anything routed through axios) attach this
 * automatically, which is why nothing in the app has needed it until now. A
 * plain `fetch()` outside that pipeline — the header bell's popover and the
 * notifications list both fire one to mark a single notification read
 * without turning that click into a full Inertia visit — gets no such
 * header for free, so it has to read the cookie itself.
 *
 * The scanner's offline station carries its own copy of this exact function
 * (`scanner/sync.js`) rather than importing this one: that module is a
 * separate, standalone build target (see CLAUDE.md's note on
 * `resources/js/scanner/`), and its version reads the cookie specifically
 * *because* a station page can be served from an hours-old service-worker
 * cache — the same reasoning, arrived at independently, does not make the
 * two the same dependency.
 */
export function csrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}
