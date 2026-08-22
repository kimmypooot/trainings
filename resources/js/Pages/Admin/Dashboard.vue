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
    modalLimit: { type: Number, default: 50 },
    upcoming: { type: Array, required: true },
    awaitingCompletion: { type: Array, required: true },
    awaitingCompletionTotal: { type: Number, default: 0 },
    registrationsList: { type: Array, default: null },
    awaitingCompletionList: { type: Array, default: null },
});

/*
 * A count is the natural handle for the list behind it. Trainings and
 * participants already have index pages worth landing on; registrations does
 * not, so that one opens a dialog rather than earning a page of its own.
 *
 * Drafts used to hold the fourth slot. It was the least urgent number on a page
 * whose reader is looking for work, and it is still a click away under
 * Trainings — the queue depth is what belongs in a tile. A role with no such
 * queue gets a three-tile row rather than a zero that reads as "all clear".
 */
const tiles = computed(() =>
    [
        { label: 'Published', value: props.stats.published, href: '/admin/trainings?status=published' },
        { label: 'Participants', value: props.stats.participants, href: '/admin/participants' },
        { label: 'Registrations', value: props.stats.registrations, modal: 'registrations', prop: 'registrationsList' },
        props.stats.requests === null
            ? null
            : { label: 'Pending Requests', value: props.stats.requests, href: '/admin/requests' },
    ].filter(Boolean)
);

// Which dialog is open, or null. One at a time by construction.
const openModal = ref(null);
const loading = ref(false);
const failed = ref(false);

/*
 * The list behind a dialog is an optional prop, so it is absent until asked
 * for. Requesting it is a partial reload of this same page — no extra route and
 * no new URL.
 *
 * Fetched on every open rather than cached after the first. The cache saved one
 * capped query and cost correctness: a staff member who approves something in
 * another tab and comes back to this dialog was shown the list as it stood the
 * first time they opened it, with nothing on screen admitting it was stale.
 *
 * onError, not just onFinish: a dropped request used to land on the empty state,
 * so the dialog said "No registrations yet" — a claim about the data that the
 * page had no basis for making.
 */
const show = (name, propName) => {
    openModal.value = name;
    failed.value = false;
    loading.value = true;

    router.reload({
        only: [propName],
        onError: () => (failed.value = true),
        onFinish: () => (loading.value = false),
    });
};

const retry = () => {
    const tile = { registrations: 'registrationsList', awaiting: 'awaitingCompletionList' };

    show(openModal.value, tile[openModal.value]);
};

// Whether a dialog is showing everything there is, or the capped slice. Compared
// against the totals the page already carries, so no extra query buys the line.
const capped = (rows, total) => rows?.length >= props.modalLimit && total > props.modalLimit;
</script>

