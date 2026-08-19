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
import AppSelect from '@/Components/AppSelect.vue';
import AppStat from '@/Components/AppStat.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppModal from '@/Components/AppModal.vue';
import AppPromptModal from '@/Components/AppPromptModal.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppPagination from '@/Components/AppPagination.vue';

const props = defineProps({
    payments: { type: Object, required: true },
    refunds: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
    methods: { type: Array, default: () => [] },
    paymentCounts: { type: Object, default: () => ({}) },
    refundCounts: { type: Object, default: () => ({}) },
    summary: { type: Object, required: true },
    refundStatuses: { type: Array, default: () => [] },
    refundPipeline: { type: Array, default: () => [] },
    paymentSettings: { type: Object, default: null },
});

const active = ref('payments');

// Money is a currency amount, not a bare integer: thousand separators and two
// decimals everywhere it appears.
const money = (value) =>
    Number(value).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

// The stat cards lead with an amount, and a bare number there reads as a count
// — every card carries the peso sign so the unit is never in doubt.
const peso = (value) => `₱${money(value)}`;

const openRefunds = computed(() => props.summary.open_refunds ?? { count: 0, amount: 0 });

// Server-side narrowing, mirroring the trainings index: the chips always count
// the whole queue (paymentCounts), while the rows below are what is filtered.
const search = ref(props.filters.search ?? '');
const statusFilter = ref(props.filters.status ?? 'pending');
const methodFilter = ref(props.filters.method ?? '');
const refundStatusFilter = ref(props.filters.refund_status ?? '');

let debounce;
watch([search, statusFilter, methodFilter, refundStatusFilter], () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            '/admin/payments',
            {
                search: search.value || undefined,
                status: statusFilter.value || undefined,
                method: methodFilter.value || undefined,
                refund_status: refundStatusFilter.value || undefined,
            },
            { preserveState: true, preserveScroll: true }
        );
    }, 300);
});

const filterBy = (status) => {
    statusFilter.value = status;
};

// Sort the rows in front of us — the page holds 25, so this is local.
const sortKey = ref(null);
const sortDir = ref('asc');

function toggleSort(key) {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = key;
        sortDir.value = 'asc';
    }
}

const sortIndicator = (key) => {
    if (sortKey.value !== key) {
        return '';
    }

    return sortDir.value === 'asc' ? ' ↑' : ' ↓';
};

const sortedPayments = computed(() => {
    const rows = props.payments.data;

    if (!sortKey.value) {
        return rows;
    }

    const dir = sortDir.value === 'asc' ? 1 : -1;

    return [...rows].sort((a, b) => {
        const av = a[sortKey.value] ?? '';
        const bv = b[sortKey.value] ?? '';

        return (typeof av === 'number' ? av - bv : String(av).localeCompare(String(bv))) * dir;
    });
});

// A refund's position along the pipeline, or -1 for a rejected claim.
const pipelinePosition = (status) => props.refundPipeline.findIndex((stage) => stage.value === status);

// The refund pipeline as a step list: done, current, and not yet reached.
const stageState = (refund, index) => {
    if (refund.status === 'rejected') {
        return index === -1 ? 'rejected' : 'muted';
    }

    const current = pipelinePosition(refund.status);

    if (index < current) {
        return 'done';
    }

    return index === current ? 'current' : 'upcoming';
};

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
 * Clearing a batch of promissory notes.
 *
 * Selection is deliberately limited to notes that are still pending, because
 * those are the only rows the endpoint will act on. Offering a checkbox beside
 * a cash payment and then reporting it as "skipped" afterwards teaches an
 * officer to distrust the count; the affordance is simply absent instead.
 */
const selected = ref([]);

const clearable = computed(() =>
    sortedPayments.value.filter((payment) => payment.status === 'pending' && payment.is_promissory)
);

const allClearableSelected = computed(
    () => clearable.value.length > 0 && selected.value.length === clearable.value.length
);

const toggleAllClearable = () => {
    selected.value = allClearableSelected.value ? [] : clearable.value.map((payment) => payment.id);
};

const bulkForm = useForm({ ids: [], remarks: '' });

