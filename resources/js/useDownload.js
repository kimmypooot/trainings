import { ref, onBeforeUnmount } from 'vue';

/**
 * Pending state for a streamed file download.
 *
 * Exports are the one action in this app that leaves through a plain `<a href>`
 * rather than an Inertia visit, and so the one action the page learns nothing
 * about. Clicking "Export" on a full participants register runs a real query
 * server-side and then streams a spreadsheet, and for the whole of that the
 * button looked exactly like a button that had not been pressed. The two costs
 * of that were paid by different people: the person waiting had no idea whether
 * their click registered, and the server ran the entire export again for every
 * extra click they made trying to find out.
 *
 * There is no browser event for "a download started", so the signal is a cookie
 * the server hands back (`SpreadsheetExport::handshakeCookie`): we mint a
 * throwaway token, send it as `?_dl=`, and watch `document.cookie` for it to
 * come home.
 *
 * What this measures is time-to-first-byte, not the transfer. Once headers land
 * the browser's own download indicator is a better and more honest reporter of
 * the rest, and the button's job — say the click landed, refuse to fire the
 * query twice — is done. Do not extend it into a claim about the whole file.
 */

const POLL_MS = 250;

/*
 * The backstop. If the cookie never arrives — an export that errors into an
 * error page, a dropped connection, a proxy eating Set-Cookie — the button must
 * still come back, because a control stuck in a pending state forever is worse
 * than one that never showed a pending state at all. Long enough not to trip a
 * genuinely slow report, short enough to be recoverable.
 */
const TIMEOUT_MS = 45000;

const readCookie = (name) =>
    document.cookie
        .split('; ')
        .find((row) => row.startsWith(`${name}=`))
        ?.slice(name.length + 1) ?? null;

// Cleared as soon as it is read: it is a one-shot signal, and leaving it set
// would make the *next* download resolve instantly against a stale token.
const clearCookie = (name) => {
    document.cookie = `${name}=; Max-Age=0; path=/`;
};

export function useDownload() {
    /** The URL currently downloading, or null. Bind with `downloading === url`. */
    const downloading = ref(null);

    let poller = null;
    let timeout = null;

    const stop = () => {
        clearInterval(poller);
        clearTimeout(timeout);
        poller = null;
        timeout = null;
        downloading.value = null;
    };

    /**
     * Start a download and hold the button until the browser takes it over.
     *
     * Re-clicking the same URL while it is pending is ignored rather than
     * queued — that click is the duplicate this exists to prevent. Clicking a
     * *different* export supersedes the first: only one button can show a
     * pending state, and the newer click is the one the user is waiting on.
     */
    const start = (url) => {
        if (downloading.value === url) return;

        stop();

        const token = Math.random().toString(36).slice(2) + Date.now().toString(36);

        // Parsed rather than concatenated so an export URL that already carries
        // filters (`?status=paid`) gains a parameter instead of a second `?`.
        const target = new URL(url, window.location.origin);
        target.searchParams.set('_dl', token);

        // Any token left over from an abandoned download would otherwise be
        // matched on the first poll and end this one immediately.
        clearCookie('dl_token');

        downloading.value = url;

        // Content-Disposition: attachment, so this starts a download rather
        // than navigating; the page and its Vue state stay exactly as they are.
        window.location.href = target.toString();

        poller = setInterval(() => {
            if (readCookie('dl_token') === token) {
                clearCookie('dl_token');
                stop();
            }
        }, POLL_MS);

        timeout = setTimeout(stop, TIMEOUT_MS);
    };

    onBeforeUnmount(stop);

    return { downloading, start };
}
