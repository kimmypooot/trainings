<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppStat from '@/Components/AppStat.vue';
import { formatDateRange } from '@/dateRange';

const props = defineProps({
    summary: { type: Object, required: true },
    nextTraining: { type: Object, default: null },
    recentActivity: { type: Array, default: () => [] },
    /** Queues the participant is holding up; empty most of the time. */
    attention: { type: Array, default: () => [] },
    profile: { type: Object, required: true },
});

// Built once rather than per call: a formatter is not free to construct, and
// this one runs on every fee the page prints.
const peso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
});

const money = (value) => peso.format(Number(value));

/*
 * The third line of an attention row: the fee, the training's dates, and
 * whatever else that queue has to add.
 *
 * The dates are the half that identifies the training. A title on its own does
 * not — this office runs the same programme several times a year, so "Records
 * Management Seminar" names four things and the schedule is what picks one
 * out. Paired through dateRange.ts rather than by hand, so a multi-day run
 * reads here exactly as it does in the catalogue and on the roster.
 *
 * The amount goes through the same peso formatter as the hero card's fee; two
 * formatters would eventually print one amount two ways on a single screen.
 * Any part may be absent, and a row with none prints no line rather than an
 * empty one.
 */
const itemMeta = (item) =>
    [
        item.amount === null || item.amount === undefined ? null : money(item.amount),
        item.starts_at ? formatDateRange(item.starts_at, item.ends_at) : null,
        item.detail,
    ]
        .filter(Boolean)
        .join(' · ');

// Fixed at mount, deliberately: a dashboard left open overnight will still say
// "Good evening" in the morning, which is a smaller cost than a timer running
// for the life of every session to correct a greeting nobody re-reads.
const greeting = computed(() => {
    const hour = new Date().getHours();
    if (hour < 12) return 'Good morning';
    if (hour < 18) return 'Good afternoon';

    return 'Good evening';
});

/*
 * The countdown to the next training, kept honest.
 *
 * The greeting above is allowed to go stale — nobody re-reads it, and a timer
 * running for the life of the session to fix a word is a bad trade. "Starts in
 * 2 days" is the opposite case: it is the one line here a participant plans
 * around, and a dashboard left open on a second monitor is exactly how it ends
 * up reading two days late.
 *
 * So it ticks — but only once a minute, only while the tab is visible, and only
 * when there is a next training to count down to. Coming back to a hidden tab
 * refreshes it immediately, which is when a stale figure would have been read.
 */
const now = ref(Date.now());
let ticker = null;

const syncClock = () => {
    if (!document.hidden) now.value = Date.now();
};

onMounted(() => {
    if (!props.nextTraining) return;

    ticker = window.setInterval(syncClock, 60_000);
    document.addEventListener('visibilitychange', syncClock);
});

onBeforeUnmount(() => {
    if (ticker) window.clearInterval(ticker);
    document.removeEventListener('visibilitychange', syncClock);
});

const relative = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

// Largest unit first: the first one the gap clears is the one it reads in.
const units = [
    ['year', 31536000000],
    ['month', 2592000000],
    ['week', 604800000],
    ['day', 86400000],
    ['hour', 3600000],
    ['minute', 60000],
];

const schedule = computed(() => {
    if (!props.nextTraining) return null;

    const gap = new Date(props.nextTraining.starts_at).getTime() - now.value;

    if (Number.isNaN(gap)) return null;

    for (const [unit, size] of units) {
        if (Math.abs(gap) >= size) return `Starts ${relative.format(Math.round(gap / size), unit)}`;
    }

    return 'Starting now';
});

const firstName = computed(() => props.profile.first_name ?? '');

// Greet by name when we have one; "Good morning," trailing a comma into nothing
// reads worse than the greeting on its own.
const greetingLine = computed(() =>
    firstName.value ? `${greeting.value}, ${firstName.value}` : greeting.value
);

