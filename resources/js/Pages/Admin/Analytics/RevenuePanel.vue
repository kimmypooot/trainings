<script setup>
import { computed } from 'vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppStatTile from '@/Components/AppStatTile.vue';
import AppTrendChart from '@/Components/AppTrendChart.vue';
import { formatMoney, formatMoneyCompact } from '@/charts';

/**
 * The money side of a report.
 *
 * Verified payments only; a promissory note is verified but no money arrived,
 * so it is counted apart. The PRIME-HRM discount gets its own line — the whole
 * point of the report is that assessed and collected never blur.
 *
 * The four headline figures are tiles rather than a chart on purpose: they are
 * four different quantities on one scale, and a bar chart of them would invite
 * a comparison ("collected is two thirds of assessed") that the discount and
 * the promissory line already explain properly in words.
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

const money = formatMoney;

const trendRows = computed(() =>
    (props.trend ?? []).map((row) => ({ label: row.label, value: row.collected }))
);
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

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <AppStatTile
                label="Assessed"
                :value="money(revenue.gross ?? 0)"
                caption="What the fees came to"
                icon="document"
                tone="brand"
            />
            <AppStatTile
                label="PRIME-HRM Discount"
                :value="`− ${money(revenue.discount ?? 0)}`"
                caption="Granted, not collected"
                icon="tag"
                tone="warning"
            />
            <AppStatTile
                label="Collected"
                :value="money(revenue.collected ?? 0)"
                caption="Money that arrived"
                icon="card"
                tone="success"
            />
            <AppStatTile
                label="On Promissory Note"
                :value="money(revenue.promissory ?? 0)"
                :caption="
                    revenue.promissory_count
                        ? `${revenue.promissory_count} outstanding`
                        : 'Verified, but no money yet'
                "
                icon="clock"
                tone="warning"
            />
        </div>

        <div class="mt-4 grid grid-cols-2 gap-4 border-t border-csc-line pt-4 sm:grid-cols-4">
            <div>
                <p class="text-xs text-csc-ink-subtle">Awaiting verification</p>
                <p class="mt-0.5 text-lg font-semibold text-warning">{{ revenue.pending_count }}</p>
            </div>
            <div>
                <p class="text-xs text-csc-ink-subtle">Rejected</p>
                <p class="mt-0.5 text-lg font-semibold text-danger">{{ revenue.rejected_count }}</p>
            </div>
        </div>

        <!--
            The money earned month by month inside the period, so an annual view
            shows the seasonal shape rather than one lump. A time series, so it
            is drawn as one — a stack of bars answers "which month was biggest"
            and this answers "what does the year look like", which is the
            question an annual report is actually asked.
        -->
        <div v-if="trendRows.length" class="mt-5 border-t border-csc-line pt-5">
            <p class="mb-3 text-sm font-semibold text-csc-ink">Collected by month</p>
            <AppTrendChart
                :rows="trendRows"
                value-label="Collected"
                :format="formatMoney"
                :tick-format="formatMoneyCompact"
                :height="150"
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
                            <th scope="col" class="py-2 pr-4 font-semibold text-csc-ink-muted">Participant</th>
                            <th scope="col" class="py-2 pr-4 font-semibold text-csc-ink-muted">OR No.</th>
                            <th scope="col" class="py-2 pr-4 text-right font-semibold text-csc-ink-muted">Full Fee</th>
                            <th scope="col" class="py-2 pr-4 text-right font-semibold text-csc-ink-muted">Discount</th>
                            <th scope="col" class="py-2 text-right font-semibold text-csc-ink-muted">Paid</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-csc-line">
                        <tr v-for="row in revenue.discounted" :key="row.id">
                            <td class="py-2.5 pr-4 text-csc-ink-muted">{{ row.participant }}</td>
                            <td class="py-2.5 pr-4 font-mono text-xs text-csc-ink-subtle">{{ row.or_number ?? '—' }}</td>
                            <td class="py-2.5 pr-4 text-right text-csc-ink-muted tabular-nums">{{ money(row.gross) }}</td>
                            <td class="py-2.5 pr-4 text-right font-medium text-warning tabular-nums">− {{ money(row.discount) }}</td>
                            <td class="py-2.5 text-right font-medium text-csc-ink tabular-nums">{{ money(row.net) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppCard>
</template>
