<script setup>
import { computed } from 'vue';
import AppCard from '@/Components/AppCard.vue';
import AppBarList from '@/Components/AppBarList.vue';
import AppButton from '@/Components/AppButton.vue';
import AppDonutChart from '@/Components/AppDonutChart.vue';
import AppShareBar from '@/Components/AppShareBar.vue';
import AppStatTile from '@/Components/AppStatTile.vue';
import AppTrendChart from '@/Components/AppTrendChart.vue';
import { formatMoney } from '@/charts';

/**
 * The live dashboard: what has happened so far, across every training. Kept as
 * the default tab of the analytics page — the report generator sits beside it.
 *
 * The cuts are grouped by the question they answer rather than by where the
 * data comes from: how much has happened (the tiles and the trend), how it is
 * going (attendance), who is being trained (the profile block), and where they
 * come from. Each cut also picks its form by what the data *is* — see the
 * notes on the individual charts, and the palette notes in app.css.
 */
const props = defineProps({
    overview: { type: Object, required: true },
});

const trend = computed(() =>
    props.overview.registrationsByMonth.map((row) => ({ label: row.month, value: row.count }))
);

// The sparkline on the registrations tile is the same series as the chart
// below it, at thumbnail size — the number gains its shape without the tile
// having to become a chart.
const sparkline = computed(() => props.overview.registrationsByMonth.map((row) => row.count));

const tiles = computed(() => [
    {
        label: 'Trainings',
        value: props.overview.headline.trainings,
        icon: 'calendar',
        tone: 'brand',
    },
    {
        label: 'Registrations',
        value: props.overview.headline.registrations,
        icon: 'users',
        tone: 'brand',
        caption: `Since ${props.overview.range.since}`,
        spark: sparkline.value,
    },
    {
        label: 'Completed',
        value: props.overview.headline.completed,
        icon: 'check',
        tone: 'success',
    },
    {
        label: 'Certificates Issued',
        value: props.overview.headline.certificates,
        icon: 'certificate',
        tone: 'brand',
    },
]);

/*
 * The attendance ring's centre is the completion rate, not the row count: the
 * rate is the figure the office is judged on, and the statuses around it are
 * the breakdown of how it got there. A ring with a bare total in the middle
 * would be a breakdown of nothing in particular.
 */
const attendanceCenter = computed(() =>
    props.overview.attendance.rate === null ? '—' : `${props.overview.attendance.rate}%`
);

/*
 * Ordered cuts take the one-hue ramp so the colour carries the sequence;
 * unordered ones are all the same blue, because the bar length already says
 * everything hue could. Sex, funding source and the like are small
 * part-to-whole splits and read better as a single share bar than as two or
 * three lonely bars.
 */
const profileShares = computed(() => [
    { key: 'sex', label: 'Sex' },
    { key: 'chargeTo', label: 'Charged To' },
]);

const profileBars = computed(() => [
    { key: 'ageBand', label: 'Age Band', tone: 'ordinal', width: '7rem' },
    { key: 'positionLevel', label: 'Position Level', tone: 'ordinal', width: '11rem' },
    { key: 'employmentStatus', label: 'Nature of Appointment', tone: 'brand', width: '11rem' },
    { key: 'sector', label: 'Sector', tone: 'brand', width: '11rem' },
]);
</script>

