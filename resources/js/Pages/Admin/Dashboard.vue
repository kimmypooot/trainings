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
import AppDonutChart from '@/Components/AppDonutChart.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppSkeleton from '@/Components/AppSkeleton.vue';
import AppStatTile from '@/Components/AppStatTile.vue';
import { formatCount, formatMoney, formatMoneyCompact } from '@/charts';
import { formatDateRange } from '@/dateRange';

/**
 * The staff landing page.
 *
 * It used to be four counts and two lists — how much exists, and what is
 * booked — which answered "how big is this" and nothing else. The four
 * questions it answers now, in the order the page reads:
 *
 *   What is happening?      the KPI row, month to date against last month
 *   What needs me?          the queue strip, only queues this role can clear
 *   Where is it stuck?      the registration pipeline
 *   What is at risk?        under-filled runs, and finished ones not closed out
 *
 * It deliberately does not restate the analytics report. Registrations by
 * month, by category, by office, the demographic cuts and revenue per training
 * all live there; anything here that needs a year of context to read belongs
 * there too. See DashboardMetrics for the same split stated from the data end.
 */
const props = defineProps({
    metrics: { type: Object, required: true },
    totals: { type: Object, required: true },
    scopedTo: { type: String, default: null },
    modalLimit: { type: Number, default: 50 },
    upcoming: { type: Array, required: true },
    awaitingCompletion: { type: Array, required: true },
    awaitingCompletionTotal: { type: Number, default: 0 },
    registrationsList: { type: Array, default: null },
    awaitingCompletionList: { type: Array, default: null },
});

const formats = {
    count: formatCount,
    money: formatMoney,
    percent: (value) => `${value}%`,
};

/*
 * A KPI's headline figure.
 *
 * Money is shown compact — ₱1.2M rather than ₱1,248,300.00. At display size
 * the full figure wraps to two lines and the tile stops being scannable, and
 * the exact peso is a question for the payments register, not for a tile whose
 * job is "more or less than last month". A rate with no window to measure
 * (nothing ended, so nothing could be completed) shows an em dash: 0% would be
 * a claim that work was left undone.
 */
const headline = (kpi) => {
    if (kpi.value === null) return '—';
    if (kpi.format === 'money') return formatMoneyCompact(kpi.value);

    return formats[kpi.format](kpi.value);
};

/*
 * The comparison chip.
 *
 * Three shapes, because three things can be true. A rate moves in *points*
 * (a percentage change of a percentage is ambiguous — "up 8%" from 80% could
 * be 88% or 86.4%). A figure with nothing behind it did not rise by any
 * percentage, it started, so it says so rather than printing +100%. Everything
 * else is a percentage against the same stretch of last month.
 */
const comparison = (kpi) => {
    const delta = kpi.delta;

    if (!delta) return null;

    const previous = kpi.format === 'money' ? formatMoneyCompact(delta.previous) : formatCount(delta.previous);

    if ('points' in delta) {
        const sign = delta.points > 0 ? '+' : '';

        return {
            direction: delta.direction,
            text: delta.points === 0 ? 'No change' : `${sign}${delta.points} pts`,
            caption: `from ${delta.previous}%`,
        };
    }

    if (delta.percent === null) {
        return {
            direction: delta.direction,
            text: delta.direction === 'up' ? 'New' : 'No change',
            caption: `nothing in ${props.metrics.period.comparison}`,
        };
    }

    const sign = delta.percent > 0 ? '+' : '';

    return {
        direction: delta.direction,
        text: delta.percent === 0 ? 'No change' : `${sign}${delta.percent}%`,
        caption: `vs ${previous} in ${props.metrics.period.comparison}`,
    };
};

/** True when every queue this role owns is empty. */
const allClear = computed(() => props.metrics.attention.every((item) => item.count === 0));

/*
 * The donut's own total counts every status, including cancelled and rejected
 * — which is right for the chart (they are part of the picture) and wrong for
 * the figure in the hole, where the reader expects the live number the rest of
 * the page uses.
 */
