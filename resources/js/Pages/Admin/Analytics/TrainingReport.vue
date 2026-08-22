<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppCard from '@/Components/AppCard.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import RevenuePanel from '@/Pages/Admin/Analytics/RevenuePanel.vue';
import BreakdownPanel from '@/Pages/Admin/Analytics/BreakdownPanel.vue';

const props = defineProps({
    trainingOptions: { type: Array, required: true },
    selectedTrainingId: { type: Number, default: null },
    trainingReport: { type: Object, default: null },
    canSeeMoney: { type: Boolean, required: true },
});

// Revenue and breakdown both ride in the same payload, so the sub-tab is pure
// client state. Revenue is only offered to roles that can see money.
const subtype = ref(props.canSeeMoney ? 'revenue' : 'breakdown');

const money = (value) =>
    Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const revenueExportUrl = computed(() => {
    if (!props.selectedTrainingId) return '';

    return `/admin/exports/reports/revenue?view=training&training_id=${props.selectedTrainingId}`;
});

const breakdownExportUrl = computed(() => {
    if (!props.selectedTrainingId) return '';

    return `/admin/exports/reports/breakdown?view=training&training_id=${props.selectedTrainingId}`;
});

function pickTraining(id) {
    router.get(
        '/admin/analytics',
        { view: 'training', training_id: id || undefined },
        { preserveState: true, replace: true }
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

        <AppEmptyState
            v-if="!trainingReport"
            icon="analytics"
            title="No training selected"
            description="Pick a training above to see its revenue and participant breakdown."
            compact
        />

        <template v-else>
            <div class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-1 border-t border-csc-line pt-4">
                <p class="text-sm font-semibold text-csc-ink">{{ trainingReport.training.title }}</p>
                <p class="text-xs text-csc-ink-subtle">{{ trainingReport.training.starts_at }}</p>
                <p v-if="trainingReport.training.payment_amount" class="text-xs text-csc-ink-subtle">
                    Fee ₱{{ money(trainingReport.training.payment_amount) }}
                </p>
            </div>

            <!-- The two report forms share one scope. -->
            <div class="mt-4 flex flex-wrap gap-2" role="tablist" aria-label="Report form">
                <button
                    v-if="canSeeMoney"
                    type="button"
                    role="tab"
                    :aria-selected="subtype === 'revenue'"
                    class="rounded-lg px-3.5 py-1.5 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    :class="
                        subtype === 'revenue'
                            ? 'bg-csc-blue text-white'
                            : 'bg-white text-csc-ink-muted ring-1 ring-csc-line hover:text-csc-blue'
                    "
                    @click="subtype = 'revenue'"
                >
                    Revenue
                </button>
                <button
                    type="button"
                    role="tab"
                    :aria-selected="subtype === 'breakdown'"
                    class="rounded-lg px-3.5 py-1.5 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    :class="
                        subtype === 'breakdown'
                            ? 'bg-csc-blue text-white'
                            : 'bg-white text-csc-ink-muted ring-1 ring-csc-line hover:text-csc-blue'
                    "
                    @click="subtype = 'breakdown'"
                >
                    Breakdown
                </button>
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