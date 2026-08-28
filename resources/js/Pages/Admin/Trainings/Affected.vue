<script setup>
/**
 * Who a rescheduled run stranded.
 *
 * Deliberately not a filter on the roster. The roster is read on the day of an
 * event, about attendance and certificates; this is read at a desk afterwards,
 * about money, and the two questions want different columns in front of them.
 *
 * The screen makes no decisions of its own. Every row's movability is decided
 * on the server by the same helper the transfer itself uses, so what is shown
 * here is what will happen — this page only renders the verdict.
 */
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppModal from '@/Components/AppModal.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import { formatDateRange } from '@/dateRange';

const props = defineProps({
    training: { type: Object, required: true },
    target: { type: Object, default: null },
    affected: { type: Array, required: true },
    summary: { type: Object, required: true },
    scopedTo: { type: String, default: null },
    transferTargets: { type: Array, default: () => [] },
});

const peso = (value) =>
    new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value ?? 0);

/* -------------------------------------------------------------------------- */
/* Fee state                                                                   */
/* -------------------------------------------------------------------------- */

/*
 * Never colour alone — each carries its own icon through AppBadge and a word.
 * "Promissory note" is spelled out rather than shortened because the entire
 * point of the distinction is that it reads as money the office does *not*
 * hold, and every abbreviation of it so far has been mistaken for money in.
 */
const feeStates = {
    paid: { status: 'verified', label: 'Paid' },
    promissory: { status: 'pending', label: 'Promissory note' },
    unpaid: { status: 'neutral', label: 'Unpaid' },
    free: { status: 'neutral', label: 'No fee' },
};

const feeState = (row) => feeStates[row.fee_state] ?? feeStates.unpaid;

/* -------------------------------------------------------------------------- */
/* Filtering                                                                   */
/* -------------------------------------------------------------------------- */

const feeFilter = ref('');

const filters = computed(() => [
    { value: '', label: 'All', count: props.summary.total },
    { value: 'paid', label: 'Paid', count: props.summary.paid },
    { value: 'promissory', label: 'Promissory', count: props.summary.promissory },
    { value: 'unpaid', label: 'Unpaid', count: props.summary.unpaid },
]);

const filtered = computed(() =>
    feeFilter.value ? props.affected.filter((row) => row.fee_state === feeFilter.value) : props.affected
);

/* -------------------------------------------------------------------------- */
/* Selection                                                                   */
/* -------------------------------------------------------------------------- */

const selected = ref(new Set());

// Only ever the movable ones. Offering a checkbox beside a row the server has
// already refused would be inviting a click that silently does nothing.
const selectableIds = computed(() => props.affected.filter((row) => row.movable).map((row) => row.id));

const visibleSelectable = computed(() =>
    filtered.value.filter((row) => row.movable).map((row) => row.id)
);

// A reload re-decides who is movable — someone may have taken the last seat —
// so a selection made against the old answer is dropped rather than carried.
watch(
    () => props.affected,
    () => {
        selected.value = new Set([...selected.value].filter((id) => selectableIds.value.includes(id)));
    }
);

