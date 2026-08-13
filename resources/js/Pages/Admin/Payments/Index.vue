<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppPromptModal from '@/Components/AppPromptModal.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppPagination from '@/Components/AppPagination.vue';

const props = defineProps({
    payments: { type: Object, required: true },
    refunds: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
});

const active = ref('payments');

const pendingRefunds = computed(() => props.refunds.filter((r) => r.status === 'pending').length);

const filterBy = (status) =>
    router.get(
        '/admin/payments',
        {
            status,
            // A new filter starts from the first page, not wherever the last
            // filter left the user.
            page: 1,
        },
        { preserveState: true, preserveScroll: true }
    );

/**
 * Two dialogs cover all reviews: a rejection has to carry a reason, so it opens
 * the prompt; an approval is a yes/no on the spot, so it asks for confirmation
 * instead of firing the moment the button is tapped.
 */
const prompt = ref(null);
const promptBusy = ref(false);
const confirm = ref(null);
const confirmBusy = ref(false);

const closePrompt = () => {
    prompt.value = null;
    promptBusy.value = false;
};
const closeConfirm = () => {
    confirm.value = null;
    confirmBusy.value = false;
};

const confirmPrompt = (reason) => {
    promptBusy.value = true;
    prompt.value.onConfirm(reason);
};

const confirmDecision = () => {
    confirmBusy.value = true;
    confirm.value.onConfirm();
};

const post = (url, payload) =>
    router.post(url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            closePrompt();
            closeConfirm();
        },
        onFinish: () => {
            promptBusy.value = false;
            confirmBusy.value = false;
        },
    });

/** Money decisions get the confirmation; only a rejection asks why. */
const verifyPayment = (payment) => {
    confirm.value = {
        title: 'Verify this payment?',
        description: `${payment.participant} paid PHP ${payment.amount} for “${payment.training}”.`,
        confirmLabel: 'Verify payment',
        onConfirm: () =>
            post(`/admin/payments/${payment.id}/review`, { decision: 'verified', remarks: null }),
    };
};

const rejectPayment = (payment) => {
    prompt.value = {
        title: 'Reject this payment',
        description: 'The participant sees this reason and can submit a corrected proof of payment.',
        label: 'Reason for rejection',
        confirmLabel: 'Reject payment',
        minLength: 10,
        onConfirm: (remarks) => post(`/admin/payments/${payment.id}/review`, { decision: 'rejected', remarks }),
    };
};

const approveRefund = (refund) => {
    confirm.value = {
        title: 'Approve this refund?',
        description: `PHP ${refund.amount} will be returned to ${refund.participant} for “${refund.training}”.`,
        confirmLabel: 'Approve refund',
        onConfirm: () =>
            post(`/admin/refunds/${refund.id}/review`, { decision: 'approved', remarks: null }),
    };
};

const rejectRefund = (refund) => {
    prompt.value = {
        title: 'Decline this refund',
        description: `The participant is shown this reason for the declined PHP ${refund.amount} refund.`,
        label: 'Reason for declining',
        confirmLabel: 'Decline refund',
        minLength: 10,
        onConfirm: (remarks) => post(`/admin/refunds/${refund.id}/review`, { decision: 'rejected', remarks }),
    };
};
</script>

<template>
    <Head title="Payments" />

    <AuthenticatedLayout title="Payments" current="admin-payments">
        <div class="mx-auto max-w-6xl space-y-5">
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

                <AppCard title="Payment Verification" :padded="payments.data.length > 0">
                    <AppEmptyState
                        v-if="!payments.data.length"
                        title="Nothing to verify"
                        description="Payments submitted by participants appear here."
                        icon="card"
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
                                    <AppButton size="sm" @click="verifyPayment(payment)">
                                        Verify
                                    </AppButton>
                                    <AppButton size="sm" variant="ghost" @click="rejectPayment(payment)">
                                        Reject
                                    </AppButton>
                                </template>
                            </div>
                        </li>
                    </ul>
                </AppCard>

                <AppPagination :pagination="payments" label="payments" class="pt-1" />
            </template>

            <AppCard v-else title="Refund Requests" :padded="refunds.length > 0">
                <AppEmptyState
                    v-if="!refunds.length"
                    title="No refund requests"
                    description="Claims against verified payments appear here."
                    icon="arrow-left"
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
                            <AppButton size="sm" @click="approveRefund(refund)">
                                Approve Refund
                            </AppButton>
                            <AppButton size="sm" variant="ghost" @click="rejectRefund(refund)">
                                Decline
                            </AppButton>
                        </div>
                    </li>
                </ul>
            </AppCard>
        </div>

        <AppPromptModal
            :open="prompt !== null"
            :title="prompt?.title ?? ''"
            :description="prompt?.description"
            :label="prompt?.label"
            :confirm-label="prompt?.confirmLabel"
            :min-length="prompt?.minLength ?? 1"
            :processing="promptBusy"
            @confirm="confirmPrompt"
            @close="closePrompt"
        />

        <AppConfirmModal
            :open="confirm !== null"
            :title="confirm?.title ?? ''"
            :description="confirm?.description"
            :confirm-label="confirm?.confirmLabel"
            :processing="confirmBusy"
            @confirm="confirmDecision"
            @close="closeConfirm"
        />
    </AuthenticatedLayout>
</template>
