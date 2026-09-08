<script setup>
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppSkeleton from '@/Components/AppSkeleton.vue';
import AppStatTile from '@/Components/AppStatTile.vue';
import StationPanels from '@/Components/Station/StationPanels.vue';
import StationShell from '@/Components/Station/StationShell.vue';
import StationSyncCard from '@/Components/Station/StationSyncCard.vue';
import StationViewport from '@/Components/Station/StationViewport.vue';
import { useScanStation } from '@/scanner/station';

/**
 * The public attendance station.
 *
 * Handed to whoever is working the door — a training aide, a volunteer, the
 * host agency's clerk — on their own phone. There is no login and no app shell:
 * the page is a kiosk pinned to one training, and the only chrome is what
 * someone standing in a doorway actually needs.
 *
 * Three screens, in the order a real session moves through them:
 *
 *  1. the gate, where the six-digit code is exchanged for the device's grant;
 *  2. setup, where the roster is downloaded while there is still a signal;
 *  3. the scanner, which from then on needs no network at all.
 *
 * Once past step 2 nothing here reads from props. The station runs entirely off
 * IndexedDB, because the assumption behind the whole feature is that the
 * network disappears the moment the session starts.
 *
 * The scanning screen is `Components/Station/*`, byte for byte the one the
 * staff door renders. That is deliberate and it is the same argument
 * ScanStationService makes on the server: two doors onto one job must not be
 * allowed to drift, and a volunteer who has been shown the office's tablet
 * should recognise what is in their hand.
 */

const props = defineProps({
    token: { type: String, required: true },
    link: { type: Object, default: null },
    state: { type: String, required: true }, // active | expired | revoked | unknown
    unlockUrl: { type: String, required: true },
    rosterUrl: { type: String, required: true },
    syncUrl: { type: String, required: true },
});

/**
 * The device's credential, kept per link.
 *
 * Per link rather than one shared key so a phone that works two doors in a day
 * does not silently drop the first link's grant when the second is unlocked.
 */
const GRANT_KEY = `csc-tims-scan:grant:${props.token}`;
const grant = ref(localStorage.getItem(GRANT_KEY));

const code = ref('');
const unlocking = ref(false);
const gateError = ref(null);

const {
    roster,
    video,
    cameraState,
    cameraError,
    torchOn,
    hasTorch,
    verdict,
    online,
    syncState,
    syncMessage,
    lastSyncedAt,
    downloading,
    credentialExpired,
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
    download,
    activate,
    startCamera,
    stopCamera,
    toggleTorch,
    markByHand,
    sync,
    retry,
} = useScanStation({
    syncUrl: props.syncUrl,
    grant,
    // Namespaced per link, and never auto-restored: opening a different link
    // must not resurrect the previous training's roster on the same phone.
    storageKey: `csc-tims-scan:last:${props.token}`,
    restoreLast: false,
    // Fixed by the link, not chosen here. A phone handed to a volunteer does
    // not get to decide whether the morning's attendance was real.
    testMode: props.link?.is_test ?? false,
});

/** The gate is shown whenever the device holds no usable grant. */
const locked = computed(() => !grant.value || credentialExpired.value);

/**
 * Whether this mount is about to restore a roster from IndexedDB.
 *
 * Every input here (`localStorage`, `props.state`) is available synchronously
 * at setup time, so this can be decided before the first paint rather than
 * flipped true then false across it — which is what let the setup screen's
 * "download the roster first" flash onto a phone that already had one, for
 * the one tick between mount and the `activate()` below resolving.
 */
const restoringRoster = ref(
    Boolean(grant.value) &&
        props.state === 'active' &&
        Boolean(localStorage.getItem(`csc-tims-scan:last:${props.token}`))
);

/**
 * Hand the viewfinder's <video> element to the composable.
 *
 * Written here rather than passing `video` straight to StationViewport, because
 * a setup-returned ref is *unwrapped* in the template and the component would
 * receive the element the ref currently holds — null — instead of the ref. See
 * the prop's own note.
 */
const bindVideo = (el) => {
    video.value = el;
};

const registered = computed(() => roster.value?.participants.length ?? 0);
const remaining = computed(() => Math.max(0, registered.value - markedToday.value));

/** "Day 2 of 3", or nothing at all on a single-day run. */
const dayLabel = computed(() => {
    const total = roster.value?.training.days.length ?? 0;

    if (!today.value || total < 2) {
        return null;
    }

    return `Day ${today.value.day} of ${total}`;
});

const deadLinkCopy = {
    unknown: {
        title: 'This scanning link is not recognised',
        body: 'The address may have been mistyped or the link may have been removed. Ask the training officer for a new one.',
    },
    expired: {
        title: 'This scanning link has expired',
        body: 'Links are issued for a limited period. Ask the training officer to issue a fresh one for today’s session.',
    },
    revoked: {
        title: 'This scanning link has been revoked',
        body: 'Someone at the office has withdrawn this link. Ask the training officer for a new one before scanning.',
    },
};

