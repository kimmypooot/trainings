<script setup>
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppBarList from '@/Components/AppBarList.vue';

/**
 * The money side of a report.
 *
 * Verified payments only; a promissory note is verified but no money arrived,
 * so it is counted apart. The PRIME-HRM discount gets its own line — the whole
 * point of the report is that assessed and collected never blur.
 */
const props = defineProps({
    revenue: { type: Object, required: true },
    /** Absolute download URL, matching the report's scope. */
    exportUrl: { type: String, required: true },
    /**
     * Verified revenue per month inside the period, when the report spans
     * more than one month. Absent for a single-training report.
     */
    trend: { type: Array, default: null },
});

const money = (value) =>
    Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <AppCard
        title="Revenue"
        subtitle="Verified payments only. A pending payment is a claim, not money."
    >
        <template #action>
            <AppButton :href="exportUrl" variant="ghost" size="sm" icon="download" external>
                Download (CSV)
            </AppButton>
        </template>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <p class="text-xs text-csc-ink/60">Assessed</p>
                <p class="mt-0.5 text-lg font-semibold text-csc-ink">₱{{ money(revenue.gross ?? 0) }}</p>
            </div>
            <div>
                <p class="text-xs text-csc-ink/60">PRIME-HRM Discount</p>
                <p class="mt-0.5 text-lg font-semibold text-warning">− ₱{{ money(revenue.discount ?? 0) }}</p>
            </div>
            <div>
                <p class="text-xs text-csc-ink/60">Collected</p>
                <p class="mt-0.5 text-lg font-semibold text-csc-blue">₱{{ money(revenue.collected ?? 0) }}</p>
            </div>
            <div>
                <p class="text-xs text-csc-ink/60">On Promissory Note</p>
                <p class="mt-0.5 text-lg font-semibold text-csc-ink/70">₱{{ money(revenue.promissory ?? 0) }}</p>
                <p v-if="revenue.promissory_count" class="text-2xs text-csc-ink/55">
                    {{ revenue.promissory_count }} outstanding
                </p>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-4 border-t border-csc-line pt-4 sm:grid-cols-4">
            <div>
                <p class="text-xs text-csc-ink/60">Awaiting verification</p>
                <p class="mt-0.5 text-lg font-semibold text-warning">{{ revenue.pending_count }}</p>
            </div>
            <div>
                <p class="text-xs text-csc-ink/60">Rejected</p>
                <p class="mt-0.5 text-lg font-semibold text-danger">{{ revenue.rejected_count }}</p>
            </div>
        </div>

        <!--
            The money earned month by month inside the period, so an annual view
            shows the seasonal shape rather than one lump.
        -->
        <div v-if="trend?.length" class="mt-5 border-t border-csc-line pt-4">
            <p class="mb-3 text-xs font-semibold text-csc-ink">Collected by month</p>
            <AppBarList
                :rows="trend.map((row) => ({ label: row.label, count: row.collected }))"
                label-width="6rem"
                empty-text="No verified payments in this period."
            />
        </div>

        <!-- Which participants, by name — the question the office asks. -->
        <div v-if="revenue.discounted?.length" class="mt-5 border-t border-csc-line pt-4">
            <p class="text-xs font-semibold text-csc-ink">
                PRIME-HRM discount granted to {{ revenue.discounted_count }} participant(s)
            </p>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-csc-line text-xs uppercase">
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-semibold text-csc-ink/70">Participant</th>
                            <th scope="col" class="py-2 pr-4 font-semibold text-csc-ink/70">OR No.</th>
                            <th scope="col" class="py-2 pr-4 text-right font-semibold text-csc-ink/70">Full Fee</th>
                            <th scope="col" class="py-2 pr-4 text-right font-semibold text-csc-ink/70">Discount</th>
                            <th scope="col" class="py-2 text-right font-semibold text-csc-ink/70">Paid</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-csc-line">
                        <tr v-for="row in revenue.discounted" :key="row.id">
                            <td class="py-2.5 pr-4 text-csc-ink/80">{{ row.participant }}</td>
                            <td class="py-2.5 pr-4 font-mono text-xs text-csc-ink/60">{{ row.or_number ?? '—' }}</td>
                            <td class="py-2.5 pr-4 text-right text-csc-ink/70">₱{{ money(row.gross) }}</td>
                            <td class="py-2.5 pr-4 text-right font-medium text-warning">− ₱{{ money(row.discount) }}</td>
                            <td class="py-2.5 text-right font-medium text-csc-ink">₱{{ money(row.net) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppCard>
</template>