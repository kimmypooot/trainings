<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppInput from '@/Components/AppInput.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppModal from '@/Components/AppModal.vue';
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

// Everything still moving, not just the untouched ones — a claim parked at MSD
// is as much outstanding work as one nobody has looked at yet.
const pendingRefunds = computed(() => props.refunds.filter((r) => r.can_act).length);

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

/*
 * Verifying is a short form rather than a bare confirmation: the officer is
 * holding the official receipt at that moment, and capturing its number then is
 * the only time it is free. Chasing it later means reopening a settled payment.
 *
 * The OR fields stay optional — a promissory note is verified without one,
 * because no receipt exists until the money actually arrives.
 */
const verifying = ref(null);

const verifyForm = useForm({
    decision: 'verified',
    remarks: '',
    or_number: '',
    or_date: new Date().toISOString().slice(0, 10),
});

const startVerifying = (payment) => {
    verifying.value = payment;
    verifyForm.reset();
    verifyForm.clearErrors();
};

const submitVerify = () =>
    verifyForm.post(`/admin/payments/${verifying.value.id}/review`, {
        preserveScroll: true,
        onSuccess: () => {
            verifying.value = null;
            verifyForm.reset();
        },
    });

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

// Only ever one forward move is offered, and the server checks the stage we
// send against the live one — so a tab left open overnight cannot skip a claim
// past a stage someone else already moved it through.
const advanceRefund = (refund) => {
    confirm.value = {
        title: `Move to ${refund.next_stage.label}?`,
        description:
            refund.next_stage.value === 'refunded'
                ? `This records PHP ${refund.amount} as released to ${refund.participant}. It cannot be undone.`
                : `${refund.request_code} moves to ${refund.next_stage.label}. ${refund.participant} is notified.`,
        confirmLabel: `Move to ${refund.next_stage.label}`,
        onConfirm: () =>
            post(`/admin/refunds/${refund.id}/review`, {
                decision: 'advance',
                target: refund.next_stage.value,
            }),
    };
};

