<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppInput from '@/Components/AppInput.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import AppModal from '@/Components/AppModal.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppFileField from '@/Components/AppFileField.vue';

const props = defineProps({
    requests: { type: Array, required: true },
    defaultAgency: { type: String, default: null },
    completionKinds: { type: Array, default: () => [] },
});

// --- New request -----------------------------------------------------------

const composing = ref(false);

const form = useForm({
    agency_name: props.defaultAgency ?? '',
    training_title: '',
    proposed_start: '',
    proposed_end: '',
    proposed_venue: '',
    expected_participants: '',
    request_letter: null,
});

const submit = () =>
    form.post('/my/agency-requests', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            composing.value = false;
            form.reset();
            form.agency_name = props.defaultAgency ?? '';
        },
    });

// --- Returning the signed confirmation --------------------------------------

const confirming = ref(null);

const confirmForm = useForm({
    confirmed_start: '',
    confirmed_end: '',
    confirmed_venue: '',
    signed_confirmation_form: null,
});

const startConfirming = (request) => {
    confirming.value = request;
    confirmForm.reset();
    confirmForm.clearErrors();
    // Pre-filled with what was proposed: most confirmations agree to the dates
    // as asked, and retyping them is where transcription slips come from.
    confirmForm.confirmed_venue = request.proposed_venue;
};

const submitConfirmation = () =>
    confirmForm.post(`/my/agency-requests/${confirming.value.id}/confirmation`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            confirming.value = null;
            confirmForm.reset();
        },
    });

// --- Post-training documents ------------------------------------------------

const completing = ref(null);

const completionForm = useForm({
    certificate_of_duties: null,
    attendance_sheet: null,
    attendance_list: null,
    proof_of_payment: null,
    payment_amount: '',
});

const startCompleting = (request) => {
    completing.value = request;
    completionForm.reset();
    completionForm.clearErrors();
};

const submitCompletion = () =>
    completionForm.post(`/my/agency-requests/${completing.value.id}/completion`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            completing.value = null;
            completionForm.reset();
        },
    });

// --- Withdrawing ------------------------------------------------------------

const cancelling = ref(null);

const cancelForm = useForm({ reason: '' });

const startCancelling = (request) => {
    cancelling.value = request;
    cancelForm.reset();
    cancelForm.clearErrors();
};

