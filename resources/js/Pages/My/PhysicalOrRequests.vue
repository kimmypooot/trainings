<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppFileField from '@/Components/AppFileField.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppModal from '@/Components/AppModal.vue';

const props = defineProps({
    requests: { type: Array, required: true },
    pipeline: { type: Array, required: true },
    gcash: { type: Object, required: true },
});

const money = (value) =>
    Number(value).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const openCount = computed(() => props.requests.filter((request) => !['delivered', 'rejected'].includes(request.status)).length);

// Uploading the courier fee proof, and cancelling the request — both are the
// participant's remaining moves, and each gets its own tiny dialog.
const uploading = ref(null);
const uploadForm = useForm({ proof: null });

const startUpload = (request) => {
    uploading.value = request;
    uploadForm.reset();
    uploadForm.clearErrors();
};

const submitUpload = () =>
    uploadForm.post(`/my/physical-or/${uploading.value.id}/proof`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploading.value = null;
            uploadForm.reset();
        },
    });

const cancelling = ref(null);
const cancelBusy = ref(false);

const cancelRequest = (request) => {
    cancelling.value = request;
};

const confirmCancel = () => {
    cancelBusy.value = true;
    useForm().post(`/my/physical-or/${cancelling.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null;
            cancelBusy.value = false;
        },
        onFinish: () => {
            cancelBusy.value = false;
        },
    });
};
</script>

<template>
    <Head title="Physical OR Requests" />

    <AuthenticatedLayout title="Physical OR Requests" current="physical-or">
        <div class="mx-auto max-w-4xl space-y-5">
            <AppCard title="Physical OR Requests" :padded="requests.length > 0">
                <AppEmptyState
                    v-if="!requests.length"
                    title="No physical OR requests"
                    description="Requests for a physical copy of your official receipt appear here. Ask from your Payments page on a verified payment."
                    icon="document"
                />

                <ul v-else class="space-y-3">
                    <li v-for="request in requests" :key="request.id" class="rounded-lg border border-csc-line p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-csc-ink">
                                    {{ request.payment.training }}
                                    <span class="ml-1 font-mono text-xs font-normal text-csc-ink-subtle">
                                        {{ request.request_code }}
                                    </span>
                                </p>
                                <p class="mt-0.5 text-sm text-csc-ink-subtle">
                                    OR {{ request.payment.or_number }} ·
                                    Courier fee ₱{{ money(request.courier_fee) }}
                                </p>
                            </div>
                            <AppBadge :status="request.status" />
                        </div>

                        <p class="mt-3 text-sm text-csc-ink-muted">{{ request.message }}</p>

                        <p v-if="request.rejection_reason" class="mt-1.5 text-sm text-csc-red-ink">
                            {{ request.rejection_reason }}
                        </p>

                        <!-- The stage track. Status is never colour-alone here
                             either: a reached stage is named and marked. -->
                        <ol v-if="request.stages.length" class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5">
                            <li
                                v-for="stage in request.stages"
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

                        <!-- Shipping block: what the courier was and when it moved. -->
                        <dl v-if="request.courier_name" class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                            <div class="flex gap-2">
                                <dt class="text-csc-ink-subtle">Courier</dt>
                                <dd class="text-csc-ink">{{ request.courier_name }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-csc-ink-subtle">Tracking no.</dt>
                                <dd class="font-mono text-csc-ink">{{ request.tracking_number || '—' }}</dd>
                            </div>
                            <div v-if="request.shipped_at" class="flex gap-2">
                                <dt class="text-csc-ink-subtle">Shipped</dt>
                                <dd class="text-csc-ink">{{ request.shipped_at }}</dd>
                            </div>
                            <div v-if="request.delivered_at" class="flex gap-2">
                                <dt class="text-csc-ink-subtle">Delivered</dt>
                                <dd class="text-csc-ink">{{ request.delivered_at }}</dd>
                            </div>
                        </dl>

                        <div v-if="request.can_upload_proof || request.can_cancel" class="mt-4 flex flex-wrap gap-2">
                            <AppButton v-if="request.can_upload_proof" size="sm" icon="upload" @click="startUpload(request)">
                                Upload courier fee proof
                            </AppButton>
                            <AppButton v-if="request.can_cancel" size="sm" variant="ghost" icon="close" @click="cancelRequest(request)">
                                Cancel request
                            </AppButton>
                        </div>
                    </li>
                </ul>
            </AppCard>
        </div>

        <AppModal
            :open="uploading !== null"
            title="Upload courier fee proof"
            :subtitle="uploading ? `GCash proof of the ₱${money(uploading.courier_fee)} courier fee for ${uploading.request_code}.` : undefined"
            @close="uploading = null"
        >
            <div class="rounded-lg border border-csc-line bg-csc-mist/40 p-3 text-sm text-csc-ink-muted">
                <p class="font-medium text-csc-ink">GCash</p>
                <p class="mt-1">
                    Send the courier fee to <span class="font-mono font-semibold text-csc-ink">{{ gcash.number }}</span>
                    ({{ gcash.account_name }}).
                </p>
                <p class="mt-1 text-csc-ink-subtle">{{ gcash.instructions }}</p>
            </div>

            <form class="mt-4 space-y-4" @submit.prevent="submitUpload">
                <AppFileField
                    id="physical-or-proof"
                    label="Screenshot of the GCash transaction"
                    hint="PDF, JPG or PNG, up to 5 MB."
                    accept=".pdf,.jpg,.jpeg,.png"
                    required
                    :error="uploadForm.errors.proof"
                    @change="uploadForm.proof = $event"
                />

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="uploading = null">Cancel</AppButton>
                    <AppButton type="submit" :processing="uploadForm.processing" icon="upload">
                        Upload proof
                    </AppButton>
                </div>
            </form>
        </AppModal>

        <AppConfirmModal
            :open="cancelling !== null"
            title="Cancel this request?"
            :description="cancelling ? `Your request ${cancelling.request_code} will be cancelled. This cannot be undone.` : undefined"
            confirm-label="Cancel request"
            :processing="cancelBusy"
            @confirm="confirmCancel"
            @close="cancelling = null"
        />
    </AuthenticatedLayout>
</template>