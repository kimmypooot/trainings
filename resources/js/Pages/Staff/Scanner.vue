<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
 * The venue attendance station, for signed-in staff.
 *
 * Deliberately not wrapped in AuthenticatedLayout. This is a kiosk: it is held
 * in one hand at a door or propped on a registration desk, it wants the whole
 * screen for a camera viewport, and a sidebar full of links is a liability when
 * a mis-tap loses the operator's place mid-queue. The only way out is one
 * explicit control.
 *
 * Everything on this page reads from IndexedDB, never from props, once a roster
 * is loaded. Props supply the list of trainings that *could* be downloaded and
 * the two URLs — beyond that the station is self-contained, because the whole
 * design assumes the network disappears the moment the session starts.
 *
 * The behaviour lives in useScanStation, shared with the public scan-link
 * station at Pages/Scan/Station.vue, and the chrome now lives in
 * Components/Station/*, shared with it too. What remains in this file is what
 * is genuinely this door's: a way back to the admin area, a choice of which
 * training to load, the walk-in offer, and rehearsal mode.
 *
 * The layout is built around one question — is the desk working? — and answers
 * it in three tiers. The viewfinder and its verdict dominate; the day's three
 * figures sit directly under them; the lists that give the operator confidence
 * they scanned the right person sit alongside, on screen rather than behind a
 * toggle. Nothing else on the page competes with the camera.
 */

