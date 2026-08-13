import { applySyncResults, pendingScans } from './store';

/**
 * Getting the queue back to the server.
 *
 * The station is optimistic about the network and pessimistic about its own
 * memory: a scan is durable on the device the instant it happens, and reaching
 * the server is treated as a separate thing that may take hours. Sync is
 * therefore always safe to run, always safe to run twice, and never destructive
 * — nothing leaves the queue until the server has said what became of it.
 */

/** Batched so one long session cannot post a megabyte in a single request. */
const BATCH_SIZE = 200;

/**
 * Laravel's session cookie, read fresh rather than from the page's meta tag.
 *
 * This matters here specifically: the station page may have been served from
 * the service worker cache hours ago, so any CSRF token baked into that HTML is
 * stale. The XSRF-TOKEN cookie is whatever the browser holds right now, which is
 * the value the server will actually accept.
 */
function csrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * The public station's credential, when there is one.
 *
 * A signed-in staff scanner has no grant and authenticates by cookie; a station
 * opened from a scan link carries one and no session at all. Both call the same
 * functions, so the header is simply absent in the first case rather than the
 * two paths forking.
 */
function grantHeader(grant) {
    return grant ? { 'X-Scan-Grant': grant } : {};
}

/**
 * What to tell the operator when the credential is refused.
 *
 * Worth distinguishing: "sign in again" is useless advice to a volunteer who
 * never had an account, and "enter the code again" is useless to staff who
 * never had a code.
 */
function expiredMessage(grant) {
    return grant
        ? 'This scanning link needs its code entered again. Your scans are safe on this device.'
        : 'Your session has expired. Sign in again on this device, then sync.';
}

function chunk(items, size) {
    const chunks = [];

    for (let index = 0; index < items.length; index += size) {
        chunks.push(items.slice(index, index + size));
    }

    return chunks;
}

/**
 * Flush every pending scan, grouped by the training it belongs to.
 *
 * Grouped because the server validates each batch against one training's roster
 * — that is what keeps a tampered payload from checking someone in elsewhere —
 * and because a station that ran two sessions in a day should still be able to
 * send both without the operator choosing between them.
 *
 * Returns a per-training summary; it never throws for a rejected *scan*, only
 * for a request that could not be made at all.
 */
export async function syncPending(syncUrl, { trainingId = null, grant = null } = {}) {
    const pending = await pendingScans();
    const scans = trainingId === null ? pending : pending.filter((scan) => scan.training_id === trainingId);

    if (scans.length === 0) {
        return { sent: 0, synced: 0, duplicate: 0, rejected: 0 };
    }

    /*
     * Grouped by training *and* by whether the scan was a rehearsal.
     *
     * The second half matters more than it looks. A station that was in test
     * mode and then left it would otherwise flush its practice scans in the
     * same batch as real ones, under whichever flag happened to be current —
     * and a rehearsal silently becoming real attendance is the one outcome test
     * mode exists to prevent. A scan is marked when it is recorded, and that
     * marking travels with it.
     */
    const grouped = scans.reduce((groups, scan) => {
        const key = `${scan.training_id}:${scan.dry_run ? 1 : 0}`;

        (groups[key] ??= []).push(scan);

        return groups;
    }, {});

    const summary = { sent: 0, synced: 0, duplicate: 0, rejected: 0 };

    for (const [key, rows] of Object.entries(grouped)) {
        const [training, dryRun] = key.split(':');

        for (const batch of chunk(rows, BATCH_SIZE)) {
            const results = await postBatch(syncUrl, Number(training), batch, grant, dryRun === '1');

            await applySyncResults(results);

            summary.sent += batch.length;

            results.forEach((result) => {
                if (result.status === 'rejected') {
                    summary.rejected += 1;
                } else if (result.status === 'duplicate') {
                    summary.duplicate += 1;
                } else {
                    summary.synced += 1;
                }
            });
        }
    }

    return summary;
}

async function postBatch(syncUrl, trainingId, batch, grant = null, dryRun = false) {
    const response = await fetch(syncUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
            ...grantHeader(grant),
        },
        body: JSON.stringify({
            training_id: trainingId,
            // Advisory only. The staff scanner refuses it from anyone but a
            // super administrator, and the public station ignores it entirely
            // in favour of the flag stored on the link itself.
            dry_run: dryRun,
            scans: batch.map((scan) => ({
                client_id: scan.client_id,
                registration_id: scan.registration_id,
                // The moment at the door, not the moment of the request — this
                // is what puts an 8am arrival on day 2 rather than on whatever
                // day the queue happened to drain.
                scanned_at: scan.scanned_at,
            })),
        }),
    });

    if (response.status === 419 || response.status === 401) {
        // The credential died while the tablet was offline. Nothing is lost —
        // the queue is untouched — but somebody has to prove themselves again.
        throw new SyncError(expiredMessage(grant), true);
    }

    if (!response.ok) {
        throw new SyncError(`The server refused the batch (${response.status}). Nothing was lost — try again.`);
    }

    const payload = await response.json();

    return payload.results ?? [];
}

export class SyncError extends Error {
    constructor(message, requiresSignIn = false) {
        super(message);
        this.name = 'SyncError';
        this.requiresSignIn = requiresSignIn;
    }
}

/**
 * Fetch a roster bundle for offline use.
 *
 * Kept beside sync rather than in the store because it is the other half of the
 * same bargain: this is the only moment the station needs a network before the
 * session starts.
 */
export async function downloadRoster(rosterUrl, { grant = null } = {}) {
    const response = await fetch(rosterUrl, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...grantHeader(grant),
        },
    });

    if (response.status === 419 || response.status === 401) {
        throw new SyncError(expiredMessage(grant), true);
    }

    if (!response.ok) {
        throw new SyncError(`Could not download the roster (${response.status}).`);
    }

    return response.json();
}
