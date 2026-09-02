<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppModal from '@/Components/AppModal.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

/**
 * The scannable evaluation posters for one training, day by day.
 *
 * Lives on the roster for the reason the scanning-stations card does: cutting
 * the codes is part of preparing a session, and whoever does it is already
 * looking at the run they are preparing.
 *
 * The one design decision worth defending is that this lists *every* day of the
 * run, including the days that have no code and never will. An expert who is
 * back tomorrow is rated at the end of their stretch, so a four-day course
 * delivered by one person collects a single evaluation on day four — and a panel
 * that quietly showed one row for a four-day course would read as three missing
 * codes. Showing all four, with the three saying where their feedback actually
 * gets collected, turns a suspected bug into an explanation of the rule.
 */
const props = defineProps({
    training: { type: Object, required: true },
    /** Server-built board: days, codes, and per-day submission counts. */
    codes: { type: Object, required: true },
});

const working = ref(null);

const regenerate = (day) => {
    working.value = day.code.id;
    router.post(
        `/admin/evaluation-codes/${day.code.id}/regenerate`,
        {},
        { preserveScroll: true, onFinish: () => (working.value = null) }
    );
};

const revoke = (day) => {
    working.value = day.code.id;
    router.delete(`/admin/evaluation-codes/${day.code.id}`, {
        preserveScroll: true,
        onFinish: () => (working.value = null),
    });
};

/*
 * Copying a code's link.
 *
 * The URL matters on its own, not only as a picture of itself: it goes into the
 * chat thread for the run, into a slide at the end of the session, and into the
 * message to the participant who says the poster would not scan. Tracked by day
 * rather than as one flag so that copying day 2 does not light up day 1 and
 * leave the reader unsure which link is actually on the clipboard.
 */
const copiedDay = ref(null);
let copiedTimer = null;

async function copyUrl(day) {
    try {
        await navigator.clipboard.writeText(day.code.url);
    } catch {
        // Clipboard access needs a secure context, and this app is routinely
        // opened over a plain-http LAN address at a venue. Say nothing rather
        // than claim a copy that did not happen — the URL is on the printable
        // sheet either way.
        return;
    }

    copiedDay.value = day.day;

    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => (copiedDay.value = null), 2000);
}

/*
 * Which days to cut codes for.
 *
 * Only days that collect a form are offered — a poster for a carried-over day
 * would invite the room to a form that turns them away — so the picker is built
 * from the board rather than from the length of the run.
 */
const picking = ref(false);
const chosen = ref([]);

const pickableDays = computed(() => props.codes.days.filter((day) => day.collects));

/*
 * Why the dialog lists fewer days than the run has.
 *
 * Built as a sentence rather than assembled in the template because it has to
 * agree with itself: "1 of this training's 2 days collect no form" is what
 * interpolating a count into fixed plural wording produces, and a dialog that
 * cannot conjugate its own explanation is a poor advertisement for trusting it.
 */
const carriedOverNote = computed(() => {
    const hidden = props.codes.days.length - pickableDays.value.length;

    if (hidden < 1) return null;

    const tail = "the expert's session carries over and is rated at the end, so it is not listed here.";

    // Whole sentences per branch rather than a stitched subject: "no form of
    // their own" needs "its own" in the singular, and threading two agreements
    // through one template string is how the second one gets missed.
    return hidden === 1
        ? `One day of this training collects no form of its own — ${tail}`
        : `${hidden} of this training's ${props.codes.days.length} days collect no form of their own — ${tail}`;
});

const openPicker = () => {
    /*
     * Pre-ticked to exactly the days still missing a code, which is what the
     * button did before it asked. Days that already have one start unticked:
     * re-issuing them is harmless — issuing is idempotent — but a box ticked by
     * default invites the reader to believe pressing Generate will refresh it,
     * and it will not. Replacing a live code is Regenerate, deliberately.
     */
    chosen.value = pickableDays.value.filter((day) => !day.code).map((day) => day.day);
    picking.value = true;
};

const toggle = (day) => {
    const at = chosen.value.indexOf(day.day);

    if (at === -1) chosen.value.push(day.day);
    else chosen.value.splice(at, 1);
};