const submitCancel = () =>
    cancelForm.post(`/my/agency-requests/${cancelling.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null;
            cancelForm.reset();
        },
    });
</script>

<template>
    <Head title="Agency Requests" />

    <AuthenticatedLayout title="Agency Requests" current="agency-requests">
        <div class="mx-auto max-w-4xl space-y-5">
            <AppCard
                title="Request a Training for Your Agency"
                subtitle="Ask CSC to conduct a training for your own staff. Attach your agency's letter of request."
            >
                <AppButton v-if="!composing" icon="plus" @click="composing = true">
                    New Request
                </AppButton>

                <form v-else class="grid gap-5" @submit.prevent="submit">
                    <AppInput
                        v-model="form.agency_name"
                        label="Agency"
                        hint="Leave as-is unless you are filing on behalf of another office."
                        :error="form.errors.agency_name"
                    />

                    <AppInput
                        v-model="form.training_title"
                        label="Training being requested"
                        :error="form.errors.training_title"
                        required
                    />

                    <div class="grid gap-5 sm:grid-cols-2">
                        <AppInput
                            v-model="form.proposed_start"
                            label="Proposed start"
                            type="date"
                            :error="form.errors.proposed_start"
                            required
                        />
                        <AppInput
                            v-model="form.proposed_end"
                            label="Proposed end"
                            type="date"
                            :error="form.errors.proposed_end"
                            required
                        />
                    </div>

                    <AppInput
                        v-model="form.proposed_venue"
                        label="Proposed venue"
                        :error="form.errors.proposed_venue"
                        required
                    />

                    <AppInput
                        v-model="form.expected_participants"
                        label="Expected number of participants"
                        type="number"
                        min="1"
                        :error="form.errors.expected_participants"
                    />

                    <AppFileField
                        id="request-letter"
                        label="Letter of request"
                        hint="Signed by your head of office. PDF or Word, up to 10 MB."
                        accept=".pdf,.doc,.docx"
                        required
                        :error="form.errors.request_letter"
                        @change="form.request_letter = $event"
                    />

                    <div class="flex justify-end gap-2">
                        <AppButton type="button" variant="ghost" @click="composing = false">
                            Cancel
                        </AppButton>
                        <AppButton type="submit" :loading="form.processing">Submit Request</AppButton>
                    </div>
                </form>
            </AppCard>

            <AppCard title="Your Requests" :padded="requests.length > 0">
                <AppEmptyState
                    v-if="!requests.length"
                    title="No requests yet"
                    description="Requests you file on behalf of your agency appear here."
                    icon="document"
                />

                <ul v-else class="space-y-4">
                    <li
                        v-for="request in requests"
                        :key="request.id"
                        class="rounded-lg border border-csc-line p-4"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-csc-ink">
                                    {{ request.training_title }}
                                    <span class="ml-1 font-mono text-xs font-normal text-csc-ink/50">
                                        {{ request.request_code }}
                                    </span>
                                </p>
                                <p class="mt-0.5 text-sm text-csc-ink/60">{{ request.agency_name }}</p>
                                <p class="mt-1 text-sm text-csc-ink/75">
                                    {{ request.confirmed_start ?? request.proposed_start }} –
                                    {{ request.confirmed_end ?? request.proposed_end }}
                                    · {{ request.confirmed_venue ?? request.proposed_venue }}
                                    <span v-if="request.confirmed_start" class="text-csc-ink/55">(confirmed)</span>
                                </p>
                            </div>
                            <AppBadge :status="request.status" :label="request.status_label" />
                        </div>

                        <p class="mt-3 text-sm text-csc-ink/75">{{ request.message }}</p>

                        <p v-if="request.rejection_reason" class="mt-2 text-sm text-csc-red-ink">
                            {{ request.rejection_reason }}
                        </p>

                        <!-- What HRD has asked for. -->
                        <div
                            v-if="request.requirements_text"
                            class="mt-3 rounded-lg border border-csc-line bg-csc-mist/40 p-3"
                        >
                            <h3 class="mb-1 text-xs font-semibold tracking-wide text-csc-ink/70 uppercase">
                                Requirements from HRD
                            </h3>
                            <p class="text-sm whitespace-pre-line text-csc-ink/80">
                                {{ request.requirements_text }}
                            </p>
                        </div>

                        <!-- Progress through the correspondence. -->
                        <ol v-if="request.is_open" class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5">
                            <li
                                v-for="stage in request.stages"
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

                        <!-- Everything that has changed hands. -->
                        <details v-if="request.documents.length" class="mt-3">
                            <summary
                                class="cursor-pointer rounded text-xs font-medium text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            >
                                Documents ({{ request.documents.length }})
                            </summary>
                            <ul class="mt-2 space-y-1.5">
                                <li
                                    v-for="document in request.documents"
                                    :key="document.id"
                                    class="text-xs text-csc-ink/70"
                                >
                                    <a
                                        :href="document.url"
                                        class="rounded font-medium text-csc-blue underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                    >
                                        {{ document.kind_label }}
                                    </a>
                                    — {{ document.filename }} ({{ document.size }}), {{ document.uploaded_at }}
                                </li>
                            </ul>
                        </details>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <AppButton
                                v-if="request.can_confirm"
                                size="sm"
                                icon="upload"
                                @click="startConfirming(request)"
                            >
                                Return Signed Confirmation
                            </AppButton>
                            <AppButton
                                v-if="request.can_submit_completion"
                                size="sm"
                                icon="upload"
                                @click="startCompleting(request)"
                            >
                                {{ request.completion_submitted ? 'Resubmit Documents' : 'Submit Post-Training Documents' }}
                            </AppButton>
                            <AppButton
                                v-if="request.can_cancel"
                                size="sm"
                                variant="ghost"
                                icon="close"
                                @click="startCancelling(request)"
                            >
                                Withdraw
                            </AppButton>
                        </div>
                    </li>
                </ul>
            </AppCard>
        </div>

        <!-- Signed confirmation -->
        <AppModal
            :open="confirming !== null"
            title="Return the signed confirmation"
            :subtitle="
                confirming
                    ? `Confirm the final dates and venue for “${confirming.training_title}”.`
                    : undefined
            "
            @close="confirming = null"
        >
            <form class="space-y-4" @submit.prevent="submitConfirmation">
                <AppAlert v-if="confirming" tone="info">
                    Proposed: {{ confirming.proposed_start }} – {{ confirming.proposed_end }} at
                    {{ confirming.proposed_venue }}.
                </AppAlert>

                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput
                        v-model="confirmForm.confirmed_start"
                        label="Confirmed start"
                        type="date"
                        :error="confirmForm.errors.confirmed_start"
                        required
                    />
                    <AppInput
                        v-model="confirmForm.confirmed_end"
                        label="Confirmed end"
                        type="date"
                        :error="confirmForm.errors.confirmed_end"
                        required
                    />
                </div>

                <AppInput
                    v-model="confirmForm.confirmed_venue"
                    label="Confirmed venue"
                    :error="confirmForm.errors.confirmed_venue"
                    required
                />

                <AppFileField
                    id="signed-confirmation"
                    label="Signed confirmation form"
                    hint="The form HRD sent, signed by your head of office."
                    accept=".pdf,.jpg,.jpeg,.png"
                    required
                    :error="confirmForm.errors.signed_confirmation_form"
                    @change="confirmForm.signed_confirmation_form = $event"
                />

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="confirming = null">Cancel</AppButton>
                    <AppButton type="submit" :processing="confirmForm.processing">
                        Submit Confirmation
                    </AppButton>
                </div>
            </form>
        </AppModal>

        <!-- Post-training documents -->
        <AppModal
            :open="completing !== null"
            title="Post-training documents"
            subtitle="CSC verifies the payment against these before the request is closed."
            @close="completing = null"
        >
            <form class="space-y-4" @submit.prevent="submitCompletion">
                <AppAlert v-if="completionForm.errors.documents" tone="warning">
                    {{ completionForm.errors.documents }}
                </AppAlert>

                <AppFileField
                    v-for="kind in completionKinds"
                    :id="`completion-${kind.value}`"
                    :key="kind.value"
                    :label="kind.label"
                    :required="kind.required"
                    :hint="kind.required ? undefined : 'Optional.'"
                    accept=".pdf,.jpg,.jpeg,.png,.xls,.xlsx"
                    :error="completionForm.errors[kind.value]"
                    @change="completionForm[kind.value] = $event"
                />

                <AppInput
                    v-model="completionForm.payment_amount"
                    label="Amount paid"
                    type="number"
                    step="0.01"
                    min="0"
                    :error="completionForm.errors.payment_amount"
                />

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="completing = null">Cancel</AppButton>
                    <AppButton type="submit" :processing="completionForm.processing">
                        Submit Documents
                    </AppButton>
                </div>
            </form>
        </AppModal>

        <!-- Withdraw -->
        <AppModal
            :open="cancelling !== null"
            title="Withdraw this request"
            subtitle="CSC HRD is told the reason."
            @close="cancelling = null"
        >
            <form class="space-y-4" @submit.prevent="submitCancel">
                <AppTextarea
                    v-model="cancelForm.reason"
                    label="Why are you withdrawing?"
                    :error="cancelForm.errors.reason"
                    required
                />

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="cancelling = null">Keep It</AppButton>
                    <AppButton type="submit" variant="accent" :processing="cancelForm.processing">
                        Withdraw Request
                    </AppButton>
                </div>
            </form>
        </AppModal>
    </AuthenticatedLayout>
</template>
