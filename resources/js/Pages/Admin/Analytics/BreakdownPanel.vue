<script setup>
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppBarList from '@/Components/AppBarList.vue';

/**
 * The demographic side of a report: the cuts CSC reports upward, each on its
 * own labelled bar list.
 *
 * Counted per registration — one person attending three trainings in a period
 * is three training slots delivered, which is what the report is about.
 */
defineProps({
    breakdown: { type: Object, required: true },
    /** Absolute download URL, matching the report's scope. */
    exportUrl: { type: String, required: true },
});

const cuts = [
    { key: 'sector', label: 'Sector', labelWidth: '11rem' },
    { key: 'sex', label: 'Gender', labelWidth: '7rem' },
    { key: 'pwd', label: 'PWD', labelWidth: '7rem' },
    { key: 'positionLevel', label: 'Position Level', labelWidth: '11rem' },
    { key: 'employmentStatus', label: 'Employment Status', labelWidth: '11rem' },
    { key: 'ageBand', label: 'Age Band', labelWidth: '7rem' },
];
</script>

<template>
    <AppCard
        title="Breakdown"
        subtitle="Counted per registration. Gender uses the profile's sex field; PWD is the declared disability status."
    >
        <template #action>
            <AppButton :href="exportUrl" variant="ghost" size="sm" icon="download" external>
                Download (CSV)
            </AppButton>
        </template>

        <p class="text-sm text-csc-ink-muted">
            <span class="font-semibold text-csc-blue">{{ breakdown.total }}</span> registration(s)
        </p>

        <div class="mt-5 grid gap-6 sm:grid-cols-2">
            <div v-for="cut in cuts" :key="cut.key">
                <h3 class="mb-2 text-sm font-medium text-csc-ink">{{ cut.label }}</h3>
                <AppBarList :rows="breakdown[cut.key]" :label-width="cut.labelWidth" />
            </div>
        </div>
    </AppCard>
</template>