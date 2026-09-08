<script setup>
import { computed, ref } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppModal from '@/Components/AppModal.vue';
import AppTabs from '@/Components/AppTabs.vue';

/**
 * The three lists beside the scanner: what has just happened, who is
 * missing, and — for a participant who turned up without the phone their
 * code lives on — how to hand them a copy of it.
 *
 * The first two used to be hidden behind toggle buttons that started closed,
 * which meant the answer to the question a facilitator asks most often —
 * "did that last one go through, and was it the right person?" — was two
 * taps away and gone again the moment they looked back at the camera. On
 * anything wider than a phone there is room for it to simply be on screen,
 * and confidence that the desk is working is worth more than the space.
 *
 * Tabs rather than stacked cards because they answer different questions
 * about the same people and only one is ever being read; and `segmented`
 * rather than `underline` because this is a control inside a card, which is
 * exactly the split AppTabs documents.
 */
const props = defineProps({
    /** Newest first, from the composable. */
    activity: { type: Array, required: true },
    /** `rosterRows` from the composable — called with the search term. */
    rosterRows: { type: Function, required: true },
    /** Today's training day, or null when the run is not on today. */
    today: { type: Object, default: null },
    /**
     * Whether the device currently has a connection. The QR lookup is the
     * one thing on this whole offline-first screen that has to reach the
     * server — see StationPanels' own note on why — so it is the one list
     * here that cannot simply work from what the station already holds.
     */
    online: { type: Boolean, default: true },
    /*
     * Whether the QR-codes tab is offered at all. Off by default, because
     * this component is shared with the public volunteer station
     * (Pages/Scan/Station.vue) as well as the authenticated admin one, and
     * pulling up a participant's live check-in code is staff work with a
     * name attached — the same reasoning ScannerController::participantQr()
     * gives for keeping the endpoint itself off that door. Staff/Scanner.vue
     * is the only caller that turns this on.
     */
    canLookupQr: { type: Boolean, default: false },
});

const emit = defineEmits(['mark']);

const tab = ref('activity');
const search = ref('');
const qrSearch = ref('');

const rows = computed(() => props.rosterRows(search.value));
const qrRows = computed(() => props.rosterRows(qrSearch.value));

const tabs = computed(() => [
    { key: 'activity', label: 'Recent scans', icon: 'clock', count: props.activity.length },
    { key: 'roster', label: 'Roster', icon: 'users', count: rows.value.length },
    ...(props.canLookupQr
        ? [{ key: 'qr', label: 'QR codes', icon: 'qr', count: qrRows.value.length }]
        : []),
]);

/*
 * A participant's code, fetched on demand — never from the roster the
 * station already holds.
 *
 * Everything else on this card works from data the device downloaded before
 * the session started, on purpose: the whole station is built to keep
 * working with no signal. This is the one exception, and deliberately so —
 * the offline roster carries only a digest of each code, never the code
 * itself, precisely so a device left in a function room overnight is worth
 * nothing to whoever finds it. Answering "what is this person's code" has
 * to ask the server, every time.
 */
const showingQr = ref(null);
const qrLoadingFor = ref(null);
const qrError = ref(null);

const openQr = async (row) => {
    qrError.value = null;
    qrLoadingFor.value = row.registration_id;

    try {
        const response = await fetch(`/admin/scanner/registrations/${row.registration_id}/qr`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) throw new Error('request failed');

        showingQr.value = await response.json();
    } catch (error) {
        qrError.value = 'Could not load that code. Check the connection and try again.';
    } finally {
        qrLoadingFor.value = null;
    }
};

const closeQr = () => {
    showingQr.value = null;
};

/*
 * A separate window rather than the page's own print styles: this kiosk has
 * no sidebar or footer for a print stylesheet to hide, but it does have the
 * live camera viewfinder sitting right beside whatever the operator opened,
 * and printing the screen as it stands would put that in the printout too.
 * A dedicated tab with nothing but the code is also the one a phone's own
 * share sheet can save straight to photos, which "print" covers as well as
 * an admin kiosk needs to.
 *
 * Built with DOM calls rather than a template string of HTML, because a
 * participant's own name is going into that document — `.textContent`
 * escapes it the way Vue's interpolation already does everywhere else in
 * this app; string-concatenated HTML would not.
 */
