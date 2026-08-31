<script setup>
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';
import { toneDots, useScanStation, verdictStyles } from '@/scanner/station';

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
    scans,
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

const panel = ref(null); // null | 'roster' | 'activity'
const search = ref('');

const rows = computed(() => rosterRows(search.value));

/** The gate is shown whenever the device holds no usable grant. */
const locked = computed(() => !grant.value || credentialExpired.value);

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
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ code: code.value.trim() }),
        });

        if (response.status === 429) {
            gateError.value = 'Too many attempts. Wait a minute before trying again.';

            return;
        }

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
    // A phone reopened mid-session should land straight back on its roster
    // rather than asking for a code it already answered.
    if (grant.value && props.state === 'active') {
        const saved = localStorage.getItem(`csc-tims-scan:last:${props.token}`);

        if (saved) {
            await activate(Number(saved));
        }
    }
});
</script>

<template>
    <Head :title="link ? `Scan · ${link.training_title}` : 'Attendance Scanner'" />

    <div class="flex min-h-dvh flex-col bg-csc-blue-deep text-white">
        <!-- ========================= DEAD LINK ========================= -->
        <main v-if="state !== 'active'" class="mx-auto grid w-full max-w-md flex-1 place-items-center px-5 py-10">
            <div class="text-center">
                <AppIcon name="warning" size="lg" class="mx-auto text-white/60" />
                <h1 class="mt-4 text-lg font-semibold">{{ deadLinkCopy[state].title }}</h1>
                <p class="mt-2 text-sm leading-relaxed text-white/70">{{ deadLinkCopy[state].body }}</p>
            </div>
        </main>

        <template v-else>
            <!-- Kiosk chrome. One line of identity, one of status — a phone at a
                 door has no room for more, and nothing else is actionable. -->
            <header class="sticky top-0 z-header border-b border-white/10 bg-csc-blue-deep/95 backdrop-blur">
                <div class="mx-auto flex max-w-3xl items-center gap-3 px-4 py-3">
                    <AppIcon name="qr" class="shrink-0 text-white/70" />

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold">{{ link.training_title }}</p>
                        <p class="truncate text-2xs text-white/60">
                            <template v-if="roster">
                                {{ today ? today.label : 'Not running today' }} ·
                                {{ markedToday }} of {{ roster.participants.length }} marked
                            </template>
                            <template v-else>
                                {{ link.label ?? 'Attendance station' }}
                                <span v-if="link.venue"> · {{ link.venue }}</span>
                            </template>
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

            <!--
                A practice station says so on every screen, including before
                the code is entered. Someone handed this phone must never scan a
                real queue believing it counted.
            -->
            <div v-if="testing" class="bg-warning text-csc-ink">
                <div class="mx-auto flex max-w-3xl items-center gap-3 px-4 py-2">
                    <AppIcon name="warning" size="sm" class="shrink-0" />
                    <p class="min-w-0 flex-1 text-2xs font-semibold">
                        PRACTICE STATION — scans are checked but never saved.
                    </p>
                    <button
                        v-if="testedCount"
                        type="button"
                        class="shrink-0 rounded-md border border-csc-ink/30 px-2.5 py-1 text-2xs font-semibold transition-colors hover:bg-csc-ink/10"
                        @click="clearTestScans"
                    >
                        Clear {{ testedCount }}
                    </button>
                </div>
            </div>

            <!-- =========================== GATE =========================== -->
            <main v-if="locked" class="mx-auto w-full max-w-md flex-1 px-5 py-8">
                <div class="text-center">
                    <h1 class="text-lg font-semibold">Enter the scanning code</h1>
                    <p class="mt-2 text-sm leading-relaxed text-white/70">
                        The training officer who set up this station will have given you a six-digit
                        code. It is needed once on this device.
                    </p>
                </div>

                <p
                    v-if="credentialExpired"
                    class="mt-5 rounded-xl bg-warning/20 px-4 py-3 text-sm leading-relaxed"
                >
                    This device needs the code again. Nothing has been lost — any scans still waiting
                    are safe and will be sent once you are back in.
                </p>

                <form class="mt-6" @submit.prevent="unlock">
                    <label class="block">
                        <span class="sr-only">Scanning code</span>
                        <input
                            v-model="code"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="6"
                            placeholder="000000"
                            class="w-full rounded-xl border border-white/25 bg-white/10 px-4 py-4 text-center text-2xl font-semibold tracking-[0.4em] placeholder:text-white/60 focus:border-white focus:outline-none"
                        />
                    </label>

                    <p v-if="gateError" class="mt-3 rounded-lg bg-danger/25 px-4 py-2.5 text-sm">
                        {{ gateError }}
                    </p>

                    <button
                        type="submit"
                        :disabled="unlocking || !code.trim()"
                        class="mt-4 w-full rounded-xl bg-white px-6 py-4 text-base font-semibold text-csc-blue transition-colors hover:bg-csc-blue-tint disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ unlocking ? 'Checking…' : 'Unlock scanner' }}
                    </button>
                </form>

                <p class="mt-6 text-center text-2xs leading-relaxed text-white/70">
                    This station records attendance only. It cannot change registrations, payments or
                    certificates.
                </p>
            </main>

            <!-- ========================== SETUP =========================== -->
            <main v-else-if="!roster" class="mx-auto w-full max-w-md flex-1 px-5 py-8">
                <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                    <div class="flex items-start gap-3">
                        <AppIcon name="download" size="lg" class="mt-0.5 shrink-0 text-white/70" />
                        <div>
                            <h1 class="text-base font-semibold">Download the roster first</h1>
                            <p class="mt-1 text-sm leading-relaxed text-white/70">
                                The participant list is kept on this phone, so scanning keeps working
                                when the venue has no signal. Download it now, while you still have a
                                connection.
                            </p>
                        </div>
                    </div>
                </div>

                <p
                    v-if="syncMessage"
                    class="mt-4 rounded-xl px-4 py-3 text-sm leading-relaxed"
                    :class="syncState === 'error' ? 'bg-danger/20' : 'bg-success/20'"
                >
                    {{ syncMessage }}
                </p>

                <button
                    type="button"
                    :disabled="!online || downloading !== null"
                    class="mt-5 w-full rounded-xl bg-white px-6 py-4 text-base font-semibold text-csc-blue transition-colors hover:bg-csc-blue-tint disabled:cursor-not-allowed disabled:opacity-50"
                    @click="fetchRoster"
                >
                    {{ downloading !== null ? 'Downloading…' : 'Download roster' }}
                </button>

                <p v-if="!online" class="mt-3 text-center text-2xs text-white/60">
                    You are offline. Connect to a network to download the roster — after that, no
                    connection is needed.
                </p>
            </main>

            <!-- ========================== SCANNER ========================= -->
            <main v-else class="mx-auto flex w-full max-w-3xl flex-1 flex-col px-4 pt-4 pb-24">
                <!-- Sync status. Always visible: whoever is holding the phone
                     must never have to wonder whether the last hour of scanning
                     has actually left it. -->
                <div class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-4 py-2.5">
                    <span class="size-2 shrink-0 rounded-full" :class="toneDots[syncTone]" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">{{ syncLabel }}</p>
                        <p v-if="lastSyncedAt" class="truncate text-2xs text-white/70">
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

                    <div
                        v-if="cameraState === 'running'"
                        class="pointer-events-none absolute inset-0 grid place-items-center"
                    >
                        <div class="size-48 rounded-2xl border-2 border-white/70 shadow-[0_0_0_9999px_rgba(0,0,0,0.35)] sm:size-56" />
                    </div>

                    <div
                        v-if="cameraState !== 'running'"
                        class="absolute inset-0 grid place-items-center bg-csc-blue-deep/90 p-6 text-center"
                    >
                        <div>
                            <AppIcon name="qr" size="lg" class="mx-auto text-white/60" />
                            <p v-if="cameraError" class="mt-3 text-sm leading-relaxed text-white/80">
                                {{ cameraError }}
                            </p>
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

                    <!-- Verdict, over the viewport: the operator's eyes are on
                         the camera, so the answer has to be where they already
                         are. Colour is never the only signal — every state
                         carries an icon and a written title. -->
                    <transition
                        enter-active-class="transition duration-150"
                        enter-from-class="translate-y-2 opacity-0"
                        leave-active-class="transition duration-150"
                        leave-to-class="opacity-0"
                    >
                        <div v-if="verdict" class="absolute inset-x-0 bottom-0 p-3" role="status" aria-live="assertive">
                            <div
                                class="flex items-start gap-3 rounded-xl p-4 shadow-lg"
                                :class="verdictStyles[verdict.verdict].tone"
                            >
                                <AppIcon :name="verdictStyles[verdict.verdict].icon" size="lg" class="mt-0.5 shrink-0" />
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold">{{ verdictStyles[verdict.verdict].title }}</p>
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

                                    <p v-if="testing" class="mt-2 rounded-lg bg-black/25 px-2 py-1 text-2xs font-semibold">
                                        Practice — nothing was saved.
                                        <template v-if="verdict.simulatedDay">
                                            This training is not running today, so day
                                            {{ verdict.day }} was stood in for the rehearsal.
                                        </template>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </transition>
                </div>

                <!-- Controls. Big targets, two per row on a phone: this is
                     operated one-handed, often standing. -->
                <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <button
                        v-if="cameraState === 'running'"
                        type="button"
                        class="rounded-lg border border-white/25 px-3 py-3 text-sm font-semibold transition-colors hover:bg-white/10"
                        @click="stopCamera"
                    >
                        Pause camera
                    </button>
                    <button
                        v-if="cameraState === 'running' && hasTorch"
                        type="button"
                        class="rounded-lg border border-white/25 px-3 py-3 text-sm font-semibold transition-colors hover:bg-white/10"
                        @click="toggleTorch"
                    >
                        Light {{ torchOn ? 'off' : 'on' }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-white/25 px-3 py-3 text-sm font-semibold transition-colors hover:bg-white/10"
                        @click="panel = panel === 'roster' ? null : 'roster'"
                    >
                        Roster
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-white/25 px-3 py-3 text-sm font-semibold transition-colors hover:bg-white/10"
                        @click="panel = panel === 'activity' ? null : 'activity'"
                    >
                        Activity
                    </button>
                </div>

                <!-- Roster: the manual fallback for a code that will not read,
                     and the answer to "who is still missing". -->
                <section v-if="panel === 'roster'" class="mt-4 rounded-2xl border border-white/10 bg-white/5 p-4">
                    <label class="block">
                        <span class="sr-only">Search the roster</span>
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Search by name or organisation"
                            class="w-full rounded-lg border border-white/20 bg-white/10 px-3 py-2.5 text-sm placeholder:text-white/70 focus:border-white focus:outline-none"
                        />
                    </label>

                    <p v-if="!today" class="mt-3 text-2xs text-white/60">
                        This training is not running today, so nobody can be marked by hand.
                    </p>

                    <ul class="mt-3 max-h-96 space-y-1.5 overflow-y-auto">
                        <li
                            v-for="row in rows"
                            :key="row.registration_id"
                            class="flex items-center gap-3 rounded-lg bg-white/5 px-3 py-2.5"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ row.name }}</p>
                                <p v-if="row.organization" class="truncate text-2xs text-white/70">
                                    {{ row.organization }}
                                </p>
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

                <!-- Activity: every scan this device holds, and where it stands -->
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
                                <p class="truncate text-2xs text-white/70">
                                    Day {{ scan.training_day }} · {{ scan.time_in }}
                                    <span v-if="scan.by_hand"> · marked by hand</span>
                                    <span v-if="scan.message"> · {{ scan.message }}</span>
                                </p>
                            </div>

                            <span
                                class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-2xs font-semibold"
                                :class="{
                                    'bg-warning/30 text-white': scan.dry_run,
                                    'bg-success/25': !scan.dry_run && scan.state === 'synced',
                                    'bg-warning/25': !scan.dry_run && scan.state === 'pending',
                                    'bg-danger/30': !scan.dry_run && scan.state === 'failed',
                                }"
                            >
                                <AppIcon
                                    :name="scan.state === 'synced' ? 'check' : scan.state === 'pending' ? 'clock' : 'warning'"
                                    size="sm"
                                />
                                <template v-if="scan.dry_run">
                                    {{ scan.state === 'pending' ? 'Test pending' : 'Tested' }}
                                </template>
                                <template v-else>
                                    {{ scan.state === 'synced' ? 'Synced' : scan.state === 'pending' ? 'Pending' : 'Failed' }}
                                </template>
                            </span>
                        </li>
                    </ul>
                </section>

                <p v-if="pendingCount" class="mt-6 border-t border-white/10 pt-4 text-2xs leading-relaxed text-white/60">
                    {{ pendingCount }} scan<span v-if="pendingCount !== 1">s</span> still waiting to be
                    sent. They are safe on this phone — keep the page open and they will go out as soon
                    as there is a signal.
                </p>
            </main>
        </template>
    </div>
</template>
