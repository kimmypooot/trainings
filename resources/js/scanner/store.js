/**
 * The scanner's local database.
 *
 * IndexedDB rather than localStorage, for one reason that matters more than any
 * other: localStorage is synchronous, string-only and capped at a few megabytes,
 * and a roster of four hundred participants plus a day's scans would sit right
 * at that edge. It is also cleared by "clear browsing data" in ways that are
 * hard to reason about mid-session. IndexedDB stores structured records, has no
 * practical size limit at this scale, and survives a reload, a crash and a flat
 * battery — which is the whole promise the station is making to whoever is
 * holding it at the door.
 *
 * Two stores:
 *
 *  - `rosters`, one record per training, holding everything needed to decide a
 *    scan without a network: participants, token digests, the day calendar and
 *    the lateness rule.
 *  - `scans`, an append-only queue. A scan is written here *before* anything is
 *    shown on screen, so a device that dies between the beep and the render has
 *    still recorded the arrival.
 */

const DB_NAME = 'csc-tims-scanner';
const DB_VERSION = 1;

export const ROSTERS = 'rosters';
export const SCANS = 'scans';

let dbPromise = null;

/**
 * Open the database, creating the stores on first run.
 *
 * Cached: opening is cheap but not free, and the scan path runs it per frame's
 * worth of work in the worst case.
 */
function openDb() {
    if (dbPromise) {
        return dbPromise;
    }

    dbPromise = new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;

            if (!db.objectStoreNames.contains(ROSTERS)) {
                db.createObjectStore(ROSTERS, { keyPath: 'training_id' });
            }

            if (!db.objectStoreNames.contains(SCANS)) {
                const scans = db.createObjectStore(SCANS, { keyPath: 'client_id' });

                // Every read of the queue is either "this training's scans" or
                // "everything still pending", so both get an index rather than a
                // full cursor walk once a long training has hundreds of rows.
                scans.createIndex('by_training', 'training_id');
                scans.createIndex('by_state', 'state');
            }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });

    return dbPromise;
}

/**
 * Run `work` against a store and resolve when the *transaction* completes.
 *
 * Resolving on the request rather than the transaction is the classic IndexedDB
 * mistake: the callback fires before the write is durable, so a reader a
 * millisecond later can miss it. Waiting for `oncomplete` is what makes "the
 * scan is saved" true rather than merely likely.
 */
async function withStore(name, mode, work) {
    const db = await openDb();

    return new Promise((resolve, reject) => {
        const transaction = db.transaction(name, mode);
        const store = transaction.objectStore(name);
        let result;

        try {
            result = work(store);
        } catch (error) {
            reject(error);

            return;
        }

        transaction.oncomplete = () => resolve(result instanceof IDBRequest ? result.result : result);
        transaction.onerror = () => reject(transaction.error);
        transaction.onabort = () => reject(transaction.error);
    });
}

/* -------------------------------------------------------------------------- */
/* Rosters                                                                     */
/* -------------------------------------------------------------------------- */

/**
 * Store a downloaded bundle, replacing any earlier copy of the same training.
 *
 * Replacing wholesale is deliberate. A re-download is how staff pick up
 * check-ins another station recorded this morning, and merging two partial
 * views of the same roster would be a source of bugs with no upside — the
 * server copy is always the more complete one.
 */
export async function saveRoster(trainingId, bundle) {
    await withStore(ROSTERS, 'readwrite', (store) =>
        store.put({
            training_id: trainingId,
            saved_at: new Date().toISOString(),
            ...bundle,
        })
    );
}

export async function getRoster(trainingId) {
    return withStore(ROSTERS, 'readonly', (store) => store.get(trainingId));
}

export async function listRosters() {
    return withStore(ROSTERS, 'readonly', (store) => store.getAll());
}

/**
 * Drop a roster and everything scanned against it.
 *
 * Both in one transaction is not possible across stores here, so the scans go
 * first: a queue with no roster is unreadable, whereas a roster with no queue is
 * merely empty. Failing in that order leaves the least confusing state.
 */
export async function deleteRoster(trainingId) {
    const scans = await scansFor(trainingId);

    await withStore(SCANS, 'readwrite', (store) => {
        scans.forEach((scan) => store.delete(scan.client_id));
    });

    await withStore(ROSTERS, 'readwrite', (store) => store.delete(trainingId));
}

/* -------------------------------------------------------------------------- */
/* Scan queue                                                                  */
/* -------------------------------------------------------------------------- */

/**
 * Append a scan.
 *
 * `client_id` is generated on the device and is what makes a retry safe: the
 * server echoes it back, so a response lost to a dropped connection can be
 * re-sent without the station losing track of which reply belongs to which row.
 */
export async function addScan(scan) {
    const record = {
        state: 'pending',
        ...scan,
    };

    await withStore(SCANS, 'readwrite', (store) => store.put(record));

    return record;
}

export async function scansFor(trainingId) {
    const rows = await withStore(SCANS, 'readonly', (store) =>
        store.index('by_training').getAll(trainingId)
    );

    return rows ?? [];
}

/**
 * Everything still waiting to reach the server, oldest first.
 *
 * Order matters: attendance is a record of arrivals, and flushing them in the
 * order they happened keeps the server's audit trail readable even though the
 * result would be identical either way.
 */
export async function pendingScans() {
    const rows = await withStore(SCANS, 'readonly', (store) =>
        store.index('by_state').getAll('pending')
    );

    return (rows ?? []).sort((a, b) => a.scanned_at.localeCompare(b.scanned_at));
}

/**
 * Apply the server's verdicts to the queue.
 *
 * `synced` and `duplicate` both mean the record is safely on the server, so both
 * leave the queue. `failed` stays, but is not retried automatically — it is
 * shown to the operator instead, because a scan the server refuses will be
 * refused again and a silent retry loop would just hide the problem.
 */
export async function applySyncResults(results) {
    return withStore(SCANS, 'readwrite', (store) => {
        results.forEach((result) => {
            const request = store.get(result.client_id);

            request.onsuccess = () => {
                const scan = request.result;

                if (!scan) {
                    return;
                }

                store.put({
                    ...scan,
                    state: result.status === 'rejected' ? 'failed' : 'synced',
                    synced_at: new Date().toISOString(),
                    server_status: result.status,
                    // Echoed back by the server rather than trusted from the
                    // local record, so the badge reflects what actually
                    // happened rather than what the device intended.
                    dry_run: Boolean(result.dry_run),
                    message: result.message ?? null,
                });
            };
        });
    });
}

/**
 * Drop every rehearsal scan for a training.
 *
 * Rehearsals are the one kind of scan it is safe to destroy on the device,
 * because by definition the server never kept a copy — so this is offered as a
 * plain button, where clearing real scans deliberately is not.
 */
export async function deleteTestScans(trainingId) {
    const scans = await scansFor(trainingId);

    await withStore(SCANS, 'readwrite', (store) => {
        scans.filter((scan) => scan.dry_run).forEach((scan) => store.delete(scan.client_id));
    });
}

/** Put failed rows back in the queue, for the operator's "retry" button. */
export async function retryFailed(trainingId) {
    const scans = await scansFor(trainingId);

    await withStore(SCANS, 'readwrite', (store) => {
        scans
            .filter((scan) => scan.state === 'failed')
            .forEach((scan) => store.put({ ...scan, state: 'pending', message: null }));
    });
}