const allChosen = computed(
    () => pickableDays.value.length > 0 && chosen.value.length === pickableDays.value.length
);

const toggleAll = () => {
    chosen.value = allChosen.value ? [] : pickableDays.value.map((day) => day.day);
};

const generate = () => {
    working.value = 'generate';
    router.post(
        props.codes.generateUrl,
        { days: chosen.value },
        {
            preserveScroll: true,
            onSuccess: () => (picking.value = false),
            onFinish: () => (working.value = null),
        }
    );
};
</script>

<template>
    <!--
        print:hidden, like the stations card. A management table of scan counts
        is not what anybody wants coming out of the printer — the sheet at
        printUrl is, and it has its own layout because a sign read from across a
        function room shares nothing with this.
    -->
    <AppCard
        title="Evaluation codes"
        subtitle="One per evaluation day. Participants scan it and land on that day's form, signed in as themselves."
        collapsible
        remember-as="roster.evaluation-codes"
        class="print:hidden"
    >
        <template v-if="codes.collects" #action>
            <div class="flex gap-2">
                <AppButton
                    size="sm"
                    variant="ghost"
                    icon="download"
                    :href="codes.printUrl"
                    external
                >
                    Print sheet
                </AppButton>
                <AppButton size="sm" icon="qr" @click="openPicker">Generate</AppButton>
            </div>
        </template>

        <!--
            No panel means nothing to ask about, which is a training-form problem
            rather than something to solve here — so the empty state points at
            the cause instead of offering a button that would only fail.
        -->
        <AppEmptyState
            v-if="!codes.collects"
            compact
            icon="clipboard"
            title="No evaluation days yet"
            description="Assign subject matter experts to this training and the days they deliver, and a code can be cut for each day that collects feedback."
        />

        <ul v-else class="divide-y divide-csc-line">
            <li
                v-for="day in codes.days"
                :key="day.day"
                class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:gap-4"
            >
                <!--
                    The code itself, small. Fetched as an image rather than
                    inlined as a data URI: a roster with four of those in its
                    payload pays for them on every load, and this thumbnail is
                    a confirmation that a code exists, not the thing anybody
                    scans. `only light` because an inverted QR does not scan,
                    and the OS — not this page — decides when to invert.
                -->
                <div
                    v-if="day.code"
                    class="w-fit shrink-0 rounded-lg border border-csc-line bg-white p-1.5"
                    style="color-scheme: only light; forced-color-adjust: none"
                >
                    <img
                        :src="day.code.image_url"
                        :alt="`QR code for day ${day.day}`"
                        class="size-16"
                        :class="day.code.active ? '' : 'opacity-40 grayscale'"
                    />
                </div>
                <div
                    v-else
                    class="flex size-[76px] shrink-0 items-center justify-center rounded-lg border border-dashed border-csc-line text-csc-ink-subtle"
                >
                    <AppIcon name="qr" />
                </div>

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-csc-ink">
                        Day {{ day.day }}
                        <span class="font-normal text-csc-ink-subtle">· {{ day.date }}</span>
                    </p>

                    <!-- A day that collects nothing says where its feedback went. -->
                    <p v-if="!day.collects" class="mt-0.5 text-sm text-csc-ink-muted">
                        <template v-if="day.rated_on">
                            Session continues — rated at the end of day {{ day.rated_on }}.
                        </template>
                        <template v-else>No expert assigned to this day.</template>
                    </p>

                    <template v-else>
                        <p v-if="day.experts.length" class="mt-0.5 truncate text-sm text-csc-ink-muted">
                            {{ day.experts.join(', ') }}
                        </p>
                        <p class="mt-1 text-xs text-csc-ink-subtle">
                            {{ day.submitted }} of {{ day.expected }} answered<template v-if="day.code">
                                ·
                                <template v-if="day.code.scan_count">
                                    {{ day.code.scan_count }} scan{{ day.code.scan_count === 1 ? '' : 's' }},
                                    last {{ day.code.last_scanned_at }}
                                </template>
                                <template v-else>not scanned yet</template>
                            </template>
                        </p>
                        <p v-if="day.code && !day.code.active" class="mt-1 text-xs font-medium text-warning">
                            Withdrawn — this code no longer opens the form.
                        </p>
                    </template>
                </div>

                <div v-if="day.collects && day.code" class="flex shrink-0 flex-wrap gap-2">
                    <!--
                        Acknowledged in place rather than by a toast: the reader
                        is looking at the row they clicked, and a message at the
                        edge of the screen would not tell them *which* day's link
                        they now hold.
                    -->
                    <AppButton
                        size="sm"
                        variant="ghost"
                        :icon="copiedDay === day.day ? 'check' : 'link'"
                        @click="copyUrl(day)"
                    >
                        {{ copiedDay === day.day ? 'Copied' : 'Copy link' }}
                    </AppButton>
                    <AppButton size="sm" variant="ghost" :href="day.code.image_url" external icon="download">
                        PNG
                    </AppButton>
                    <AppButton
                        size="sm"
                        variant="ghost"
                        :loading="working === day.code.id"
                        @click="regenerate(day)"
                    >
                        Regenerate
                    </AppButton>
                    <AppButton
                        v-if="day.code.active"
                        size="sm"
                        variant="ghost"
                        :loading="working === day.code.id"
                        @click="revoke(day)"
                    >
                        Withdraw
                    </AppButton>
                </div>
            </li>
        </ul>

        <!--
            Said once, at the bottom, rather than on every Regenerate button:
            the consequence is the same for all of them and repeating it per row
            would turn a page of controls into a page of warnings.
        -->
        <p v-if="codes.collects" class="mt-4 text-xs leading-relaxed text-csc-ink-subtle">
            Regenerating a code stops every printed copy of it from working. Withdrawing one
            closes that day's scanning entrance — participants can still reach the form from
            their own evaluations list.
        </p>
    </AppCard>

    <AppModal
        :open="picking"
        title="Generate evaluation codes"
        subtitle="Pick the days to cut a code for. Days that already have one keep it."
        @close="picking = false"
    >
        <div class="space-y-3">
            <label
                class="flex cursor-pointer items-center gap-2 text-sm font-medium text-csc-ink"
            >
                <input
                    type="checkbox"
                    class="size-4 rounded border-csc-line text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    :checked="allChosen"
                    @change="toggleAll"
                />
                Select every evaluation day
            </label>

            <ul class="divide-y divide-csc-line rounded-lg border border-csc-line">
                <li v-for="day in pickableDays" :key="day.day">
                    <label class="flex cursor-pointer items-start gap-3 p-3">
                        <input
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0 rounded border-csc-line text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            :checked="chosen.includes(day.day)"
                            @change="toggle(day)"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-csc-ink">
                                Day {{ day.day }}
                                <span class="font-normal text-csc-ink-subtle">· {{ day.date }}</span>
                            </span>
                            <span v-if="day.experts.length" class="mt-0.5 block truncate text-xs text-csc-ink-muted">
                                {{ day.experts.join(', ') }}
                            </span>
                            <!--
                                Stated per row rather than as a blanket note: the
                                reader is deciding this day, and "already has one"
                                is the fact that decides it.
                            -->
                            <span v-if="day.code" class="mt-0.5 block text-xs text-csc-ink-subtle">
                                Already has a code — use Regenerate to replace it.
                            </span>
                        </span>
                    </label>
                </li>
            </ul>

            <!--
                Named, not omitted. A four-day run offering two days here looks
                like a bug unless the dialog says where the other two went — the
                same reason the panel behind it lists them.
            -->
            <p v-if="carriedOverNote" class="text-xs leading-relaxed text-csc-ink-subtle">
                {{ carriedOverNote }}
            </p>
        </div>

        <template #footer>
            <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                <AppButton variant="ghost" size="sm" @click="picking = false">Cancel</AppButton>
                <AppButton
                    size="sm"
                    icon="qr"
                    :disabled="!chosen.length"
                    :loading="working === 'generate'"
                    @click="generate"
                >
                    Generate {{ chosen.length }} code{{ chosen.length === 1 ? '' : 's' }}
                </AppButton>
            </div>
        </template>
    </AppModal>
</template>
