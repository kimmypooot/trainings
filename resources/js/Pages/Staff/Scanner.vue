<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';
import { toneDots, useScanStation, verdictStyles } from '@/scanner/station';

/**
 * The venue attendance station, for signed-in staff.
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
 *
 * The behaviour lives in useScanStation, shared with the public scan-link
 * station at Pages/Scan/Station.vue; what remains here is this door's chrome.
 */

const props = defineProps({
    trainings: { type: Array, default: () => [] },
    syncUrl: { type: String, required: true },
    scopedTo: { type: String, default: null },
    operator: { type: String, default: null },
    canTest: { type: Boolean, default: false },
});

/**
 * Rehearsal mode.
 *
 * Off on every load, never remembered. A station that came back from a screen
 * lock still quietly in test mode would be the exact failure this is meant to
 * prevent — an operator scanning a real queue into nothing.
 */
const testMode = ref(false);

const {
    roster,
    scans,
    storedRosters,
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
    release,
    startCamera,
    stopCamera,
    toggleTorch,
    markByHand,
    sync,
    retry,
} = useScanStation({ syncUrl: props.syncUrl, testMode });

const panel = ref(null); // null | 'roster' | 'activity'
const search = ref('');
const confirmingRelease = ref(false);

const rows = computed(() => rosterRows(search.value));

async function confirmRelease() {
    await release();
    confirmingRelease.value = false;
}
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

        <!--
            Rehearsal strip. Rendered for super administrators only, and sits
            directly under the header so it is impossible to be in test mode
            without the fact being on screen above whatever you are doing.
        -->
        <div v-if="canTest" :class="testing ? 'bg-warning text-csc-ink' : 'border-b border-white/10 bg-white/5'">
            <div class="mx-auto flex max-w-3xl items-center gap-3 px-4 py-2">
                <template v-if="testing">
                    <AppIcon name="warning" size="sm" class="shrink-0" />
                    <p class="min-w-0 flex-1 text-2xs font-semibold">
                        TEST MODE — scans are checked but never saved.
                        <span v-if="testedCount"> {{ testedCount }} test scan{{ testedCount === 1 ? '' : 's' }} on this device.</span>
                    </p>
                    <button
                        v-if="testedCount"
                        type="button"
                        class="shrink-0 rounded-md border border-csc-ink/30 px-2.5 py-1 text-2xs font-semibold transition-colors hover:bg-csc-ink/10"
                        @click="clearTestScans"
                    >
                        Clear
                    </button>
                    <button
                        type="button"
                        class="shrink-0 rounded-md bg-csc-ink px-2.5 py-1 text-2xs font-semibold text-white"
                        @click="testMode = false"
                    >
                        Turn off
                    </button>
                </template>

                <template v-else>
                    <p class="min-w-0 flex-1 text-2xs text-white/60">
                        Scans are recorded for real.
                    </p>
                    <button
                        type="button"
                        :disabled="pendingCount > 0"
                        class="shrink-0 rounded-md border border-white/25 px-2.5 py-1 text-2xs font-semibold transition-colors hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-40"
                        :title="pendingCount ? 'Sync the waiting scans before rehearsing' : 'Rehearse without saving anything'"
                        @click="testMode = true"
                    >
                        Start test mode
                    </button>
                </template>
            </div>
        </div>

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

                                <p v-if="testing" class="mt-2 rounded-lg bg-black/25 px-2 py-1 text-2xs font-semibold">
                                    Test mode — nothing was saved.
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
                        v-for="row in rows"
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
                            <!-- A rehearsal never reads as "Synced": the whole
                                 point is that no record exists anywhere. -->
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
                            @click="confirmRelease"
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
