<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppFileField from '@/Components/AppFileField.vue';
import AppInput from '@/Components/AppInput.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import AppModal from '@/Components/AppModal.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppPagination from '@/Components/AppPagination.vue';

defineProps({
    requests: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    counts: { type: Object, default: () => ({}) },
});

/*
 * "Waiting on us" is the default view. HRD's standing complaint about v1 was
 * chasing requests that were never theirs to move — a queue that mixes the two
 * is a queue nobody trusts, so whose move it is comes first.
 */
const tabs = [
    { value: 'ours', label: 'Awaiting HRD' },
    { value: 'theirs', label: 'Awaiting Agency' },
    { value: 'open', label: 'All Open' },
    { value: 'all', label: 'Everything' },
];

const setFilter = (filter) =>
    router.get('/admin/agency-requests', { filter, page: 1 }, { preserveState: true, preserveScroll: true });

// Assign and notify-ord carry no payload; the server decides everything.
const simplePost = (url) => router.post(url, {}, { preserveScroll: true });

// --- Sending the requirements ----------------------------------------------

const sending = ref(null);

const requirementsForm = useForm({
    requirements_text: '',
    response_letter: null,
    blank_confirmation_form: null,
});

const startSending = (request) => {
    sending.value = request;
    requirementsForm.reset();
    requirementsForm.clearErrors();
};

const submitRequirements = () =>
    requirementsForm.post(`/admin/agency-requests/${sending.value.id}/requirements`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            sending.value = null;
            requirementsForm.reset();
        },
    });

// --- Verifying the payment --------------------------------------------------

const verifying = ref(null);

const verifyForm = useForm({ notes: '' });

const startVerifying = (request) => {
    verifying.value = request;
    verifyForm.reset();
    verifyForm.clearErrors();
};

const submitVerify = () =>
    verifyForm.post(`/admin/agency-requests/${verifying.value.id}/verify-payment`, {
        preserveScroll: true,
        onSuccess: () => {
            verifying.value = null;
            verifyForm.reset();
        },
    });

// --- Declining ---------------------------------------------------------------

const rejecting = ref(null);

const rejectForm = useForm({ reason: '', rejection_letter: null });

const startRejecting = (request) => {
    rejecting.value = request;
    rejectForm.reset();
    rejectForm.clearErrors();
};

const submitReject = () =>
    rejectForm.post(`/admin/agency-requests/${rejecting.value.id}/reject`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            rejecting.value = null;
            rejectForm.reset();
        },
    });
</script>

