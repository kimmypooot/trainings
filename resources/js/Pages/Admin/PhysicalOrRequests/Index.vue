<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppInput from '@/Components/AppInput.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import AppStat from '@/Components/AppStat.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppModal from '@/Components/AppModal.vue';
import AppPromptModal from '@/Components/AppPromptModal.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppPagination from '@/Components/AppPagination.vue';

const props = defineProps({
    requests: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
    pipeline: { type: Array, default: () => [] },
    settings: { type: Object, required: true },
});

const money = (value) =>
    Number(value).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const search = ref(props.filters.search ?? '');
const statusFilter = ref(props.filters.status ?? 'payment_verification_pending');

let debounce;
watch([search, statusFilter], () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            '/admin/physical-or',
            {
                search: search.value || undefined,
                status: statusFilter.value || undefined,
            },
            { preserveState: true, preserveScroll: true }
        );
    }, 300);
});

const filterBy = (status) => {
    statusFilter.value = status;
};

const openTotal = computed(() =>
    Object.entries(props.counts)
        .filter(([key]) => !['delivered', 'rejected'].includes(key))
        .reduce((sum, [, value]) => sum + value, 0)
);

// A request's position along the pipeline, or -1 for a declined one.
const pipelinePosition = (status) => props.pipeline.findIndex((stage) => stage.value === status);

const stageState = (request, index) => {
    if (request.status === 'rejected') {
        return 'muted';
    }

    const current = pipelinePosition(request.status);

    if (index < current) {
        return 'done';
    }

    return index === current ? 'current' : 'upcoming';
};

// Advancing is a confirm, declining needs a reason — same split as the refund
// queue. Shipped is special: it carries the courier and tracking number, so it
// opens a small form instead of a bare confirmation.
const prompt = ref(null);
const promptBusy = ref(false);
const confirm = ref(null);
const confirmBusy = ref(false);
const shipping = ref(null);

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
            shipping.value = null;
            shipForm.reset();
        },
        onFinish: () => {
            promptBusy.value = false;
            confirmBusy.value = false;
        },
    });

const advanceRequest = (request) => {
    // Handing it to a courier is the one stage that needs data, so it is its
    // own modal; every other move is a yes/no.
    if (request.next_stage.value === 'shipped') {
        shipping.value = request;
        shipForm.reset();
        shipForm.clearErrors();
        return;
    }

    confirm.value = {
        title: `Move to ${request.next_stage.label}?`,
        description: `${request.request_code} moves to ${request.next_stage.label}. ${request.participant} is notified.`,
        confirmLabel: `Move to ${request.next_stage.label}`,
        onConfirm: () =>
            post(`/admin/physical-or/${request.id}/review`, {
                decision: 'advance',
                target: request.next_stage.value,
            }),
    };
};

const rejectRequest = (request) => {
    prompt.value = {
        title: 'Decline this request',
        description: `The participant is shown this reason for the declined ${request.request_code} request.`,
        label: 'Reason for declining',
        confirmLabel: 'Decline request',
        minLength: 10,
        onConfirm: (reason) =>
            post(`/admin/physical-or/${request.id}/review`, {
                decision: 'reject',
                rejection_reason: reason,
            }),
    };
};

const shipForm = useForm({
    decision: 'advance',
    target: 'shipped',
    courier_name: '',
    tracking_number: '',
    notes: '',
});

const submitShip = () =>
    shipForm.post(`/admin/physical-or/${shipping.value.id}/review`, {
        preserveScroll: true,
        onSuccess: () => {
            shipping.value = null;
            shipForm.reset();
        },
    });

// The GCash details participants are asked to pay. Editing them here edits the
// modal on every participant's Payments page.
const editingSettings = ref(false);
const settingsForm = useForm({
    gcash_number: props.settings.gcash_number,
    account_name: props.settings.account_name,
    courier_fee: props.settings.courier_fee,
    instructions: props.settings.instructions,
});

const openSettings = () => {
    settingsForm.reset();
    settingsForm.clearErrors();
    settingsForm.gcash_number = props.settings.gcash_number;
    settingsForm.account_name = props.settings.account_name;
    settingsForm.courier_fee = props.settings.courier_fee;
    settingsForm.instructions = props.settings.instructions;
    editingSettings.value = true;
};

