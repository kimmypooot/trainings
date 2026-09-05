<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppPromptModal from '@/Components/AppPromptModal.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';

const props = defineProps({
    cancellations: { type: Array, required: true },
    trainingRequests: { type: Array, required: true },
    outputs: { type: Array, required: true },
    // Per-queue { pending, total, shown }, counted in the database rather than
    // off the arrays above — see below.
    queues: { type: Object, required: true },
    scopedTo: { type: String, default: null },
});

/*
 * The tab badges count the database, not the list.
 *
 * They used to filter the arrays above, which are capped at 100 rows. Past that
 * cap the tab and the sidebar badge disagreed, and the sidebar — which counts
 * the database — was the one telling the truth. Both read the same number now.
 */
const tabs = computed(() => [
    { key: 'cancellations', label: 'Withdrawals', count: props.queues.cancellations.pending },
    { key: 'trainings', label: 'Training Requests', count: props.queues.trainings.pending },
    { key: 'outputs', label: 'Outputs', count: props.queues.outputs.pending },
]);

const active = ref('cancellations');

/**
 * What a capped list is not showing, or null when it is showing everything.
 *
 * Said out loud because a list that simply stops at its hundredth row is
 * indistinguishable from one that has reached the end. Everything hidden has
 * already been decided — the server orders pending first for exactly that
 * reason — so this is history, not work, and the wording says so.
 */
function hiddenNotice(key) {
    const queue = props.queues[key];

    if (!queue || queue.total <= queue.shown) {
        return null;
    }

    return `Showing the ${queue.shown} most recent of ${queue.total}. The rest have all been decided.`;
}

/**
 * Two dialogs cover a review: the rejection asks for a reason that goes on the
 * record; the approval is decided in one glance, so its weight is carried by a
 * confirmation rather than a forced note.
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

const rejectWithReason = (config) => {
    prompt.value = config;
};

const approveWithConfirm = (config) => {
    confirm.value = config;
};

/**
 * Approvals release capacity or commit money-adjacent decisions; each asks
 * before it fires. Rejections carry a reason.
 */
const approveCancellation = (item) => {
    approveWithConfirm({
        title: 'Release this slot?',
        description: `${item.participant} will be withdrawn from “${item.training}” and the slot offered to the next person.`,
        confirmLabel: 'Approve & Release Slot',
        onConfirm: () =>
            post(`/admin/requests/cancellations/${item.id}`, { decision: 'approved', remarks: null }),
    });
};

const rejectCancellation = (item) => {
    rejectWithReason({
        title: 'Decline this withdrawal',
        description: 'The participant keeps their slot and is shown this reason.',
        label: 'Reason for declining',
        confirmLabel: 'Decline request',
        minLength: 10,
        onConfirm: (remarks) =>
            post(`/admin/requests/cancellations/${item.id}`, { decision: 'rejected', remarks }),
    });
};

const approveTraining = (item) => {
    approveWithConfirm({
        title: 'Approve this training request?',
        description: `“${item.title}” is approved for scheduling on behalf of ${item.requester ?? 'the agency'}.`,
        confirmLabel: 'Approve request',
        onConfirm: () =>
            post(`/admin/requests/trainings/${item.id}`, { decision: 'approved', remarks: null }),
    });
};

const rejectTraining = (item) => {
    rejectWithReason({
        title: 'Decline this training request',
        description: 'The requester is shown this reason.',
        label: 'Reason for declining',
        confirmLabel: 'Decline request',
        minLength: 10,
        onConfirm: (remarks) =>
            post(`/admin/requests/trainings/${item.id}`, { decision: 'rejected', remarks }),
    });
};

const acceptOutput = (item) => {
    approveWithConfirm({
        title: 'Accept this output?',
        description: `“${item.title}” by ${item.participant} is accepted and will let their certificate go out.`,
        confirmLabel: 'Accept output',
        onConfirm: () =>
            post(`/admin/requests/outputs/${item.id}`, { decision: 'approved', remarks: null }),
    });
};

const returnOutput = (item) => {
    rejectWithReason({
        title: 'Return this output',
        description: `${item.participant} is told what to fix and can resubmit.`,
        label: 'Reason for returning',
        confirmLabel: 'Return output',
        minLength: 10,
        onConfirm: (remarks) =>
            post(`/admin/requests/outputs/${item.id}`, { decision: 'rejected', remarks }),
    });
};

const convert = (id) => {
    const item = props.trainingRequests.find((request) => request.id === id);

    approveWithConfirm({
        title: 'Create a draft training?',
        description: item
            ? `A draft is created from “${item.title}” for you to finish the venue and schedule.`
            : 'A draft training is created from this approved request.',
        confirmLabel: 'Create Draft Training',
        onConfirm: () =>
            router.post(`/admin/requests/trainings/${id}/convert`, {}, { preserveScroll: true }),
    });
};
</script>

