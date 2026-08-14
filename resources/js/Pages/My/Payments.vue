<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppInput from '@/Components/AppInput.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppFileField from '@/Components/AppFileField.vue';
import AppModal from '@/Components/AppModal.vue';

const props = defineProps({
    payments: { type: Array, required: true },
    awaitingPayment: { type: Array, required: true },
    methods: { type: Array, required: true },
});

const paying = ref(null);

const form = useForm({
    amount: '',
    payment_method: 'online',
    reference_number: '',
    payment_date: '',
    proof: null,
});

// Cash is settled over the counter against a receipt, and a promissory note is
// its own document; every other method has a reference that is the only proof
// there is.
const needsReference = computed(() => !['cash', 'promissory'].includes(form.payment_method));

const isPromissory = computed(() => form.payment_method === 'promissory');

// Offered only where the training was published as accepting one. The server
// applies the same rule — this keeps the option from appearing where it would
// only be rejected.
const availableMethods = computed(() =>
    props.methods.filter((method) => method.value !== 'promissory' || paying.value?.accepts_promissory)
);

const startPaying = (item) => {
    paying.value = item;
    form.reset();
    form.amount = item.amount;
};

const submit = () =>
    form.post(`/my/registrations/${paying.value}/payments`, {
        forceFormData: true,
        onSuccess: () => {
            paying.value = null;
            form.reset();
        },
    });

const refunding = ref(null);
const refundConfirming = ref(null);

// Money is shown with thousand separators and two decimals wherever it
// appears — a fee is a currency amount, not a bare integer.
const money = (value) =>
    Number(value).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

// A refund claim is a disbursement instruction, not just a complaint — MSD
// pays by bank transfer, so the payee block and the original receipt are as
// mandatory as the reason.
const refundForm = useForm({
    reason: '',
    account_name: '',
    bank_name: '',
    account_number: '',
    proof: null,
});

// Requesting a refund first surfaces the receipt-return notice. Only once the
// participant confirms it are they taken into the claim form.
const startRefund = (payment) => {
    refundConfirming.value = payment;
    refundForm.reset();
    refundForm.clearErrors();
};

const closeRefundConfirm = () => {
    refundConfirming.value = null;
};

const confirmRefund = () => {
    refunding.value = refundConfirming.value;
    refundConfirming.value = null;
};

const closeRefund = () => {
    refunding.value = null;
    refundForm.reset();
};

const submitRefund = () =>
    refundForm.post(`/my/payments/${refunding.value.id}/refund`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: closeRefund,
    });
</script>

