<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppCard from '@/Components/AppCard.vue';
import AppTabs from '@/Components/AppTabs.vue';
import { formatMoney } from '@/charts';
import AppSelect from '@/Components/AppSelect.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import RevenuePanel from '@/Pages/Admin/Analytics/RevenuePanel.vue';
import BreakdownPanel from '@/Pages/Admin/Analytics/BreakdownPanel.vue';
import ReportSkeleton from '@/Pages/Admin/Analytics/ReportSkeleton.vue';

const props = defineProps({
    trainingOptions: { type: Array, required: true },
    selectedTrainingId: { type: Number, default: null },
    trainingReport: { type: Object, default: null },
    canSeeMoney: { type: Boolean, required: true },
});

// Revenue and breakdown both ride in the same payload, so the sub-tab is pure
// client state. Revenue is only offered to roles that can see money.
const subtype = ref(props.canSeeMoney ? 'revenue' : 'breakdown');

// Revenue is dropped from the strip entirely rather than shown disabled:
// a role that cannot see money has no use for a greyed-out tab telling it so.
const subtypes = computed(() => [
    ...(props.canSeeMoney ? [{ key: 'revenue', label: 'Revenue', icon: 'card' }] : []),
    { key: 'breakdown', label: 'Breakdown', icon: 'users' },
]);

const money = formatMoney;

/*
 * The session count, beside the span.
 *
 * Not derivable from the dates on screen: a training running three Fridays
 * spans a month but is three days of attendance, and the span alone would have
 * a reader counting weekdays to guess. Suppressed for a single-day run, where
 * the date already says it.
 */
const durationLabel = computed(() => {
    const days = props.trainingReport?.training?.duration_days ?? 1;

    return days > 1 ? `${days} days` : null;
});

const revenueExportUrl = computed(() => {
    if (!props.selectedTrainingId) return '';

    return `/admin/exports/reports/revenue?view=training&training_id=${props.selectedTrainingId}`;
});

const breakdownExportUrl = computed(() => {
    if (!props.selectedTrainingId) return '';

    return `/admin/exports/reports/breakdown?view=training&training_id=${props.selectedTrainingId}`;
});

/*
 * Building a training's report is the expensive part of this screen, and until
 * now the only sign it was happening was the navigation hairline: the picker
 * kept showing the training you had just chosen above a report for the previous
 * one, which is the worst of both — stale figures under a fresh label.
 *
 * The flag goes true on the change rather than when the request leaves, the
 * same rule useFilters keeps, and the report region swaps to a skeleton instead
 * of dimming: unlike a filtered list, the arriving report is not a narrowing of
 * the one on screen, it is a different training entirely, and there is nothing
 * to be gained by leaving the old one legible underneath.
 */
const loading = ref(false);

function pickTraining(id) {
    loading.value = true;

    router.get(
        '/admin/analytics',
        { view: 'training', training_id: id || undefined },
        {
            // Only the report and the scope it was built from. `trainingOptions`
            // is the picker's own catalogue — re-querying the whole training
            // list on every pick is exactly the waste `only` exists to stop.
            only: ['trainingReport', 'selectedTrainingId'],
            preserveState: true,
            replace: true,
            onFinish: () => {
                loading.value = false;
            },
        }
    );
}
</script>

<template>
    <AppCard title="Report by Selected Training">
        <AppSelect
            :model-value="selectedTrainingId ?? ''"
            label="Training"
            placeholder="Select a training…"
            :options="trainingOptions"
            @update:model-value="pickTraining($event)"
        />

        <div v-if="loading" class="mt-5">
            <ReportSkeleton :tiles="4" label="Building report" />
        </div>

        <AppEmptyState
            v-else-if="!trainingReport"
            icon="analytics"
            title="No training selected"
            description="Pick a training above to see its revenue and participant breakdown."
            compact
        />

        <template v-else>
            <!--
                The scope, stated as a heading rather than a line of grey
                metadata. This strip is what a printed report is read from, so
                it names the run every number below it belongs to.
            -->
            <div
                class="mt-5 flex flex-wrap items-center justify-between gap-4 rounded-xl bg-csc-blue-tint px-4 py-3.5"
            >
                <p class="text-base font-semibold text-csc-blue">{{ trainingReport.training.title }}</p>
                <div class="flex flex-wrap items-center gap-x-6 gap-y-1">
                    <p class="text-sm text-csc-ink-muted">
                        {{ trainingReport.training.dates }}
                        <span v-if="durationLabel" class="text-csc-ink-subtle">· {{ durationLabel }}</span>
                    </p>
                    <p v-if="trainingReport.training.payment_amount" class="text-sm text-csc-ink-muted">
                        Fee
                        <span class="font-semibold text-csc-ink tabular-nums">
                            {{ money(trainingReport.training.payment_amount) }}
                        </span>
                    </p>
                </div>
            </div>

            <!-- The two report forms share one scope. -->
            <div class="mt-4">
                <AppTabs v-model="subtype" :tabs="subtypes" aria-label="Report form" size="sm" />
            </div>

            <div class="mt-4">
                <RevenuePanel v-if="subtype === 'revenue'" :revenue="trainingReport.revenue" :export-url="revenueExportUrl" />
                <BreakdownPanel
                    v-else
                    :breakdown="trainingReport.breakdown"
                    :export-url="breakdownExportUrl"
                />
            </div>
        </template>
    </AppCard>
</template>