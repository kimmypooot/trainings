<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppStat from '@/Components/AppStat.vue';

const props = defineProps({
    summary: { type: Object, required: true },
    nextTraining: { type: Object, default: null },
    recentActivity: { type: Array, default: () => [] },
    profile: { type: Object, required: true },
});

const money = (value) =>
    Number(value).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const greeting = computed(() => {
    const hour = new Date().getHours();
    if (hour < 12) return 'Good morning';
    if (hour < 18) return 'Good afternoon';

    return 'Good evening';
});

const firstName = computed(() => props.profile.first_name ?? '');

// Greet by name when we have one; "Good morning," trailing a comma into nothing
// reads worse than the greeting on its own.
const greetingLine = computed(() =>
    firstName.value ? `${greeting.value}, ${firstName.value}` : greeting.value
);

const statusLine = computed(() => {
    const { pending, registered, certificates } = props.summary;

    // Pending counts as upcoming here because it counts as upcoming in the hero
    // card — the backend treats a pending registration as the next training, so
    // saying "nothing upcoming" above it would contradict the card.
    const upcoming = pending + registered;

    if (!upcoming && !certificates) {
        return 'You have no upcoming trainings yet. Browse the catalogue to get started.';
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
    registered: { icon: 'bookmark', node: 'bg-csc-blue-tint text-csc-blue' },
    approved: { icon: 'check', node: 'bg-info-soft text-info' },
    waitlisted: { icon: 'clock', node: 'bg-warning-soft text-warning' },
    rejected: { icon: 'close', node: 'bg-danger-soft text-danger' },
    withdrawn: { icon: 'close', node: 'bg-csc-line/60 text-csc-ink/60' },
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

// Labelled "Approved" rather than "Registered": the count excludes pending
// registrations, and the badge vocabulary elsewhere already says "Approved".
const stats = computed(() => [
    { label: 'Approved', value: props.summary.registered, href: '/my/registrations' },
    { label: 'Completed', value: props.summary.completed, href: '/my/registrations' },
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
                <p class="mt-1.5 text-sm leading-relaxed text-csc-ink/70">{{ statusLine }}</p>
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

            <!-- 3. Next training — the hero -->
            <AppCard v-if="nextTraining" tone="brand" :title="nextTraining.title" :subtitle="nextTraining.schedule">
                <template #action>
                    <AppBadge :status="nextTraining.status" />
                </template>

                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-white/60">Venue</dt>
                        <dd class="mt-0.5 font-medium text-white">{{ nextTraining.venue }}</dd>
                    </div>
                    <div>
                        <dt class="text-white/60">Date</dt>
                        <dd class="mt-0.5 font-medium text-white">{{ nextTraining.date }}</dd>
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
                        <dd class="mt-0.5 font-medium text-white">PHP {{ money(nextTraining.payment_amount) }}</dd>
                    </div>
                </dl>

                <template #footer>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <AppButton :href="nextTraining.url" size="sm" on-dark icon="arrow-right">View Details</AppButton>
                        <AppButton href="/my/qr" size="sm" variant="ghost" on-dark>Show QR Code</AppButton>
                    </div>
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

            <!-- 4. Quick actions -->
            <div>
                <h3 class="mb-3 text-sm font-semibold tracking-wide text-csc-ink/60 uppercase">Quick Actions</h3>
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Link
                        v-for="action in quickActions"
                        :key="action.href"
                        :href="action.href"
                        class="flex min-h-24 flex-col justify-between rounded-xl border border-csc-line bg-white p-4 transition-colors duration-150 hover:border-csc-blue/40 hover:bg-csc-blue-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    >
                        <span class="inline-flex size-9 items-center justify-center rounded-lg bg-csc-blue-tint text-csc-blue">
                            <AppIcon :name="action.icon" />
                        </span>
                        <span class="mt-3 block">
                            <span class="block text-sm font-semibold text-csc-ink">{{ action.label }}</span>
                            <span class="mt-0.5 block text-xs text-csc-ink/60">{{ action.description }}</span>
                        </span>
                    </Link>
                </div>
            </div>

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
                        <h3 class="mb-4 text-2xs font-semibold tracking-wider text-csc-ink/50 uppercase">
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
                                        :class="activityTones[entry.kind].node"
                                    >
                                        <AppIcon :name="activityTones[entry.kind].icon" />
                                    </span>

                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block truncate text-sm leading-5 font-semibold text-csc-ink transition-colors group-hover:text-csc-blue"
                                        >
                                            {{ entry.title }}
                                        </span>
                                        <span class="mt-0.5 block truncate text-sm leading-5 text-csc-ink/70">
                                            {{ entry.subject }}
                                        </span>
                                        <time
                                            v-if="entry.at"
                                            :datetime="entry.at"
                                            :title="entry.at_exact"
                                            class="mt-2 block text-xs leading-4 text-csc-ink/50"
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
            <div class="grid grid-cols-3 gap-3">
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
