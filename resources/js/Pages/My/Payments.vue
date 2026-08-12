<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

const props = defineProps({
    payments: { type: Array, required: true },
    awaitingPayment: { type: Array, required: true },
    methods: { type: Array, required: true },
});

const page = usePage();
const flash = computed(() => page.props.flash?.success);

const paying = ref(null);

const form = useForm({
    amount: '',
    payment_method: 'online',
    reference_number: '',
    payment_date: '',
    proof: null,
});

// Cash is settled over the counter against a receipt; every other method has a
// reference that is the only proof there is.
const needsReference = computed(() => form.payment_method !== 'cash');

const startPaying = (item) => {
    paying.value = item.registration_id;
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

const requestRefund = (payment) => {
    const reason = window.prompt('Why are you requesting a refund? (at least 10 characters)');

    if (!reason) {
        return;
    }

    router.post(`/my/payments/${payment.id}/refund`, { reason }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Payments" />

    <AuthenticatedLayout title="Payments" current="payments">
        <div class="mx-auto max-w-3xl space-y-5">
            <AppAlert v-if="flash" tone="success">{{ flash }}</AppAlert>

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
                                <p class="font-semibold text-csc-ink">{{ item.training }}</p>
                                <p class="mt-0.5 text-sm text-csc-ink/60">PHP {{ item.amount }}</p>
                            </div>
                            <AppButton
                                v-if="paying !== item.registration_id"
                                size="sm"
                                @click="startPaying(item)"
                            >
                                Record Payment
                            </AppButton>
                        </div>

                        <form
                            v-if="paying === item.registration_id"
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

                                <div>
                                    <label
                                        for="method"
                                        class="mb-1.5 block text-sm font-medium text-csc-ink"
                                    >
                                        Method <span class="text-csc-red-ink" aria-hidden="true">*</span>
                                    </label>
                                    <select
                                        id="method"
                                        v-model="form.payment_method"
                                        class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                                    >
                                        <option
                                            v-for="method in methods"
                                            :key="method.value"
                                            :value="method.value"
                                        >
                                            {{ method.label }}
                                        </option>
                                    </select>
                                </div>

                                <AppInput
                                    v-if="needsReference"
                                    v-model="form.reference_number"
                                    label="Reference Number"
                                    :error="form.errors.reference_number"
                                    required
                                />

                                <AppInput
                                    v-model="form.payment_date"
                                    label="Date Paid"
                                    type="date"
                                    :error="form.errors.payment_date"
                                    required
                                />
                            </div>

                            <div>
                                <label for="proof" class="mb-1.5 block text-sm font-medium text-csc-ink">
                                    Proof of Payment
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
                                <AppButton type="submit" :loading="form.processing">Submit</AppButton>
                            </div>
                        </form>
                    </li>
                </ul>
            </AppCard>

            <!-- History -->
            <AppCard title="My Payments" :padded="!payments.length">
                <AppEmptyState
                    v-if="!payments.length"
                    title="No payments recorded"
                    description="Payments you submit appear here with their verification status."
                    icon="M3 10h18M5 6h14a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z"
                />

                <ul v-else class="space-y-3">
                    <li v-for="payment in payments" :key="payment.id" class="rounded-lg border border-csc-line p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-csc-ink">{{ payment.training }}</p>
                                <p class="mt-0.5 text-sm text-csc-ink/60">
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
                                @click="requestRefund(payment)"
                            >
                                Request Refund
                            </AppButton>
                            <span v-else-if="payment.refund_status" class="text-xs text-csc-ink/55">
                                Refund: {{ payment.refund_status }}
                            </span>
                        </div>
                    </li>
                </ul>
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