/**
 * Exchange the code for a grant.
 *
 * Plain fetch rather than an Inertia visit: the answer is a credential the
 * device has to keep, and a full page visit would throw away the IndexedDB-backed
 * state the station may already be holding.
 */
async function unlock() {
    if (code.value.trim().length === 0 || unlocking.value) {
        return;
    }

    unlocking.value = true;
    gateError.value = null;

    try {
        const response = await fetch(props.unlockUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ code: code.value.trim() }),
        });

        if (!response.ok) {
            gateError.value = 'That code does not match this scanning link.';

            return;
        }

        const payload = await response.json();

        grant.value = payload.grant;
        localStorage.setItem(GRANT_KEY, payload.grant);
        credentialExpired.value = false;
        code.value = '';
    } catch {
        gateError.value = 'Could not reach the server. Check the connection and try again.';
    } finally {
        unlocking.value = false;
    }
}

/**
 * Pull the roster for this link's training.
 *
 * The link is bound to exactly one training server-side, so there is nothing to
 * choose — the station passes its one roster URL and takes what comes back.
 */
async function fetchRoster() {
    await download({ id: props.token, roster_url: props.rosterUrl });
}

onMounted(async () => {
    if (!restoringRoster.value) {
        return;
    }

    // A phone reopened mid-session should land straight back on its roster
    // rather than asking for a code it already answered.
    try {
        const saved = localStorage.getItem(`csc-tims-scan:last:${props.token}`);

        if (saved) {
            await activate(Number(saved));
        }
    } finally {
        restoringRoster.value = false;
    }
});
</script>

