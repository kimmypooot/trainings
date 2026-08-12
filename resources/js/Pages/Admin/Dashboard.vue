<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

const props = defineProps({
    stats: { type: Object, required: true },
    scopedTo: { type: String, default: null },
    upcoming: { type: Array, required: true },
    awaitingCompletion: { type: Array, required: true },
});

// A count is the natural handle for the list behind it, so each tile links
// where one exists. Registrations has no index page of its own yet, so that
// tile stays a plain figure rather than a link that goes nowhere useful.
const tiles = computed(() => [
    { label: 'Published', value: props.stats.published, href: '/admin/trainings?status=published' },
    { label: 'Drafts', value: props.stats.drafts, href: '/admin/trainings?status=draft' },
    { label: 'Participants', value: props.stats.participants, href: '/admin/participants' },
    { label: 'Registrations', value: props.stats.registrations, href: null },
]);
</script>

<template>
    <Head title="Admin Dashboard" />

    <AuthenticatedLayout title="Dashboard" current="admin-dashboard">
        <div class="mx-auto max-w-5xl space-y-6">
            <AppAlert v-if="scopedTo" tone="info">
                Showing participants for <strong>{{ scopedTo }}</strong> only.
            </AppAlert>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <component
                    :is="tile.href ? Link : 'div'"
                    v-for="tile in tiles"
                    :key="tile.label"
                    :href="tile.href ?? undefined"
                    class="rounded-xl border border-csc-line bg-white p-4 sm:p-5"
                    :class="
                        tile.href
                            ? 'block transition-colors duration-150 hover:border-csc-blue/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue'
                            : ''
                    "
                >
                    <span class="block text-2xl font-bold text-csc-blue sm:text-3xl">{{ tile.value }}</span>
                    <span class="mt-1 block text-xs font-medium text-csc-ink/60 sm:text-sm">{{ tile.label }}</span>
                </component>
            </div>

            <AppCard title="Needs Attention" subtitle="Finished trainings with participants not yet marked complete">
                <ul v-if="awaitingCompletion.length" class="divide-y divide-csc-line">
                    <li
                        v-for="item in awaitingCompletion"
                        :key="item.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3"
                    >
                        <div>
                            <p class="text-sm font-medium text-csc-ink">{{ item.title }}</p>
                            <p class="mt-0.5 text-xs text-csc-ink/60">Ended {{ item.ended }}</p>
                        </div>
                        <AppButton :href="item.roster_url" size="sm" variant="ghost">Open Roster</AppButton>
                    </li>
                </ul>
                <AppEmptyState
                    v-else
                    title="Nothing pending"
                    description="Every finished training has had its participants marked complete."
                    icon="M5 13l4 4L19 7"
                />
            </AppCard>

            <AppCard title="Upcoming Trainings">
                <template #action>
                    <AppButton href="/admin/trainings" size="sm" variant="ghost">Manage All</AppButton>
                </template>

                <ul v-if="upcoming.length" class="divide-y divide-csc-line">
                    <li
                        v-for="training in upcoming"
                        :key="training.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3.5"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-csc-ink">{{ training.title }}</p>
                            <p class="mt-0.5 text-xs text-csc-ink/60">
                                {{ training.starts_at }} · {{ training.venue }} · {{ training.when }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <!-- Colour alone would not carry this, so a
                                 nearly-full session also says so in words. -->
                            <span
                                class="text-xs font-semibold"
                                :class="training.nearly_full ? 'text-warning' : 'text-csc-ink/60'"
                            >
                                {{ training.registered
                                }}<template v-if="training.capacity"> / {{ training.capacity }}</template>
                                <template v-if="training.nearly_full"> · Nearly full</template>
                            </span>
                            <Link
                                :href="training.roster_url"
                                class="text-xs font-semibold text-csc-blue hover:underline"
                            >
                                Roster
                            </Link>
                        </div>
                    </li>
                </ul>

                <AppEmptyState
                    v-else
                    title="No upcoming trainings"
                    description="Create a training and publish it so participants can register."
                    icon="M8 3v3M16 3v3M4 9h16M5 6h14a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z"
                >
                    <template #action>
                        <AppButton href="/admin/trainings/create">Create Training</AppButton>
                    </template>
                </AppEmptyState>
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