<template>
    <Head title="Payments" />

    <AuthenticatedLayout title="Payments" current="payments">
        <div class="mx-auto max-w-3xl space-y-5">
            <!-- Owed -->
            <AppCard v-if="awaitingPayment.length" title="Awaiting Payment">
                <ul class="space-y-3">
                    <li
                        v-for="item in awaitingPayment"
                        :key="item.registration_id"
                        class="rounded-lg border border-csc-line p-4"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-csc-ink">{{ item.training.title }}</p>
                                <p class="mt-0.5 text-sm text-csc-ink/60">PHP {{ money(item.amount) }}</p>
                                <p class="text-xs text-csc-ink/55">
                                    {{ item.training.starts_at }}
                                    <span v-if="item.training.mode_label">· {{ item.training.mode_label }}</span>
                                </p>
                            </div>
                            <AppButton
                                v-if="paying?.registration_id !== item.registration_id"
                                size="sm"
                                icon="plus"
                                @click="startPaying(item)"
                            >
                                Record Payment
                            </AppButton>
                        </div>

                        <form
                            v-if="paying?.registration_id === item.registration_id"
                            class="mt-5 grid gap-5 border-t border-csc-line pt-5"
                            novalidate
                            @submit.prevent="submit"
                        >
                            <div class="grid gap-5 sm:grid-cols-2">
                                <AppInput
                                    v-model="form.amount"
                                    label="Amount Paid"
                                    type="number"
                                    :error="form.errors.amount"
                                    required
                                />

                                <AppSelect
                                    v-model="form.payment_method"
                                    label="Method"
                                    :options="availableMethods"
                                    :error="form.errors.payment_method"
                                    required
                                />

                                <AppInput
                                    v-if="needsReference"
                                    v-model="form.reference_number"
                                    label="Reference Number"
                                    :error="form.errors.reference_number"
                                    required
                                />

                                <AppInput
                                    v-model="form.payment_date"
                                    :label="isPromissory ? 'Date Signed' : 'Date Paid'"
                                    type="date"
                                    :error="form.errors.payment_date"
                                    required
                                />
                            </div>

                            <AppAlert v-if="isPromissory" tone="info">
                                A promissory note secures your slot and gives you access to the training,
                                including the join link for online sessions. Your certificate is held until
                                the fee is paid and verified.
                            </AppAlert>

                            <div>
                                <label for="proof" class="mb-1.5 block text-sm font-medium text-csc-ink">
                                    {{ isPromissory ? 'Signed Promissory Note' : 'Proof of Payment' }}
                                </label>
                                <input
                                    id="proof"
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink file:mr-3 file:rounded file:border-0 file:bg-csc-blue-tint file:px-3 file:py-1.5 file:text-sm file:text-csc-blue"
                                    @change="form.proof = $event.target.files[0]"
                                />
                                <p class="mt-1.5 text-xs text-csc-ink/60">
                                    PDF or image, up to 5 MB. Only you and CSC finance staff can open it.
                                </p>
                                <p v-if="form.errors.proof" class="mt-1.5 text-xs font-medium text-csc-red-ink">
                                    {{ form.errors.proof }}
                                </p>
                            </div>

                            <div class="flex flex-wrap justify-end gap-3">
                                <AppButton variant="ghost" type="button" @click="paying = null">
                                    Cancel
                                </AppButton>
                                <AppButton type="submit" :loading="form.processing" icon="check">Submit</AppButton>
                            </div>
                        </form>
                    </li>
                </ul>
            </AppCard>

            <!-- History -->
            <AppCard title="My Payments" :padded="payments.length > 0">
                <AppEmptyState
                    v-if="!payments.length"
                    title="No payments recorded"
                    description="Payments you submit appear here with their verification status."
                    icon="card"
                />

                <ul v-else class="space-y-3">
                    <li v-for="payment in payments" :key="payment.id" class="rounded-lg border border-csc-line p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-csc-ink">
                                    <a
                                        :href="payment.training.url"
                                        class="rounded hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                    >
                                        {{ payment.training.title }}
                                    </a>
                                </p>
                                <p class="mt-0.5 text-sm text-csc-ink/60">
                                    PHP {{ money(payment.amount) }} · {{ payment.method }} ·
                                    {{ payment.payment_date }}
                                </p>
                                <p v-if="payment.training.starts_at" class="text-xs text-csc-ink/55">
                                    {{ payment.training.starts_at }}
                                    <span v-if="payment.training.mode_label">· {{ payment.training.mode_label }}</span>
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

                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <a
                                v-if="payment.proof_url"
                                :href="payment.proof_url"
                                class="rounded text-sm font-medium text-csc-blue underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            >
                                View proof
                            </a>
                            <AppButton
                                v-if="payment.can_request_refund"
                                size="sm"
                                variant="ghost"
                                @click="startRefund(payment)"
                            >
                                Request Refund
                            </AppButton>
                        </div>

                        <!-- A claim in flight: where it is, and how far along. -->
                        <div
                            v-if="payment.refund"
                            class="mt-4 rounded-lg border border-csc-line bg-csc-mist/40 p-3"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-medium text-csc-ink">
                                    Refund {{ payment.refund.request_code }}
                                    <span class="text-csc-ink/55">· PHP {{ money(payment.refund.amount) }}</span>
                                </p>
                                <AppBadge :status="payment.refund.status" />
                            </div>

                            <p class="mt-1.5 text-sm text-csc-ink/75">{{ payment.refund.message }}</p>

                            <p
                                v-if="payment.refund.rejection_reason"
                                class="mt-1.5 text-sm text-csc-red-ink"
                            >
                                {{ payment.refund.rejection_reason }}
                            </p>

                            <!--
                                The stage track. Status is never colour-alone
                                here either: a reached stage is named and
                                marked, not merely tinted.
                            -->
                            <ol
                                v-if="payment.refund.stages.length"
                                class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5"
                            >
                                <li
                                    v-for="stage in payment.refund.stages"
                                    :key="stage.label"
                                    class="flex items-center gap-1.5 text-xs"
                                    :class="stage.reached ? 'text-csc-ink' : 'text-csc-ink/40'"
                                >
                                    <AppIcon
                                        :name="stage.reached ? 'check' : 'clock'"
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {{ stage.label }}
                                </li>
                            </ol>
                        </div>
                    </li>
                </ul>
            </AppCard>
        </div>

        <AppModal
            :open="refunding !== null"
            title="Request a refund"
            :subtitle="
                refunding
                    ? `PHP ${money(refunding.amount)} for “${refunding.training.title}”. CSC reviews the claim, then Management Services releases the transfer.`
                    : undefined
            "
            @close="closeRefund"
        >
            <form class="space-y-4" @submit.prevent="submitRefund">
                <AppTextarea
                    v-model="refundForm.reason"
                    label="Why are you requesting a refund?"
                    hint="At least 10 characters, so finance can act on it without coming back to you."
                    :error="refundForm.errors.reason"
                    required
                />

                <fieldset class="space-y-4 rounded-lg border border-csc-line p-4">
                    <legend class="px-1.5 text-sm font-medium text-csc-ink">
                        Where should the refund be sent?
                    </legend>

                    <AppInput
                        v-model="refundForm.account_name"
                        label="Account name"
                        hint="Exactly as it appears on the account — a mismatch will bounce the transfer."
                        :error="refundForm.errors.account_name"
                        required
                    />
                    <AppInput
                        v-model="refundForm.bank_name"
                        label="Bank"
                        :error="refundForm.errors.bank_name"
                        required
                    />
                    <AppInput
                        v-model="refundForm.account_number"
                        label="Account number"
                        :error="refundForm.errors.account_number"
                        required
                    />
                </fieldset>

                <AppFileField
                    id="refund-proof"
                    label="Proof of the original payment"
                    hint="Your CSC official receipt or deposit slip. PDF, JPG or PNG, up to 5 MB."
                    accept=".pdf,.jpg,.jpeg,.png"
                    required
                    :error="refundForm.errors.proof"
                    @change="refundForm.proof = $event"
                />

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="closeRefund">Cancel</AppButton>
                    <AppButton type="submit" :processing="refundForm.processing">
                        Send request
                    </AppButton>
                </div>
            </form>
        </AppModal>

        <!--
            The receipt-return notice is its own step before the claim form: a
            refund is not processed without the original official receipt, so the
            participant is told that before they begin, not buried inside it.
        -->
        <AppModal
            :open="refundConfirming !== null"
            title="Return your official receipt"
            :subtitle="
                refundConfirming
                    ? `PHP ${money(refundConfirming.amount)} for “${refundConfirming.training.title}”.`
                    : undefined
            "
            @close="closeRefundConfirm"
        >
            <AppAlert tone="info">
                The original official receipt(s) must be returned to the CSC Regional Office VIII
                or to the nearest CSC Field Office / Satellite Office — they are required as
                attachment for the processing of your refund.
            </AppAlert>

            <div class="mt-5 flex justify-end gap-2">
                <AppButton type="button" variant="ghost" @click="closeRefundConfirm">Cancel</AppButton>
                <AppButton type="button" icon="check" @click="confirmRefund">Continue</AppButton>
            </div>
        </AppModal>
    </AuthenticatedLayout>
</template>
