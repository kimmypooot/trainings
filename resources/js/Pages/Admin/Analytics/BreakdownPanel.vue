<script setup>
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppBarList from '@/Components/AppBarList.vue';
import AppShareBar from '@/Components/AppShareBar.vue';
import { formatCount } from '@/charts';
import { useDownload } from '@/useDownload';

/**
 * The demographic side of a report: the cuts CSC reports upward, each on its
 * own labelled chart.
 *
 * Counted per registration — one person attending three trainings in a period
 * is three training slots delivered, which is what the report is about.
 *
 * Each cut takes the form its data actually is. Sex and PWD are small
 * part-to-whole splits and read as one share bar; age band and position level
 * are *ordered*, so they take the one-hue ramp and the colour carries the
 * sequence; sector and employment status are unordered, so every bar is the
 * same blue and the length does all the work. Getting this wrong — a rainbow
 * over unordered rows, a flat colour over an ordered one — is the difference
 * between a chart that reads and one that just looks busy.
 */
defineProps({
    breakdown: { type: Object, required: true },
    /** Absolute download URL, matching the report's scope. */
    exportUrl: { type: String, required: true },
});

const { downloading, start } = useDownload();

const shares = [
    { key: 'sex', label: 'Gender' },
    { key: 'pwd', label: 'PWD' },
];

const bars = [
    { key: 'ageBand', label: 'Age Band', tone: 'ordinal', labelWidth: '7rem' },
    { key: 'positionLevel', label: 'Position Level', tone: 'ordinal', labelWidth: '11rem' },
    { key: 'sector', label: 'Sector', tone: 'brand', labelWidth: '11rem' },
    { key: 'employmentStatus', label: 'Employment Status', tone: 'brand', labelWidth: '11rem' },
];
</script>

<template>
    <AppCard
        title="Breakdown"
        subtitle="Counted per registration. Gender uses the profile's sex field; PWD is the declared disability status."
    >
        <template #action>
            <AppButton
                :href="exportUrl"
                variant="ghost"
                size="sm"
                icon="download"
                external
                :loading="downloading === exportUrl"
                @click.prevent="start(exportUrl)"
            >
                Download (CSV)
            </AppButton>
        </template>

        <div class="flex items-baseline gap-2 rounded-lg bg-csc-blue-tint px-4 py-3">
            <span class="text-2xl font-bold text-csc-blue">{{ formatCount(breakdown.total) }}</span>
            <span class="text-sm text-csc-ink-muted">registration(s) in scope</span>
        </div>

        <div class="mt-6 grid gap-x-8 gap-y-7 sm:grid-cols-2">
            <div v-for="cut in shares" :key="cut.key">
                <h3 class="mb-2.5 text-sm font-semibold text-csc-ink">{{ cut.label }}</h3>
                <AppShareBar :rows="breakdown[cut.key]" />
            </div>
            <div v-for="cut in bars" :key="cut.key">
                <h3 class="mb-2.5 text-sm font-semibold text-csc-ink">{{ cut.label }}</h3>
                <AppBarList :rows="breakdown[cut.key]" :tone="cut.tone" :label-width="cut.labelWidth" />
            </div>
        </div>
    </AppCard>
</template>