<template>
    <Head title="Requests" />

    <AuthenticatedLayout title="Requests" current="admin-requests">
        <div class="mx-auto max-w-7xl space-y-5">
            <AppAlert v-if="scopedTo" tone="info">
                Showing requests from <strong>{{ scopedTo }}</strong> only.
            </AppAlert>

            <div class="flex flex-wrap gap-2" role="tablist">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    role="tab"
                    :aria-selected="active === tab.key"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    :class="
                        active === tab.key
                            ? 'bg-csc-blue text-white'
                            : 'bg-white text-csc-ink-muted ring-1 ring-csc-line hover:text-csc-blue'
                    "
                    @click="active = tab.key"
                >
                    {{ tab.label }}
                    <span
                        v-if="tab.count"
                        class="ml-1.5 rounded-full px-1.5 py-0.5 text-xs font-semibold"
                        :class="active === tab.key ? 'bg-white/20' : 'bg-csc-red text-white'"
                    >
                        {{ tab.count }}
                    </span>
                </button>
            </div>

            <!-- Withdrawals -->
            <AppCard v-if="active === 'cancellations'" title="Withdrawal Requests" :padded="cancellations.length > 0">
                <AppEmptyState
                    v-if="!cancellations.length"
                    title="No withdrawal requests"
                    description="Participants asking to give up a slot appear here."
                    icon="close"
                />

                <ul v-else class="space-y-3">
                    <li
                        v-for="item in cancellations"
                        :key="item.id"
                        class="rounded-lg border border-csc-line p-4"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-csc-ink">{{ item.participant }}</p>
                                <p class="mt-0.5 text-sm text-csc-ink-subtle">{{ item.training }}</p>
                            </div>
                            <AppBadge :status="item.status" />
                        </div>

                        <p class="mt-3 text-sm text-csc-ink-muted">{{ item.reason }}</p>
                        <p v-if="item.review_remarks" class="mt-1.5 text-xs text-csc-ink-subtle">
                            Remarks: {{ item.review_remarks }}
                        </p>

                        <div v-if="item.status === 'pending'" class="mt-4 flex flex-wrap gap-2">
                            <AppButton size="sm" icon="check" @click="approveCancellation(item)">
                                Approve &amp; Release Slot
                            </AppButton>
                            <AppButton size="sm" variant="ghost" icon="close" @click="rejectCancellation(item)">
                                Decline
                            </AppButton>
                        </div>
                    </li>
                </ul>

                <p v-if="hiddenNotice('cancellations')" class="mt-4 text-sm text-csc-ink-subtle">
                    {{ hiddenNotice('cancellations') }}
                </p>
            </AppCard>

            <!-- Training requests -->
            <AppCard v-if="active === 'trainings'" title="Requested Trainings" :padded="trainingRequests.length > 0">
                <AppEmptyState
                    v-if="!trainingRequests.length"
                    title="No training requests"
                    description="Agencies asking CSC to run a training appear here."
                    icon="calendar"
                />

                <ul v-else class="space-y-3">
                    <li
                        v-for="item in trainingRequests"
                        :key="item.id"
                        class="rounded-lg border border-csc-line p-4"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-csc-ink">{{ item.title }}</p>
                                <p class="mt-0.5 text-sm text-csc-ink-subtle">
                                    Requested by {{ item.requester ?? '—' }} · {{ item.submitted_at }}
                                </p>
                            </div>
                            <AppBadge :status="item.status" />
                        </div>

                        <p class="mt-3 text-sm text-csc-ink-muted">{{ item.justification }}</p>
                        <p class="mt-1.5 text-xs text-csc-ink-subtle">
                            <template v-if="item.category">{{ item.category }} · </template>
                            <template v-if="item.expected_participants">
                                ~{{ item.expected_participants }} participants ·
                            </template>
                            Preferred {{ item.preferred_start ?? 'any date' }}
                        </p>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <template v-if="item.status === 'pending'">
                                <AppButton size="sm" icon="check" @click="approveTraining(item)">
                                    Approve
                                </AppButton>
                                <AppButton size="sm" variant="ghost" icon="close" @click="rejectTraining(item)">
                                    Decline
                                </AppButton>
                            </template>

                            <AppButton
                                v-else-if="item.status === 'approved' && !item.converted"
                                size="sm"
                                icon="plus"
                                @click="convert(item.id)"
                            >
                                Create Draft Training
                            </AppButton>

                            <p v-else-if="item.converted" class="text-xs text-csc-ink-subtle">
                                A draft training has been created from this request.
                            </p>
                        </div>
                    </li>
                </ul>

                <p v-if="hiddenNotice('trainings')" class="mt-4 text-sm text-csc-ink-subtle">
                    {{ hiddenNotice('trainings') }}
                </p>
            </AppCard>

            <!-- Outputs -->
            <AppCard v-if="active === 'outputs'" title="Submitted Outputs" :padded="outputs.length > 0">
                <AppEmptyState
                    v-if="!outputs.length"
                    title="No outputs submitted"
                    description="Post-training deliverables appear here for review."
                    icon="upload"
                />

                <ul v-else class="space-y-3">
                    <li v-for="item in outputs" :key="item.id" class="rounded-lg border border-csc-line p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-csc-ink">{{ item.title }}</p>
                                <p class="mt-0.5 text-sm text-csc-ink-subtle">
                                    {{ item.participant }} · {{ item.training }}
                                </p>
                            </div>
                            <AppBadge :status="item.status" />
                        </div>

                        <p v-if="item.description" class="mt-3 text-sm text-csc-ink-muted">
                            {{ item.description }}
                        </p>

                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <a
                                :href="item.download_url"
                                class="rounded text-sm font-medium text-csc-blue underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            >
                                {{ item.filename }}
                            </a>
                            <span class="text-xs text-csc-ink-subtle">{{ item.size }}</span>
                        </div>

                        <div v-if="item.status === 'pending'" class="mt-4 flex flex-wrap gap-2">
                            <AppButton size="sm" icon="check" @click="acceptOutput(item)">
                                Accept
                            </AppButton>
                            <AppButton size="sm" variant="ghost" icon="arrow-left" @click="returnOutput(item)">
                                Return for Revision
                            </AppButton>
                        </div>
                    </li>
                </ul>

                <p v-if="hiddenNotice('outputs')" class="mt-4 text-sm text-csc-ink-subtle">
                    {{ hiddenNotice('outputs') }}
                </p>
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
