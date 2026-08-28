<script setup>
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppTabs from '@/Components/AppTabs.vue';
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
    { key: 'overview', label: 'Overview', icon: 'analytics' },
    { key: 'training', label: 'By Training', icon: 'calendar' },
    { key: 'period', label: 'By Period', icon: 'clock' },
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

            <AppTabs
                :model-value="view"
                :tabs="tabs"
                aria-label="Analytics view"
                @update:model-value="switchTab"
            />

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