const printQr = () => {
    if (!showingQr.value) return;

    const printWindow = window.open('', '_blank', 'width=420,height=560');
    if (!printWindow) return;

    printWindow.document.title = `${showingQr.value.name} — QR Code`;

    const style = printWindow.document.createElement('style');
    style.textContent = `
        body { font-family: sans-serif; text-align: center; padding: 24px; }
        img { width: 280px; height: 280px; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        p { font-size: 13px; color: #555; margin: 0 0 16px; }
    `;
    printWindow.document.head.appendChild(style);

    const heading = printWindow.document.createElement('h1');
    heading.textContent = showingQr.value.name;
    printWindow.document.body.appendChild(heading);

    if (showingQr.value.organization) {
        const subheading = printWindow.document.createElement('p');
        subheading.textContent = showingQr.value.organization;
        printWindow.document.body.appendChild(subheading);
    }

    const image = printWindow.document.createElement('img');
    image.alt = 'QR code';
    // Printed, not shown on screen first, so the print dialog only opens
    // once there is something in it to print.
    image.onload = () => {
        printWindow.focus();
        printWindow.print();
    };
    image.src = showingQr.value.qr;
    printWindow.document.body.appendChild(image);
};

/**
 * The chip on a recorded scan.
 *
 * A rehearsal never reads as "Synced": the whole point of test mode is that no
 * record exists anywhere, and an operator glancing at a green "Synced" during a
 * rehearsal would draw the one conclusion the mode exists to prevent.
 */
function stateSkin(scan) {
    if (scan.dry_run) {
        return { tone: 'bg-warning-soft text-warning', icon: 'warning', label: scan.state === 'pending' ? 'Test pending' : 'Tested' };
    }

    if (scan.state === 'synced') {
        return { tone: 'bg-success-soft text-success', icon: 'check', label: 'Synced' };
    }

    if (scan.state === 'pending') {
        return { tone: 'bg-warning-soft text-warning', icon: 'clock', label: 'Pending' };
    }

    return { tone: 'bg-danger-soft text-danger', icon: 'warning', label: 'Failed' };
}
</script>

