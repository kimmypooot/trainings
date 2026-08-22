<script setup>
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppAlert from '@/Components/AppAlert.vue';
import Overview from '@/Pages/Admin/Analytics/Overview.vue';
import TrainingReport from '@/Pages/Admin/Analytics/TrainingReport.vue';
import PeriodReport from '@/Pages/Admin/Analytics/PeriodReport.vue';

const props = defineProps({
    view: { type: String, required: true },
    scopedTo: { type: String, default: null },
    canSeeMoney: { type: Boolean, required: true },
    trainingOptions: { type: Array, required: true },
    selectedTrainingId: { type: Number, default: null },
    period: { type: Object, required: true },
    overview: { type: Object, required: true },
    trainingReport: { type: Object, default: null },
    periodReport: { type: Object, default: null },
});

const tabs = [
    { key: 'overview', label: 'Overview' },
    { key: 'training', label: 'By Training' },
    { key: 'period', label: 'By Period' },
];

// The tab is the only part the server needs to switch views; each report tab
// carries its own scope in the query string.
function switchTab(key) {
    if (key === props.view) return;

    router.get('/admin/analytics', { view: key }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Analytics" />

    <AuthenticatedLayout title="Analytics" current="admin-analytics">
        <div class="mx-auto max-w-7xl space-y-5">
            <AppAlert v-if="scopedTo" tone="info">
                Figures cover <strong>{{ scopedTo }}</strong> only.
            </AppAlert>

            <div class="flex flex-wrap gap-2" role="tablist" aria-label="Analytics view">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    role="tab"
                    :aria-selected="view === tab.key"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    :class="
                        view === tab.key
                            ? 'bg-csc-blue text-white'
                            : 'bg-white text-csc-ink-muted ring-1 ring-csc-line hover:text-csc-blue'
                    "
                    @click="switchTab(tab.key)"
                >
                    {{ tab.label }}
                </button>
            </div>

            <Overview v-if="view === 'overview'" :overview="overview" />
            <TrainingReport
                v-else-if="view === 'training'"
                :training-options="trainingOptions"
                :selected-training-id="selectedTrainingId"
                :training-report="trainingReport"
                :can-see-money="canSeeMoney"
            />
            <PeriodReport
                v-else
                :period="period"
                :period-report="periodReport"
                :can-see-money="canSeeMoney"
            />
        </div>
    </AuthenticatedLayout>
</template>