const submitSettings = () =>
    settingsForm.post('/admin/physical-or/settings', {
        preserveScroll: true,
        onSuccess: () => {
            editingSettings.value = false;
            settingsForm.reset();
        },
    });
</script>

<template>
    <Head title="Physical OR Requests" />

    <AuthenticatedLayout title="Physical OR Requests" current="admin-physical-or">
        <div class="mx-auto max-w-7xl space-y-5">
            <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                <AppStat :value="counts.payment_verification_pending ?? 0" label="Awaiting fee verification" />
                <AppStat :value="openTotal" label="Open requests" />
                <AppStat :value="counts.shipped ?? 0" label="In transit" />
                <AppStat :value="counts.delivered ?? 0" label="Delivered" />
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <input
                    v-model="search"
                    type="search"
                    placeholder="Find by name, OR or request code…"
                    aria-label="Find physical OR requests"
                    class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue sm:max-w-xs"
                />
                <AppButton size="sm" icon="settings" class="sm:ml-auto" @click="openSettings">
                    GCash &amp; delivery settings
                </AppButton>
            </div>

            <div class="flex flex-wrap gap-1.5" role="tablist" aria-label="Filter by status">
                <button
                    v-for="status in statuses"
                    :key="status.value"
                    type="button"
                    role="tab"
                    :aria-selected="statusFilter === status.value"
                    class="rounded-lg px-3.5 py-1.5 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    :class="
                        statusFilter === status.value
                            ? 'bg-csc-blue text-white shadow-sm'
                            : 'bg-white text-csc-ink-muted ring-1 ring-csc-line hover:text-csc-blue'
                    "
                    @click="filterBy(status.value)"
                >
                    {{ status.label }}
                    <span class="ml-1 text-xs" :class="statusFilter === status.value ? 'text-white/80' : 'text-csc-ink-subtle'">
                        {{ counts[status.value] ?? 0 }}
                    </span>
                </button>
            </div>

            <AppCard title="Physical OR Requests" :padded="requests.data.length > 0">
                <AppEmptyState
                    v-if="!requests.data.length"
                    title="Nothing here"
                    description="Requests filed by participants appear here, awaiting their next move."
                    icon="document"
                />

                <ul v-else class="space-y-3">
                    <li v-for="request in requests.data" :key="request.id" class="rounded-lg border border-csc-line p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-csc-ink">
                                    {{ request.participant }}
                                    <span class="ml-1 font-mono text-xs font-normal text-csc-ink-subtle">
                                        {{ request.request_code }}
                                    </span>
                                </p>
                                <p class="mt-0.5 text-sm text-csc-ink-subtle">{{ request.training }}</p>
                                <p class="mt-1 text-sm text-csc-ink">
                                    OR <span class="font-mono">{{ request.or_number }}</span> ·
                                    Courier fee ₱{{ money(request.courier_fee) }}
                                </p>
                            </div>
                            <AppBadge :status="request.status" />
                        </div>

                        <p v-if="request.notes" class="mt-2 text-sm text-csc-ink-muted">{{ request.notes }}</p>
                        <p v-if="request.rejection_reason" class="mt-2 text-sm text-csc-red-ink">
                            Declined: {{ request.rejection_reason }}
                        </p>

                        <ol v-if="request.status !== 'rejected'" class="mt-4 flex flex-wrap items-center gap-1.5" aria-label="Physical OR pipeline">
                            <li v-for="(stage, index) in pipeline" :key="stage.value" class="flex items-center gap-1.5">
                                <span
                                    class="size-2.5 rounded-full"
                                    :class="{
                                        'bg-success': stageState(request, index) === 'done',
                                        'bg-csc-blue': stageState(request, index) === 'current',
                                        'bg-csc-line': stageState(request, index) === 'upcoming',
                                    }"
                                ></span>
                                <span
                                    class="text-xs"
                                    :class="stageState(request, index) === 'upcoming' ? 'text-csc-ink-subtle' : 'font-medium text-csc-ink'"
                                >
                                    {{ stage.label }}
                                </span>
                                <span v-if="index < pipeline.length - 1" class="h-px w-4 bg-csc-line"></span>
                            </li>
                        </ol>
                        <p v-else class="mt-3 text-xs font-semibold uppercase tracking-wide text-csc-red-ink">Declined</p>

                        <div v-if="request.courier_name" class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                            <p class="flex gap-2">
                                <span class="text-csc-ink-subtle">Courier</span>
                                <span class="text-csc-ink">{{ request.courier_name }}</span>
                            </p>
                            <p class="flex gap-2">
                                <span class="text-csc-ink-subtle">Tracking</span>
                                <span class="font-mono text-csc-ink">{{ request.tracking_number || '—' }}</span>
                            </p>
                            <p v-if="request.verified_by" class="flex gap-2">
                                <span class="text-csc-ink-subtle">Fee verified by</span>
                                <span class="text-csc-ink">{{ request.verified_by }}</span>
                            </p>
                        </div>

                        <details v-if="request.trail.length > 1" class="mt-3">
                            <summary
                                class="cursor-pointer rounded text-xs font-medium text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            >
                                History ({{ request.trail.length }})
                            </summary>
                            <ol class="mt-2 space-y-1.5 border-l-2 border-csc-line pl-3">
                                <li v-for="(entry, index) in request.trail" :key="index" class="text-xs">
                                    <span class="font-medium text-csc-ink">{{ entry.to }}</span>
                                    <span class="text-csc-ink-subtle"> · {{ entry.actor }} · {{ entry.at }}</span>
                                    <p v-if="entry.notes" class="text-csc-ink-muted">{{ entry.notes }}</p>
                                </li>
                            </ol>
                        </details>

                        <div v-if="request.can_act" class="mt-4 flex flex-wrap gap-2">
                            <a
                                v-if="request.proof_url"
                                :href="request.proof_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 rounded text-xs font-semibold text-csc-blue hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            >
                                <AppIcon name="eye" size="sm" />
                                Fee proof
                            </a>
                            <AppButton
                                v-if="request.next_stage"
                                size="sm"
                                icon="check"
                                @click="advanceRequest(request)"
                            >
                                {{ request.next_stage.value === 'shipped' ? 'Ship' : `Move to ${request.next_stage.label}` }}
                            </AppButton>
                            <AppButton size="sm" variant="ghost" icon="close" @click="rejectRequest(request)">
                                Decline
                            </AppButton>
                        </div>
                    </li>
                </ul>
            </AppCard>

            <AppPagination :pagination="requests" label="physical OR requests" class="pt-1" />
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
            :open="shipping !== null"
            title="Ship this request"
            :subtitle="shipping ? `${shipping.request_code} — ${shipping.participant}. The tracking number goes straight to the participant.` : undefined"
            @close="shipping = null"
        >
            <form class="space-y-4" @submit.prevent="submitShip">
                <AppInput
                    v-model="shipForm.courier_name"
                    label="Courier name"
                    :error="shipForm.errors.courier_name"
                    required
                />
                <AppInput
                    v-model="shipForm.tracking_number"
                    label="Tracking number"
                    hint="The participant can check the parcel with this."
                    :error="shipForm.errors.tracking_number"
                    required
                />
                <AppTextarea
                    v-model="shipForm.notes"
                    label="Notes"
                    hint="Optional. Recorded against the request."
                    :error="shipForm.errors.notes"
                />

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="shipping = null">Cancel</AppButton>
                    <AppButton type="submit" icon="check" :processing="shipForm.processing">
                        Mark shipped
                    </AppButton>
                </div>
            </form>
        </AppModal>

        <AppModal
            :open="editingSettings"
            title="GCash &amp; delivery settings"
            subtitle="What every participant sees in the physical OR request modal."
            @close="editingSettings = false"
        >
            <form class="space-y-4" @submit.prevent="submitSettings">
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput
                        v-model="settingsForm.gcash_number"
                        label="GCash number"
                        :error="settingsForm.errors.gcash_number"
                        required
                    />
                    <AppInput
                        v-model="settingsForm.account_name"
                        label="Account name"
                        :error="settingsForm.errors.account_name"
                        required
                    />
                </div>
                <AppInput
                    v-model="settingsForm.courier_fee"
                    label="Courier fee (PHP)"
                    type="number"
                    step="0.01"
                    :error="settingsForm.errors.courier_fee"
                    required
                />
                <AppTextarea
                    v-model="settingsForm.instructions"
                    label="Delivery instructions"
                    :error="settingsForm.errors.instructions"
                    required
                />

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="editingSettings = false">Cancel</AppButton>
                    <AppButton type="submit" icon="check" :processing="settingsForm.processing">
                        Save settings
                    </AppButton>
                </div>
            </form>
        </AppModal>
    </AuthenticatedLayout>
</template>