<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

const props = defineProps({
    summary: { type: Object, required: true },
    nextTraining: { type: Object, default: null },
    recentActivity: { type: Array, default: () => [] },
    profile: { type: Object, required: true },
});

const page = usePage();

const greeting = computed(() => {
    const hour = new Date().getHours();
    if (hour < 12) return 'Good morning';
    if (hour < 18) return 'Good afternoon';

    return 'Good evening';
});

const firstName = computed(() => props.profile.first_name ?? page.props.auth?.user?.email ?? '');

const statusLine = computed(() => {
    const { registered, certificates } = props.summary;

    if (!registered && !certificates) {
        return 'You have no upcoming trainings yet. Browse the catalogue to get started.';
    }

    const parts = [];
    if (registered) parts.push(`${registered} upcoming training${registered === 1 ? '' : 's'}`);
    if (certificates) parts.push(`${certificates} certificate${certificates === 1 ? '' : 's'} ready`);

    return `You have ${parts.join(' and ')}.`;
});

const quickActions = [
    {
        label: 'Browse Trainings',
        description: 'Find a program',
        href: '/trainings',
        icon: 'M4 6h16M4 12h16M4 18h10',
    },
    {
        label: 'My QR Code',
        description: 'For event check-in',
        href: '/my/qr',
        icon: 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 18h2v2h-2z',
    },
    {
        label: 'Certificates',
        description: 'View and download',
        href: '/my/certificates',
        icon: 'M12 4a5 5 0 1 1 0 10 5 5 0 0 1 0-10ZM9 14.5V21l3-1.8 3 1.8v-6.5',
    },
    {
        label: 'My Profile',
        description: 'Keep details current',
        href: '/profile',
        icon: 'M4.5 20a7.5 7.5 0 0 1 15 0M12 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7',
    },
];

const stats = computed(() => [
    { label: 'Registered', value: props.summary.registered, href: '/my/registrations' },
    { label: 'Completed', value: props.summary.completed, href: '/my/registrations' },
    { label: 'Certificates', value: props.summary.certificates, href: '/my/certificates' },
]);
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout title="Dashboard" current="dashboard">
        <div class="mx-auto max-w-5xl space-y-6">
            <!-- 1. Greeting + state -->
            <div>
                <h2 class="text-xl font-semibold tracking-tight text-csc-blue sm:text-2xl">
                    {{ greeting }}, {{ firstName }}
                </h2>
                <p class="mt-1.5 text-sm leading-relaxed text-csc-ink/70">{{ statusLine }}</p>
            </div>

            <!-- 2. Action required — rendered only when something is genuinely pending -->
            <AppAlert v-if="!profile.organization" tone="warning" title="Your profile is missing employment details">
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
                    </div>
                </dl>

                <template #footer>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <AppButton :href="nextTraining.url" size="sm" on-dark>View Details</AppButton>
                        <AppButton href="/my/qr" size="sm" variant="ghost" on-dark>Show QR Code</AppButton>
                    </div>
                </template>
            </AppCard>

            <AppCard v-else padded>
                <AppEmptyState
                    title="No upcoming trainings"
                    description="When you register for a training, it will appear here with its schedule, venue, and check-in code."
                    icon="M8 3v3M16 3v3M4 9h16M5 6h14a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z"
                >
                    <template #action>
                        <AppButton href="/trainings" size="md">Browse Trainings</AppButton>
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
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path :d="action.icon" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </span>
                        <span class="mt-3 block">
                            <span class="block text-sm font-semibold text-csc-ink">{{ action.label }}</span>
                            <span class="mt-0.5 block text-xs text-csc-ink/60">{{ action.description }}</span>
                        </span>
                    </Link>
                </div>
            </div>

            <!-- 5. Recent activity -->
            <AppCard title="Recent Activity" :padded="recentActivity.length === 0">
                <ol v-if="recentActivity.length" class="divide-y divide-csc-line">
                    <li v-for="entry in recentActivity" :key="entry.id" class="flex items-start gap-3 py-3.5">
                        <AppBadge :status="entry.status" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-csc-ink">{{ entry.title }}</p>
                            <p class="mt-0.5 text-xs text-csc-ink/60">{{ entry.happened_at }}</p>
                        </div>
                    </li>
                </ol>

                <AppEmptyState
                    v-else
                    title="Nothing here yet"
                    description="Your registrations, completions, and certificate releases will be listed here."
                    icon="M12 8v4l2.5 2.5M12 21a9 9 0 1 1 0-18 9 9 0 0 1 0 18Z"
                />
            </AppCard>

            <!-- 6. Summary counts — navigational, deliberately last -->
            <div class="grid grid-cols-3 gap-3">
                <Link
                    v-for="stat in stats"
                    :key="stat.label"
                    :href="stat.href"
                    class="rounded-xl border border-csc-line bg-white p-4 text-center transition-colors duration-150 hover:border-csc-blue/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue sm:p-5"
                >
                    <span class="block text-2xl font-bold text-csc-blue sm:text-3xl">{{ stat.value }}</span>
                    <span class="mt-1 block text-xs font-medium text-csc-ink/60 sm:text-sm">{{ stat.label }}</span>
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