const submitBulk = () => {
    bulkForm.ids = selected.value;

    bulkForm.post('/admin/payments/bulk', {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = [];
            bulkForm.reset();
        },
    });
};

/*
 * Verifying is a short form rather than a bare confirmation:
 the officer is
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
    prime_hrm_discount: false,
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

/*
 * The bank account participants are told to deposit into. One settings row
 * feeds every approval notification and payment prompt, so this modal is the
 * only place it is edited.
 */
const editingSettings = ref(false);

const settingsForm = useForm({
    bank_name: '',
    account_name: '',
    account_number: '',
    instructions: '',
});

const openSettings = () => {
    settingsForm.reset();
    settingsForm.clearErrors();
    settingsForm.bank_name = props.paymentSettings?.bank_name ?? '';
    settingsForm.account_name = props.paymentSettings?.account_name ?? '';
    settingsForm.account_number = props.paymentSettings?.account_number ?? '';
    settingsForm.instructions = props.paymentSettings?.instructions ?? '';
    editingSettings.value = true;
};

const closeSettings = () => {
    editingSettings.value = false;
    settingsForm.reset();
};

const submitSettings = () =>
    settingsForm.post('/admin/payments/settings', {
        preserveScroll: true,
        onSuccess: closeSettings,
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
                ? `This records ₱${money(refund.amount)} as released to ${refund.participant}. It cannot be undone.`
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
        description: `The participant is shown this reason for the declined ₱${money(refund.amount)} refund.`,
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
        <div class="mx-auto max-w-7xl space-y-5">
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
                        v-if="openRefunds.count"
                        class="ml-1.5 rounded-full px-1.5 py-0.5 text-xs font-semibold"
                        :class="active === 'refunds' ? 'bg-white/20' : 'bg-csc-red text-white'"
                    >
                        {{ openRefunds.count }}
                    </span>
                </button>

                <AppButton
                    variant="ghost"
                    size="sm"
                    icon="card"
                    class="ml-auto"
                    @click="openSettings"
                >
                    Bank deposit details
                </AppButton>
            </div>

            <!-- What is in motion right now, for the officer and end-of-day
                 reconciliation alike. Amounts lead, counts ride along. -->
            <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                <AppStat :value="peso(summary.pending?.amount ?? 0)" :label="`Pending · ${summary.pending?.count ?? 0} payment(s)`" />
                <AppStat :value="peso(summary.verified?.amount ?? 0)" :label="`Collected · ${summary.verified?.count ?? 0} verified`" />
                <AppStat :value="peso(summary.rejected?.amount ?? 0)" :label="`Rejected · ${summary.rejected?.count ?? 0}`" />
                <AppStat :value="peso(openRefunds.amount)" :label="`Open refunds · ${openRefunds.count}`" />
            </div>

            <template v-if="active === 'payments'">
                <!-- Find and narrow. Rows re-query the server; the chips count
                     the whole queue so their numbers never move under a filter. -->
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Find by name, OR number or reference…"
                        aria-label="Find payments"
                        class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue sm:max-w-xs"
                    />

                    <AppSelect
                        v-model="methodFilter"
                        label=""
                        :options="[{ value: '', label: 'All methods' }, ...methods]"
                        class="w-full sm:w-52"
                        aria-label="Filter by payment method"
                    />

                    <div class="flex flex-wrap gap-2 sm:ml-auto">
                        <AppButton
                            :href="`/admin/exports/payments?format=csv&status=${statusFilter}${methodFilter ? '&method=' + methodFilter : ''}${search ? '&search=' + encodeURIComponent(search) : ''}`"
                            variant="ghost"
                            size="sm"
                            icon="download"
                            external
                        >
                            CSV
                        </AppButton>
                        <AppButton
                            :href="`/admin/exports/payments?format=xlsx&status=${statusFilter}${methodFilter ? '&method=' + methodFilter : ''}${search ? '&search=' + encodeURIComponent(search) : ''}`"
                            variant="ghost"
                            size="sm"
                            icon="download"
                            external
                        >
                            Excel
                        </AppButton>
                    </div>
                </div>

                <div class="flex flex-wrap gap-1.5" role="tablist" aria-label="Filter by payment status">
                    <button
                        v-for="chip in ['pending', 'verified', 'rejected']"
                        :key="chip"
                        type="button"
                        role="tab"
                        :aria-selected="statusFilter === chip"
                        class="rounded-lg px-3.5 py-1.5 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        :class="
                            statusFilter === chip ? 'bg-csc-blue text-white shadow-sm' : 'bg-white text-csc-ink/70 ring-1 ring-csc-line hover:text-csc-blue'
                        "
                        @click="filterBy(chip)"
                    >
                        {{ statuses.find((s) => s.value === chip)?.label ?? chip }}
                        <span
                            class="ml-1 text-xs"
                            :class="statusFilter === chip ? 'text-white/80' : 'text-csc-ink/45'"
                        >
                            {{ paymentCounts[chip] ?? 0 }}
                        </span>
                    </button>
                </div>

                <AppCard title="Payment Verification" :padded="payments.data.length > 0">
                    <AppEmptyState
                        v-if="!payments.data.length"
                        title="Nothing to verify"
                        description="Payments submitted by participants appear here."
                        icon="card"
                    />

                    <template v-else>
                        <!--
                            The batch bar appears only once something is
                            selected, so the screen is unchanged for an officer
                            working the queue one payment at a time — which is
                            still the normal day. It is a walk-in event that
                            leaves two hundred notes behind, not a Tuesday.
                        -->
                        <div
                            v-if="selected.length"
                            class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-info/30 bg-info-soft px-4 py-3"
                        >
                            <p class="text-sm font-semibold text-csc-ink">
                                {{ selected.length }} promissory note{{ selected.length === 1 ? '' : 's' }} selected
                            </p>

                            <AppInput
                                v-model="bulkForm.remarks"
                                class="min-w-56 flex-1"
                                placeholder="Remarks (optional)"
                                aria-label="Remarks recorded on every note in this batch"
                            />

                            <AppButton :disabled="bulkForm.processing" @click="submitBulk">
                                {{ bulkForm.processing ? 'Verifying…' : 'Verify selected' }}
                            </AppButton>

                            <AppButton variant="ghost" @click="selected = []">Cancel</AppButton>

                            <!--
                                Said before the click, not after. Verifying a
                                note confirms the slot and mails the
                                participant, and unlike the roster's bulk
                                actions there is no undo window behind it.
                            -->
                            <p class="w-full text-xs text-csc-ink/60">
                                Each note is verified and its registration confirmed. The fee stays
                                outstanding until the money is collected, and this cannot be undone.
                            </p>
                        </div>

                        <!-- Desktop table -->
                        <div class="-mx-5 hidden overflow-x-auto sm:-mx-6 md:block">
                            <table class="w-full min-w-200 text-left text-sm">
                                <thead class="border-y border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                                    <tr>
                                        <th scope="col" class="w-10 px-5 py-3">
                                            <input
                                                v-if="clearable.length"
                                                type="checkbox"
                                                class="size-4 rounded border-csc-line text-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                                                :checked="allClearableSelected"
                                                :aria-label="allClearableSelected ? 'Clear selection' : 'Select every pending promissory note'"
                                                @change="toggleAllClearable"
                                            />
                                        </th>
                                        <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-0.5 uppercase hover:text-csc-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                                @click="toggleSort('participant')"
                                            >
                                                Participant{{ sortIndicator('participant') }}
                                            </button>
                                        </th>
                                        <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Training</th>
                                        <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-0.5 uppercase hover:text-csc-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                                @click="toggleSort('amount')"
                                            >
                                                Amount{{ sortIndicator('amount') }}
                                            </button>
                                        </th>
                                        <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-0.5 uppercase hover:text-csc-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                                @click="toggleSort('payment_date_ts')"
                                            >
                                                Paid on{{ sortIndicator('payment_date_ts') }}
                                            </button>
                                        </th>
                                        <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">OR number</th>
                                        <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-0.5 uppercase hover:text-csc-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                                @click="toggleSort('status')"
                                            >
                                                Status{{ sortIndicator('status') }}
                                            </button>
                                        </th>
                                        <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink/70">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-csc-line">
                                    <tr v-for="payment in sortedPayments" :key="payment.id">
                                        <td class="px-5 py-3.5">
                                            <input
                                                v-if="payment.status === 'pending' && payment.is_promissory"
                                                v-model="selected"
                                                type="checkbox"
                                                :value="payment.id"
                                                class="size-4 rounded border-csc-line text-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                                                :aria-label="`Select the promissory note for ${payment.participant}`"
                                            />
                                        </td>
                                        <td class="px-5 py-3.5">
                                            <p class="font-medium text-csc-ink">{{ payment.participant }}</p>
                                            <p v-if="payment.reference_number" class="mt-0.5 text-xs text-csc-ink/55">
                                                Ref {{ payment.reference_number }}
                                            </p>
                                        </td>
                                        <td class="px-5 py-3.5 text-csc-ink/75">
                                            {{ payment.training }}
                                            <p v-if="payment.charge_to" class="mt-0.5 text-xs text-csc-ink/55">
                                                {{ payment.charge_to }}
                                            </p>
                                        </td>
                                        <td class="px-5 py-3.5 whitespace-nowrap text-csc-ink">
                                            ₱{{ money(payment.amount) }}
                                            <p class="text-xs text-csc-ink/55">{{ payment.method }}</p>
                                            <!--
                                                Both figures, because the gross
                                                is what the report reconciles on
                                                and the net is what arrived.
                                            -->
                                            <p
                                                v-if="payment.prime_hrm_discount"
                                                class="mt-0.5 text-2xs font-medium text-warning"
                                                :title="`Full fee ₱${money(payment.gross_amount)}, PRIME-HRM discount ₱${money(payment.discount_amount)}`"
                                            >
                                                PRIME-HRM −₱{{ money(payment.discount_amount) }}
                                            </p>
                                        </td>
                                        <td class="px-5 py-3.5 whitespace-nowrap text-csc-ink/75">{{ payment.payment_date }}</td>
                                        <td class="px-5 py-3.5">
                                            <template v-if="payment.or_number">
                                                <p class="font-mono text-xs text-csc-ink">{{ payment.or_number }}</p>
                                                <p class="mt-0.5 text-xs text-csc-ink/55">
                                                    {{ payment.or_date }}
                                                    <template v-if="payment.collecting_officer">
                                                        · {{ payment.collecting_officer }}
                                                    </template>
                                                </p>
                                            </template>
                                            <span v-else class="text-xs text-csc-ink/45">—</span>
                                        </td>
                                        <td class="px-5 py-3.5">
                                            <AppBadge :status="payment.status" />
                                            <p v-if="payment.rejection_reason" class="mt-1 max-w-48 text-xs text-csc-red-ink">
                                                {{ payment.rejection_reason }}
                                            </p>
                                            <p v-if="payment.remarks" class="mt-1 max-w-48 text-xs text-csc-ink/55">
                                                {{ payment.remarks }}
                                            </p>
                                        </td>
                                        <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                            <a
                                                v-if="payment.proof_url"
                                                :href="payment.proof_url"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="inline-flex items-center gap-1.5 rounded text-xs font-semibold text-csc-blue hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                            >
                                                <AppIcon name="eye" size="sm" />
                                                Proof
                                            </a>
                                            <!--
                                                An online transfer recorded with
                                                no slip. Accepted from the
                                                participant, raised here — icon
                                                and label, never colour alone.
                                            -->
                                            <span
                                                v-else-if="payment.proof_missing"
                                                class="inline-flex items-center gap-1.5 rounded-full bg-warning-soft px-2 py-0.5 text-xs font-semibold text-warning"
                                            >
                                                <AppIcon name="warning" size="sm" />
                                                No proof
                                            </span>
                                            <template v-if="payment.status === 'pending'">
                                                <span
                                                    v-if="payment.proof_url || payment.proof_missing"
                                                    class="px-2 text-csc-line"
                                                >|</span>
                                                <AppButton size="sm" icon="check" @click="startVerifying(payment)">
                                                    Verify
                                                </AppButton>
                                                <AppButton size="sm" variant="ghost" icon="close" @click="rejectPayment(payment)">
                                                    Reject
                                                </AppButton>
                                            </template>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mobile cards -->
                        <ul class="space-y-3 md:hidden">
                            <li
                                v-for="payment in sortedPayments"
                                :key="payment.id"
                                class="rounded-lg border border-csc-line p-4"
                            >
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="font-semibold text-csc-ink">{{ payment.participant }}</p>
                                        <p class="mt-0.5 text-sm text-csc-ink/60">{{ payment.training }}</p>
                                        <p class="mt-1 text-sm text-csc-ink">
                                            ₱{{ money(payment.amount) }} · {{ payment.method }} ·
                                            {{ payment.payment_date }}
                                        </p>
                                        <p
                                            v-if="payment.prime_hrm_discount"
                                            class="text-xs font-medium text-warning"
                                        >
                                            PRIME-HRM 20% — ₱{{ money(payment.gross_amount) }} less
                                            ₱{{ money(payment.discount_amount) }}
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

                                <p v-if="payment.remarks" class="mt-1.5 text-xs text-csc-ink/55">
                                    {{ payment.remarks }}
                                </p>

                                <p v-if="payment.or_number" class="mt-2 text-sm text-csc-ink">
                                    <span class="text-csc-ink/55">OR</span>
                                    <span class="font-mono">{{ payment.or_number }}</span>
                                    <span v-if="payment.or_date" class="text-csc-ink/55"> · {{ payment.or_date }}</span>
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

                                    <!-- See the table above: expected, absent. -->
                                    <span
                                        v-else-if="payment.proof_missing"
                                        class="inline-flex items-center gap-2 rounded-lg bg-warning-soft px-4 py-2 text-sm font-semibold text-warning"
                                    >
                                        <AppIcon name="warning" size="sm" class="shrink-0" />
                                        No proof uploaded
                                    </span>

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
                    </template>
                </AppCard>

                <AppPagination :pagination="payments" label="payments" class="pt-1" />
            </template>

            <template v-else>
                <div class="flex flex-wrap gap-1.5" role="tablist" aria-label="Filter by refund status">
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="refundStatusFilter === ''"
                        class="rounded-lg px-3.5 py-1.5 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        :class="
                            refundStatusFilter === ''
                                ? 'bg-csc-blue text-white shadow-sm'
                                : 'bg-white text-csc-ink/70 ring-1 ring-csc-line hover:text-csc-blue'
                        "
                        @click="refundStatusFilter = ''"
                    >
                        All
                        <span class="ml-1 text-xs" :class="refundStatusFilter === '' ? 'text-white/80' : 'text-csc-ink/45'">
                            {{ Object.values(refundCounts).reduce((sum, n) => sum + n, 0) }}
                        </span>
                    </button>
                    <button
                        v-for="status in refundStatuses"
                        :key="status.value"
                        type="button"
                        role="tab"
                        :aria-selected="refundStatusFilter === status.value"
                        class="rounded-lg px-3.5 py-1.5 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        :class="
                            refundStatusFilter === status.value
                                ? 'bg-csc-blue text-white shadow-sm'
                                : 'bg-white text-csc-ink/70 ring-1 ring-csc-line hover:text-csc-blue'
                        "
                        @click="refundStatusFilter = status.value"
                    >
                        {{ status.label }}
                        <span
                            class="ml-1 text-xs"
                            :class="refundStatusFilter === status.value ? 'text-white/80' : 'text-csc-ink/45'"
                        >
                            {{ refundCounts[status.value] ?? 0 }}
                        </span>
                    </button>
                </div>

                <AppCard title="Refund Requests" :padded="refunds.data.length > 0">
                    <AppEmptyState
                        v-if="!refunds.data.length"
                        title="No refund requests"
                        description="Claims against verified payments appear here."
                        icon="arrow-left"
                    />

                    <ul v-else class="space-y-3">
                        <li v-for="refund in refunds.data" :key="refund.id" class="rounded-lg border border-csc-line p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-semibold text-csc-ink">
                                        {{ refund.participant }}
                                        <span class="ml-1 font-mono text-xs font-normal text-csc-ink/50">
                                            {{ refund.request_code }}
                                        </span>
                                    </p>
                                    <p class="mt-0.5 text-sm text-csc-ink/60">{{ refund.training }}</p>
                                    <p class="mt-1 text-sm text-csc-ink">₱{{ money(refund.amount) }}</p>
                                </div>
                                <AppBadge :status="refund.status" />
                            </div>

                            <p class="mt-3 text-sm text-csc-ink/80">{{ refund.reason }}</p>

                            <!-- Where it is along the pipeline, drawn from the
                                 same ordered stages the server uses. -->
                            <ol v-if="refund.status !== 'rejected'" class="mt-4 flex items-center gap-1.5" aria-label="Refund pipeline">
                                <li
                                    v-for="(stage, index) in refundPipeline"
                                    :key="stage.value"
                                    class="flex items-center gap-1.5"
                                >
                                    <span
                                        class="size-2.5 rounded-full"
                                        :class="{
                                            'bg-success': stageState(refund, index) === 'done',
                                            'bg-csc-blue': stageState(refund, index) === 'current',
                                            'bg-csc-line': stageState(refund, index) === 'upcoming',
                                        }"
                                    ></span>
                                    <span
                                        class="text-xs"
                                        :class="stageState(refund, index) === 'upcoming' ? 'text-csc-ink/45' : 'font-medium text-csc-ink'"
                                    >
                                        {{ stage.label }}
                                    </span>
                                    <span v-if="index < refundPipeline.length - 1" class="h-px w-4 bg-csc-line"></span>
                                </li>
                            </ol>
                            <p v-else class="mt-3 text-xs font-semibold uppercase tracking-wide text-csc-red-ink">
                                Declined
                            </p>

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

                <AppPagination :pagination="refunds" label="refunds" class="pt-1" />
            </template>
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
                    ? `${verifying.participant} paid ₱${money(verifying.amount)} for “${verifying.training}”.`
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

                <!--
                    Unlike a counter payment, the amount here is already fixed —
                    the money has moved. Ticking this records *why* it fell
                    short of the full fee; the server refuses if the two do not
                    reconcile, because an overpayment is a discrepancy rather
                    than something to annotate.
                -->
                <div class="rounded-lg border border-csc-line bg-csc-blue-tint/30 p-3">
                    <label class="flex items-start gap-3 text-sm text-csc-ink">
                        <input
                            v-model="verifyForm.prime_hrm_discount"
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0 rounded border-csc-line accent-csc-blue"
                        />
                        <span class="leading-relaxed">
                            Paid under the PRIME-HRM 20% discount — this payment is the discounted
                            fee, not the full one.
                        </span>
                    </label>
                    <p v-if="verifyForm.errors.prime_hrm_discount" class="mt-2 text-xs font-medium text-csc-red-ink">
                        {{ verifyForm.errors.prime_hrm_discount }}
                    </p>
                </div>

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="verifying = null">Cancel</AppButton>
                    <AppButton type="submit" icon="check" :loading="verifyForm.processing">
                        Verify payment
                    </AppButton>
                </div>
            </form>
        </AppModal>

        <!--
            The bank-deposit details. This row is what the approval email and the
            participant payment prompt print, so the modal explains that editing
            it changes every future notification at once.
        -->
        <AppModal
            :open="editingSettings"
            title="Bank deposit details"
            subtitle="Participants are told to pay training fees into this account. Saving updates every approval notification and payment prompt."
            @close="closeSettings"
        >
            <form class="space-y-4" @submit.prevent="submitSettings">
                <AppInput
                    v-model="settingsForm.bank_name"
                    label="Bank"
                    placeholder="e.g. Land Bank of the Philippines"
                    :error="settingsForm.errors.bank_name"
                    required
                />
                <AppInput
                    v-model="settingsForm.account_name"
                    label="Account name"
                    :error="settingsForm.errors.account_name"
                    required
                />
                <AppInput
                    v-model="settingsForm.account_number"
                    label="Account number"
                    :error="settingsForm.errors.account_number"
                    required
                />
                <AppTextarea
                    v-model="settingsForm.instructions"
                    label="Instructions"
                    hint="Optional. Extra guidance shown with the account, e.g. how to mark a deposit."
                    :rows="3"
                    :error="settingsForm.errors.instructions"
                />

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="closeSettings">Cancel</AppButton>
                    <AppButton type="submit" :processing="settingsForm.processing">Save</AppButton>
                </div>
            </form>
        </AppModal>
    </AuthenticatedLayout>
</template>