const statusLine = computed(() => {
    const { pending, registered, completed, certificates } = props.summary;

    // Pending counts as upcoming here because it counts as upcoming in the hero
    // card — the backend treats a pending registration as the next training, so
    // saying "nothing upcoming" above it would contradict the card.
    const upcoming = pending + registered;

    if (!upcoming && !certificates) {
        /*
         * Nothing ahead, nothing to collect — but that is two different people.
         * The "get started" line was going to the participant with eleven
         * completed trainings and no certificate released yet, which reads as
         * the app having forgotten them. Only somebody with no history at all
         * is actually starting.
         */
        return completed
            ? `You have nothing upcoming. ${completed} completed training${completed === 1 ? '' : 's'} so far — browse the catalogue for what is next.`
            : 'You have no upcoming trainings yet. Browse the catalogue to get started.';
    }

    const parts = [];
    if (upcoming) parts.push(`${upcoming} upcoming training${upcoming === 1 ? '' : 's'}`);
    if (certificates) parts.push(`${certificates} certificate${certificates === 1 ? '' : 's'} ready`);

    const sentence = `You have ${parts.join(' and ')}.`;

    return pending ? `${sentence} ${pending} ${pending === 1 ? 'is' : 'are'} awaiting approval.` : sentence;
});

const quickActions = [
    {
        label: 'Browse Trainings',
        description: 'Find a program',
        href: '/trainings',
        icon: 'list',
    },
    {
        label: 'My QR Code',
        description: 'For event check-in',
        href: '/my/qr',
        icon: 'qr',
    },
    {
        label: 'Certificates',
        description: 'View and download',
        href: '/my/certificates',
        icon: 'certificate',
    },
    {
        label: 'My Profile',
        description: 'Keep details current',
        href: '/profile',
        icon: 'user',
    },
];

/*
 * How each kind of event reads on the feed.
 *
 * The tones borrow the semantic palette the badges already use, so a green tile
 * means the same thing here as it does anywhere else in the app. Every tile
 * also carries a distinct glyph — the feed has to survive greyscale print and
 * colour blindness on its own, exactly as AppBadge does.
 */
const activityTones = {
    // The fallback matters more than it looks: the feed's kinds are minted
    // server-side, and a new one arriving here without a matching entry used to
    // take the whole dashboard down with a TypeError, nowhere near the change
    // that caused it. An unstyled-but-rendered tile is the better failure.
    default: { icon: 'clock', node: 'bg-csc-line/60 text-csc-ink-subtle' },
    registered: { icon: 'bookmark', node: 'bg-csc-blue-tint text-csc-blue' },
    approved: { icon: 'check', node: 'bg-info-soft text-info' },
    waitlisted: { icon: 'clock', node: 'bg-warning-soft text-warning' },
    rejected: { icon: 'close', node: 'bg-danger-soft text-danger' },
    withdrawn: { icon: 'close', node: 'bg-csc-line/60 text-csc-ink-subtle' },
    completed: { icon: 'check', node: 'bg-success-soft text-success' },
    certificate: { icon: 'certificate', node: 'bg-success-soft text-success' },
};

// Consecutive entries sharing a day band sit under one heading, so the eye gets
// "Today" once rather than the same date stamped on every row.
const activityGroups = computed(() => {
    const groups = [];

    for (const entry of props.recentActivity) {
        const last = groups.at(-1);

        if (last?.label === entry.group) {
            last.entries.push(entry);
            continue;
        }

        groups.push({ label: entry.group, entries: [entry] });
    }

    return groups;
});

const tone = (kind) => activityTones[kind] ?? activityTones.default;

/*
 * The counts, as handles on the lists behind them.
 *
 * Labelled "Approved" rather than "Registered": the count excludes pending
 * registrations, and the badge vocabulary elsewhere already says "Approved".
 *
 * Each carries the status it counts through to the list, so a number lands on
 * the rows it was counting rather than on an undifferentiated page the reader
 * then has to scan for them.
 *
 * All four, always. Pending used to take Certificates' slot whenever there was
 * one to chase, which meant the row changed shape between visits and the
 * participant most likely to hold certificates — an active one, mid-cycle — was
 * the one who lost the link to them. A tile reading zero still says something
 * true, and a row that stays put can be aimed at without reading it first.
 */
