<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppCard from '@/Components/AppCard.vue';
import AppSelect from '@/Components/AppSelect.vue';
import RevenuePanel from '@/Pages/Admin/Analytics/RevenuePanel.vue';
import BreakdownPanel from '@/Pages/Admin/Analytics/BreakdownPanel.vue';

const props = defineProps({
    period: { type: Object, required: true },
    periodReport: { type: Object, required: true },
    canSeeMoney: { type: Boolean, required: true },
});

// Revenue and breakdown both ride in the same payload, so the sub-tab is pure
// client state. Revenue is only offered to roles that can see money.
const subtype = ref(props.canSeeMoney ? 'revenue' : 'breakdown');

const periodType = ref(props.period.value);
const year = ref(props.period.year);
const month = ref(props.period.month);
const quarter = ref(props.period.quarter);

// The scope is in the URL; when the page re-renders after a navigation the
// selects must follow what the server actually used.
watch(
    () => props.period,
    (period) => {
        periodType.value = period.value;
        year.value = period.year;
        month.value = period.month;
        quarter.value = period.quarter;
    }
);

const periodTypes = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'quarterly', label: 'Quarterly' },
    { value: 'annual', label: 'Annual' },
];

const currentYear = new Date().getFullYear();
const years = [];
for (let y = currentYear; y >= 2000; y--) years.push(y);

const monthNames = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];
const months = monthNames.map((label, index) => ({ value: index + 1, label }));

const quarters = [1, 2, 3, 4].map((quarter) => ({ value: quarter, label: `Quarter ${quarter}` }));

function commit() {
    router.get(
        '/admin/analytics',
        {
            view: 'period',
            period: periodType.value,
            year: year.value,
            month: periodType.value === 'monthly' ? month.value : undefined,
            quarter: periodType.value === 'quarterly' ? quarter.value : undefined,
        },
        { preserveState: true, replace: true }
    );
}

const revenueExportUrl = computed(
    () =>
        `/admin/exports/reports/revenue?view=period&period=${periodType.value}&year=${year.value}` +
        `&month=${month.value}&quarter=${quarter.value}`
);

const breakdownExportUrl = computed(
    () =>
        `/admin/exports/reports/breakdown?view=period&period=${periodType.value}&year=${year.value}` +
        `&month=${month.value}&quarter=${quarter.value}`
);
</script>

<template>
    <AppCard title="Report by Period" subtitle="All trainings conducted in the selected period.">
        <div class="grid gap-4 sm:grid-cols-4">
            <AppSelect
                :model-value="periodType"
                label="Period"
                :options="periodTypes"
                @update:model-value="(value) => { periodType = value; commit(); }"
            />
            <AppSelect
                :model-value="year"
                label="Year"
                :options="years"
                @update:model-value="(value) => { year = Number(value); commit(); }"
            />
            <AppSelect
                v-if="periodType === 'monthly'"
                :model-value="month"
                label="Month"
                :options="months"
                @update:model-value="(value) => { month = Number(value); commit(); }"
            />
            <AppSelect
                v-if="periodType === 'quarterly'"
                :model-value="quarter"
                label="Quarter"
                :options="quarters"
                @update:model-value="(value) => { quarter = Number(value); commit(); }"
            />
        </div>

        <div class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-1 border-t border-csc-line pt-4">
            <p class="text-sm font-semibold text-csc-blue">{{ periodReport.label }}</p>
            <p class="text-xs text-csc-ink/60">
                {{ periodReport.conducted }} training(s) conducted, {{ periodReport.participants }} registration(s)
            </p>
        </div>

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
                        : 'bg-white text-csc-ink/70 ring-1 ring-csc-line hover:text-csc-blue'
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
                        : 'bg-white text-csc-ink/70 ring-1 ring-csc-line hover:text-csc-blue'
                "
                @click="subtype = 'breakdown'"
            >
                Breakdown
            </button>
        </div>

        <div class="mt-4">
            <RevenuePanel
                v-if="subtype === 'revenue'"
                :revenue="periodReport.revenue"
                :trend="periodReport.byPeriod"
                :export-url="revenueExportUrl"
            />
            <BreakdownPanel
                v-else
                :breakdown="periodReport.breakdown"
                :export-url="breakdownExportUrl"
            />
        </div>
    </AppCard>
</template>