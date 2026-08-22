<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
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
    physical_or_settings: { type: Object, default: null },
    physical_or_pipeline: { type: Array, default: () => [] },
    payment_settings: { type: Object, default: null },
});

const paying = ref(null);

const form = useForm({
    amount: '',
    payment_method: 'online',
    payment_date: '',
    proof: null,
});

const isPromissory = computed(() => form.payment_method === 'promissory');

// Whether a document is expected with this method — asked for, never demanded.
// A payment without one still goes through and is flagged for staff instead,
// so this drives the wording only. Read off the method list the server sent so
// the two cannot drift apart.
const proofExpected = computed(
    () => props.methods.find((method) => method.value === form.payment_method)?.expects_proof ?? false
);

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

// A physical copy of the official receipt: paid for with a GCash courier fee,
// proved with a screenshot, then prepared and shipped by CSC. Optional for
// everyone — this is the ask, the payment details, and the proof in one modal.
const requestingOr = ref(null);
const orForm = useForm({
    proof: null,
    notes: '',
});

const startPhysicalOr = (payment) => {
    requestingOr.value = payment;
    orForm.reset();
    orForm.clearErrors();
};

const closePhysicalOr = () => {
    requestingOr.value = null;
    orForm.reset();
};

const submitPhysicalOr = () =>
    orForm.post(`/my/payments/${requestingOr.value.id}/physical-or`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: closePhysicalOr,
    });
</script>

