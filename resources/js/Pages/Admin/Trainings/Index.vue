<script setup>
import { computed, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppPagination from '@/Components/AppPagination.vue';
import AppRowActions from '@/Components/AppRowActions.vue';
import AppStatTile from '@/Components/AppStatTile.vue';
import { spansMultipleDays } from '@/dateRange';
import { useFilters, filteringClass } from '@/useFilters';

const props = defineProps({
    trainings: { type: Object, required: true },
    filters: { type: Object, required: true },
    tabs: { type: Array, required: true },
    // The whole catalogue, not the filtered page — see the controller.
    summary: { type: Object, required: true },
    /** `manage` is the pen: create and edit. Reading a roster needs neither. */
    can: { type: Object, default: () => ({}) },
    /** The office every head on this page belongs to, or null for the region. */
    scopedTo: { type: String, default: null },
});

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');

// `tabs` counts every status across the whole catalogue — that is the point of
// the chips, so they are not reloaded with the narrowed rows.
const { filtering, apply } = useFilters({
    url: '/admin/trainings',
    only: ['trainings', 'filters'],
    query: () => ({
        search: search.value || undefined,
        status: status.value || undefined,
    }),
});

// The status chips act on the click; only the text box waits for a pause.
watch(search, () => apply());
watch(status, () => apply({ immediate: true }));

/*
 * What can be done with one run, listed once for both layouts. The roster is
 * the destination for anyone; the pen is `can.manage`.
 */
const actionsFor = (training) => [
    { label: 'Roster', icon: 'users', href: training.roster_url },
    ...(props.can.manage ? [{ label: 'Edit', icon: 'pencil', href: training.edit_url }] : []),
];

const tones = {
    draft: 'bg-csc-blue-tint text-csc-ink',
    published: 'bg-success-soft text-success',
    closed: 'bg-warning-soft text-warning',
    completed: 'bg-info-soft text-info',
    cancelled: 'bg-danger-soft text-danger',
};

/*
 * Totals for the table footer, over the rows on screen.
 *
 * These are the *page's* totals, not the catalogue's — the list is paginated
 * 25 at a time and this sums `trainings.data`. The footer says so now; it
 * previously read "Totals", which invited it to be taken for the region's, and
 * the comment here claimed as much. The catalogue-wide figures are the tiles
 * above, which come from the server.
 *
 * The per-training "registered" figure is the sum of its paid + promissory +
 * pending buckets, so summing again across rows never double-counts; free and
 * cancelled are counted apart.
 */
const totals = computed(() => {
    const sum = (key) => props.trainings.data.reduce((acc, training) => acc + (training[key] ?? 0), 0);

    return {
        registered: sum('registered'),
        paid: sum('paid'),
        pending: sum('pending'),
        promissory: sum('promissory'),
        free: sum('free'),
        cancelled: sum('cancelled'),
    };
});
</script>

<template>
    <Head title="Manage Trainings" />

    <AuthenticatedLayout title="Manage Trainings" current="admin-trainings">
        <div class="mx-auto max-w-7xl space-y-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search by title…"
                    aria-label="Search trainings"
                    class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue sm:max-w-xs"
                />

                <AppButton v-if="can.manage" href="/admin/trainings/create" icon="plus">New Training</AppButton>
            </div>

            <!--
                Same notice the roster carries, for the same reason: every head
                counted below is one of this office's, and a scoped figure that
                does not say so is indistinguishable from a wrong one. The runs
                themselves are the whole region's — a training with none of this
                office's people still belongs on the list, at zero.
            -->
            <AppAlert v-if="scopedTo" tone="info">
                Participant counts are for <strong>{{ scopedTo }}</strong> only. Every training in the region is
                listed.
            </AppAlert>

            <!--
                When, not what — the chips below already carry one count per
                status, so a tile for "Published" would be the same number
                twice. These answer the question the chips cannot: a run's
                status says it is published, not whether it is happening this
                morning.

                Runs, never people. The catalogue is regional while participant
                counts are scoped to a field office, so a row mixing the two
                would need a "your office only" caveat on half of it — which is
                exactly the confusion the notice above exists to prevent.
            -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <AppStatTile
                    label="Running Now"
                    :value="summary.running"
                    icon="clock"
                    :tone="summary.running > 0 ? 'warning' : 'brand'"
                    :caption="summary.running > 0 ? 'Attendance is being taken' : 'Nothing in session'"
                />
                <AppStatTile
                    label="Starts This Week"
                    :value="summary.this_week"
                    icon="calendar"
                    caption="Next seven days"
                />
                <AppStatTile label="Upcoming" :value="summary.upcoming" icon="arrow-forward" />
                <AppStatTile
                    label="Ended"
                    :value="summary.ended"
                    icon="check-circle"
                    caption="Rosters ready to close out"
                />
            </div>

            <div class="flex flex-wrap gap-2" role="tablist" aria-label="Filter trainings by status">
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    role="tab"
                    :aria-selected="status === tab.value"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    :class="
                        status === tab.value
                            ? 'bg-csc-blue text-white'
                            : 'bg-white text-csc-ink-muted ring-1 ring-csc-line hover:text-csc-blue'
                    "
                    @click="status = tab.value"
                >
                    {{ tab.label }}
                    <span
                        v-if="tab.count"
                        class="ml-1.5 rounded-full px-1.5 py-0.5 text-xs font-semibold"
                        :class="status === tab.value ? 'bg-white/20' : 'bg-csc-red text-white'"
                    >
                        {{ tab.count }}
                    </span>
                </button>
            </div>

            <!--
                 The results dim while a filtered visit is out. The controls above stay
                 live — narrowing further mid-request is the normal thing to do — but
                 these rows are already superseded, so they stop taking clicks until
                 they have been redrawn.
            -->
            <div :class="filteringClass(filtering)" :aria-busy="filtering" class="space-y-5">
                <AppCard v-if="!trainings.data.length" :padded="false">
                    <AppEmptyState
                        title="No trainings found"
                        :description="
                            can.manage
                                ? 'Create one, or clear the filters if you were searching.'
                                : 'Clear the filters if you were searching.'
                        "
                        icon="calendar"
                    >
                        <template v-if="can.manage" #action>
                            <AppButton href="/admin/trainings/create" icon="plus">Create Training</AppButton>
                        </template>
                    </AppEmptyState>
                </AppCard>

                <template v-else>
                    <!-- Table on wide screens; the same rows stack into cards below md -->
                    <div class="hidden overflow-hidden rounded-xl border border-csc-line bg-white md:block">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                                <tr>
                                    <th scope="col" rowspan="2" class="px-5 py-3 font-semibold text-csc-ink-muted">Training</th>
                                    <th scope="col" rowspan="2" class="px-5 py-3 font-semibold text-csc-ink-muted">Schedule</th>
                                    <th scope="colgroup" colspan="6" class="border-b border-csc-line px-5 py-3 text-center font-semibold text-csc-ink-muted">
                                        Breakdown of Participants
                                    </th>
                                    <th scope="col" rowspan="2" class="px-5 py-3 text-right font-semibold text-csc-ink-muted">Actions</th>
                                </tr>
                                <tr>
                                    <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink-muted">Total</th>
                                    <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink-muted">Paid</th>
                                    <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink-muted">Pending</th>
                                    <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink-muted">Promissory</th>
                                    <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink-muted">Free</th>
                                    <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink-muted">Cancelled</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-csc-line">
                                <tr v-for="training in trainings.data" :key="training.id">
                                    <td class="px-5 py-3.5">
                                        <p class="font-medium text-csc-ink">{{ training.title }}</p>
                                        <p class="mt-0.5 text-xs text-csc-ink-subtle">{{ training.venue }}</p>
                                        <AppBadge v-if="training.is_supervisory" status="supervisory" class="mt-1.5" />
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <p class="text-[11px] leading-snug text-csc-ink-muted">
                                            {{ training.starts_at }}<template v-if="spansMultipleDays(training.starts_at, training.ends_at)"> –</template>
                                        </p>
                                        <p v-if="spansMultipleDays(training.starts_at, training.ends_at)" class="text-[11px] leading-snug text-csc-ink-muted">
                                            {{ training.ends_at }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-3.5 text-right font-medium text-csc-ink">
                                        {{ training.registered
                                        }}<template v-if="training.capacity"> / {{ training.capacity }}</template>
                                    </td>
                                    <td class="px-5 py-3.5 text-right tabular-nums text-csc-ink-muted">{{ training.paid }}</td>
                                    <td class="px-5 py-3.5 text-right tabular-nums text-csc-ink-muted">{{ training.pending }}</td>
                                    <td class="px-5 py-3.5 text-right tabular-nums text-csc-ink-muted">{{ training.promissory }}</td>
                                    <td class="px-5 py-3.5 text-right tabular-nums text-csc-ink-muted">{{ training.free }}</td>
                                    <td class="px-5 py-3.5 text-right tabular-nums text-csc-ink-muted">{{ training.cancelled }}</td>
                                    <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                        <AppRowActions :actions="actionsFor(training)" />
                                    </td>
                                </tr>
                            </tbody>
                            <!-- The footer spans the label columns, then one cell per
                                 payment bucket so a glance gives the totals without
                                 opening any roster. These cover the rows on screen,
                                 which is 25 of a paginated catalogue — the label
                                 says so, because "Totals" beside a paginator reads
                                 as the whole set. -->
                            <tfoot class="border-t border-csc-line bg-csc-blue-tint/60">
                                <tr>
                                    <td colspan="2" class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-csc-ink-muted">
                                        Totals · this page
                                    </td>
                                    <td class="px-5 py-3 text-right font-semibold text-csc-ink">{{ totals.registered }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums font-semibold text-csc-ink">{{ totals.paid }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums font-semibold text-csc-ink">{{ totals.pending }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums font-semibold text-csc-ink">{{ totals.promissory }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums font-semibold text-csc-ink">{{ totals.free }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums font-semibold text-csc-ink">{{ totals.cancelled }}</td>
                                    <td class="px-5 py-3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <ul class="space-y-3 md:hidden">
                        <li
                            v-for="training in trainings.data"
                            :key="training.id"
                            class="rounded-xl border border-csc-line bg-white p-4"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-csc-ink">{{ training.title }}</p>
                                    <p class="mt-0.5 text-[11px] leading-snug text-csc-ink-subtle">
                                        {{ training.starts_at }}<template v-if="spansMultipleDays(training.starts_at, training.ends_at)"> –</template>
                                    </p>
                                    <p v-if="spansMultipleDays(training.starts_at, training.ends_at)" class="text-[11px] leading-snug text-csc-ink-subtle">
                                        {{ training.ends_at }}
                                    </p>
                                    <p class="text-xs text-csc-ink-subtle">{{ training.venue }}</p>
                                    <AppBadge v-if="training.is_supervisory" status="supervisory" class="mt-1.5" />
                                </div>
                                <span
                                    class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="tones[training.status]"
                                >
                                    {{ training.status_label }}
                                </span>
                            </div>
                            <dl class="mt-3 grid grid-cols-3 gap-x-3 gap-y-1.5 border-t border-csc-line pt-3 text-xs">
                                <div class="flex items-center justify-between gap-2">
                                    <dt class="text-csc-ink-subtle">Registered</dt>
                                    <dd class="font-semibold text-csc-ink">{{ training.registered }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-2">
                                    <dt class="text-csc-ink-subtle">Paid</dt>
                                    <dd class="tabular-nums text-csc-ink-muted">{{ training.paid }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-2">
                                    <dt class="text-csc-ink-subtle">Pending</dt>
                                    <dd class="tabular-nums text-csc-ink-muted">{{ training.pending }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-2">
                                    <dt class="text-csc-ink-subtle">Promissory</dt>
                                    <dd class="tabular-nums text-csc-ink-muted">{{ training.promissory }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-2">
                                    <dt class="text-csc-ink-subtle">Free</dt>
                                    <dd class="tabular-nums text-csc-ink-muted">{{ training.free }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-2">
                                    <dt class="text-csc-ink-subtle">Cancelled</dt>
                                    <dd class="tabular-nums text-csc-ink-muted">{{ training.cancelled }}</dd>
                                </div>
                            </dl>
                            <div class="mt-3 border-t border-csc-line pt-3">
                                <AppRowActions :actions="actionsFor(training)" layout="card" />
                            </div>
                        </li>
                    </ul>

                    <AppPagination :pagination="trainings" label="trainings" class="pt-2" />
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