<template>
    <section class="overflow-hidden rounded-2xl border border-csc-line bg-white shadow-sm">
        <div class="border-b border-csc-line px-3 py-2.5">
            <AppTabs v-model="tab" :tabs="tabs" size="sm" aria-label="Scanner lists" block />
        </div>

        <!-- ======================= RECENT SCANS ======================= -->
        <div v-if="tab === 'activity'">
            <AppEmptyState
                v-if="!activity.length"
                icon="qr"
                title="Nothing scanned yet"
                description="Arrivals appear here the moment a badge is read, newest first."
                compact
            />

            <ul v-else class="max-h-[26rem] divide-y divide-csc-line overflow-y-auto">
                <li v-for="scan in activity" :key="scan.client_id" class="flex items-center gap-3 px-4 py-2.5">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-csc-ink">{{ scan.name }}</p>
                        <p class="truncate text-2xs text-csc-ink-subtle">
                            Day {{ scan.training_day }} · {{ scan.time_in }}
                            <span v-if="scan.by_hand"> · marked by hand</span>
                            <span v-if="scan.message"> · {{ scan.message }}</span>
                        </p>
                    </div>

                    <span
                        class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-2xs font-semibold"
                        :class="stateSkin(scan).tone"
                    >
                        <AppIcon :name="stateSkin(scan).icon" size="sm" />
                        {{ stateSkin(scan).label }}
                    </span>
                </li>
            </ul>
        </div>

        <!-- ========================== ROSTER ========================== -->
        <div v-else-if="tab === 'roster'">
            <!--
                The manual fallback. A creased badge, a cracked phone screen, a
                participant who left their code at the hotel — a station with no
                way to mark somebody by hand sends them to the back of a queue
                that has no answer for them.
            -->
            <div class="border-b border-csc-line px-4 py-3">
                <label class="relative block">
                    <span class="sr-only">Search the roster</span>
                    <AppIcon
                        name="search"
                        size="sm"
                        class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-csc-ink-placeholder"
                    />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search by name or agency"
                        class="w-full rounded-lg border border-csc-line bg-white py-2.5 pr-3 pl-9 text-sm text-csc-ink placeholder:text-csc-ink-placeholder focus:border-csc-blue focus:outline-none"
                    />
                </label>

                <p v-if="!today" class="mt-2 text-2xs text-csc-ink-subtle">
                    This training is not running today, so nobody can be marked by hand.
                </p>
            </div>

            <AppEmptyState
                v-if="!rows.length"
                icon="users"
                title="No one matches that search"
                description="Clear the box to see the whole roster again."
                compact
            />

            <ul v-else class="max-h-[26rem] divide-y divide-csc-line overflow-y-auto">
                <li v-for="row in rows" :key="row.registration_id" class="flex items-center gap-3 px-4 py-2.5">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-csc-ink">{{ row.name }}</p>
                        <p v-if="row.organization" class="truncate text-2xs text-csc-ink-subtle">
                            {{ row.organization }}
                        </p>
                    </div>

                    <span
                        v-if="row.marked"
                        class="inline-flex shrink-0 items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-2xs font-semibold text-success"
                    >
                        <AppIcon name="check" size="sm" />
                        {{ row.status_label }}<template v-if="row.time_in"> · {{ row.time_in }}</template>
                    </span>
                    <button
                        v-else-if="today"
                        type="button"
                        class="shrink-0 rounded-lg border border-csc-blue/30 px-3 py-1.5 text-2xs font-semibold text-csc-blue transition-colors hover:border-csc-blue hover:bg-csc-blue-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        @click="emit('mark', row)"
                    >
                        Mark present
                    </button>
                </li>
            </ul>
        </div>

        <!-- ========================== QR CODES ========================= -->
        <div v-else-if="canLookupQr">
            <!--
                Same list, same search, as the Roster tab — this answers a
                different question about the same people, not a different
                set of them.
            -->
            <div class="border-b border-csc-line px-4 py-3">
                <label class="relative block">
                    <span class="sr-only">Search for a participant's code</span>
                    <AppIcon
                        name="search"
                        size="sm"
                        class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-csc-ink-placeholder"
                    />
                    <input
                        v-model="qrSearch"
                        type="search"
                        placeholder="Search by name or agency"
                        class="w-full rounded-lg border border-csc-line bg-white py-2.5 pr-3 pl-9 text-sm text-csc-ink placeholder:text-csc-ink-placeholder focus:border-csc-blue focus:outline-none"
                    />
                </label>

                <p v-if="!online" class="mt-2 flex items-center gap-1.5 text-2xs font-medium text-warning">
                    <AppIcon name="warning" size="sm" />
                    A code cannot be looked up while this device is offline.
                </p>
                <p v-if="qrError" class="mt-2 text-2xs font-medium text-danger">{{ qrError }}</p>
            </div>

            <AppEmptyState
                v-if="!qrRows.length"
                icon="users"
                title="No one matches that search"
                description="Clear the box to see the whole roster again."
                compact
            />

            <ul v-else class="max-h-[26rem] divide-y divide-csc-line overflow-y-auto">
                <li v-for="row in qrRows" :key="row.registration_id" class="flex items-center gap-3 px-4 py-2.5">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-csc-ink">{{ row.name }}</p>
                        <p v-if="row.organization" class="truncate text-2xs text-csc-ink-subtle">
                            {{ row.organization }}
                        </p>
                    </div>

                    <button
                        type="button"
                        :disabled="!online || qrLoadingFor === row.registration_id"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-csc-blue/30 px-3 py-1.5 text-2xs font-semibold text-csc-blue transition-colors hover:border-csc-blue hover:bg-csc-blue-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue disabled:cursor-not-allowed disabled:opacity-50"
                        @click="openQr(row)"
                    >
                        <AppIcon :name="qrLoadingFor === row.registration_id ? 'clock' : 'qr'" size="sm" />
                        {{ qrLoadingFor === row.registration_id ? 'Loading…' : 'Show code' }}
                    </button>
                </li>
            </ul>
        </div>
    </section>

    <!--
        The code itself, full size — this is what the participant (or a
        camera scanning it back off the screen) actually needs to be legible,
        which a 40px row icon never could be.
    -->
    <AppModal v-if="canLookupQr" :open="showingQr !== null" title="Check-in code" size="sm" @close="closeQr">
        <div v-if="showingQr" class="flex flex-col items-center text-center">
            <img :src="showingQr.qr" alt="" class="size-56 rounded-lg border border-csc-line p-2" />
            <p class="mt-3 text-sm font-semibold text-csc-ink">{{ showingQr.name }}</p>
            <p v-if="showingQr.organization" class="text-xs text-csc-ink-subtle">{{ showingQr.organization }}</p>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <AppButton variant="ghost" @click="closeQr">Close</AppButton>
                <AppButton icon="print" @click="printQr">Print</AppButton>
            </div>
        </template>
    </AppModal>
</template>