<template>
    <Head title="Payments" />

    <AuthenticatedLayout title="Payments" current="payments">
        <div class="mx-auto max-w-5xl space-y-5">
            <!-- Owed -->
            <AppCard v-if="awaitingPayment.length" title="Awaiting Payment">
                <!--
                    Where the money goes. The bank account is an admin-editable
                    setting, so it is rendered, never hard-coded.
                -->
                <div
                    v-if="payment_settings"
                    class="mb-4 rounded-lg border border-csc-line bg-csc-mist/40 p-3 text-sm"
                >
                    <p class="font-medium text-csc-ink">Deposit to</p>
                    <dl class="mt-1.5 grid gap-y-1 text-csc-ink-muted sm:grid-cols-2">
                        <div class="flex gap-2">
                            <dt class="w-28 shrink-0 text-csc-ink-subtle">Bank</dt>
                            <dd class="font-semibold text-csc-ink">{{ payment_settings.bank_name }}</dd>
                        </div>
                        <div class="flex gap-2">
                            <dt class="w-28 shrink-0 text-csc-ink-subtle">Account name</dt>
                            <dd class="text-csc-ink">{{ payment_settings.account_name }}</dd>
                        </div>
                        <div class="flex gap-2">
                            <dt class="w-28 shrink-0 text-csc-ink-subtle">Account no.</dt>
                            <dd class="font-mono font-semibold text-csc-ink">{{ payment_settings.account_number }}</dd>
                        </div>
                    </dl>
                    <p
                        v-if="payment_settings.instructions"
                        class="mt-2 border-t border-csc-line pt-2 leading-relaxed text-csc-ink-muted"
                    >
                        {{ payment_settings.instructions }}
                    </p>
                </div>

                <ul class="space-y-3">
                    <li
                        v-for="item in awaitingPayment"
                        :key="item.registration_id"
                        class="rounded-lg border border-csc-line p-4"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-csc-ink">{{ item.training.title }}</p>
                                <p class="mt-0.5 text-sm text-csc-ink-subtle">₱{{ money(item.amount) }}</p>
                                <p class="text-xs text-csc-ink-subtle">
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
                            <!--
                                The three answers, on one line. They fitted a
                                two-column grid awkwardly while the reference
                                number was among them; without it the row is
                                exactly what a payment is — how much, how, and
                                when.
                            -->
                            <div class="grid gap-5 sm:grid-cols-3">
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
                                <p class="mt-1.5 text-xs text-csc-ink-subtle">
                                    <template v-if="proofExpected">
                                        Please attach the transfer slip if you have it — it is what CSC
                                        matches against the bank statement. You can submit without one,
                                        and staff will follow up.
                                    </template>
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
                                <p class="mt-0.5 text-sm text-csc-ink-subtle">
                                    ₱{{ money(payment.amount) }} · {{ payment.method }} ·
                                    {{ payment.payment_date }}
                                </p>
                                <p v-if="payment.training.starts_at" class="text-xs text-csc-ink-subtle">
                                    {{ payment.training.starts_at }}
                                    <span v-if="payment.training.mode_label">· {{ payment.training.mode_label }}</span>
                                </p>
                                <p v-if="payment.reference_number" class="text-xs text-csc-ink-subtle">
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
                            <AppButton
                                v-if="payment.can_request_physical_or"
                                size="sm"
                                variant="ghost"
                                @click="startPhysicalOr(payment)"
                            >
                                Request Physical OR
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
                                    <span class="text-csc-ink-subtle">· ₱{{ money(payment.refund.amount) }}</span>
                                </p>
                                <AppBadge :status="payment.refund.status" />
                            </div>

                            <p class="mt-1.5 text-sm text-csc-ink-muted">{{ payment.refund.message }}</p>

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
                                    :class="stage.reached ? 'text-csc-ink' : 'text-csc-ink-subtle'"
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

                        <!-- A physical OR request in flight, rendered the same
                             way as a refund claim: where it is, and how far
                             along the delivery pipeline that is. -->
                        <div
                            v-if="payment.physical_or"
                            class="mt-4 rounded-lg border border-csc-line bg-csc-mist/40 p-3"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-medium text-csc-ink">
                                    Physical OR {{ payment.physical_or.request_code }}
                                    <span v-if="payment.physical_or.courier_name" class="text-csc-ink-subtle">
                                        · {{ payment.physical_or.courier_name }}
                                        <template v-if="payment.physical_or.tracking_number">
                                            {{ payment.physical_or.tracking_number }}
                                        </template>
                                    </span>
                                </p>
                                <AppBadge :status="payment.physical_or.status" />
                            </div>

                            <p class="mt-1.5 text-sm text-csc-ink-muted">{{ payment.physical_or.message }}</p>

                            <p
                                v-if="payment.physical_or.rejection_reason"
                                class="mt-1.5 text-sm text-csc-red-ink"
                            >
                                {{ payment.physical_or.rejection_reason }}
                            </p>

                            <ol
                                v-if="payment.physical_or.stages.length"
                                class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5"
                            >
                                <li
                                    v-for="stage in payment.physical_or.stages"
                                    :key="stage.label"
                                    class="flex items-center gap-1.5 text-xs"
                                    :class="stage.reached ? 'text-csc-ink' : 'text-csc-ink-subtle'"
                                >
                                    <AppIcon
                                        :name="stage.reached ? 'check' : 'clock'"
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {{ stage.label }}
                                </li>
                            </ol>

                            <Link
                                v-if="payment.physical_or.can_upload_proof"
                                href="/my/physical-or"
                                class="mt-3 inline-block rounded text-xs font-semibold text-csc-blue underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            >
                                Upload courier fee proof
                            </Link>
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
                    ? `₱${money(refunding.amount)} for “${refunding.training.title}”. CSC reviews the claim, then Management Services releases the transfer.`
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
                    ? `₱${money(refundConfirming.amount)} for “${refundConfirming.training.title}”.`
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

        <!--
            The physical OR request: delivery instructions and the GCash details
            first, then the fee proof. Everything the participant sees here is
            editable by Admin/Super Admin on the admin queue.
        -->
        <AppModal
            :open="requestingOr !== null"
            title="Request a physical official receipt"
            :subtitle="
                requestingOr
                    ? `A hard copy of the OR for “${requestingOr.training.title}” will be shipped to you.`
                    : undefined
            "
            @close="closePhysicalOr"
        >
            <form class="space-y-4" @submit.prevent="submitPhysicalOr">
                <AppAlert tone="info">
                    {{ physical_or_settings?.instructions ?? 'To have your official receipt delivered, please pay the courier fee to the GCash account below, then upload a screenshot of your transaction.' }}
                </AppAlert>

                <div class="rounded-lg border border-csc-line bg-csc-mist/40 p-3 text-sm">
                    <p class="font-medium text-csc-ink">Payment details</p>
                    <dl class="mt-1.5 grid gap-y-1 text-csc-ink-muted">
                        <div class="flex gap-2">
                            <dt class="w-28 shrink-0 text-csc-ink-subtle">Courier fee</dt>
                            <dd class="font-semibold text-csc-ink">₱{{ money(physical_or_settings?.courier_fee ?? 200) }}</dd>
                        </div>
                        <div class="flex gap-2">
                            <dt class="w-28 shrink-0 text-csc-ink-subtle">GCash</dt>
                            <dd class="font-mono font-semibold text-csc-ink">{{ physical_or_settings?.gcash_number }}</dd>
                        </div>
                        <div class="flex gap-2">
                            <dt class="w-28 shrink-0 text-csc-ink-subtle">Account name</dt>
                            <dd class="text-csc-ink">{{ physical_or_settings?.account_name }}</dd>
                        </div>
                    </dl>
                </div>

                <AppFileField
                    id="physical-or-proof"
                    label="Screenshot of the GCash transaction"
                    hint="Proof of the courier fee payment. PDF, JPG or PNG, up to 5 MB."
                    accept=".pdf,.jpg,.jpeg,.png"
                    :error="orForm.errors.proof"
                    @change="orForm.proof = $event"
                />

                <AppTextarea
                    v-model="orForm.notes"
                    label="Delivery notes"
                    hint="Optional — anything the courier should know."
                    :error="orForm.errors.notes"
                />

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="closePhysicalOr">Cancel</AppButton>
                    <AppButton type="submit" icon="check" :processing="orForm.processing">
                        Submit request
                    </AppButton>
                </div>
            </form>
        </AppModal>
    </AuthenticatedLayout>
</template>
