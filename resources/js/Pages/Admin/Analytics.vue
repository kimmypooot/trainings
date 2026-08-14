<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBarList from '@/Components/AppBarList.vue';
import AppButton from '@/Components/AppButton.vue';

const props = defineProps({
    range: { type: Object, required: true },
    headline: { type: Object, required: true },
    registrationsByMonth: { type: Array, required: true },
    byCategory: { type: Array, required: true },
    byFieldOffice: { type: Array, required: true },
    attendance: { type: Object, required: true },
    payments: { type: Object, default: null },
    demographics: { type: Object, required: true },
    topAgencies: { type: Array, required: true },
    scopedTo: { type: String, default: null },
});

// The month strip is drawn as columns rather than rows, so it keeps its own
// peak; every other breakdown uses AppBarList, which works its own out.
const monthPeak = computed(() =>
    Math.max(1, ...props.registrationsByMonth.map((row) => row.count))
);

const tiles = computed(() => [
    { label: 'Trainings', value: props.headline.trainings },
    { label: 'Registrations', value: props.headline.registrations },
    { label: 'Completed', value: props.headline.completed },
    { label: 'Certificates Issued', value: props.headline.certificates },
]);
</script>

<template>
    <Head title="Analytics" />

    <AuthenticatedLayout title="Analytics" current="admin-analytics">
        <div class="mx-auto max-w-7xl space-y-5">
            <AppAlert v-if="scopedTo" tone="info">
                Figures cover <strong>{{ scopedTo }}</strong> only.
            </AppAlert>

            <!-- Headline -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div
                    v-for="tile in tiles"
                    :key="tile.label"
                    class="rounded-xl border border-csc-line bg-white p-4"
                >
                    <p class="text-2xl font-bold text-csc-blue">{{ tile.value }}</p>
                    <p class="mt-0.5 text-xs text-csc-ink/60">{{ tile.label }}</p>
                </div>
            </div>

            <AppCard title="Registrations Over Time" :subtitle="`Since ${range.since}`">
                <ul class="space-y-2">
                    <li
                        v-for="row in registrationsByMonth"
                        :key="row.month"
                        class="grid grid-cols-[6rem_1fr_2.5rem] items-center gap-3"
                    >
                        <span class="text-xs text-csc-ink/60">{{ row.month }}</span>
                        <span class="h-2.5 rounded-full bg-csc-blue-tint">
                            <span
                                class="block h-full rounded-full bg-csc-blue transition-all duration-150"
                                :style="{ width: `${(row.count / monthPeak) * 100}%` }"
                            />
                        </span>
                        <span class="text-right text-xs font-medium text-csc-ink">{{ row.count }}</span>
                    </li>
                </ul>
            </AppCard>

            <div class="grid gap-5 lg:grid-cols-2">
                <AppCard title="Attendance">
                    <p v-if="attendance.rate === null" class="text-sm text-csc-ink/60">
                        No attendance has been recorded yet.
                    </p>

                    <template v-else>
                        <p class="text-3xl font-bold text-csc-blue">{{ attendance.rate }}%</p>
                        <p class="mt-0.5 text-xs text-csc-ink/60">
                            of {{ attendance.total }} recorded days counted toward completion
                        </p>

                        <ul class="mt-5 space-y-2">
                            <li
                                v-for="row in attendance.byStatus"
                                :key="row.label"
                                class="flex items-center justify-between text-sm"
                            >
                                <span class="text-csc-ink/70">{{ row.label }}</span>
                                <span class="font-medium text-csc-ink">{{ row.count }}</span>
                            </li>
                        </ul>
                    </template>
                </AppCard>

                <AppCard title="By Category">
                    <AppBarList :rows="byCategory" empty-text="No registrations yet." />
                </AppCard>
            </div>

            <AppCard v-if="byFieldOffice.length" title="By Field Office">
                <AppBarList :rows="byFieldOffice" label-width="10rem" />
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
                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <h3 class="mb-2 text-sm font-medium text-csc-ink">Sex</h3>
                        <AppBarList :rows="demographics.sex" label-width="7rem" />
                    </div>
                    <div>
                        <h3 class="mb-2 text-sm font-medium text-csc-ink">Age Band</h3>
                        <AppBarList :rows="demographics.ageBand" label-width="7rem" />
                    </div>
                    <div>
                        <h3 class="mb-2 text-sm font-medium text-csc-ink">Position Level</h3>
                        <AppBarList :rows="demographics.positionLevel" label-width="11rem" />
                    </div>
                    <div>
                        <h3 class="mb-2 text-sm font-medium text-csc-ink">Nature of Appointment</h3>
                        <AppBarList :rows="demographics.employmentStatus" label-width="11rem" />
                    </div>
                    <div>
                        <h3 class="mb-2 text-sm font-medium text-csc-ink">Sector</h3>
                        <AppBarList :rows="demographics.sector" label-width="11rem" />
                    </div>
                    <div>
                        <h3 class="mb-2 text-sm font-medium text-csc-ink">Charged To</h3>
                        <AppBarList :rows="demographics.chargeTo" label-width="7rem" />
                    </div>
                </div>
            </AppCard>

            <AppCard
                v-if="topAgencies.length"
                title="Top Agencies"
                subtitle="The ten sending the most participants."
            >
                <AppBarList :rows="topAgencies" label-width="14rem" />
            </AppCard>

            <AppCard v-if="payments" title="Payments">
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <p class="text-2xl font-bold text-csc-blue">
                            {{ Number(payments.verified_total).toLocaleString() }}
                        </p>
                        <p class="text-xs text-csc-ink/60">PHP verified</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-warning">{{ payments.pending_count }}</p>
                        <p class="text-xs text-csc-ink/60">Awaiting verification</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-danger">{{ payments.rejected_count }}</p>
                        <p class="text-xs text-csc-ink/60">Rejected</p>
                    </div>
                </div>
            </AppCard>

            <AppCard title="Exports" subtitle="Downloads honour the same field-office scoping as these figures.">
                <div class="flex flex-wrap gap-3">
                    <AppButton href="/admin/exports/participants" variant="ghost" size="sm" icon="download">
                        Participants (CSV)
                    </AppButton>
                    <AppButton href="/admin/exports/participants?format=xlsx" variant="ghost" size="sm" icon="download">
                        Participants (Excel)
                    </AppButton>
                    <AppButton href="/admin/exports/registrations" variant="ghost" size="sm" icon="download">
                        Registrations (CSV)
                    </AppButton>
                    <AppButton v-if="payments" href="/admin/exports/payments" variant="ghost" size="sm" icon="download">
                        Payments (CSV)
                    </AppButton>
                </div>
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
