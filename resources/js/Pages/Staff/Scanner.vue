<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';
import { Scanner, beep, buzz } from '@/scanner/camera';
import { localTime, resolveScan } from '@/scanner/resolve';
import {
    addScan,
    deleteRoster,
    getRoster,
    listRosters,
    retryFailed,
    saveRoster,
    scansFor,
} from '@/scanner/store';
import { SyncError, downloadRoster, syncPending } from '@/scanner/sync';

/**
 * The venue attendance station.
 *
 * Deliberately not wrapped in AuthenticatedLayout. This is a kiosk: it is held
 * in one hand at a door, it wants the whole screen for a camera viewport, and a
 * sidebar full of links is a liability when a mis-tap loses the operator's
 * place mid-queue. The only way out is one explicit control.
 *
 * Everything on this page reads from IndexedDB, never from props, once a roster
 * is loaded. Props supply the list of trainings that *could* be downloaded and
 * the two URLs — beyond that the station is self-contained, because the whole
 * design assumes the network disappears the moment the session starts.
 */

const props = defineProps({
    trainings: { type: Array, default: () => [] },
    syncUrl: { type: String, required: true },
    scopedTo: { type: String, default: null },
    operator: { type: String, default: null },
});

/* -------------------------------------------------------------------------- */
/* State                                                                       */
/* -------------------------------------------------------------------------- */

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

const online = ref(navigator.onLine);
const syncState = ref('idle'); // idle | syncing | error
const syncMessage = ref(null);
const lastSyncedAt = ref(null);
const downloading = ref(null);

const panel = ref(null); // null | 'roster' | 'activity'
const search = ref('');
const confirmingRelease = ref(false);

/** Remembered so a reopened tablet lands back on the training it was scanning. */
const LAST_TRAINING_KEY = 'csc-tims-scanner:last-training';

let syncTimer = null;

/* -------------------------------------------------------------------------- */
/* Derived                                                                     */
/* -------------------------------------------------------------------------- */

const pendingCount = computed(() => scans.value.filter((scan) => scan.state === 'pending').length);
const failedCount = computed(() => scans.value.filter((scan) => scan.state === 'failed').length);
const syncedCount = computed(() => scans.value.filter((scan) => scan.state === 'synced').length);

/**
 * How many of today's participants are accounted for.
 *
 * Counted against today specifically, not the whole training: on day three of a
 * run, "142 of 160" has to mean today's hall, not the sum of every day.
 */