const stats = computed(() => [
    { label: 'Pending', value: props.summary.pending, href: '/my/registrations?status=pending' },
    { label: 'Approved', value: props.summary.registered, href: '/my/registrations?status=approved' },
    { label: 'Completed', value: props.summary.completed, href: '/my/registrations?status=completed' },
    { label: 'Certificates', value: props.summary.certificates, href: '/my/certificates' },
]);
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout title="Dashboard" current="dashboard">
        <div class="mx-auto max-w-7xl space-y-5">
            <!-- 1. Greeting + state -->
            <div>
                <h2 class="text-xl font-semibold tracking-tight text-csc-blue sm:text-2xl">
                    {{ greetingLine }}
                </h2>
                <p class="mt-1.5 text-sm leading-relaxed text-csc-ink-muted">{{ statusLine }}</p>
            </div>

            <!-- 2. Action required — rendered only when something is genuinely pending -->
            <AppAlert
                v-if="!profile.organization || !profile.position"
                tone="warning"
                title="Your profile is missing employment details"
            >
                Complete your profile so your certificates carry the correct agency and position.
                <template #action>
                    <AppButton href="/profile" size="sm" variant="ghost">Review</AppButton>
                </template>
            </AppAlert>

            <!--
                2b. What the participant owes.

                The feed below says what happened; this says what has not. It
                renders only when a queue actually has something in it, so the
                common case is that this block is absent entirely — which is
                what keeps it worth reading when it is not.
            -->
            <AppCard v-if="attention.length" title="Needs your attention">
                <ul class="space-y-2">
                    <li v-for="item in attention" :key="item.key">
                        <Link
                            :href="item.href"
                            class="group flex items-start gap-3 rounded-lg border border-warning/30 bg-warning-soft px-4 py-3 transition-colors duration-150 hover:border-warning/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        >
                            <span
                                class="mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-white text-warning"
                            >
                                <AppIcon :name="item.icon" size="sm" />
                            </span>

                            <!--
                                Three lines, in the order the question is asked:
                                what is needed, what it is about, and the figures
                                that decide whether it can be dealt with now.
                                The subject is titled and truncated — a training
                                name is exactly the sort of string that runs long,
                                and wrapping it to three lines would bury the next
                                row.
                            -->
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-csc-ink">{{ item.label }}</span>
                                <span
                                    v-if="item.subject"
                                    :title="item.subject"
                                    class="mt-0.5 block truncate text-sm text-csc-ink-muted"
                                >
                                    {{ item.subject }}
                                </span>
                                <span v-if="itemMeta(item)" class="mt-0.5 block text-xs text-csc-ink-subtle">
                                    {{ itemMeta(item) }}
                                </span>
                            </span>

                            <AppIcon
                                name="chevron-right"
                                size="sm"
                                class="mt-1.5 shrink-0 text-csc-ink-subtle transition-colors group-hover:text-csc-blue"
                            />
                        </Link>
                    </li>
                </ul>
            </AppCard>

            <!-- 3. Next training — the hero -->
            <AppCard v-if="nextTraining" tone="brand" :title="nextTraining.title" :subtitle="schedule">
                <template #action>
                    <AppBadge :status="nextTraining.status" />
                </template>

                <!--
                    Venue, date, mode and fee on one line once there is room for
                    one. The card sits in a max-w-7xl column, so four abreast is
                    comfortable from lg up; below that they would be four narrow
                    slots with a wrapping venue in the first, which is harder to
                    read than the pairs it falls back to.

                    The columns are weighted rather than equal: the other three
                    are a date, a two-word mode and a peso figure, all of known
                    and similar length, while a venue is free text that runs to
                    "CSC Regional Office VIII, Palo, Leyte" and longer. Giving
                    it double the share spends the slack where it is needed
                    instead of leaving it at the end of three short columns.
                -->
                <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr]">
                    <div>
                        <dt class="text-white/60">Venue</dt>
                        <dd class="mt-0.5 font-medium text-white">{{ nextTraining.venue }}</dd>
                    </div>
                    <div>
                        <dt class="text-white/60">Date</dt>
                        <!--
                            The countdown in the subtitle is prose and cannot
                            carry a machine-readable value, so the instant it
                            was computed from is published here instead.
                        -->
                        <dd class="mt-0.5 font-medium text-white">
                            <time :datetime="nextTraining.starts_at">{{ nextTraining.date }}</time>
                        </dd>
                        <dd v-if="nextTraining.ends_at" class="mt-0.5 text-xs text-white/60">
                            Ends {{ nextTraining.ends_at }}
                        </dd>
                    </div>
                    <div v-if="nextTraining.mode_label">
                        <dt class="text-white/60">Mode</dt>
                        <dd class="mt-0.5 font-medium text-white">{{ nextTraining.mode_label }}</dd>
                    </div>
                    <div v-if="nextTraining.payment_amount !== null">
                        <dt class="text-white/60">Training fee</dt>
                        <dd class="mt-0.5 font-medium text-white">{{ money(nextTraining.payment_amount) }}</dd>
                        <!--
                            A figure on its own reads as either a receipt or a
                            bill depending on who is looking, so it says which.
                            Not colour-alone: both states are words first.
                        -->
                        <dd class="mt-0.5 text-xs" :class="nextTraining.fee_settled ? 'text-white/60' : 'text-white'">
                            {{ nextTraining.fee_settled ? 'Settled' : 'Not yet settled' }}
                        </dd>
                    </div>
                </dl>

                <template #footer>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <AppButton :href="nextTraining.url" size="sm" on-dark icon="arrow-right">View Details</AppButton>
                        <!--
                            Withheld on a pending registration: a QR the scanner
                            will refuse is worse than no button, because the
                            participant only finds out at the door.
                        -->
                        <AppButton v-if="nextTraining.can_check_in" href="/my/qr" size="sm" variant="ghost" on-dark>
                            Show QR Code
                        </AppButton>
                        <!--
                            A plain anchor, not a Link: this is a file download,
                            and Inertia would try to render the .ics as a page.
                        -->
                        <AppButton :href="nextTraining.calendar_url" external size="sm" variant="ghost" on-dark icon="calendar">
                            Add to Calendar
                        </AppButton>
                    </div>

                    <!--
                        Said rather than merely omitted. Quick Actions below links
                        to the same code unconditionally — it is the participant's
                        standing code, not this training's — so silence here only
                        routes them around the guard and leaves them to find out
                        at the door. The sentence is the part that was missing.
                    -->
                    <p v-if="!nextTraining.can_check_in" class="mt-3 text-xs text-white/70">
                        Your check-in code opens this training once the registration is approved.
                    </p>
                </template>
            </AppCard>

            <AppCard v-else :padded="false">
                <AppEmptyState
                    title="No upcoming trainings"
                    description="When you register for a training, it will appear here with its schedule, venue, and check-in code."
                    icon="calendar"
                >
                    <template #action>
                        <AppButton href="/trainings" size="md" icon="calendar">Browse Trainings</AppButton>
                    </template>
                </AppEmptyState>
            </AppCard>

            <!--
                4. Quick actions

                A section with an h2, like the cards above and below it: these are
                peers in the page, and the heading was an h3 sitting between two
                h2s — a level the outline skips over. The tiles are a list for the
                same reason the attention queue and the activity feed are: a
                screen reader gets "4 items" before it starts reading them.
            -->
            <section aria-labelledby="quick-actions-heading">
                <h2
                    id="quick-actions-heading"
                    class="mb-3 text-sm font-semibold tracking-wide text-csc-ink-subtle uppercase"
                >
                    Quick Actions
                </h2>
                <ul class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <li v-for="action in quickActions" :key="action.href">
                        <Link
                            :href="action.href"
                            class="flex h-full min-h-24 flex-col justify-between rounded-xl border border-csc-line bg-white p-4 transition-colors duration-150 hover:border-csc-blue/40 hover:bg-csc-blue-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        >
                            <span class="inline-flex size-9 items-center justify-center rounded-lg bg-csc-blue-tint text-csc-blue">
                                <AppIcon :name="action.icon" />
                            </span>
                            <span class="mt-3 block">
                                <span class="block text-sm font-semibold text-csc-ink">{{ action.label }}</span>
                                <span class="mt-0.5 block text-xs text-csc-ink-subtle">{{ action.description }}</span>
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>

            <!-- 5. Recent activity -->
            <AppCard title="Recent Activity" :padded="recentActivity.length > 0">
                <template v-if="recentActivity.length" #action>
                    <AppButton href="/my/registrations" size="sm" variant="ghost">View All</AppButton>
                </template>

                <!--
                    Each event is its own tile, so a glance over the dashboard
                    reads as a set of things that happened, not a sequence. The
                    tiles sit in a two-up grid and borrow the quick-action card
                    treatment — soft border, tinted hover, and a title + time
                    on the right of the semantic icon tile.
                -->
                <div v-if="recentActivity.length" class="space-y-8">
                    <section v-for="group in activityGroups" :key="group.label">
                        <h3 class="mb-4 text-2xs font-semibold tracking-wider text-csc-ink-subtle uppercase">
                            {{ group.label }}
                        </h3>

                        <ul class="grid gap-4 sm:grid-cols-2">
                            <li v-for="entry in group.entries" :key="entry.id">
                                <Link
                                    :href="entry.url"
                                    class="group flex h-full items-start gap-4 rounded-xl border border-csc-line bg-white p-4 transition-colors duration-150 hover:border-csc-blue/40 hover:bg-csc-blue-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                >
                                    <span
                                        class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg"
                                        :class="tone(entry.kind).node"
                                    >
                                        <AppIcon :name="tone(entry.kind).icon" />
                                    </span>

                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block truncate text-sm leading-5 font-semibold text-csc-ink transition-colors group-hover:text-csc-blue"
                                        >
                                            {{ entry.title }}
                                        </span>
                                        <!--
                                            Titled, because a training name is
                                            exactly the sort of long string
                                            truncate eats without recourse.
                                        -->
                                        <span
                                            :title="entry.subject"
                                            class="mt-0.5 block truncate text-sm leading-5 text-csc-ink-muted"
                                        >
                                            {{ entry.subject }}
                                        </span>
                                        <time
                                            v-if="entry.at"
                                            :datetime="entry.at"
                                            :title="entry.at_exact"
                                            class="mt-2 block text-xs leading-4 text-csc-ink-subtle"
                                        >
                                            {{ entry.at_label }}
                                        </time>
                                    </span>
                                </Link>
                            </li>
                        </ul>
                    </section>
                </div>

                <AppEmptyState
                    v-else
                    title="Nothing here yet"
                    description="Registrations, approvals, completions, and certificates will appear here as they happen."
                    icon="clock"
                />
            </AppCard>

            <!-- 6. Summary counts — navigational, deliberately last -->
            <!--
                Two abreast on a phone, four once there is a row for them —
                the same rhythm as Quick Actions directly above, which is four
                tiles of the same size making the same promise. Below ~26rem
                even two are ~90px columns carrying a text-3xl figure and a
                label, and the label is what breaks first, so they stack.
            -->
            <div class="grid grid-cols-1 gap-3 min-[26rem]:grid-cols-2 lg:grid-cols-4">
                <AppStat
                    v-for="stat in stats"
                    :key="stat.label"
                    :label="stat.label"
                    :value="stat.value"
                    :href="stat.href"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
