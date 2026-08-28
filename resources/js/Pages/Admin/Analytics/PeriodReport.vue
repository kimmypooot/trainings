<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppCard from '@/Components/AppCard.vue';
import AppTabs from '@/Components/AppTabs.vue';
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

// Revenue is dropped from the strip entirely rather than shown disabled:
// a role that cannot see money has no use for a greyed-out tab telling it so.
const subtypes = computed(() => [
    ...(props.canSeeMoney ? [{ key: 'revenue', label: 'Revenue', icon: 'card' }] : []),
    { key: 'breakdown', label: 'Breakdown', icon: 'users' },
]);

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
            // Both travel on every visit, whichever period type is showing.
            // Sending only the one in use let ReportScope fall back to today
            // for the other, and the watch above then wrote that default over
            // what was selected — so picking March, glancing at a quarter and
            // coming back landed on the current month instead of March. The
            // server clamps both and ignores whichever the period does not
            // need, so there is nothing to gain by withholding one.
            month: month.value,
            quarter: quarter.value,
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

        <!--
            The scope, stated as a heading rather than a line of grey metadata.
            This strip is what a printed report is read from, so it names the
            period and the two figures that qualify every number below it.
        -->
        <div
            class="mt-5 flex flex-wrap items-center justify-between gap-4 rounded-xl bg-csc-blue-tint px-4 py-3.5"
        >
            <p class="text-base font-semibold text-csc-blue">{{ periodReport.label }}</p>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-1">
                <p class="text-sm text-csc-ink-muted">
                    <span class="font-semibold text-csc-ink tabular-nums">{{ periodReport.conducted }}</span>
                    training(s) conducted
                </p>
                <p class="text-sm text-csc-ink-muted">
                    <span class="font-semibold text-csc-ink tabular-nums">{{ periodReport.participants }}</span>
                    registration(s)
                </p>
            </div>
        </div>

        <div class="mt-4">
            <AppTabs v-model="subtype" :tabs="subtypes" aria-label="Report form" size="sm" />
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