const today = computed(() => {
    if (!roster.value) {
        return null;
    }

    const date = new Date();
    const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(
        date.getDate()
    ).padStart(2, '0')}`;

    return roster.value.training.days.find((day) => day.date === key) ?? null;
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
 * A creased badge, a cracked phone screen, a participant who left their code at
 * the hotel — a station with no way to mark someone by hand sends them to the
 * back of a queue that has no answer for them.
 */
const rosterRows = computed(() => {
    if (!roster.value) {
        return [];
    }

    const day = today.value?.day ?? null;
    const term = search.value.trim().toLowerCase();

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
                status_label: fromServer?.status_label ?? (local?.status === 'late' ? 'Late' : local ? 'Present' : null),
            };
        })
        .filter((row) => !term || row.name.toLowerCase().includes(term) || (row.organization ?? '').toLowerCase().includes(term));
});

/** Most recent first — the operator only ever looks at the last few. */
const activity = computed(() => [...scans.value].sort((a, b) => b.scanned_at.localeCompare(a.scanned_at)));

const syncLabel = computed(() => {
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
    if (syncState.value === 'error' || failedCount.value > 0) {
        return 'danger';
    }

    if (syncState.value === 'syncing') {
        return 'info';
    }

    return pendingCount.value > 0 ? 'warning' : 'success';
});

/* -------------------------------------------------------------------------- */
/* Roster management                                                           */
/* -------------------------------------------------------------------------- */

async function refreshStoredRosters() {
    storedRosters.value = await listRosters();
}

/**
 * Download a training's roster and make it the active one.
 *
 * Safe to repeat: re-downloading mid-session is how a station picks up
 * check-ins recorded elsewhere, and the local queue is untouched by it.
 */
async function download(training) {
    downloading.value = training.id;
    syncMessage.value = null;

    try {
        const bundle = await downloadRoster(training.roster_url);

        await saveRoster(training.id, bundle);
        await refreshStoredRosters();
        await activate(training.id);

        syncState.value = 'idle';
        syncMessage.value = `${bundle.participants.length} participants ready for offline scanning.`;
    } catch (error) {
        syncState.value = 'error';
        syncMessage.value =
            error instanceof SyncError
                ? error.message
                : 'Could not reach the server. Connect to a network and try again.';
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
    localStorage.setItem(LAST_TRAINING_KEY, String(trainingId));
}

/**
 * Finish with a training and clear it off the device.
 *
 * Guarded behind a confirmation *and* a pending check: a roster carries the
 * identities of everyone in the hall, so it should not outlive the session — but
 * deleting it while scans are still queued would destroy attendance that exists
 * nowhere else.
 */
async function release() {
    if (!roster.value) {
        return;
    }

    await deleteRoster(roster.value.training_id);
    await refreshStoredRosters();

    roster.value = null;
    scans.value = [];
    confirmingRelease.value = false;
    localStorage.removeItem(LAST_TRAINING_KEY);

    stopCamera();
}

/* -------------------------------------------------------------------------- */
/* Scanning                                                                    */
/* -------------------------------------------------------------------------- */

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
 * The order is the point: decide, *write to IndexedDB*, then render. A device
 * that dies between the beep and the paint has still recorded the arrival, and
 * that is the guarantee the operator is relying on when they wave someone
 * through.
 */
async function handleScan(text) {
    if (!roster.value) {
        return;
    }

    const result = await resolveScan(text, roster.value, scans.value);

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

    // Long enough to read across a badge table, short enough that the next
    // person is not left looking at somebody else's name.
    verdictTimer = setTimeout(() => (verdict.value = null), result.verdict === 'success' ? 3500 : 6000);
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

/* -------------------------------------------------------------------------- */
/* Sync                                                                        */
/* -------------------------------------------------------------------------- */

/**
 * Push the queue.
 *
 * `quiet` is for the automatic runs that happen after every scan: they must not
 * paint an error banner over a busy door when the wifi drops for ten seconds.
 * The manual button is never quiet, because someone pressed it and is owed an
 * answer.
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
        const summary = await syncPending(props.syncUrl, { trainingId: roster.value.training_id });

        scans.value = await scansFor(roster.value.training_id);
        syncState.value = 'idle';
        lastSyncedAt.value = new Date();

        if (!quiet) {
            syncMessage.value =
                summary.sent === 0
                    ? 'Nothing was waiting — everything is already on the server.'
                    : `${summary.synced} recorded, ${summary.duplicate} already present, ${summary.rejected} refused.`;
        }
    } catch (error) {
        syncState.value = 'error';

        if (!quiet) {
            syncMessage.value =
                error instanceof SyncError
                    ? error.message
                    : 'Could not reach the server. Your scans are safe on this device and will be sent when a connection returns.';
        }
    }
}

async function retry() {
    if (!roster.value) {
        return;
    }

    await retryFailed(roster.value.training_id);
    scans.value = await scansFor(roster.value.training_id);

    await sync();
}

/* -------------------------------------------------------------------------- */
/* Lifecycle                                                                   */
/* -------------------------------------------------------------------------- */

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

    const last = localStorage.getItem(LAST_TRAINING_KEY);

    if (last) {
        await activate(Number(last));
    }

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    // A slow safety net under the `online` event, which some Android browsers
    // fire late or not at all after a screen lock.
    syncTimer = setInterval(() => {
        if (online.value && pendingCount.value > 0) {
            void sync({ quiet: true });
        }
    }, 60000);

    // Registered from here rather than app.js: this is the only page that needs
    // to survive with the network unplugged, and a worker installed for the
    // whole app would cache pages nobody asked to keep.
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

// Switching training mid-session must not leave a camera pointed at a roster it
// no longer knows about.
watch(
    () => roster.value?.training_id,
    () => scanner.value?.forget()
);

/* -------------------------------------------------------------------------- */
/* Presentation                                                                */
/* -------------------------------------------------------------------------- */

const verdictStyles = {
    success: { tone: 'bg-success text-white', icon: 'check', title: 'Checked in' },
    duplicate: { tone: 'bg-warning text-white', icon: 'clock', title: 'Already marked' },
    'off-day': { tone: 'bg-danger text-white', icon: 'warning', title: 'Not running today' },
    unknown: { tone: 'bg-danger text-white', icon: 'warning', title: 'Not on this roster' },
    invalid: { tone: 'bg-danger text-white', icon: 'close', title: 'Unrecognised code' },
};

const toneDots = {
    success: 'bg-success',
    warning: 'bg-warning',
    danger: 'bg-danger',
    info: 'bg-white',
};
</script>

<template>
    <Head title="Attendance Scanner" />

    <div class="flex min-h-dvh flex-col bg-csc-blue-deep text-white">
        <!-- Kiosk chrome: identity, connection, and the one way out. -->
        <header class="sticky top-0 z-header border-b border-white/10 bg-csc-blue-deep/95 backdrop-blur">
            <div class="mx-auto flex max-w-3xl items-center gap-3 px-4 py-3">
                <Link
                    href="/admin"
                    class="-ml-1 rounded-lg p-2 text-white/70 transition-colors hover:bg-white/10 hover:text-white"
                >
                    <AppIcon name="arrow-left" label="Leave the scanner" />
                </Link>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold">
                        {{ roster ? roster.training.title : 'Attendance Scanner' }}
                    </p>
                    <p class="truncate text-2xs text-white/60">
                        <template v-if="roster">
                            {{ today ? today.label : 'Not running today' }} ·
                            {{ markedToday }} of {{ roster.participants.length }} marked
                        </template>
                        <template v-else>{{ operator }}<span v-if="scopedTo"> · {{ scopedTo }}</span></template>
                    </p>
                </div>

                <span
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-white/20 px-2.5 py-1 text-2xs font-semibold"
                    :title="online ? 'Connected' : 'Working offline'"
                >
                    <span class="size-1.5 rounded-full" :class="online ? 'bg-success' : 'bg-warning'" />
                    {{ online ? 'Online' : 'Offline' }}
                </span>
            </div>
        </header>

        <!-- ============================ SETUP ============================ -->
        <main v-if="!roster" class="mx-auto w-full max-w-3xl flex-1 px-4 py-6">
            <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                <div class="flex items-start gap-3">
                    <AppIcon name="download" size="lg" class="mt-0.5 shrink-0 text-white/70" />
                    <div>
                        <h1 class="text-base font-semibold">Download a roster before the session</h1>
                        <p class="mt-1 text-sm leading-relaxed text-white/70">
                            The station keeps the participant list on this device, so scanning keeps
                            working when the venue has no signal. Download while you still have a
                            connection — everything after that is offline.
                        </p>
                    </div>
                </div>
            </div>

            <p
                v-if="syncMessage"
                class="mt-4 rounded-xl px-4 py-3 text-sm"
                :class="syncState === 'error' ? 'bg-danger/20 text-white' : 'bg-success/20 text-white'"
            >
                {{ syncMessage }}
            </p>

            <section v-if="storedRosters.length" class="mt-6">
                <h2 class="text-2xs font-semibold tracking-wide text-white/50 uppercase">On this device</h2>
                <ul class="mt-2 space-y-2">
                    <li
                        v-for="stored in storedRosters"
                        :key="stored.training_id"
                        class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 p-4"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold">{{ stored.training.title }}</p>
                            <p class="mt-0.5 text-2xs text-white/60">
                                {{ stored.participants.length }} participants · saved
                                {{ new Date(stored.saved_at).toLocaleString() }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="shrink-0 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-csc-blue transition-colors hover:bg-csc-blue-tint"
                            @click="activate(stored.training_id)"
                        >
                            Open
                        </button>
                    </li>
                </ul>
            </section>

            <section class="mt-6">
                <h2 class="text-2xs font-semibold tracking-wide text-white/50 uppercase">Available trainings</h2>

                <p v-if="!trainings.length" class="mt-2 rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-white/70">
                    No training is scheduled in the current window.
                </p>

                <ul v-else class="mt-2 space-y-2">
                    <li
                        v-for="training in trainings"
                        :key="training.id"
                        class="rounded-xl border border-white/10 bg-white/5 p-4"
                    >
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold">{{ training.title }}</p>
                                <p class="mt-0.5 text-2xs text-white/60">
                                    {{ training.date_label }}
                                    <span v-if="training.venue"> · {{ training.venue }}</span>
                                </p>
                                <span
                                    v-if="training.is_today"
                                    class="mt-2 inline-flex items-center gap-1 rounded-full bg-success/25 px-2 py-0.5 text-2xs font-semibold"
                                >
                                    <AppIcon name="check" size="sm" />
                                    Running today
                                </span>
                            </div>

                            <button
                                type="button"
                                :disabled="!online || downloading === training.id"
                                class="shrink-0 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-csc-blue transition-colors hover:bg-csc-blue-tint disabled:cursor-not-allowed disabled:opacity-50"
                                @click="download(training)"
                            >
                                {{ downloading === training.id ? 'Downloading…' : 'Download' }}
                            </button>
                        </div>
                    </li>
                </ul>

                <p v-if="!online" class="mt-3 text-2xs text-white/60">
                    A new roster cannot be downloaded while offline. Rosters already on this device
                    stay available.
                </p>
            </section>
        </main>

        <!-- ============================ SCAN ============================= -->
        <main v-else class="mx-auto flex w-full max-w-3xl flex-1 flex-col px-4 pt-4 pb-24">
            <!-- Sync status. Always visible: an operator must never have to
                 wonder whether the last hour of scanning has left the tablet. -->
            <div class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-4 py-2.5">
                <span class="size-2 shrink-0 rounded-full" :class="toneDots[syncTone]" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">{{ syncLabel }}</p>
                    <p v-if="lastSyncedAt" class="truncate text-2xs text-white/50">
                        Last sync {{ lastSyncedAt.toLocaleTimeString() }} · {{ syncedCount }} sent in total
                    </p>
                </div>

                <button
                    v-if="failedCount"
                    type="button"
                    class="shrink-0 rounded-lg border border-white/30 px-3 py-1.5 text-2xs font-semibold transition-colors hover:bg-white/10"
                    @click="retry"
                >
                    Retry
                </button>
                <button
                    type="button"
                    :disabled="syncState === 'syncing'"
                    class="shrink-0 rounded-lg bg-white px-3 py-1.5 text-2xs font-semibold text-csc-blue transition-colors hover:bg-csc-blue-tint disabled:opacity-50"
                    @click="sync()"
                >
                    {{ syncState === 'syncing' ? 'Syncing…' : 'Sync now' }}
                </button>
            </div>

            <p
                v-if="syncMessage"
                class="mt-2 rounded-xl px-4 py-2.5 text-2xs leading-relaxed"
                :class="syncState === 'error' ? 'bg-danger/20' : 'bg-white/10'"
            >
                {{ syncMessage }}
            </p>

            <!-- Camera viewport -->
            <div class="relative mt-4 aspect-square w-full overflow-hidden rounded-2xl bg-black sm:aspect-video">
                <video ref="video" class="size-full object-cover" muted playsinline />

                <!-- Reticle. Purely to tell the operator where to aim; the
                     decoder reads the whole frame. -->
                <div v-if="cameraState === 'running'" class="pointer-events-none absolute inset-0 grid place-items-center">
                    <div class="size-48 rounded-2xl border-2 border-white/70 shadow-[0_0_0_9999px_rgba(0,0,0,0.35)] sm:size-56" />
                </div>

                <div
                    v-if="cameraState !== 'running'"
                    class="absolute inset-0 grid place-items-center bg-csc-blue-deep/90 p-6 text-center"
                >
                    <div>
                        <AppIcon name="qr" size="lg" class="mx-auto text-white/60" />
                        <p v-if="cameraError" class="mt-3 text-sm leading-relaxed text-white/80">{{ cameraError }}</p>
                        <p v-else class="mt-3 text-sm text-white/70">
                            The camera is off. Nothing is scanned until you start it.
                        </p>

                        <button
                            v-if="cameraState !== 'unsupported'"
                            type="button"
                            class="mt-4 rounded-lg bg-white px-6 py-3 text-sm font-semibold text-csc-blue transition-colors hover:bg-csc-blue-tint"
                            @click="startCamera"
                        >
                            {{ cameraState === 'starting' ? 'Starting…' : 'Start camera' }}
                        </button>
                    </div>
                </div>

                <!-- Verdict. Over the viewport rather than beside it: the
                     operator is looking at the camera, so the answer has to be
                     where their eyes already are. -->
                <transition
                    enter-active-class="transition duration-150"
                    enter-from-class="translate-y-2 opacity-0"
                    leave-active-class="transition duration-150"
                    leave-to-class="opacity-0"
                >
                    <div
                        v-if="verdict"
                        class="absolute inset-x-0 bottom-0 p-3"
                        role="status"
                        aria-live="assertive"
                    >
                        <div
                            class="flex items-start gap-3 rounded-xl p-4 shadow-lg"
                            :class="verdictStyles[verdict.verdict].tone"
                        >
                            <AppIcon :name="verdictStyles[verdict.verdict].icon" size="lg" class="mt-0.5 shrink-0" />
                            <div class="min-w-0">
                                <p class="text-sm font-semibold">
                                    {{ verdictStyles[verdict.verdict].title }}
                                </p>
                                <p v-if="verdict.participant" class="truncate text-lg leading-tight font-bold">
                                    {{ verdict.participant.name }}
                                </p>
                                <p v-if="verdict.verdict === 'success'" class="mt-0.5 text-sm">
                                    Day {{ verdict.day }} · {{ verdict.status === 'late' ? 'Late' : 'Present' }}
                                </p>
                                <p v-else-if="verdict.verdict === 'duplicate'" class="mt-0.5 text-sm">
                                    Already marked
                                    <template v-if="verdict.existing.time_in">
                                        at {{ verdict.existing.time_in }}
                                    </template>
                                    ({{ verdict.existing.status_label }}) — no second record was made.
                                </p>
                                <p v-else class="mt-0.5 text-sm leading-relaxed">{{ verdict.message }}</p>

                                <p
                                    v-if="verdict.participant?.food_restrictions"
                                    class="mt-2 rounded-lg bg-black/20 px-2 py-1 text-2xs"
                                >
                                    Food restrictions: {{ verdict.participant.food_restrictions }}
                                </p>
                            </div>
                        </div>
                    </div>
                </transition>
            </div>

            <!-- Controls -->
            <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <button
                    v-if="cameraState === 'running'"
                    type="button"
                    class="rounded-lg border border-white/25 px-3 py-2.5 text-sm font-semibold transition-colors hover:bg-white/10"
                    @click="stopCamera"
                >
                    Pause camera
                </button>
                <button
                    v-if="cameraState === 'running' && hasTorch"
                    type="button"
                    class="rounded-lg border border-white/25 px-3 py-2.5 text-sm font-semibold transition-colors hover:bg-white/10"
                    @click="toggleTorch"
                >
                    Light {{ torchOn ? 'off' : 'on' }}
                </button>
                <button
                    type="button"
                    class="rounded-lg border border-white/25 px-3 py-2.5 text-sm font-semibold transition-colors hover:bg-white/10"
                    @click="panel = panel === 'roster' ? null : 'roster'"
                >
                    Roster
                </button>
                <button
                    type="button"
                    class="rounded-lg border border-white/25 px-3 py-2.5 text-sm font-semibold transition-colors hover:bg-white/10"
                    @click="panel = panel === 'activity' ? null : 'activity'"
                >
                    Activity
                </button>
            </div>

            <!-- Roster: the manual fallback, and the answer to "who is missing" -->
            <section v-if="panel === 'roster'" class="mt-4 rounded-2xl border border-white/10 bg-white/5 p-4">
                <label class="block">
                    <span class="sr-only">Search the roster</span>
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search by name or organisation"
                        class="w-full rounded-lg border border-white/20 bg-white/10 px-3 py-2.5 text-sm placeholder:text-white/40 focus:border-white focus:outline-none"
                    />
                </label>

                <p v-if="!today" class="mt-3 text-2xs text-white/60">
                    This training is not running today, so nobody can be marked by hand.
                </p>

                <ul class="mt-3 max-h-96 space-y-1.5 overflow-y-auto">
                    <li
                        v-for="row in rosterRows"
                        :key="row.registration_id"
                        class="flex items-center gap-3 rounded-lg bg-white/5 px-3 py-2.5"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ row.name }}</p>
                            <p v-if="row.organization" class="truncate text-2xs text-white/50">{{ row.organization }}</p>
                        </div>

                        <span
                            v-if="row.marked"
                            class="inline-flex shrink-0 items-center gap-1 rounded-full bg-success/25 px-2 py-0.5 text-2xs font-semibold"
                        >
                            <AppIcon name="check" size="sm" />
                            {{ row.status_label }}<template v-if="row.time_in"> · {{ row.time_in }}</template>
                        </span>
                        <button
                            v-else-if="today"
                            type="button"
                            class="shrink-0 rounded-lg border border-white/30 px-3 py-1.5 text-2xs font-semibold transition-colors hover:bg-white/10"
                            @click="markByHand(row)"
                        >
                            Mark present
                        </button>
                    </li>
                </ul>
            </section>

            <!-- Activity: every scan this device holds, and its sync state -->
            <section v-if="panel === 'activity'" class="mt-4 rounded-2xl border border-white/10 bg-white/5 p-4">
                <p v-if="!activity.length" class="text-sm text-white/60">Nothing has been scanned yet.</p>

                <ul v-else class="max-h-96 space-y-1.5 overflow-y-auto">
                    <li
                        v-for="scan in activity"
                        :key="scan.client_id"
                        class="flex items-center gap-3 rounded-lg bg-white/5 px-3 py-2.5"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ scan.name }}</p>
                            <p class="truncate text-2xs text-white/50">
                                Day {{ scan.training_day }} · {{ scan.time_in }}
                                <span v-if="scan.by_hand"> · marked by hand</span>
                                <span v-if="scan.message"> · {{ scan.message }}</span>
                            </p>
                        </div>

                        <span
                            class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-2xs font-semibold"
                            :class="{
                                'bg-success/25': scan.state === 'synced',
                                'bg-warning/25': scan.state === 'pending',
                                'bg-danger/30': scan.state === 'failed',
                            }"
                        >
                            <AppIcon
                                :name="scan.state === 'synced' ? 'check' : scan.state === 'pending' ? 'clock' : 'warning'"
                                size="sm"
                            />
                            {{ scan.state === 'synced' ? 'Synced' : scan.state === 'pending' ? 'Pending' : 'Failed' }}
                        </span>
                    </li>
                </ul>
            </section>

            <!-- Finishing up -->
            <section class="mt-6 border-t border-white/10 pt-4">
                <p v-if="pendingCount" class="text-2xs leading-relaxed text-white/60">
                    {{ pendingCount }} scan<span v-if="pendingCount !== 1">s</span> still to send. Sync
                    before removing this roster from the device.
                </p>

                <div class="mt-2 flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-white/25 px-4 py-2 text-sm font-semibold transition-colors hover:bg-white/10"
                        @click="roster = null"
                    >
                        Switch training
                    </button>
                    <button
                        type="button"
                        :disabled="pendingCount > 0"
                        class="rounded-lg border border-white/25 px-4 py-2 text-sm font-semibold transition-colors hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-40"
                        :title="pendingCount ? 'Sync the remaining scans first' : 'Remove this roster from the device'"
                        @click="confirmingRelease = true"
                    >
                        Remove from device
                    </button>
                </div>

                <div v-if="confirmingRelease" class="mt-3 rounded-xl bg-danger/20 p-4">
                    <p class="text-sm leading-relaxed">
                        Remove “{{ roster.training.title }}” and its {{ scans.length }} recorded scans from
                        this device? Everything has been sent to the server; this only clears the local copy.
                    </p>
                    <div class="mt-3 flex gap-2">
                        <button
                            type="button"
                            class="rounded-lg bg-white px-4 py-2 text-sm font-semibold text-danger"
                            @click="release"
                        >
                            Remove
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-white/30 px-4 py-2 text-sm font-semibold"
                            @click="confirmingRelease = false"
                        >
                            Keep it
                        </button>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>