const toggle = (id) => {
    const next = new Set(selected.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selected.value = next;
};

const allSelected = computed(
    () => visibleSelectable.value.length > 0 && visibleSelectable.value.every((id) => selected.value.has(id))
);

const toggleAll = () => {
    const next = new Set(selected.value);
    allSelected.value
        ? visibleSelectable.value.forEach((id) => next.delete(id))
        : visibleSelectable.value.forEach((id) => next.add(id));
    selected.value = next;
};

/** How much of what the office is holding walks out with this selection. */
const selectedValue = computed(() =>
    props.affected
        .filter((row) => selected.value.has(row.id) && row.fee_state === 'paid')
        .reduce((total, row) => total + (row.amount ?? 0), 0)
);

/* -------------------------------------------------------------------------- */
/* Moving them across                                                          */
/* -------------------------------------------------------------------------- */

const moving = ref(false);

// The training's date, rendered as a range where it spans more than one day —
// used both in the header and to pre-fill the reason a transfer is offered.
const trainingDateRange = computed(() => formatDateRange(props.training.starts_at, props.training.ends_at));
const targetDateRange = computed(() =>
    props.target ? formatDateRange(props.target.starts_at, props.target.ends_at) : ''
);

const form = useForm({
    target_training_id: props.target?.id ?? '',
    reason: props.target
        ? `“${props.training.title}” on ${trainingDateRange.value} was rescheduled.`
        : '',
    ids: [],
});

const startMove = () => {
    form.clearErrors();
    form.ids = [...selected.value];
    form.target_training_id = props.target?.id ?? form.target_training_id;
    moving.value = true;
};

const submitMove = () => {
    form.ids = [...selected.value];
    form.post(`/admin/trainings/${props.training.id}/registrations/transfer`, {
        preserveScroll: true,
        onSuccess: () => {
            moving.value = false;
            selected.value = new Set();
        },
    });
};

/** Weigh the same roster against a different candidate date. */
const compareAgainst = (id) => {
    router.get(
        `/admin/trainings/${props.training.id}/affected`,
        id ? { target: id } : {},
        { preserveScroll: true, preserveState: true }
    );
};

const exportUrl = computed(() => {
    const query = props.target ? `?target=${props.target.id}` : '';

    return `/admin/exports/trainings/${props.training.id}/affected${query}`;
});
</script>

<template>
    <Head :title="`Affected Participants — ${training.title}`" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold tracking-wide text-csc-ink-subtle uppercase">
                        Affected participants
                    </p>
                    <h1 class="mt-1 text-2xl font-semibold text-csc-ink">{{ training.title }}</h1>
                    <p class="mt-1 text-sm text-csc-ink-muted">
                        {{ trainingDateRange }} · {{ training.venue }}
                    </p>
                    <p v-if="scopedTo" class="mt-1 text-xs text-csc-ink-subtle">
                        Showing {{ scopedTo }} participants only.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2 print:hidden">
                    <AppButton
                        variant="ghost"
                        size="sm"
                        icon="download"
                        :href="exportUrl"
                        external
                    >
                        Export
                    </AppButton>
                    <AppButton
                        variant="ghost"
                        size="sm"
                        :href="`/admin/trainings/${training.id}/roster`"
                    >
                        Back to Roster
                    </AppButton>
                </div>
            </header>

            <!--
                The hazard worth interrupting for: a run still published while
                its replacement is on offer keeps taking registrations for dates
                that will not happen, and every one of them lands on this list a
                day later.
            -->
            <AppAlert v-if="training.still_open" tone="warning" title="This run is still accepting registrations">
                It is published, so participants can still sign up for the old dates. Close or cancel
                it once everyone has been moved.
            </AppAlert>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <AppCard>
                    <p class="text-xs font-medium text-csc-ink-subtle uppercase">Affected</p>
                    <p class="mt-1 text-2xl font-semibold text-csc-ink">{{ summary.total }}</p>
                    <p class="mt-1 text-xs text-csc-ink-subtle">Holding or waiting on a slot</p>
                </AppCard>
                <AppCard>
                    <p class="text-xs font-medium text-csc-ink-subtle uppercase">Collected</p>
                    <p class="mt-1 text-2xl font-semibold text-success">{{ peso(summary.collected) }}</p>
                    <p class="mt-1 text-xs text-csc-ink-subtle">{{ summary.paid }} paid in full</p>
                </AppCard>
                <AppCard>
                    <p class="text-xs font-medium text-csc-ink-subtle uppercase">On promissory notes</p>
                    <p class="mt-1 text-2xl font-semibold text-warning">{{ peso(summary.promised) }}</p>
                    <p class="mt-1 text-xs text-csc-ink-subtle">
                        {{ summary.promissory }} promised, not received
                    </p>
                </AppCard>
                <AppCard>
                    <p class="text-xs font-medium text-csc-ink-subtle uppercase">Can be moved</p>
                    <p class="mt-1 text-2xl font-semibold text-csc-ink">
                        {{ target ? summary.movable : '—' }}
                    </p>
                    <p class="mt-1 text-xs text-csc-ink-subtle">
                        {{ target ? `${summary.blocked} blocked` : 'Choose a run to compare against' }}
                    </p>
                </AppCard>
            </div>

            <AppCard
                title="New schedule"
                :subtitle="
                    target
                        ? 'Everyone moved keeps their registration date, attendance and payment.'
                        : 'Pick the run these participants should move to.'
                "
            >
                <div class="space-y-4">
                    <AppSelect
                        :model-value="target?.id ?? ''"
                        label="Move them to"
                        :options="transferTargets"
                        placeholder="Choose a training"
                        @update:model-value="compareAgainst"
                    />

                    <div v-if="target" class="space-y-3">
                        <p class="text-sm text-csc-ink-muted">
                            <span class="font-medium text-csc-ink">{{ target.title }}</span>
                            — {{ targetDateRange }} · {{ target.venue }}
                        </p>

                        <!--
                            Both of these are consequences of rules enforced in
                            the service, surfaced here because this is where the
                            decision is actually made rather than where it fails.
                        -->
                        <AppAlert
                            v-if="!target.accepts_transfers"
                            tone="danger"
                            title="Nobody can be moved onto this run yet"
                        >
                            It is {{ target.status_label.toLowerCase() }}. Publish it first — a transfer
                            onto a run participants cannot see would strand them a second time.
                        </AppAlert>

                        <AppAlert
                            v-else-if="target.fee_difference !== 0"
                            tone="warning"
                            title="The fee is different on the new run"
                        >
                            {{ peso(Math.abs(target.fee_difference)) }}
                            {{ target.fee_difference > 0 ? 'more' : 'less' }} per participant. The
                            difference is recorded against each transfer for finance to collect or
                            refund; it is not adjusted automatically.
                        </AppAlert>
                    </div>
                </div>
            </AppCard>

            <AppCard :padded="false">
                <div class="flex flex-wrap gap-2 px-5 py-4 sm:px-6 print:hidden">
                    <button
                        v-for="filter in filters"
                        :key="filter.value"
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        :class="
                            feeFilter === filter.value
                                ? 'border-csc-blue bg-csc-blue text-white'
                                : 'border-csc-line text-csc-ink-muted hover:border-csc-blue'
                        "
                        @click="feeFilter = filter.value"
                    >
                        {{ filter.label }} ({{ filter.count }})
                    </button>
                </div>

                <AppEmptyState
                    v-if="!filtered.length"
                    icon="users"
                    title="Nobody to move"
                    :description="
                        affected.length
                            ? 'No affected participant matches this filter.'
                            : 'This run has no registrations holding or waiting on a slot.'
                    "
                />

                <template v-else>
                    <div
                        v-if="selected.size"
                        class="sticky bottom-[calc(3.5rem+env(safe-area-inset-bottom))] z-(--z-tabbar) flex flex-wrap items-center gap-3 border-y border-csc-line bg-white/95 px-5 py-3 backdrop-blur sm:px-6 md:bottom-0 print:hidden"
                    >
                        <p class="text-sm font-medium text-csc-ink" role="status">
                            {{ selected.size }} selected
                            <span v-if="selectedValue" class="font-normal text-csc-ink-subtle">
                                · {{ peso(selectedValue) }} collected moves with them
                            </span>
                        </p>

                        <button
                            type="button"
                            class="rounded text-xs font-medium text-csc-ink-subtle underline hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            @click="selected = new Set()"
                        >
                            Clear
                        </button>

                        <div class="ml-auto">
                            <AppButton
                                size="sm"
                                icon="calendar"
                                :disabled="!target || !target.accepts_transfers"
                                @click="startMove"
                            >
                                Move {{ selected.size }} to the New Schedule
                            </AppButton>
                        </div>
                    </div>

                    <div class="-mx-5 overflow-x-auto sm:-mx-6">
                        <table class="w-full min-w-200 text-left text-sm">
                            <thead class="border-y border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                                <tr>
                                    <th scope="col" class="w-10 px-5 py-3 sm:px-6 print:hidden">
                                        <input
                                            type="checkbox"
                                            class="size-4 rounded border-csc-line text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                            :checked="allSelected"
                                            :indeterminate="selected.size > 0 && !allSelected"
                                            :disabled="!visibleSelectable.length"
                                            :aria-label="`Select all movable participants`"
                                            @change="toggleAll"
                                        />
                                    </th>
                                    <th scope="col" class="px-3 py-3 font-semibold">Participant</th>
                                    <th scope="col" class="px-3 py-3 font-semibold">Field Office</th>
                                    <th scope="col" class="px-3 py-3 font-semibold">Registration</th>
                                    <th scope="col" class="px-3 py-3 font-semibold">Fee</th>
                                    <th scope="col" class="px-3 py-3 font-semibold">Amount</th>
                                    <th scope="col" class="px-3 py-3 font-semibold">Can Be Moved</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-csc-line">
                                <tr v-for="row in filtered" :key="row.id" class="align-top">
                                    <td class="px-5 py-3 sm:px-6 print:hidden">
                                        <input
                                            type="checkbox"
                                            class="size-4 rounded border-csc-line text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                            :checked="selected.has(row.id)"
                                            :disabled="!row.movable"
                                            :aria-label="`Select ${row.name}`"
                                            @change="toggle(row.id)"
                                        />
                                    </td>
                                    <td class="px-3 py-3">
                                        <p class="font-medium text-csc-ink">{{ row.name }}</p>
                                        <p class="text-xs text-csc-ink-subtle">{{ row.email }}</p>
                                    </td>
                                    <td class="px-3 py-3 text-csc-ink-muted">{{ row.office ?? '—' }}</td>
                                    <td class="px-3 py-3">
                                        <AppBadge :status="row.status" :label="row.status_label" />
                                        <p class="mt-1 text-xs text-csc-ink-subtle">{{ row.registered_at }}</p>
                                    </td>
                                    <td class="px-3 py-3">
                                        <AppBadge
                                            :status="feeState(row).status"
                                            :label="feeState(row).label"
                                        />
                                        <p v-if="row.or_number" class="mt-1 text-xs text-csc-ink-subtle">
                                            OR {{ row.or_number }}
                                        </p>
                                    </td>
                                    <td class="px-3 py-3 text-csc-ink-muted">
                                        {{ row.amount === null ? '—' : peso(row.amount) }}
                                    </td>
                                    <td class="px-3 py-3">
                                        <span v-if="!target" class="text-xs text-csc-ink-subtle">
                                            Choose a run
                                        </span>
                                        <span
                                            v-else-if="row.movable"
                                            class="text-xs font-medium text-success"
                                        >
                                            Yes
                                        </span>
                                        <span v-else class="text-xs text-csc-ink-muted">
                                            No — {{ row.blocker }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </AppCard>
        </div>

        <AppModal
            :open="moving"
            title="Move to the new schedule"
            :subtitle="`${selected.size} participant(s). Their registration date, attendance and any payment move with them.`"
            @close="moving = false"
        >
            <form class="space-y-4" @submit.prevent="submitMove">
                <AppSelect
                    v-model="form.target_training_id"
                    label="Move to"
                    :options="transferTargets"
                    placeholder="Choose a training"
                    :error="form.errors.target_training_id"
                    required
                />

                <AppTextarea
                    v-model="form.reason"
                    label="Why are they being moved?"
                    hint="Shown to every participant in the notification they receive."
                    :error="form.errors.reason"
                    required
                />

                <p v-if="form.errors.ids" class="text-xs font-medium text-csc-red-ink">
                    {{ form.errors.ids }}
                </p>
                <p v-if="form.errors.transfer" class="text-xs font-medium text-csc-red-ink">
                    {{ form.errors.transfer }}
                </p>

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="moving = false">Cancel</AppButton>
                    <AppButton type="submit" :loading="form.processing">
                        Move {{ selected.size }} Participant(s)
                    </AppButton>
                </div>
            </form>
        </AppModal>
    </AuthenticatedLayout>
</template>