<template>
    <Head :title="link ? `Scan · ${link.training_title}` : 'Attendance Scanner'" />

    <StationShell
        :title="link ? link.training_title : 'Attendance Scanner'"
        :online="online"
    >
        <!-- Event context, once there is an event on the device. -->
        <template v-if="state === 'active' && roster" #meta>
            <span class="inline-flex items-center gap-1.5">
                <AppIcon name="calendar" size="sm" class="text-csc-blue" />
                {{ today ? today.label : 'Not running today' }}
            </span>
            <span v-if="dayLabel" class="inline-flex items-center gap-1.5 font-semibold text-csc-blue">
                {{ dayLabel }}
            </span>
            <span v-if="link.venue" class="inline-flex items-center gap-1.5">
                <AppIcon name="map-pin" size="sm" class="text-csc-blue" />
                {{ link.venue }}
            </span>
            <span v-if="link.label" class="inline-flex items-center gap-1.5">
                <AppIcon name="qr" size="sm" class="text-csc-blue" />
                {{ link.label }}
            </span>
        </template>

        <!--
            A practice station says so on every screen, including before the
            code is entered. Someone handed this phone must never scan a real
            queue believing it counted.
        -->
        <template v-if="state === 'active' && testing" #banner>
            <div class="bg-warning text-white">
                <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-2">
                    <AppIcon name="warning" size="sm" class="shrink-0" />
                    <p class="min-w-0 flex-1 text-2xs font-semibold">
                        PRACTICE STATION — scans are checked but never saved.
                    </p>
                    <button
                        v-if="testedCount"
                        type="button"
                        class="shrink-0 rounded-md border border-white/60 px-2.5 py-1 text-2xs font-semibold transition-colors hover:bg-white/15"
                        @click="clearTestScans"
                    >
                        Clear {{ testedCount }}
                    </button>
                </div>
            </div>
        </template>

        <!-- ========================= DEAD LINK ========================= -->
        <div v-if="state !== 'active'" class="mx-auto max-w-md py-10 text-center">
            <span class="mx-auto grid size-14 place-items-center rounded-2xl bg-danger-soft text-danger">
                <AppIcon name="warning" size="lg" />
            </span>
            <h1 class="mt-4 text-lg font-semibold text-csc-blue">{{ deadLinkCopy[state].title }}</h1>
            <p class="mt-2 text-sm leading-relaxed text-csc-ink-muted">{{ deadLinkCopy[state].body }}</p>
        </div>

        <!-- =========================== GATE =========================== -->
        <div v-else-if="locked" class="mx-auto max-w-md">
            <div class="rounded-2xl border border-csc-line bg-white p-6 shadow-sm">
                <div class="text-center">
                    <span class="mx-auto grid size-12 place-items-center rounded-xl bg-csc-blue-tint text-csc-blue">
                        <AppIcon name="lock" size="lg" />
                    </span>
                    <h1 class="mt-4 text-lg font-semibold text-csc-blue">Enter the scanning code</h1>
                    <p class="mt-2 text-sm leading-relaxed text-csc-ink-muted">
                        The training officer who set up this station will have given you a six-digit
                        code. It is needed once on this device.
                    </p>
                </div>

                <p
                    v-if="credentialExpired"
                    class="mt-5 rounded-xl bg-warning-soft px-4 py-3 text-sm leading-relaxed text-warning"
                >
                    This device needs the code again. Nothing has been lost — any scans still waiting
                    are safe and will be sent once you are back in.
                </p>

                <form class="mt-5" @submit.prevent="unlock">
                    <label class="block">
                        <span class="sr-only">Scanning code</span>
                        <input
                            v-model="code"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="6"
                            placeholder="000000"
                            class="w-full rounded-xl border border-csc-line bg-white px-4 py-4 text-center text-2xl font-semibold tracking-[0.4em] text-csc-ink placeholder:text-csc-ink-placeholder focus:border-csc-blue focus:outline-none"
                        />
                    </label>

                    <p v-if="gateError" class="mt-3 rounded-lg bg-danger-soft px-4 py-2.5 text-sm text-danger">
                        {{ gateError }}
                    </p>

                    <AppButton
                        class="mt-4"
                        type="submit"
                        size="lg"
                        block
                        :disabled="!code.trim()"
                        :loading="unlocking"
                    >
                        {{ unlocking ? 'Checking…' : 'Unlock scanner' }}
                    </AppButton>
                </form>
            </div>

            <p class="mt-5 text-center text-2xs leading-relaxed text-csc-ink-subtle">
                This station records attendance only. It cannot change registrations, payments or
                certificates.
            </p>
        </div>

        <!--
            Restoring. The device may already hold this link's last roster in
            IndexedDB; reading it back is asynchronous, so this holds the frame
            rather than flashing "download the roster first" underneath it.
        -->
        <div v-else-if="restoringRoster" class="mx-auto max-w-md">
            <AppSkeleton variant="list" :count="2" label="Restoring the roster" />
        </div>

        <!-- ========================== SETUP =========================== -->
        <div v-else-if="!roster" class="mx-auto max-w-md">
            <div class="rounded-2xl border border-csc-line bg-white p-5 shadow-sm">
                <div class="flex items-start gap-4">
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-csc-blue-tint text-csc-blue">
                        <AppIcon name="download" size="lg" />
                    </span>
                    <div>
                        <h1 class="text-base font-semibold text-csc-blue">Download the roster first</h1>
                        <p class="mt-1 text-sm leading-relaxed text-csc-ink-muted">
                            The participant list is kept on this phone, so scanning keeps working when
                            the venue has no signal. Download it now, while you still have a connection.
                        </p>
                    </div>
                </div>
            </div>

            <p
                v-if="syncMessage"
                class="mt-4 rounded-xl px-4 py-3 text-sm leading-relaxed"
                :class="syncState === 'error' ? 'bg-danger-soft text-danger' : 'bg-success-soft text-success'"
            >
                {{ syncMessage }}
            </p>

            <AppButton
                class="mt-5"
                size="lg"
                block
                icon="download"
                :disabled="!online"
                :loading="downloading !== null"
                @click="fetchRoster"
            >
                {{ downloading !== null ? 'Downloading…' : 'Download roster' }}
            </AppButton>

            <p v-if="!online" class="mt-3 text-center text-2xs text-csc-ink-subtle">
                You are offline. Connect to a network to download the roster — after that, no
                connection is needed.
            </p>
        </div>

        <!-- ========================= SCANNING ========================= -->
        <!-- See the matching comment in Pages/Staff/Scanner.vue: `grid-cols-1`
             here stops the mobile column growing to an unshrinkable
             descendant's min-content and overflowing the viewport horizontally. -->
        <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-12 lg:items-start">
            <div class="space-y-4 lg:col-span-7">
                <StationViewport
                    :bind-video="bindVideo"
                    :camera-state="cameraState"
                    :camera-error="cameraError"
                    :torch-on="torchOn"
                    :has-torch="hasTorch"
                    :verdict="verdict"
                    :testing="testing"
                    test-label="Practice"
                    @start="startCamera"
                    @stop="stopCamera"
                    @torch="toggleTorch"
                />

                <div class="grid grid-cols-3 gap-2 sm:gap-3">
                    <AppStatTile label="Registered" :value="registered" icon="users" caption="on this roster" />
                    <AppStatTile
                        label="Here today"
                        :value="markedToday"
                        icon="check-circle"
                        :tone="markedToday > 0 ? 'success' : 'brand'"
                        :caption="today ? today.label : 'not running today'"
                    />
                    <AppStatTile label="Remaining" :value="remaining" icon="clock" caption="not yet scanned" />
                </div>
            </div>

            <div class="space-y-4 lg:col-span-5">
                <StationPanels
                    :activity="activity"
                    :roster-rows="rosterRows"
                    :today="today"
                    @mark="markByHand"
                />

                <StationSyncCard
                    :label="syncLabel"
                    :tone="syncTone"
                    :state="syncState"
                    :message="syncMessage"
                    :last-synced-at="lastSyncedAt"
                    :synced-count="syncedCount"
                    :pending-count="pendingCount"
                    :failed-count="failedCount"
                    @sync="sync()"
                    @retry="retry"
                />
            </div>
        </div>
    </StationShell>
</template>
