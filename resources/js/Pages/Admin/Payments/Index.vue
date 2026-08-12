<script setup>
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

const props = defineProps({
    payments: { type: Object, required: true },
    refunds: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash?.success);

const active = ref('payments');

const pendingRefunds = computed(() => props.refunds.filter((r) => r.status === 'pending').length);

const filterBy = (status) =>
    router.get('/admin/payments', { status }, { preserveState: true, preserveScroll: true });

/**
 * A rejection has to carry a reason — money decisions are the ones most likely
 * to be queried later.
 */
const decide = (url, decision, rejectValue) => {
    let remarks = null;

    if (decision === rejectValue) {
        remarks = window.prompt('Reason for rejecting:');

        if (!remarks) {
            return;
        }
    }

    router.post(url, { decision, remarks }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Payments" />

    <AuthenticatedLayout title="Payments" current="admin-payments">
        <div class="mx-auto max-w-5xl space-y-5">
            <AppAlert v-if="flash" tone="success">{{ flash }}</AppAlert>

            <div class="flex flex-wrap gap-2" role="tablist">
                <button
                    type="button"
                    role="tab"
                    :aria-selected="active === 'payments'"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    :class="
                        active === 'payments'
                            ? 'bg-csc-blue text-white'
                            : 'bg-white text-csc-ink/70 ring-1 ring-csc-line hover:text-csc-blue'
                    "
                    @click="active = 'payments'"
                >
                    Payments
                </button>
                <button
                    type="button"
                    role="tab"
                    :aria-selected="active === 'refunds'"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    :class="
                        active === 'refunds'
                            ? 'bg-csc-blue text-white'
                            : 'bg-white text-csc-ink/70 ring-1 ring-csc-line hover:text-csc-blue'
                    "
                    @click="active = 'refunds'"
                >
                    Refunds
                    <span
                        v-if="pendingRefunds"
                        class="ml-1.5 rounded-full px-1.5 py-0.5 text-xs font-semibold"
                        :class="active === 'refunds' ? 'bg-white/20' : 'bg-csc-red text-white'"
                    >
                        {{ pendingRefunds }}
                    </span>
                </button>
            </div>

            <template v-if="active === 'payments'">
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="status in statuses"
                        :key="status.value"
                        type="button"
                        class="rounded-full px-3 py-1.5 text-xs font-medium ring-1 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        :class="
                            (filters.status || 'pending') === status.value
                                ? 'bg-csc-blue-tint text-csc-blue ring-csc-blue/30'
                                : 'bg-white text-csc-ink/60 ring-csc-line hover:text-csc-blue'
                        "
                        @click="filterBy(status.value)"
                    >
                        {{ status.label }}
                    </button>
                </div>

                <AppCard title="Payment Verification" :padded="!payments.data.length">
                    <AppEmptyState
                        v-if="!payments.data.length"
                        title="Nothing to verify"
                        description="Payments submitted by participants appear here."
                        icon="M3 10h18M5 6h14a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z"
                    />

                    <ul v-else class="space-y-3">
                        <li
                            v-for="payment in payments.data"
                            :key="payment.id"
                            class="rounded-lg border border-csc-line p-4"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-semibold text-csc-ink">{{ payment.participant }}</p>
                                    <p class="mt-0.5 text-sm text-csc-ink/60">{{ payment.training }}</p>
                                    <p class="mt-1 text-sm text-csc-ink">
                                        PHP {{ payment.amount }} · {{ payment.method }} ·
                                        {{ payment.payment_date }}
                                    </p>
                                    <p v-if="payment.reference_number" class="text-xs text-csc-ink/55">
                                        Ref {{ payment.reference_number }}
                                    </p>
                                </div>
                                <AppBadge :status="payment.status" />
                            </div>

                            <p v-if="payment.rejection_reason" class="mt-3 text-sm text-csc-red-ink">
                                {{ payment.rejection_reason }}
                            </p>
                            <p v-if="payment.verified_by" class="mt-1.5 text-xs text-csc-ink/55">
                                Reviewed by {{ payment.verified_by }}
                            </p>

                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <a
                                    v-if="payment.proof_url"
                                    :href="payment.proof_url"
                                    class="rounded text-sm font-medium text-csc-blue underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                >
                                    View proof
                                </a>

                                <template v-if="payment.status === 'pending'">
                                    <AppButton
                                        size="sm"
                                        @click="decide(`/admin/payments/${payment.id}/review`, 'verified', 'rejected')"
                                    >
                                        Verify
                                    </AppButton>
                                    <AppButton
                                        size="sm"
                                        variant="ghost"
                                        @click="decide(`/admin/payments/${payment.id}/review`, 'rejected', 'rejected')"
                                    >
                                        Reject
                                    </AppButton>
                                </template>
                            </div>
                        </li>
                    </ul>
                </AppCard>
            </template>

            <AppCard v-else title="Refund Requests" :padded="!refunds.length">
                <AppEmptyState
                    v-if="!refunds.length"
                    title="No refund requests"
                    description="Claims against verified payments appear here."
                    icon="M9 14l-4-4 4-4M5 10h9a5 5 0 0 1 0 10h-3"
                />

                <ul v-else class="space-y-3">
                    <li v-for="refund in refunds" :key="refund.id" class="rounded-lg border border-csc-line p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-csc-ink">{{ refund.participant }}</p>
                                <p class="mt-0.5 text-sm text-csc-ink/60">{{ refund.training }}</p>
                                <p class="mt-1 text-sm text-csc-ink">PHP {{ refund.amount }}</p>
                            </div>
                            <AppBadge :status="refund.status" />
                        </div>

                        <p class="mt-3 text-sm text-csc-ink/80">{{ refund.reason }}</p>
                        <p v-if="refund.review_remarks" class="mt-1.5 text-xs text-csc-ink/55">
                            Remarks: {{ refund.review_remarks }}
                        </p>

                        <div v-if="refund.status === 'pending'" class="mt-4 flex flex-wrap gap-2">
                            <AppButton
                                size="sm"
                                @click="decide(`/admin/refunds/${refund.id}/review`, 'approved', 'rejected')"
                            >
                                Approve Refund
                            </AppButton>
                            <AppButton
                                size="sm"
                                variant="ghost"
                                @click="decide(`/admin/refunds/${refund.id}/review`, 'rejected', 'rejected')"
                            >
                                Decline
                            </AppButton>
                        </div>
                    </li>
                </ul>
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