const rejectRefund = (refund) => {
    prompt.value = {
        title: 'Decline this refund',
        description: `The participant is shown this reason for the declined PHP ${refund.amount} refund.`,
        label: 'Reason for declining',
        confirmLabel: 'Decline refund',
        minLength: 10,
        onConfirm: (reason) =>
            post(`/admin/refunds/${refund.id}/review`, {
                decision: 'reject',
                rejection_reason: reason,
            }),
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
                                    <p v-if="payment.charge_to" class="text-xs text-csc-ink/55">
                                        Charged to: {{ payment.charge_to }}
                                    </p>
                                </div>
                                <AppBadge :status="payment.status" />
                            </div>

                            <p v-if="payment.rejection_reason" class="mt-3 text-sm text-csc-red-ink">
                                {{ payment.rejection_reason }}
                            </p>

                            <!-- The receipt record finance reconciles against. -->
                            <p v-if="payment.or_number" class="mt-2 text-sm text-csc-ink">
                                <span class="text-csc-ink/55">OR</span>
                                <span class="font-mono">{{ payment.or_number }}</span>
                                <span v-if="payment.or_date" class="text-csc-ink/55">
                                    · {{ payment.or_date }}
                                </span>
                                <span v-if="payment.collecting_officer" class="text-csc-ink/55">
                                    · issued by {{ payment.collecting_officer }}
                                </span>
                            </p>

                            <p v-if="payment.verified_by" class="mt-1.5 text-xs text-csc-ink/55">
                                Reviewed by {{ payment.verified_by }}
                            </p>

                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <a
                                    v-if="payment.proof_url"
                                    :href="payment.proof_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-2 rounded-lg border border-csc-blue/30 px-4 py-2 text-sm font-semibold text-csc-blue transition-colors duration-150 hover:border-csc-blue hover:bg-csc-blue-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                >
                                    <AppIcon name="eye" size="sm" class="shrink-0" />
                                    View proof
                                </a>

                                <template v-if="payment.status === 'pending'">
                                    <AppButton size="sm" icon="check" @click="startVerifying(payment)">
                                        Verify
                                    </AppButton>
                                    <AppButton size="sm" variant="ghost" icon="close" @click="rejectPayment(payment)">
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
                                <p class="font-semibold text-csc-ink">
                                    {{ refund.participant }}
                                    <span class="ml-1 font-mono text-xs font-normal text-csc-ink/50">
                                        {{ refund.request_code }}
                                    </span>
                                </p>
                                <p class="mt-0.5 text-sm text-csc-ink/60">{{ refund.training }}</p>
                                <p class="mt-1 text-sm text-csc-ink">PHP {{ refund.amount }}</p>
                            </div>
                            <AppBadge :status="refund.status" />
                        </div>

                        <p class="mt-3 text-sm text-csc-ink/80">{{ refund.reason }}</p>

                        <!-- What MSD needs to actually release the money. -->
                        <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                            <div class="flex gap-2">
                                <dt class="text-csc-ink/55">Account name</dt>
                                <dd class="text-csc-ink">{{ refund.account_name || '—' }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-csc-ink/55">Bank</dt>
                                <dd class="text-csc-ink">{{ refund.bank_name || '—' }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-csc-ink/55">Account no.</dt>
                                <dd class="font-mono text-csc-ink">{{ refund.account_number || '—' }}</dd>
                            </div>
                            <div v-if="refund.proof_url" class="flex gap-2">
                                <dt class="text-csc-ink/55">Proof</dt>
                                <dd>
                                    <a
                                        :href="refund.proof_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1.5 rounded text-sm font-medium text-csc-blue underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                    >
                                        View attachment
                                        <AppIcon name="eye" size="sm" />
                                    </a>
                                </dd>
                            </div>
                        </dl>

                        <p v-if="refund.rejection_reason" class="mt-2 text-sm text-csc-red-ink">
                            Declined: {{ refund.rejection_reason }}
                        </p>

                        <!--
                            The trail, not just the latest remark. On a claim
                            that has crossed two units, "who moved this and
                            when" is the question that actually gets asked.
                        -->
                        <details v-if="refund.trail.length > 1" class="mt-3">
                            <summary
                                class="cursor-pointer rounded text-xs font-medium text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            >
                                History ({{ refund.trail.length }})
                            </summary>
                            <ol class="mt-2 space-y-1.5 border-l-2 border-csc-line pl-3">
                                <li v-for="(entry, index) in refund.trail" :key="index" class="text-xs">
                                    <span class="font-medium text-csc-ink">{{ entry.to }}</span>
                                    <span class="text-csc-ink/55"> · {{ entry.actor }} · {{ entry.at }}</span>
                                    <p v-if="entry.notes" class="text-csc-ink/70">{{ entry.notes }}</p>
                                </li>
                            </ol>
                        </details>

                        <div v-if="refund.can_act" class="mt-4 flex flex-wrap gap-2">
                            <AppButton
                                v-if="refund.next_stage"
                                size="sm"
                                icon="check"
                                @click="advanceRefund(refund)"
                            >
                                Move to {{ refund.next_stage.label }}
                            </AppButton>
                            <AppButton size="sm" variant="ghost" icon="close" @click="rejectRefund(refund)">
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

        <AppModal
            :open="verifying !== null"
            title="Verify this payment"
            :subtitle="
                verifying
                    ? `${verifying.participant} paid PHP ${verifying.amount} for “${verifying.training}”.`
                    : undefined
            "
            @close="verifying = null"
        >
            <form class="space-y-4" @submit.prevent="submitVerify">
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput
                        v-model="verifyForm.or_number"
                        label="OR number"
                        hint="Leave blank for a promissory note — there is no receipt yet."
                        :error="verifyForm.errors.or_number"
                    />
                    <AppInput
                        v-model="verifyForm.or_date"
                        label="OR date"
                        type="date"
                        :error="verifyForm.errors.or_date"
                    />
                </div>

                <AppInput
                    v-model="verifyForm.remarks"
                    label="Remarks"
                    hint="Optional. Recorded against the payment."
                    :error="verifyForm.errors.remarks"
                />

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="verifying = null">Cancel</AppButton>
                    <AppButton type="submit" icon="check" :processing="verifyForm.processing">
                        Verify payment
                    </AppButton>
                </div>
            </form>
        </AppModal>
    </AuthenticatedLayout>
</template>
