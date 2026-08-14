<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppModal from '@/Components/AppModal.vue';
import AppSkeleton from '@/Components/AppSkeleton.vue';
import AppStat from '@/Components/AppStat.vue';

const props = defineProps({
    stats: { type: Object, required: true },
    scopedTo: { type: String, default: null },
    upcoming: { type: Array, required: true },
    awaitingCompletion: { type: Array, required: true },
    registrationsList: { type: Array, default: null },
    awaitingCompletionList: { type: Array, default: null },
});

// A count is the natural handle for the list behind it. Trainings and
// participants already have index pages worth landing on; registrations does
// not, so that one opens a dialog rather than earning a page of its own.
const tiles = computed(() => [
    { label: 'Published', value: props.stats.published, href: '/admin/trainings?status=published' },
    { label: 'Drafts', value: props.stats.drafts, href: '/admin/trainings?status=draft' },
    { label: 'Participants', value: props.stats.participants, href: '/admin/participants' },
    { label: 'Registrations', value: props.stats.registrations, modal: 'registrations', prop: 'registrationsList' },
]);

// Which dialog is open, or null. One at a time by construction.
const openModal = ref(null);
const loading = ref(false);

/*
 * The list behind a dialog is an optional prop, so it is absent until asked
 * for. Requesting it is a partial reload of this same page — no extra route, no
 * new URL — and once it has arrived we do not ask again.
 */
const show = (name, propName) => {
    openModal.value = name;

    if (props[propName] !== null) return;

    loading.value = true;
    router.reload({
        only: [propName],
        onFinish: () => (loading.value = false),
    });
};
</script>

<template>
    <Head title="Admin Dashboard" />

    <AuthenticatedLayout title="Dashboard" current="admin-dashboard">
        <div class="mx-auto max-w-7xl space-y-5">
            <AppAlert v-if="scopedTo" tone="info">
                Showing participants for <strong>{{ scopedTo }}</strong> only.
            </AppAlert>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <AppStat
                    v-for="tile in tiles"
                    :key="tile.label"
                    :label="tile.label"
                    :value="tile.value"
                    :href="tile.href"
                    :action="Boolean(tile.modal)"
                    @click="tile.modal && show(tile.modal, tile.prop)"
                />
            </div>

            <AppCard
                title="Needs Attention"
                subtitle="Finished trainings with participants not yet marked complete"
                :padded="awaitingCompletion.length > 0"
            >
                <template v-if="awaitingCompletion.length >= 5" #action>
                    <AppButton size="sm" variant="ghost" @click="show('awaiting', 'awaitingCompletionList')">
                        View All
                    </AppButton>
                </template>

                <ul v-if="awaitingCompletion.length" class="divide-y divide-csc-line">
                    <li
                        v-for="item in awaitingCompletion"
                        :key="item.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3.5"
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
                    icon="check"
                />
            </AppCard>

            <AppCard title="Upcoming Trainings" :padded="upcoming.length > 0">
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
                    icon="calendar"
                >
                    <template #action>
                        <AppButton href="/admin/trainings/create" icon="plus">Create Training</AppButton>
                    </template>
                </AppEmptyState>
            </AppCard>
        </div>

        <AppModal
            :open="openModal === 'registrations'"
            title="Registrations"
            :subtitle="scopedTo ? `Most recent for ${scopedTo}` : 'Most recent across all offices'"
            size="lg"
            @close="openModal = null"
        >
            <AppSkeleton v-if="loading" variant="list" :count="6" label="Loading registrations" />

            <ul v-else-if="registrationsList?.length" class="divide-y divide-csc-line">
                <li
                    v-for="entry in registrationsList"
                    :key="entry.id"
                    class="flex flex-wrap items-center justify-between gap-3 py-3.5"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-csc-ink">{{ entry.participant }}</p>
                        <p class="mt-0.5 text-xs text-csc-ink/60">{{ entry.training }} · {{ entry.registered_on }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <AppBadge :status="entry.status" />
                        <Link :href="entry.roster_url" class="text-xs font-semibold text-csc-blue hover:underline">
                            Roster
                        </Link>
                    </div>
                </li>
            </ul>

            <AppEmptyState
                v-else
                compact
                title="No registrations yet"
                description="Registrations appear here as participants sign up for published trainings."
                icon="user"
            />
        </AppModal>

        <AppModal
            :open="openModal === 'awaiting'"
            title="Needs Attention"
            subtitle="Finished trainings with participants not yet marked complete"
            size="lg"
            @close="openModal = null"
        >
            <AppSkeleton v-if="loading" variant="list" :count="5" label="Loading trainings" />

            <ul v-else-if="awaitingCompletionList?.length" class="divide-y divide-csc-line">
                <li
                    v-for="item in awaitingCompletionList"
                    :key="item.id"
                    class="flex flex-wrap items-center justify-between gap-3 py-3.5"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-csc-ink">{{ item.title }}</p>
                        <p class="mt-0.5 text-xs text-csc-ink/60">
                            Ended {{ item.ended }} · {{ item.pending }} awaiting completion
                        </p>
                    </div>
                    <AppButton :href="item.roster_url" size="sm" variant="ghost">Open Roster</AppButton>
                </li>
            </ul>

            <AppEmptyState
                v-else
                compact
                title="Nothing pending"
                description="Every finished training has had its participants marked complete."
                icon="check"
            />
        </AppModal>
    </AuthenticatedLayout>
</template>
