/*
 * The attendance station's service worker.
 *
 * IndexedDB keeps the roster and the scan queue alive offline, but none of that
 * helps if the page itself will not load — and a tablet at a venue gets locked,
 * pocketed, and reopened an hour later with no signal. This worker is what makes
 * that reopen work: it keeps a copy of the station page and its assets, so the
 * app boots from the device and then reads its state out of IndexedDB.
 *
 * Written by hand rather than generated. The caching rules here are few and
 * specific, and a precache manifest would have to be regenerated on every build
 * to stay honest — this stays correct without that coupling.
 */

/*
 * Bumped when the caching rules change, not when the app does. activate()
 * drops every cache but this one, so a rename is what evicts pages cached
 * under the old rules — here, stations cached before /scan/ was recognised.
 */
const CACHE = 'csc-tims-scanner-v2';

/*
 * The station pages. Their assets are hashed, so they are cached as they appear.
 *
 * Both doors are listed: the signed-in staff scanner and the public scan link.
 * The public one matters more here, not less — it runs on a volunteer's own
 * phone, which is far likelier to be locked, backgrounded and reopened in a
 * dead spot than a tablet the office prepared.
 */
const SCANNER_PATHS = ['/admin/scanner', '/station/'];

self.addEventListener('install', (event) => {
    // Take over immediately. A half-updated worker sitting in "waiting" while
    // an operator is mid-session is worse than a brief double-fetch.
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const names = await caches.keys();

            await Promise.all(
                names.filter((name) => name !== CACHE).map((name) => caches.delete(name))
            );

            await self.clients.claim();
        })()
    );
});

self.addEventListener('message', (event) => {
    if (event.data === 'skip-waiting') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Only GETs against this origin are ever cached. In particular the roster
    // download and the sync POST must always hit the network: one is
    // deliberately re-fetched to pick up other stations' check-ins, and the
    // other is a write.
    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate' && SCANNER_PATHS.some((path) => url.pathname.startsWith(path))) {
        event.respondWith(networkFirst(request));

        return;
    }

    if (isBuildAsset(url)) {
        event.respondWith(cacheFirst(request));
    }
});

/**
 * Vite's output, all of it content-hashed.
 *
 * That hashing is what makes cache-first safe here: a changed file is a
 * different URL, so a stale copy can never be served in place of a new one.
 */
function isBuildAsset(url) {
    return url.pathname.startsWith('/build/');
}

/**
 * Fresh page when there is a network, the last known good page when there is
 * not.
 *
 * Network-first rather than cache-first because the station page carries the
 * list of trainings available to download, and an operator setting up in the
 * office should see today's, not last week's.
 */
async function networkFirst(request) {
    const cache = await caches.open(CACHE);

    try {
        const response = await fetch(request);

        if (response.ok) {
            cache.put(request, response.clone());
        }

        return response;
    } catch (error) {
        const cached = await cache.match(request, { ignoreSearch: true });

        if (cached) {
            return cached;
        }

        throw error;
    }
}

async function cacheFirst(request) {
    const cache = await caches.open(CACHE);
    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (response.ok) {
        cache.put(request, response.clone());
    }

    return response;
}