<template>
    <Head title="Agency Requests" />

    <AuthenticatedLayout title="Agency Requests" current="admin-agency-requests">
        <div class="mx-auto max-w-7xl space-y-5">
            <div class="flex flex-wrap gap-2" role="tablist">
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    role="tab"
                    :aria-selected="(filters.filter ?? 'ours') === tab.value"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    :class="
                        (filters.filter ?? 'ours') === tab.value
                            ? 'bg-csc-blue text-white'
                            : 'bg-white text-csc-ink-muted ring-1 ring-csc-line hover:text-csc-blue'
                    "
                    @click="setFilter(tab.value)"
                >
                    {{ tab.label }}
                    <span
                        v-if="counts[tab.value]"
                        class="ml-1.5 rounded-full px-1.5 py-0.5 text-xs font-semibold"
                        :class="
                            (filters.filter ?? 'ours') === tab.value
                                ? 'bg-white/20'
                                : 'bg-csc-blue-tint text-csc-blue'
                        "
                    >
                        {{ counts[tab.value] }}
                    </span>
                </button>
            </div>

            <AppCard :padded="requests.data.length > 0">
                <AppEmptyState
                    v-if="!requests.data.length"
                    title="Nothing here"
                    description="Agency requests appear here as they are filed."
                    icon="document"
                />

                <ul v-else class="space-y-4">
                    <li
                        v-for="request in requests.data"
                        :key="request.id"
                        class="rounded-lg border border-csc-line p-4"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-csc-ink">
                                    {{ request.training_title }}
                                    <span class="ml-1 font-mono text-xs font-normal text-csc-ink-subtle">
                                        {{ request.request_code }}
                                    </span>
                                </p>
                                <p class="mt-0.5 text-sm text-csc-ink-subtle">
                                    {{ request.agency_name }} · {{ request.requester }}
                                    <span class="text-csc-ink-subtle">&lt;{{ request.requester_email }}&gt;</span>
                                </p>
                                <p class="mt-1 text-sm text-csc-ink-muted">
                                    {{ request.confirmed_start ?? request.proposed_start }} –
                                    {{ request.confirmed_end ?? request.proposed_end }}
                                    · {{ request.confirmed_venue ?? request.proposed_venue }}
                                    <span v-if="request.confirmed_start" class="text-csc-ink-subtle">(confirmed)</span>
                                    <span v-if="request.expected_participants" class="text-csc-ink-subtle">
                                        · ~{{ request.expected_participants }} participants
                                    </span>
                                </p>
                            </div>
                            <AppBadge :status="request.status" :label="request.status_label" />
                        </div>

                        <p class="mt-2 text-xs text-csc-ink-subtle">
                            Filed {{ request.submitted_at }}
                            <template v-if="request.assigned_to"> · handled by {{ request.assigned_to }}</template>
                            <template v-else> · unassigned</template>
                            <template v-if="request.ord_notified"> · ORD notified</template>
                        </p>

                        <p v-if="request.rejection_reason" class="mt-2 text-sm text-csc-red-ink">
                            Declined: {{ request.rejection_reason }}
                        </p>
                        <p v-if="request.cancellation_reason" class="mt-2 text-sm text-csc-ink-muted">
                            Withdrawn: {{ request.cancellation_reason }}
                        </p>

                        <div
                            v-if="request.requirements_text"
                            class="mt-3 rounded-lg border border-csc-line bg-csc-mist/40 p-3"
                        >
                            <h3 class="mb-1 text-xs font-semibold tracking-wide text-csc-ink-muted uppercase">
                                Requirements sent
                            </h3>
                            <p class="text-sm whitespace-pre-line text-csc-ink-muted">
                                {{ request.requirements_text }}
                            </p>
                        </div>

                        <!-- Post-training state, which is what the payment turns on. -->
                        <p v-if="request.completion_submitted" class="mt-2 text-sm text-csc-ink">
                            Documents submitted
                            <span v-if="request.payment_amount" class="text-csc-ink-subtle">
                                · ₱{{ request.payment_amount }} declared
                            </span>
                            <span v-if="request.payment_verified_at" class="text-csc-ink-subtle">
                                · verified {{ request.payment_verified_at }}
                            </span>
                        </p>
                        <p v-if="request.missing_documents.length" class="mt-1 text-xs text-warning">
                            Missing: {{ request.missing_documents.join(', ') }}
                        </p>

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
                                    class="text-xs text-csc-ink-muted"
                                >
                                    <a
                                        :href="document.url"
                                        class="rounded font-medium text-csc-blue underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                    >
                                        {{ document.kind_label }}
                                    </a>
                                    — {{ document.filename }} ({{ document.size }}),
                                    {{ document.uploaded_by ?? 'unknown' }}, {{ document.uploaded_at }}
                                </li>
                            </ul>
                        </details>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <AppButton
                                v-if="request.can_assign"
                                size="sm"
                                variant="ghost"
                                icon="user"
                                @click="simplePost(`/admin/agency-requests/${request.id}/assign`)"
                            >
                                Assign to Me
                            </AppButton>
                            <AppButton
                                v-if="request.can_notify_ord"
                                size="sm"
                                variant="ghost"
                                icon="envelope"
                                @click="simplePost(`/admin/agency-requests/${request.id}/notify-ord`)"
                            >
                                Mark ORD Notified
                            </AppButton>
                            <AppButton
                                v-if="request.can_send_requirements"
                                size="sm"
                                icon="upload"
                                @click="startSending(request)"
                            >
                                Send Requirements
                            </AppButton>
                            <AppButton
                                v-if="request.can_verify_payment"
                                size="sm"
                                icon="check"
                                @click="startVerifying(request)"
                            >
                                Verify Payment &amp; Complete
                            </AppButton>
                            <AppButton
                                v-if="request.can_reject"
                                size="sm"
                                variant="ghost"
                                icon="close"
                                @click="startRejecting(request)"
                            >
                                Decline
                            </AppButton>
                        </div>
                    </li>
                </ul>
            </AppCard>

            <AppPagination :pagination="requests" label="requests" class="pt-1" />
        </div>

        <!-- Requirements -->
        <AppModal
            :open="sending !== null"
            title="Send the requirements"
            :subtitle="
                sending ? `Reply to ${sending.agency_name} about “${sending.training_title}”.` : undefined
            "
            @close="sending = null"
        >
            <form class="space-y-4" @submit.prevent="submitRequirements">
                <AppTextarea
                    v-model="requirementsForm.requirements_text"
                    label="What the agency must provide"
                    rows="5"
                    hint="Shown to the agency and included in the notification they receive."
                    :error="requirementsForm.errors.requirements_text"
                    required
                />

                <AppFileField
                    id="response-letter"
                    label="HRD response letter"
                    accept=".pdf,.doc,.docx"
                    required
                    :error="requirementsForm.errors.response_letter"
                    @change="requirementsForm.response_letter = $event"
                />

                <AppFileField
                    id="blank-form"
                    label="Confirmation form to be signed"
                    hint="Optional — some requests need only the letter."
                    accept=".pdf,.doc,.docx"
                    :error="requirementsForm.errors.blank_confirmation_form"
                    @change="requirementsForm.blank_confirmation_form = $event"
                />

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="sending = null">Cancel</AppButton>
                    <AppButton type="submit" :processing="requirementsForm.processing">
                        Send Requirements
                    </AppButton>
                </div>
            </form>
        </AppModal>

        <!-- Verify payment -->
        <AppModal
            :open="verifying !== null"
            title="Verify payment and complete"
            :subtitle="
                verifying
                    ? `Closes ${verifying.request_code}. Check the proof of payment before confirming.`
                    : undefined
            "
            @close="verifying = null"
        >
            <form class="space-y-4" @submit.prevent="submitVerify">
                <AppInput
                    v-model="verifyForm.notes"
                    label="Notes"
                    hint="Optional. Recorded against the request."
                    :error="verifyForm.errors.notes"
                />

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="verifying = null">Cancel</AppButton>
                    <AppButton type="submit" icon="check" :processing="verifyForm.processing">
                        Verify and Complete
                    </AppButton>
                </div>
            </form>
        </AppModal>

        <!-- Decline -->
        <AppModal
            :open="rejecting !== null"
            title="Decline this request"
            subtitle="The agency is shown the reason and sent a notification."
            @close="rejecting = null"
        >
            <form class="space-y-4" @submit.prevent="submitReject">
                <AppTextarea
                    v-model="rejectForm.reason"
                    label="Reason for declining"
                    :error="rejectForm.errors.reason"
                    required
                />

                <AppFileField
                    id="rejection-letter"
                    label="Rejection letter"
                    hint="Optional — attach the formal letter if one was issued."
                    accept=".pdf,.doc,.docx"
                    :error="rejectForm.errors.rejection_letter"
                    @change="rejectForm.rejection_letter = $event"
                />

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="rejecting = null">Cancel</AppButton>
                    <AppButton type="submit" variant="accent" :processing="rejectForm.processing">
                        Decline Request
                    </AppButton>
                </div>
            </form>
        </AppModal>
    </AuthenticatedLayout>
</template>