<template>
    <div class="space-y-5">
        <!-- Headline -->
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <AppStatTile
                v-for="tile in tiles"
                :key="tile.label"
                :label="tile.label"
                :value="tile.value"
                :caption="tile.caption"
                :icon="tile.icon"
                :tone="tile.tone"
                :spark="tile.spark"
            />
        </div>

        <AppCard
            title="Registrations Over Time"
            :subtitle="`Monthly, since ${overview.range.since}.`"
        >
            <AppTrendChart
                :rows="trend"
                value-label="Registrations"
                empty-text="No registrations in this window yet."
            />
        </AppCard>

        <div class="grid gap-5 lg:grid-cols-2">
            <AppCard
                title="Attendance"
                subtitle="Share of recorded days that counted toward completion."
            >
                <p v-if="overview.attendance.rate === null" class="text-sm text-csc-ink-subtle">
                    No attendance has been recorded yet.
                </p>

                <AppDonutChart
                    v-else
                    :rows="overview.attendance.byStatus"
                    :center-value="attendanceCenter"
                    center-label="of days credited"
                />
            </AppCard>

            <AppCard title="By Category" subtitle="Registrations per training category.">
                <AppBarList
                    :rows="overview.byCategory"
                    :limit="8"
                    empty-text="No registrations yet."
                />
            </AppCard>
        </div>

        <AppCard v-if="overview.byFieldOffice.length" title="By Field Office">
            <AppBarList :rows="overview.byFieldOffice" label-width="10rem" />
        </AppCard>

        <!--
            Who is being trained. These are the cuts CSC reports upward, so
            they sit together rather than being scattered among the
            operational figures above.
        -->
        <AppCard
            title="Participant Profile"
            subtitle="Counted per registration — one person attending three trainings counts three times."
        >
            <div class="grid gap-x-8 gap-y-7 sm:grid-cols-2">
                <div v-for="cut in profileShares" :key="cut.key">
                    <h3 class="mb-2.5 text-sm font-semibold text-csc-ink">{{ cut.label }}</h3>
                    <AppShareBar :rows="overview.demographics[cut.key]" />
                </div>
                <div v-for="cut in profileBars" :key="cut.key">
                    <h3 class="mb-2.5 text-sm font-semibold text-csc-ink">{{ cut.label }}</h3>
                    <AppBarList
                        :rows="overview.demographics[cut.key]"
                        :tone="cut.tone"
                        :label-width="cut.width"
                    />
                </div>
            </div>
        </AppCard>

        <AppCard
            title="Where Participants Come From"
            subtitle="Their own region and province, not the field office they are assigned to."
        >
            <div class="grid gap-x-8 gap-y-7 sm:grid-cols-2">
                <div>
                    <h3 class="mb-2.5 text-sm font-semibold text-csc-ink">Region</h3>
                    <AppBarList :rows="overview.demographics.region" label-width="13rem" :limit="10" />
                </div>
                <div>
                    <h3 class="mb-2.5 text-sm font-semibold text-csc-ink">Province</h3>
                    <AppBarList :rows="overview.demographics.province" label-width="11rem" :limit="10" />
                </div>
            </div>
        </AppCard>

        <AppCard
            v-if="overview.topAgencies.length"
            title="Top Agencies"
            subtitle="The ten sending the most participants."
        >
            <AppBarList :rows="overview.topAgencies" label-width="14rem" />
        </AppCard>

        <div v-if="overview.payments" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <AppStatTile
                label="Verified"
                :value="formatMoney(overview.payments.verified_total)"
                caption="Money that has actually arrived"
                icon="card"
                tone="success"
            />
            <AppStatTile
                label="Awaiting verification"
                :value="overview.payments.pending_count"
                caption="Claims, not yet money"
                icon="clock"
                tone="warning"
            />
            <AppStatTile
                label="Rejected"
                :value="overview.payments.rejected_count"
                icon="warning"
                tone="danger"
            />
        </div>

        <AppCard
            title="Exports"
            subtitle="Downloads honour the same field-office scoping as these figures."
        >
            <div class="flex flex-wrap gap-3">
                <AppButton href="/admin/exports/participants" variant="ghost" size="sm" icon="download" external>
                    Participants (CSV)
                </AppButton>
                <AppButton href="/admin/exports/participants?format=xlsx" variant="ghost" size="sm" icon="download" external>
                    Participants (Excel)
                </AppButton>
                <AppButton href="/admin/exports/registrations" variant="ghost" size="sm" icon="download" external>
                    Registrations (CSV)
                </AppButton>
                <AppButton v-if="overview.payments" href="/admin/exports/payments" variant="ghost" size="sm" icon="download" external>
                    Payments (CSV)
                </AppButton>
            </div>
        </AppCard>
    </div>
</template>
