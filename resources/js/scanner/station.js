import { computed, onBeforeUnmount, onMounted, ref, unref, watch } from 'vue';
import { Scanner, beep, buzz } from './camera';
import { localTime, resolveScan } from './resolve';
import {
    addScan,
    deleteRoster,
    deleteTestScans,
    getRoster,
    listRosters,
    retryFailed,
    saveRoster,
    scansFor,
} from './store';
import { SyncError, csrfToken, downloadRoster, syncPending } from './sync';

/**
 * Everything an attendance station does, minus how it looks.
 *
 * There are two stations — the signed-in staff scanner and the public one
 * opened from a scan link — and they differ only in chrome and in how the
 * device proves itself. The parts that are genuinely hard are the parts they
 * share: writing a scan to IndexedDB before it is ever rendered, draining the
 * queue without losing rows, keeping a camera and a roster in step. Those are
 * here, once, so a fix to any of them cannot land on one door and miss the
 * other.
 *
 * The composable owns state and lifecycle; the pages own layout and wording.
 */
export function useScanStation({
    syncUrl,
    /*
     * Where to admit a walk-in, or null on a station that may not.
     *
     * The public door gets null and therefore gets no walk-in affordance at
     * all. That is the authority boundary in one argument: admitting enrols a
     * person and, on a paid run, issues a promissory note in their name, and
     * an unauthenticated volunteer link must never do either. The server
     * refuses it independently — this only keeps the offer off a screen that
     * could not act on it.
     */
    walkInUrl = null,
    grant = null,
    storageKey = 'csc-tims-scanner:last-training',
    // A reopened tablet should land back on the training it was scanning. The
    // public station opts out: it is pinned to one training by its link, and
    // reopening a *different* link must never restore the previous one's roster.
    restoreLast = true,
    /*
     * Whether scans recorded here are rehearsals.
     *
     * A ref on the staff scanner, where a super administrator turns it on and
     * off; a fixed value on the public station, where it belongs to the link
     * and the phone gets no say. Either way it is stamped onto each scan as it
     * is recorded, so a scan's nature cannot be changed afterwards by toggling
     * the station.
     */
    testMode = false,
} = {}) {
    /* ---------------------------------------------------------------------- */
    /* State                                                                   */
    /* ---------------------------------------------------------------------- */

    const roster = ref(null);
    const scans = ref([]);
    const storedRosters = ref([]);

    const video = ref(null);
    const scanner = ref(null);
    const cameraState = ref('idle'); // idle | starting | running | denied | unsupported
    const cameraError = ref(null);
    const torchOn = ref(false);
    const hasTorch = ref(false);

    const verdict = ref(null);
    let verdictTimer = null;

    const admitting = ref(false);

    const online = ref(navigator.onLine);
    const syncState = ref('idle'); // idle | syncing | error
    const syncMessage = ref(null);
    const lastSyncedAt = ref(null);
    const downloading = ref(null);

    /**
     * Raised when the server refuses the device's credential.
     *
     * Surfaced as its own flag rather than folded into `syncState` because the
     * two demand different things of the operator: a sync error means try
     * again, this means prove yourself again — and the queue must be visibly
     * intact while they do.
     */
    const credentialExpired = ref(false);

    let syncTimer = null;

    /* ---------------------------------------------------------------------- */
    /* Derived                                                                 */
    /* ---------------------------------------------------------------------- */

    const pendingCount = computed(() => scans.value.filter((scan) => scan.state === 'pending').length);
    const failedCount = computed(() => scans.value.filter((scan) => scan.state === 'failed').length);
    const syncedCount = computed(() => scans.value.filter((scan) => scan.state === 'synced').length);

    /**
     * How many of today's participants are accounted for.
     *
     * Counted against today specifically, not the whole training: on day three
     * of a run, "142 of 160" has to mean today's hall, not the sum of every day.
     */
    const today = computed(() => {
        if (!roster.value) {
            return null;
        }

        const date = new Date();
        const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(
            date.getDate()
        ).padStart(2, '0')}`;

        const running = roster.value.training.days.find((day) => day.date === key) ?? null;

        // A practice station stands in on day 1 when the training is not
        // running, so the roster panel, the "x of y marked" count and marking
        // by hand all stay usable during a rehearsal. Live stations get null,
        // which is what disables all three.
        if (running === null && unref(testMode)) {
            return roster.value.training.days[0] ?? null;
        }

        return running;
    });

    const markedToday = computed(() => {
        if (!roster.value || !today.value) {
            return 0;
        }

        const day = today.value.day;

        return roster.value.participants.filter(
            (participant) =>
                participant.attendance?.[String(day)] ||
                scans.value.some(
                    (scan) =>
                        scan.registration_id === participant.registration_id &&
                        scan.training_day === day &&
                        scan.state !== 'failed'
                )
        ).length;
    });

    /**
     * The roster as a checklist, for the manual fallback.
     *
     * A creased badge, a cracked phone screen, a participant who left their code
     * at the hotel — a station with no way to mark someone by hand sends them to
     * the back of a queue that has no answer for them.
     */
    function rosterRows(term = '') {
        if (!roster.value) {
            return [];
        }

        const day = today.value?.day ?? null;
        const needle = term.trim().toLowerCase();

        return roster.value.participants
            .map((participant) => {
                const fromServer = day === null ? null : participant.attendance?.[String(day)];
                const local = scans.value.find(
                    (scan) =>
                        scan.registration_id === participant.registration_id &&
                        scan.training_day === day &&
                        scan.state !== 'failed'
                );

                return {
                    ...participant,
                    marked: Boolean(fromServer || local),
                    time_in: fromServer?.time_in ?? local?.time_in ?? null,
                    status_label:
                        fromServer?.status_label ??
                        (local?.status === 'late' ? 'Late' : local ? 'Present' : null),
                };
            })
            .filter(
                (row) =>
                    !needle ||
                    row.name.toLowerCase().includes(needle) ||
                    (row.organization ?? '').toLowerCase().includes(needle)
            );
    }

    /** Most recent first — the operator only ever looks at the last few. */
    const activity = computed(() =>
        [...scans.value].sort((a, b) => b.scanned_at.localeCompare(a.scanned_at))
    );

    const syncLabel = computed(() => {
        if (credentialExpired.value) {
            return 'Not authorised';
        }

        if (syncState.value === 'syncing') {
            return 'Syncing…';
        }

        if (failedCount.value > 0) {
            return `${failedCount.value} failed`;
        }

        if (pendingCount.value > 0) {
            return `${pendingCount.value} pending`;
        }

        return online.value ? 'All synced' : 'Offline — nothing pending';
    });

    const syncTone = computed(() => {
        if (syncState.value === 'error' || failedCount.value > 0 || credentialExpired.value) {
            return 'danger';
        }

        if (syncState.value === 'syncing') {
            return 'info';
        }

        return pendingCount.value > 0 ? 'warning' : 'success';
    });

    /* ---------------------------------------------------------------------- */
    /* Roster management                                                       */
    /* ---------------------------------------------------------------------- */

    async function refreshStoredRosters() {
        storedRosters.value = await listRosters();
    }

    /**
     * Download a roster and make it the active one.
     *
     * Safe to repeat: re-downloading mid-session is how a station picks up
     * check-ins recorded elsewhere, and the local queue is untouched by it.
     */
    async function download({ id, roster_url: rosterUrl }) {
        downloading.value = id;
        syncMessage.value = null;

        try {
            const bundle = await downloadRoster(rosterUrl, { grant: unref(grant) });

            // The public station is handed a roster for one training and is not
            // told its id up front, so the bundle is the authority on it.
            const trainingId = bundle.training?.id ?? id;

            await saveRoster(trainingId, bundle);
            await refreshStoredRosters();
            await activate(trainingId);

            syncState.value = 'idle';
            credentialExpired.value = false;
            syncMessage.value = `${bundle.participants.length} participants ready for offline scanning.`;

            return true;
        } catch (error) {
            syncState.value = 'error';
            credentialExpired.value = error instanceof SyncError && error.requiresSignIn;
            syncMessage.value =
                error instanceof SyncError
                    ? error.message
                    : 'Could not reach the server. Connect to a network and try again.';

            return false;
        } finally {
            downloading.value = null;
        }
    }

    async function activate(trainingId) {
        const bundle = await getRoster(trainingId);

        if (!bundle) {
            return;
        }

        roster.value = bundle;
        scans.value = await scansFor(trainingId);
        localStorage.setItem(storageKey, String(trainingId));
    }

    /**
     * Finish with a training and clear it off the device.
     *
     * A roster carries the identities of everyone in the hall, so it should not
     * outlive the session — but deleting it while scans are still queued would
     * destroy attendance that exists nowhere else, which is why callers guard
     * this behind a pending check.
     */
    async function release() {
        if (!roster.value) {
            return;
        }

        await deleteRoster(roster.value.training_id);
        await refreshStoredRosters();

        roster.value = null;
        scans.value = [];
        localStorage.removeItem(storageKey);

        stopCamera();
    }

    /* ---------------------------------------------------------------------- */
    /* Scanning                                                                */
    /* ---------------------------------------------------------------------- */

    async function startCamera() {
        if (!Scanner.isSupported()) {
            cameraState.value = 'unsupported';
            cameraError.value =
                'This browser cannot open a camera. A camera needs a secure (https) connection — or use the roster to mark participants by hand.';

            return;
        }

        cameraState.value = 'starting';
        cameraError.value = null;

        scanner.value = new Scanner({
            video: video.value,
            onResult: handleScan,
            onError: () => {},
        });

        try {
            await scanner.value.start();
            cameraState.value = 'running';
            hasTorch.value = scanner.value.hasTorch();
        } catch (error) {
            cameraState.value = 'denied';
            cameraError.value =
                error?.name === 'NotAllowedError'
                    ? 'Camera access was blocked. Allow it in the browser’s site settings, then try again.'
                    : 'No camera could be opened on this device.';
        }
    }

    function stopCamera() {
        scanner.value?.stop();
        scanner.value = null;
        cameraState.value = 'idle';
        torchOn.value = false;
    }

    async function toggleTorch() {
        const applied = await scanner.value?.setTorch(!torchOn.value);

        if (applied) {
            torchOn.value = !torchOn.value;
        }
    }

    /**
     * One decoded code, end to end.
     *
     * The order is the point: decide, *write to IndexedDB*, then render. A
     * device that dies between the beep and the paint has still recorded the
     * arrival, and that is the guarantee the operator is relying on when they
     * wave someone through.
     */
    async function handleScan(text) {
        if (!roster.value) {
            return;
        }

        const result = await resolveScan(text, roster.value, scans.value, {
            practice: unref(testMode),
            canAdmit: Boolean(walkInUrl),
        });

        if (result.verdict === 'success') {
            const at = result.at ?? new Date();
            const record = await addScan({
                client_id: clientId(),
                training_id: roster.value.training_id,
                registration_id: result.participant.registration_id,
                training_day: result.day,
                name: result.participant.name,
                status: result.status,
                time_in: localTime(at),
                scanned_at: at.toISOString(),
                dry_run: unref(testMode),
            });

            scans.value = [...scans.value, record];

            // Straight away when there is a network, so the common case never
            // accumulates a queue at all; harmless when there is not.
            if (online.value) {
                void sync({ quiet: true });
            }
        }

        show(result);
    }

    function show(result) {
        verdict.value = result;

        beep(result.verdict);
        buzz(result.verdict);

        clearTimeout(verdictTimer);

        /*
         * A verdict the operator has to *act* on does not time out.
         *
         * Every other outcome here is information — checked in, already
         * marked, wrong day — and clearing it keeps the next person from
         * reading somebody else's name. A walk-in offer is a button, and a
         * button that vanishes six seconds after it appears is one an operator
         * reaches for and misses while a queue watches. It goes when the next
         * scan replaces it.
         */
        if (result.admittable) {
            return;
        }

        // Long enough to read across a badge table, short enough that the next
        // person is not left looking at somebody else's name.
        verdictTimer = setTimeout(
            () => (verdict.value = null),
            result.verdict === 'success' ? 3500 : 6000
        );
    }

    /**
     * Admit the walk-in the last scan could not place.
     *
     * The one action on this station that needs a network, and it says so
     * rather than queueing. Everything else here is safe to defer because it
     * only records something about a person already on the roster; this decides
     * whether they are on it. A queued admission would leave an operator
     * believing somebody had a seat while the request sat in IndexedDB, and the
     * participant finding out otherwise at the certificate.
     *
     * On success the returned roster row is appended locally, so the same badge
     * is recognised offline from the next scan onward without re-downloading
     * the whole bundle.
     */
    async function admitWalkIn(token) {
        if (!walkInUrl || !roster.value || !token || admitting.value) {
            return false;
        }

        if (!online.value) {
            show({
                verdict: 'unknown',
                token,
                admittable: true,
                message: 'Admitting a walk-in needs a network. Move to where there is signal and try again.',
            });

            return false;
        }

        admitting.value = true;

        try {
            const response = await fetch(walkInUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ training_id: roster.value.training_id, token }),
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                show({
                    verdict: 'refused',
                    message:
                        payload.errors?.walk_in?.[0] ??
                        payload.errors?.registration?.[0] ??
                        payload.message ??
                        `The server refused the admission (${response.status}).`,
                });

                return false;
            }

            roster.value = {
                ...roster.value,
                participants: [...roster.value.participants, payload.participant],
            };

            await saveRoster(roster.value.training_id, roster.value);

            show({
                verdict: payload.checked_in ? 'walk-in' : 'walk-in-pending',
                participant: payload.participant,
                message: payload.message,
                overCapacity: payload.over_capacity,
                overBy: payload.over_by,
            });

            return true;
        } catch {
            show({
                verdict: 'refused',
                message: 'Could not reach the server. The participant has not been admitted.',
            });

            return false;
        } finally {
            admitting.value = false;
        }
    }

    /** Mark someone from the roster list, when their code cannot be read. */
    async function markByHand(participant) {
        if (!roster.value || !today.value) {
            return;
        }

        const at = new Date();
        const record = await addScan({
            client_id: clientId(),
            training_id: roster.value.training_id,
            registration_id: participant.registration_id,
            training_day: today.value.day,
            name: participant.name,
            status: 'present',
            time_in: localTime(at),
            scanned_at: at.toISOString(),
            by_hand: true,
            dry_run: unref(testMode),
        });

        scans.value = [...scans.value, record];

        if (online.value) {
            void sync({ quiet: true });
        }
    }

    function clientId() {
        return crypto.randomUUID
            ? crypto.randomUUID()
            : `${Date.now()}-${Math.random().toString(36).slice(2, 12)}`;
    }

    /* ---------------------------------------------------------------------- */
    /* Sync                                                                    */
    /* ---------------------------------------------------------------------- */

    /**
     * Push the queue.
     *
     * `quiet` is for the automatic runs that happen after every scan: they must
     * not paint an error banner over a busy door when the wifi drops for ten
     * seconds. The manual button is never quiet, because someone pressed it and
     * is owed an answer.
     */
    async function sync({ quiet = false } = {}) {
        if (syncState.value === 'syncing' || !roster.value) {
            return;
        }

        syncState.value = 'syncing';

        if (!quiet) {
            syncMessage.value = null;
        }

        try {
            const summary = await syncPending(unref(syncUrl), {
                trainingId: roster.value.training_id,
                grant: unref(grant),
            });

            scans.value = await scansFor(roster.value.training_id);
            syncState.value = 'idle';
            credentialExpired.value = false;
            lastSyncedAt.value = new Date();

            if (!quiet) {
                syncMessage.value =
                    summary.sent === 0
                        ? 'Nothing was waiting — everything is already on the server.'
                        : `${summary.synced} recorded, ${summary.duplicate} already present, ${summary.rejected} refused.`;
            }
        } catch (error) {
            syncState.value = 'error';

            // Always raised, even on a quiet run: a dead credential will not
            // fix itself, and silently retrying every minute would let a device
            // accumulate a day of scans that can never be sent.
            if (error instanceof SyncError && error.requiresSignIn) {
                credentialExpired.value = true;
            }

            if (!quiet) {
                syncMessage.value =
                    error instanceof SyncError
                        ? error.message
                        : 'Could not reach the server. Your scans are safe on this device and will be sent when a connection returns.';
            }
        }
    }

    /** Whether this station is currently rehearsing. */
    const testing = computed(() => Boolean(unref(testMode)));

    /** How many rehearsal scans this device is holding. */
    const testedCount = computed(() => scans.value.filter((scan) => scan.dry_run).length);

    /** Wipe the rehearsal off the device; the server never held any of it. */
    async function clearTestScans() {
        if (!roster.value) {
            return;
        }

        await deleteTestScans(roster.value.training_id);
        scans.value = await scansFor(roster.value.training_id);
        syncMessage.value = 'Test scans cleared. Nothing was ever recorded on the server.';
    }

    async function retry() {
        if (!roster.value) {
            return;
        }

        await retryFailed(roster.value.training_id);
        scans.value = await scansFor(roster.value.training_id);

        await sync();
    }

    /* ---------------------------------------------------------------------- */
    /* Lifecycle                                                               */
    /* ---------------------------------------------------------------------- */

    function handleOnline() {
        online.value = true;

        // The whole reason the queue exists — a returning connection drains it
        // without anyone having to notice that it came back.
        void sync({ quiet: true });
    }

    function handleOffline() {
        online.value = false;
    }

    onMounted(async () => {
        await refreshStoredRosters();

        if (restoreLast) {
            const last = localStorage.getItem(storageKey);

            if (last) {
                await activate(Number(last));
            }
        }

        window.addEventListener('online', handleOnline);
        window.addEventListener('offline', handleOffline);

        // A slow safety net under the `online` event, which some Android
        // browsers fire late or not at all after a screen lock.
        syncTimer = setInterval(() => {
            if (online.value && pendingCount.value > 0 && !credentialExpired.value) {
                void sync({ quiet: true });
            }
        }, 60000);

        // Registered from here rather than app.js: the stations are the only
        // pages that need to survive with the network unplugged, and a worker
        // installed for the whole app would cache pages nobody asked to keep.
        if ('serviceWorker' in navigator && import.meta.env.PROD) {
            navigator.serviceWorker.register('/scanner-sw.js').catch(() => {
                // Offline shell caching is an enhancement; the scan queue works
                // without it as long as the tab stays open.
            });
        }
    });

    onBeforeUnmount(() => {
        window.removeEventListener('online', handleOnline);
        window.removeEventListener('offline', handleOffline);
        clearInterval(syncTimer);
        clearTimeout(verdictTimer);
        stopCamera();
    });

    // Switching training mid-session must not leave a camera pointed at a
    // roster it no longer knows about.
    watch(
        () => roster.value?.training_id,
        () => scanner.value?.forget()
    );

    return {
        // state
        roster,
        scans,
        storedRosters,
        video,
        cameraState,
        cameraError,
        torchOn,
        hasTorch,
        verdict,
        admitting,
        online,
        syncState,
        syncMessage,
        lastSyncedAt,
        downloading,
        credentialExpired,
        // derived
        pendingCount,
        failedCount,
        syncedCount,
        today,
        markedToday,
        activity,
        syncLabel,
        syncTone,
        rosterRows,
        testing,
        testedCount,
        clearTestScans,
        // actions
        refreshStoredRosters,
        download,
        activate,
        release,
        startCamera,
        stopCamera,
        toggleTorch,
        markByHand,
        admitWalkIn,
        sync,
        retry,
    };
}

/**
 * Shared presentation tables.

 *
 * Kept beside the composable because the verdict vocabulary is part of the
 * station's contract, not a per-page styling choice: "duplicate" must look the
 * same on both doors or an operator moving between them will misread it.
 */
export const verdictStyles = {
    success: { tone: 'bg-success text-white', icon: 'check', title: 'Checked in' },
    duplicate: { tone: 'bg-warning text-white', icon: 'clock', title: 'Already marked' },
    'off-day': { tone: 'bg-danger text-white', icon: 'warning', title: 'Not running today' },
    unknown: { tone: 'bg-danger text-white', icon: 'warning', title: 'Not on this roster' },
    invalid: { tone: 'bg-danger text-white', icon: 'close', title: 'Unrecognised code' },
    /*
     * Walk-ins read as success because that is what they are: the person is in
     * the room and on the register. The pending variant is warning rather than
     * danger on purpose — nothing failed, the cashier has simply not been paid
     * yet, and colouring it as an error sends the operator hunting a problem
     * that does not exist.
     */
    'walk-in': { tone: 'bg-success text-white', icon: 'check', title: 'Walk-in admitted' },
    'walk-in-pending': { tone: 'bg-warning text-white', icon: 'clock', title: 'Enrolled — fee due' },
    refused: { tone: 'bg-danger text-white', icon: 'warning', title: 'Not admitted' },
};

export const toneDots = {
    success: 'bg-success',
    warning: 'bg-warning',
    danger: 'bg-danger',
    info: 'bg-white',
};
