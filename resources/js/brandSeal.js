/**
 * The CSC seal that sits inside the auth splash's spinning rings.
 *
 * The splash is raised the moment someone clicks "Sign in", which is the worst
 * possible time to *start* fetching an image: the overlay's first paint is
 * exactly when a cold seal shows as a blank — or, worse, half-drawn — circle.
 * So the fetch and the decode are both kicked off once at boot, and the splash
 * asks for the result rather than for the file.
 *
 * `decode()` is the part that does the real work here. A warmed cache still
 * lets the browser paint a progressive PNG in bands; awaiting the decode means
 * the seal is only ever handed over as something that can be drawn whole on the
 * next frame. Almost always that promise is already settled by the time anyone
 * gets to a sign-in, so the seal is simply there in the overlay's first frame.
 *
 * The 256px rendition, not the 4499×4269 master: it renders at 48px, and it is
 * the same file AppLogo and both layouts use, so reaching a sign-in page has
 * usually put it in cache already.
 */
const SEAL_URL = '/images/csc-logo-256.png';

let pending = null;

/**
 * Resolves with the seal's URL once it is fetched and decoded; rejects if it
 * cannot be loaded, which callers treat as "draw no seal".
 */
export const sealUrl = () => {
    if (!pending) {
        const image = new Image();
        image.src = SEAL_URL;

        // decode() rejects on a broken image, so the load failure arrives on
        // the same path as a decode failure and needs no separate onerror.
        pending = image.decode().then(() => SEAL_URL);
    }

    return pending;
};

/** Begin the fetch/decode now, without waiting on the result. */
export const warmSeal = () => { sealUrl().catch(() => {}); };