const props = defineProps({
    trainings: { type: Array, default: () => [] },
    syncUrl: { type: String, required: true },
    // Null for a station that may scan but not admit, which is how the walk-in
    // affordance stays absent rather than present and refused.
    walkInUrl: { type: String, default: null },
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
    admitWalkIn,
    admitting,
    sync,
    retry,
    restoring,
} = useScanStation({ syncUrl: props.syncUrl, walkInUrl: props.walkInUrl, testMode });

const confirmingRelease = ref(false);

/**
 * Hand the viewfinder's <video> element to the composable.
 *
 * Written here rather than passing `video` straight to StationViewport, because
 * a setup-returned ref is *unwrapped* in the template: `:video-ref="video"`
 * sends the element the ref currently holds — null, on first render — and the
 * component's function ref then throws. In this scope `video` is still a Ref,
 * so the assignment lands on the ref itself. See the prop's own note.
 */
const bindVideo = (el) => {
    video.value = el;
};

/** Registered, here today, still to arrive — the three the desk actually uses. */
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

async function confirmRelease() {
    await release();
    confirmingRelease.value = false;
}
</script>

<template>
    <Head title="Attendance Scanner" />

    <StationShell :title="roster ? roster.training.title : 'Attendance Scanner'" :online="online">
        <template #lead>
            <Link
                href="/admin"
                class="-ml-1 shrink-0 rounded-lg p-2 text-csc-ink-muted transition-colors hover:bg-csc-blue-tint hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
            >
                <AppIcon name="arrow-left" label="Leave the scanner" />
            </Link>
        </template>

        <!--
            Event context, once there is an event. Date, venue and which day of
            the run — the three facts an operator checks when a scan comes back
            "not running today" and they need to know whether the tablet is on
            the wrong training or simply the wrong date.
        -->
        <template v-if="roster" #meta>
            <span class="inline-flex items-center gap-1.5">
                <AppIcon name="calendar" size="sm" class="text-csc-blue" />
                {{ today ? today.label : 'Not running today' }}
            </span>
            <span v-if="dayLabel" class="inline-flex items-center gap-1.5 font-semibold text-csc-blue">
                {{ dayLabel }}
            </span>
            <span v-if="roster.training.venue" class="inline-flex items-center gap-1.5">
                <AppIcon name="map-pin" size="sm" class="text-csc-blue" />
                {{ roster.training.venue }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <AppIcon name="user" size="sm" class="text-csc-blue" />
                {{ operator }}<template v-if="scopedTo"> · {{ scopedTo }}</template>
            </span>
        </template>

        <!--
            Rehearsal strip. Rendered for super administrators only, and sits
            directly under the masthead so it is impossible to be in test mode
            without the fact being on screen above whatever you are doing.
        -->
        <template v-if="canTest" #banner>
            <div :class="testing ? 'bg-warning text-white' : 'border-b border-csc-line bg-white'">
                <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-2">
                    <template v-if="testing">
                        <AppIcon name="warning" size="sm" class="shrink-0" />
                        <p class="min-w-0 flex-1 text-2xs font-semibold">
                            TEST MODE — scans are checked but never saved.
                            <span v-if="testedCount">
                                {{ testedCount }} test scan{{ testedCount === 1 ? '' : 's' }} on this device.
                            </span>
                        </p>
                        <button
                            v-if="testedCount"
                            type="button"
                            class="shrink-0 rounded-md border border-white/60 px-2.5 py-1 text-2xs font-semibold transition-colors hover:bg-white/15"
                            @click="clearTestScans"
                        >
                            Clear
                        </button>
                        <button
                            type="button"
                            class="shrink-0 rounded-md bg-white px-2.5 py-1 text-2xs font-semibold text-warning"
                            @click="testMode = false"
                        >
                            Turn off
                        </button>
                    </template>

                    <template v-else>
                        <p class="min-w-0 flex-1 text-2xs text-csc-ink-subtle">Scans are recorded for real.</p>
                        <button
                            type="button"
                            :disabled="pendingCount > 0"
                            class="shrink-0 rounded-md border border-csc-line px-2.5 py-1 text-2xs font-semibold text-csc-ink-muted transition-colors hover:border-csc-blue hover:text-csc-blue disabled:cursor-not-allowed disabled:opacity-40"
                            :title="pendingCount ? 'Sync the waiting scans before rehearsing' : 'Rehearse without saving anything'"
                            @click="testMode = true"
                        >
                            Start test mode
                        </button>
                    </template>
                </div>
            </div>
        </template>

        <!--
            Restoring. The device may already hold the last training's roster
            in IndexedDB, and reading it back is asynchronous — rendering the
            setup screen the instant `roster` is null would flash "download an
            event" for one frame on every reopen before flipping to the
            scanner underneath it. This holds that frame instead.
        -->
        <div v-if="restoring" class="mx-auto max-w-3xl">
            <AppSkeleton variant="list" :count="2" label="Restoring the last roster" />
        </div>

        <!-- ============================ SETUP ============================ -->
        <div v-else-if="!roster" class="mx-auto max-w-3xl">
            <div class="rounded-2xl border border-csc-line bg-white p-5 shadow-sm">
                <div class="flex items-start gap-4">
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-csc-blue-tint text-csc-blue">
                        <AppIcon name="download" size="lg" />
                    </span>
                    <div>
                        <h1 class="text-base font-semibold text-csc-blue">
                            Download an event before the session
                        </h1>
                        <p class="mt-1 text-sm leading-relaxed text-csc-ink-muted">
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
                :class="syncState === 'error' ? 'bg-danger-soft text-danger' : 'bg-success-soft text-success'"
            >
                {{ syncMessage }}
            </p>

            <section v-if="storedRosters.length" class="mt-6">
                <h2 class="flex items-center gap-2 text-2xs font-semibold tracking-wide text-csc-ink-muted uppercase">
                    <span class="h-3 w-[3px] shrink-0 rounded-full bg-csc-red" aria-hidden="true" />
                    On this device
                </h2>

                <ul class="mt-2 space-y-2">
                    <li
                        v-for="stored in storedRosters"
                        :key="stored.training_id"
                        class="flex items-center gap-3 rounded-xl border border-csc-line bg-white p-4 shadow-sm"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-csc-ink">{{ stored.training.title }}</p>
                            <p class="mt-0.5 text-2xs text-csc-ink-subtle">
                                {{ stored.participants.length }} participants · saved
                                {{ new Date(stored.saved_at).toLocaleString() }}
                            </p>
                        </div>

                        <AppButton size="sm" icon="qr" @click="activate(stored.training_id)">Open</AppButton>
                    </li>
                </ul>
            </section>

            <section class="mt-6">
                <h2 class="flex items-center gap-2 text-2xs font-semibold tracking-wide text-csc-ink-muted uppercase">
                    <span class="h-3 w-[3px] shrink-0 rounded-full bg-csc-red" aria-hidden="true" />
                    Scheduled events
                </h2>

                <p
                    v-if="!trainings.length"
                    class="mt-2 rounded-xl border border-csc-line bg-white p-4 text-sm text-csc-ink-muted"
                >
                    No training is scheduled in the current window.
                </p>

                <ul v-else class="mt-2 space-y-2">
                    <li
                        v-for="training in trainings"
                        :key="training.id"
                        class="rounded-xl border bg-white p-4 shadow-sm"
                        :class="training.is_today ? 'border-csc-blue/40' : 'border-csc-line'"
                    >
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-csc-ink">{{ training.title }}</p>
                                <p class="mt-0.5 text-2xs text-csc-ink-subtle">
                                    {{ training.date_label }}
                                    <span v-if="training.venue"> · {{ training.venue }}</span>
                                </p>
                                <span
                                    v-if="training.is_today"
                                    class="mt-2 inline-flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-2xs font-semibold text-success"
                                >
                                    <AppIcon name="check" size="sm" />
                                    Running today
                                </span>
                            </div>

                            <AppButton
                                size="sm"
                                icon="download"
                                :disabled="!online"
                                :loading="downloading === training.id"
                                @click="download(training)"
                            >
                                {{ downloading === training.id ? 'Downloading…' : 'Download' }}
                            </AppButton>
                        </div>
                    </li>
                </ul>

                <p v-if="!online" class="mt-3 text-2xs text-csc-ink-subtle">
                    A new roster cannot be downloaded while offline. Rosters already on this device stay
                    available.
                </p>
            </section>
        </div>

        <!-- =========================== SCANNING =========================== -->
        <!--
            `grid-cols-1` at the base breakpoint is load-bearing, not
            decoration. Below `lg` this was bare `display:grid` with no
            explicit column track, so the single implicit column sized to its
            widest unshrinkable descendant's min-content instead of the
            container's width — a stat tile's number, a badge chip, anything
            that would ordinarily just truncate — and stretched the whole page
            horizontally on a phone. Tailwind's `grid-cols-N` utilities set
            `minmax(0,1fr)` specifically to stop this, which is why `lg:`
            never showed it; the mobile column needs the same guard.
        -->
        <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-12 lg:items-start">
            <!-- The scanner, and the three figures it moves. -->
            <div class="space-y-4 lg:col-span-7">
                <StationViewport
                    :bind-video="bindVideo"
                    :camera-state="cameraState"
                    :camera-error="cameraError"
                    :torch-on="torchOn"
                    :has-torch="hasTorch"
                    :verdict="verdict"
                    :admitting="admitting"
                    :testing="testing"
                    test-label="Test mode"
                    @start="startCamera"
                    @stop="stopCamera"
                    @torch="toggleTorch"
                    @admit="admitWalkIn"
                />

                <!--
                    Immediate operational awareness, and no more than that. The
                    tone on "Here today" is conditional on the figure rather
                    than fixed to the label: a green zero would be claiming good
                    news about a hall nobody has walked into yet.
                -->
                <div class="grid grid-cols-3 gap-2 sm:gap-3">
                    <AppStatTile
                        label="Registered"
                        :value="registered"
                        icon="users"
                        caption="on this roster"
                    />
                    <AppStatTile
                        label="Here today"
                        :value="markedToday"
                        icon="check-circle"
                        :tone="markedToday > 0 ? 'success' : 'brand'"
                        :caption="today ? today.label : 'not running today'"
                    />
                    <AppStatTile
                        label="Remaining"
                        :value="remaining"
                        icon="clock"
                        caption="not yet scanned"
                    />
                </div>
            </div>

            <!-- Who was just scanned, and where the morning's work is. -->
            <div class="space-y-4 lg:col-span-5">
                <StationPanels
                    :activity="activity"
                    :roster-rows="rosterRows"
                    :today="today"
                    :online="online"
                    can-lookup-qr
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
                >
                    <AppButton size="sm" variant="ghost" icon="list" @click="roster = null">
                        Switch event
                    </AppButton>
                    <AppButton
                        size="sm"
                        variant="ghost"
                        icon="trash"
                        :disabled="pendingCount > 0"
                        :title="pendingCount ? 'Sync the remaining scans first' : 'Remove this roster from the device'"
                        @click="confirmingRelease = true"
                    >
                        Remove from device
                    </AppButton>
                </StationSyncCard>

                <div v-if="confirmingRelease" class="rounded-2xl border border-danger/30 bg-danger-soft p-4">
                    <p class="text-sm leading-relaxed text-csc-ink">
                        Remove “{{ roster.training.title }}” and its {{ scans.length }} recorded scans from
                        this device? Everything has been sent to the server; this only clears the local
                        copy.
                    </p>
                    <div class="mt-3 flex gap-2">
                        <AppButton size="sm" variant="accent" @click="confirmRelease">Remove</AppButton>
                        <AppButton size="sm" variant="ghost" @click="confirmingRelease = false">
                            Keep it
                        </AppButton>
                    </div>
                </div>
            </div>
        </div>
    </StationShell>
</template>