const pipelineTotal = computed(() => formatCount(props.totals.registrations));

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
                The period this page is about, the size of the operation, and
                the way through to the report. One line, because none of the
                three is the reason anybody opened the dashboard — but the KPI
                row underneath is meaningless without the first of them.

                The inventory counts were four tiles of their own until this
                change. They barely move between visits, so they are stated
                once here and linked, and the row below is given over to the
                figures that do move.
            -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-csc-ink">
                        {{ metrics.period.label }}
                        <span class="font-normal text-csc-ink-subtle">
                            · first {{ metrics.period.days }} day{{ metrics.period.days === 1 ? '' : 's' }}
                        </span>
                    </p>
                    <p class="mt-0.5 text-xs text-csc-ink-subtle">
                        <Link href="/admin/participants" class="font-medium text-csc-blue hover:underline">
                            {{ totals.participants }} participants
                        </Link>
                        ·
                        <Link
                            href="/admin/trainings?status=published"
                            class="font-medium text-csc-blue hover:underline"
                        >
                            {{ totals.published }} published trainings
                        </Link>
                    </p>
                </div>

                <AppButton href="/admin/analytics" size="sm" variant="ghost" icon="analytics">
                    View Analytics
                </AppButton>
            </div>

            <!--
                Two per row on a phone rather than one: these are the figures
                the page exists for, and a single column pushes the fourth of
                them two screens down.
            -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <AppStatTile
                    v-for="kpi in metrics.kpis"
                    :key="kpi.key"
                    :label="kpi.label"
                    :value="headline(kpi)"
                    :caption="kpi.caption"
                    :icon="kpi.icon"
                    :tone="kpi.tone"
                    :spark="kpi.spark"
                    :delta="comparison(kpi)"
                />
            </div>

            <!--
                The work, before anything describing it.

                Every queue this role can clear, zeros included — an empty
                queue is worth confirming, and a strip that reorders itself
                between visits cannot be learned. Queues the role cannot act on
                are absent rather than greyed: DashboardMetrics drops them.
            -->
            <AppCard
                title="Needs Your Attention"
                :subtitle="
                    allClear
                        ? 'Every queue you can act on is clear.'
                        : 'Open items waiting on someone at your role.'
                "
            >
                <ul class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <li v-for="item in metrics.attention" :key="item.key">
                        <Link
                            :href="item.href"
                            class="flex items-center gap-3 rounded-lg border p-3 transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            :class="
                                item.count > 0
                                    ? 'border-warning/30 bg-warning-soft/50 hover:border-warning'
                                    : 'border-csc-line hover:border-csc-blue/40'
                            "
                        >
                            <!--
                                Icon and colour together, never colour alone:
                                a cleared queue reads as cleared in greyscale.
                            -->
                            <span
                                class="grid size-8 shrink-0 place-items-center rounded-lg"
                                :class="item.count > 0 ? 'bg-warning-soft text-warning' : 'bg-success-soft text-success'"
                                aria-hidden="true"
                            >
                                <AppIcon :name="item.count > 0 ? 'clock' : 'check'" size="sm" />
                            </span>
                            <span class="min-w-0">
                                <span
                                    class="block text-lg font-bold"
                                    :class="item.count > 0 ? 'text-warning' : 'text-csc-ink-subtle'"
                                >
                                    {{ item.count }}
                                </span>
                                <span class="block text-xs text-csc-ink-muted">{{ item.label }}</span>
                            </span>
                        </Link>
                    </li>
                </ul>
            </AppCard>

            <div class="grid gap-5 lg:grid-cols-2">
                <!--
                    Where registrations are sitting. Not a history — a queue
                    depth per stage, which is why it is on this page and the
                    monthly curve is on the report.
                -->
                <AppCard
                    title="Registration Pipeline"
                    subtitle="Every registration by stage. The centre counts only those holding a seat — cancelled and rejected are in the chart but not in that figure."
                >
                    <template #action>
                        <AppButton size="sm" variant="ghost" @click="show('registrations', 'registrationsList')">
                            Recent
                        </AppButton>
                    </template>

                    <AppDonutChart
                        :rows="metrics.pipeline"
                        :center-value="pipelineTotal"
                        center-label="Holding a seat"
                        empty-text="No registrations yet."
                    />
                </AppCard>

                <!--
                    The runs that are not filling, emptiest first. A to-do list
                    rather than a census, so it is capped and each row opens the
                    roster it is about.
                -->
                <AppCard
                    title="Capacity Watch"
                    subtitle="Published upcoming runs with the most seats left."
                    :padded="metrics.capacity.length > 0"
                >
                    <ul v-if="metrics.capacity.length" class="divide-y divide-csc-line">
                        <li v-for="run in metrics.capacity" :key="run.label" class="py-3">
                            <div class="flex items-baseline justify-between gap-3">
                                <Link
                                    :href="run.href"
                                    class="min-w-0 truncate text-sm font-medium text-csc-blue hover:underline"
                                >
                                    {{ run.label }}
                                </Link>
                                <span
                                    class="shrink-0 text-xs font-semibold"
                                    :class="run.count < 50 ? 'text-warning' : 'text-csc-ink-subtle'"
                                >
                                    {{ run.registered }} / {{ run.capacity }}
                                </span>
                            </div>
                            <!--
                                The meter spans the row and the reading sits
                                under it. Squeezed into a column beside the bar
                                it wrapped to two lines and every row came out a
                                different height.
                            -->
                            <div
                                aria-hidden="true"
                                class="mt-1.5 h-1 w-full overflow-hidden rounded-full bg-csc-line"
                            >
                                <div
                                    class="h-full rounded-full"
                                    :class="run.count < 50 ? 'bg-warning' : 'bg-csc-blue'"
                                    :style="{ width: `${Math.max(run.count, 1.5)}%` }"
                                />
                            </div>
                            <p class="mt-1 text-2xs text-csc-ink-subtle">
                                {{ run.count }}% filled · starts {{ run.starts_at }}
                            </p>
                        </li>
                    </ul>

                    <AppEmptyState
                        v-else
                        compact
                        title="Nothing to watch"
                        description="No published upcoming run has a capacity set, so there is no fill to measure."
                        icon="calendar"
                    />
                </AppCard>
            </div>

            <AppCard
                title="Awaiting Completion"
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
                                {{ formatDateRange(training.starts_at, training.ends_at) }} · {{ training.venue }} ·
                                {{ training.when }}
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
                v-if="capped(registrationsList, totals.registrations)"
                class="mt-4 border-t border-csc-line pt-4 text-xs text-csc-ink-subtle"
            >
                Showing the {{ modalLimit }} most recent of {{ totals.registrations }}. Open a training's roster for
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
            title="Awaiting Completion"
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