<template>
    <Head title="Admin Dashboard" />

    <AuthenticatedLayout title="Dashboard" current="admin-dashboard">
        <div class="mx-auto max-w-7xl space-y-5">
            <AppAlert v-if="scopedTo" tone="info">
                Showing participants for <strong>{{ scopedTo }}</strong> only.
            </AppAlert>

            <!--
                The dashboard's numbers are today's; analytics is the same
                domain over time, and until now nothing on this page pointed at
                it. Staff-wide, matching the nav item's own role list.
            -->
            <div class="flex justify-end">
                <AppButton href="/admin/analytics" size="sm" variant="ghost" icon="analytics">
                    View Analytics
                </AppButton>
            </div>

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
                <!--
                    Only when the dialog would actually show more than the card
                    already does — at exactly five, "View All" opened a dialog
                    onto the same five rows.
                -->
                <template v-if="awaitingCompletionTotal > awaitingCompletion.length" #action>
                    <AppButton size="sm" variant="ghost" @click="show('awaiting', 'awaitingCompletionList')">
                        View all {{ awaitingCompletionTotal }}
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
                            <p class="mt-0.5 text-xs text-csc-ink-subtle">Ended {{ item.ended }}</p>
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
                    <!--
                        Stacked below sm rather than left to wrap. The meta line
                        runs to three fields and the fill to two more, and
                        flex-wrap resolved that into ragged half-rows on a phone
                        — the screen this card is most often read on.
                    -->
                    <li
                        v-for="training in upcoming"
                        :key="training.id"
                        class="flex flex-col gap-2 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:gap-3"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-csc-ink">{{ training.title }}</p>
                            <p class="mt-0.5 text-xs text-csc-ink-subtle">
                                {{ training.starts_at }} · {{ training.venue }} · {{ training.when }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3 sm:shrink-0">
                            <div class="min-w-0 sm:w-32">
                                <!-- Colour alone would not carry this, so a full
                                     or nearly-full session says so in words. -->
                                <span
                                    class="text-xs font-semibold"
                                    :class="
                                        training.full
                                            ? 'text-danger'
                                            : training.nearly_full
                                              ? 'text-warning'
                                              : 'text-csc-ink-subtle'
                                    "
                                >
                                    {{ training.registered
                                    }}<template v-if="training.capacity"> / {{ training.capacity }}</template>
                                    <template v-if="training.full"> · Full</template>
                                    <template v-else-if="training.nearly_full"> · Nearly full</template>
                                </span>
                                <!--
                                    The meter is the fast read; the figures above
                                    are the accessible one. Marked aria-hidden
                                    rather than given a role, because it restates
                                    a number that is already in the text.
                                -->
                                <div
                                    v-if="training.fill !== null"
                                    aria-hidden="true"
                                    class="mt-1.5 h-1 w-full overflow-hidden rounded-full bg-csc-line"
                                >
                                    <div
                                        class="h-full rounded-full"
                                        :class="
                                            training.full
                                                ? 'bg-danger'
                                                : training.nearly_full
                                                  ? 'bg-warning'
                                                  : 'bg-csc-blue'
                                        "
                                        :style="{ width: `${training.fill}%` }"
                                    />
                                </div>
                            </div>
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

            <!--
                A failed fetch is not an empty list. Without this the dialog
                claimed there were no registrations, which is a statement about
                the data that a dropped request gives no grounds for.
            -->
            <AppEmptyState
                v-else-if="failed"
                compact
                title="Could not load registrations"
                description="The list did not come back. This is usually a dropped connection rather than a problem with the data."
                icon="warning"
            >
                <template #action>
                    <AppButton size="sm" @click="retry">Try Again</AppButton>
                </template>
            </AppEmptyState>

            <ul v-else-if="registrationsList?.length" class="divide-y divide-csc-line">
                <li
                    v-for="entry in registrationsList"
                    :key="entry.id"
                    class="flex flex-wrap items-center justify-between gap-3 py-3.5"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-csc-ink">{{ entry.participant }}</p>
                        <p class="mt-0.5 text-xs text-csc-ink-subtle">{{ entry.training }} · {{ entry.registered_on }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <AppBadge :status="entry.status" />
                        <Link :href="entry.roster_url" class="text-xs font-semibold text-csc-blue hover:underline">
                            Roster
                        </Link>
                    </div>
                </li>
            </ul>

            <!--
                Says so when it is showing a slice. Fifty rows with nothing to
                the contrary reads as the whole set, and the reader has no way
                to tell otherwise from inside the dialog.
            -->
            <p
                v-if="capped(registrationsList, stats.registrations)"
                class="mt-4 border-t border-csc-line pt-4 text-xs text-csc-ink-subtle"
            >
                Showing the {{ modalLimit }} most recent of {{ stats.registrations }}. Open a training's roster for
                the full list.
            </p>

            <!--
                Guarded on loading/failed as well as emptiness: this starts a
                fresh v-if chain after the list above, so without them it would
                render its "nothing here" underneath the loading skeleton.
            -->
            <AppEmptyState
                v-else-if="!loading && !failed && !registrationsList?.length"
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

            <AppEmptyState
                v-else-if="failed"
                compact
                title="Could not load trainings"
                description="The list did not come back. This is usually a dropped connection rather than a problem with the data."
                icon="warning"
            >
                <template #action>
                    <AppButton size="sm" @click="retry">Try Again</AppButton>
                </template>
            </AppEmptyState>

            <ul v-else-if="awaitingCompletionList?.length" class="divide-y divide-csc-line">
                <li
                    v-for="item in awaitingCompletionList"
                    :key="item.id"
                    class="flex flex-wrap items-center justify-between gap-3 py-3.5"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-csc-ink">{{ item.title }}</p>
                        <p class="mt-0.5 text-xs text-csc-ink-subtle">
                            Ended {{ item.ended }} · {{ item.pending }} awaiting completion
                        </p>
                    </div>
                    <AppButton :href="item.roster_url" size="sm" variant="ghost">Open Roster</AppButton>
                </li>
            </ul>

            <p
                v-if="capped(awaitingCompletionList, awaitingCompletionTotal)"
                class="mt-4 border-t border-csc-line pt-4 text-xs text-csc-ink-subtle"
            >
                Showing the {{ modalLimit }} most recently ended of {{ awaitingCompletionTotal }}.
            </p>

            <AppEmptyState
                v-else-if="!loading && !failed && !awaitingCompletionList?.length"
                compact
                title="Nothing pending"
                description="Every finished training has had its participants marked complete."
                icon="check"
            />
        </AppModal>
    </AuthenticatedLayout>
</template>
