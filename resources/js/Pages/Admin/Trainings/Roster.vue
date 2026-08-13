<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppPromptModal from '@/Components/AppPromptModal.vue';

const props = defineProps({
    training: { type: Object, required: true },
    registrations: { type: Array, required: true },
    summary: { type: Object, required: true },
    scopedTo: { type: String, default: null },
    attendanceStatuses: { type: Array, default: () => [] },
});

const page = usePage();
const errors = computed(() => Object.values(page.props.errors ?? {}));

/*
 * Anything that has to be justified opens the same dialog.
 *
 * `prompt` describes what is being decided; `onConfirm` receives the reason and
 * performs it. One piece of state, so two dialogs can never be open at once.
 */
const prompt = ref(null);
const promptBusy = ref(false);

const askFor = (config) => {
    prompt.value = config;
};

const closePrompt = () => {
    prompt.value = null;
    promptBusy.value = false;
};

const confirmPrompt = (reason) => {
    promptBusy.value = true;
    prompt.value.onConfirm(reason);
};

const post = (url, payload) =>
    router.post(url, payload, {
        preserveScroll: true,
        onSuccess: closePrompt,
        onFinish: () => (promptBusy.value = false),
    });

/**
 * Completion follows the attendance record. When it falls short the server
 * refuses, so the override path is explicit and has to carry a reason.
 */
const markComplete = (registration) => {
    if (registration.can_complete) {
        post(`/admin/registrations/${registration.id}/complete`, {});

        return;
    }

    askFor({
        title: 'Complete without a full attendance record',
        description:
            `${registration.name} was recorded for ${registration.credited_days} of ` +
            `${props.training.duration_days} day(s).`,
        label: 'Reason for the override',
        hint: 'Kept on the registration so the exception stays auditable.',
        confirmLabel: 'Complete anyway',
        minLength: 10,
        onConfirm: (remarks) =>
            post(`/admin/registrations/${registration.id}/complete`, { force: true, remarks }),
    });
};

const setAttendance = (registration, day, status) => {
    if (!status) {
        return;
    }

    router.post(
        `/admin/registrations/${registration.id}/attendance`,
        { training_day: day, status },
        { preserveScroll: true }
    );
};

// Only a participant holding a place can be marked, matching AttendanceService.
const isMarkable = (registration) => ['approved', 'completed'].includes(registration.status);

const releaseCertificate = (id) =>
    router.post(`/admin/registrations/${id}/certificate`, {}, { preserveScroll: true });

const releaseAll = () =>
    router.post(`/admin/trainings/${props.training.id}/certificates`, {}, { preserveScroll: true });

const awaitingCertificates = computed(
    () => props.registrations.filter((r) => r.status === 'completed' && !r.certificate_number).length
);

const decide = (registration, decision) => {
    const url = `/admin/registrations/${registration.id}/review`;

    // A rejection has to carry a reason, so it is the one path that asks.
    if (decision !== 'rejected') {
        post(url, { decision, remarks: null });

        return;
    }

    askFor({
        title: 'Reject this registration',
        description: `${registration.name} will be told the registration was not approved.`,
        label: 'Reason for rejection',
        hint: 'Recorded against the registration.',
        confirmLabel: 'Reject registration',
        minLength: 10,
        onConfirm: (remarks) => post(url, { decision, remarks }),
    });
};

const pendingCount = computed(() => props.registrations.filter((r) => r.status === 'pending').length);

const restrictions = computed(() => props.registrations.filter((r) => r.food_restrictions));

/*
 * Selection.
 *
 * Only rows a bulk action could actually apply to are selectable — a checkbox
 * beside a cancelled registration is a promise the server will refuse to keep.
 */
const selectable = computed(() =>
    props.registrations.filter((r) => ['pending', 'approved'].includes(r.status))
);

const selected = ref(new Set());

// A selection is only meaningful for the rows currently on screen; when the
// roster reloads after an action, anything no longer selectable drops out.
watch(
    () => props.registrations,
    () => {
        const ids = new Set(selectable.value.map((r) => r.id));
        selected.value = new Set([...selected.value].filter((id) => ids.has(id)));
    }
);

const isSelected = (id) => selected.value.has(id);

const toggle = (id) => {
    const next = new Set(selected.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selected.value = next;
};

const allSelected = computed(
    () => selectable.value.length > 0 && selected.value.size === selectable.value.length
);

const toggleAll = () => {
    selected.value = allSelected.value ? new Set() : new Set(selectable.value.map((r) => r.id));
};

const selectedRows = computed(() => props.registrations.filter((r) => selected.value.has(r.id)));
const selectedPending = computed(() => selectedRows.value.filter((r) => r.status === 'pending').length);
const selectedApproved = computed(() => selectedRows.value.filter((r) => r.status === 'approved').length);

const applying = ref(false);

const sendBulk = (action, remarks = null) => {
    applying.value = true;

    router.post(
        `/admin/trainings/${props.training.id}/registrations/bulk`,
        { action, ids: [...selected.value], remarks },
        {
            preserveScroll: true,
            onSuccess: closePrompt,
            onFinish: () => {
                applying.value = false;
                promptBusy.value = false;
            },
        }
    );
};

const applyBulk = (action) => {
    if (action !== 'rejected') {
        sendBulk(action);

        return;
    }

    const count = selectedPending.value;

    askFor({
        title: `Reject ${count} registration(s)`,
        description: 'The same reason is recorded against every one of them.',
        label: 'Reason for rejection',
        confirmLabel: `Reject ${count}`,
        minLength: 10,
        onConfirm: (remarks) => sendBulk(action, remarks),
    });
};
</script>

<template>
    <Head :title="`Roster — ${training.title}`" />

    <AuthenticatedLayout title="Roster" current="admin-trainings">
        <div class="mx-auto max-w-6xl space-y-5">
            <Link
                href="/admin/trainings"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-csc-blue hover:text-csc-blue-deep"
            >
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M15 6l-6 6 6 6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                Manage Trainings
            </Link>

            <div class="flex flex-wrap gap-3">
                <AppButton :href="`/admin/exports/trainings/${training.id}/roster`" variant="ghost" size="sm">
                    Export Roster (CSV)
                </AppButton>
                <AppButton
                    :href="`/admin/exports/trainings/${training.id}/roster?format=xlsx`"
                    variant="ghost"
                    size="sm"
                >
                    Export Roster (Excel)
                </AppButton>
            </div>

            <AppAlert v-for="message in errors" :key="message" tone="danger">{{ message }}</AppAlert>

            <AppAlert v-if="scopedTo" tone="info">
                Showing participants from <strong>{{ scopedTo }}</strong> only.
            </AppAlert>

            <AppCard :title="training.title" :subtitle="`${training.starts_at} · ${training.venue}`">
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-6">
                    <div>
                        <p class="text-2xl font-bold text-warning">{{ pendingCount }}</p>
                        <p class="text-xs text-csc-ink/60">Pending</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-csc-blue">{{ summary.checked_in_today }}</p>
                        <p class="text-xs text-csc-ink/60">Checked in today</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-csc-blue">{{ summary.active }}</p>
                        <p class="text-xs text-csc-ink/60">Holding a slot</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-success">{{ summary.completed }}</p>
                        <p class="text-xs text-csc-ink/60">Completed</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-danger">{{ summary.cancelled }}</p>
                        <p class="text-xs text-csc-ink/60">Cancelled</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-warning">{{ summary.with_food_restrictions }}</p>
                        <p class="text-xs text-csc-ink/60">Food restrictions</p>
                    </div>
                </div>
            </AppCard>

            <AppAlert v-if="awaitingCertificates" tone="info" title="Certificates ready to issue">
                <p>
                    {{ awaitingCertificates }} completed participant(s) have no certificate yet.
                </p>
                <AppButton class="mt-3" size="sm" @click="releaseAll">Issue All Certificates</AppButton>
            </AppAlert>

            <!-- Catering needs this as a list, not buried per-row -->
            <AppAlert v-if="restrictions.length" tone="warning" title="Food restrictions for catering">
                <ul class="mt-1 space-y-1">
                    <li v-for="item in restrictions" :key="item.id">
                        <span class="font-medium">{{ item.name }}</span> — {{ item.food_restrictions }}
                    </li>
                </ul>
            </AppAlert>

            <AppCard title="Participants" :padded="registrations.length > 0">
                <AppEmptyState
                    v-if="!registrations.length"
                    title="No one has registered yet"
                    description="Registrations will appear here as participants sign up."
                    icon="users"
                />

                <template v-else>
                <!--
                    The bulk bar sticks to the bottom of the viewport rather
                    than the top of the table: on a long roster the selection is
                    made while scrolled well past any header.

                    Below md it has to clear the mobile tab bar, which is fixed
                    to the bottom at 3.5rem plus the safe-area inset; at md that
                    bar is gone and this one can sit flush.
                -->
                <div
                    v-if="selected.size"
                    class="sticky bottom-[calc(3.5rem+env(safe-area-inset-bottom))] z-(--z-tabbar) -mx-5 flex flex-wrap items-center gap-3 border-t border-csc-line bg-white/95 px-5 py-3 backdrop-blur sm:-mx-6 sm:px-6 md:bottom-0"
                >
                    <p class="text-sm font-medium text-csc-ink" role="status">
                        {{ selected.size }} selected
                    </p>

                    <button
                        type="button"
                        class="rounded text-xs font-medium text-csc-ink/60 underline hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        @click="selected = new Set()"
                    >
                        Clear
                    </button>

                    <div class="ml-auto flex flex-wrap gap-2">
                        <AppButton
                            v-if="selectedPending"
                            size="sm"
                            variant="ghost"
                            :loading="applying"
                            @click="applyBulk('approved')"
                        >
                            Approve {{ selectedPending }}
                        </AppButton>
                        <AppButton
                            v-if="selectedPending"
                            size="sm"
                            variant="ghost"
                            :loading="applying"
                            @click="applyBulk('waitlisted')"
                        >
                            Waitlist {{ selectedPending }}
                        </AppButton>
                        <AppButton
                            v-if="selectedPending"
                            size="sm"
                            variant="ghost"
                            :loading="applying"
                            @click="applyBulk('rejected')"
                        >
                            Reject {{ selectedPending }}
                        </AppButton>
                        <AppButton
                            v-if="selectedApproved"
                            size="sm"
                            :loading="applying"
                            @click="applyBulk('completed')"
                        >
                            Mark {{ selectedApproved }} Complete
                        </AppButton>
                    </div>
                </div>

                <div class="-mx-5 overflow-x-auto sm:-mx-6">
                    <table class="w-full min-w-160 text-left text-sm">
                        <thead class="border-y border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                            <tr>
                                <th scope="col" class="w-10 py-3 pl-5">
                                    <input
                                        type="checkbox"
                                        class="size-4 rounded border-csc-line text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                        :checked="allSelected"
                                        :indeterminate="selected.size > 0 && !allSelected"
                                        :disabled="!selectable.length"
                                        :aria-label="allSelected ? 'Clear selection' : 'Select all actionable participants'"
                                        @change="toggleAll"
                                    />
                                </th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Participant</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Agency</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Field Office</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Status</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Attendance</th>
                                <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink/70">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-csc-line">
                            <tr
                                v-for="registration in registrations"
                                :key="registration.id"
                                :class="isSelected(registration.id) ? 'bg-csc-blue-tint/50' : ''"
                            >
                                <td class="py-3.5 pl-5">
                                    <input
                                        v-if="['pending', 'approved'].includes(registration.status)"
                                        type="checkbox"
                                        class="size-4 rounded border-csc-line text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                        :checked="isSelected(registration.id)"
                                        :aria-label="`Select ${registration.name}`"
                                        @change="toggle(registration.id)"
                                    />
                                </td>
                                <td class="px-5 py-3.5">
                                    <p class="font-medium text-csc-ink">{{ registration.name }}</p>
                                    <p class="mt-0.5 text-xs text-csc-ink/60">{{ registration.email }}</p>
                                </td>
                                <td class="px-5 py-3.5 text-csc-ink/75">
                                    {{ registration.organization ?? '—' }}
                                    <p v-if="registration.position" class="mt-0.5 text-xs text-csc-ink/55">
                                        {{ registration.position }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-xs text-csc-ink/70">
                                    {{ registration.field_office ?? '—' }}
                                </td>
                                <td class="px-5 py-3.5">
                                    <AppBadge :status="registration.status" />
                                    <p
                                        v-if="registration.review_remarks"
                                        class="mt-1 max-w-48 text-xs text-csc-ink/55"
                                    >
                                        {{ registration.review_remarks }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <div v-if="isMarkable(registration)" class="flex flex-wrap gap-1.5">
                                        <label
                                            v-for="day in training.days"
                                            :key="day.day"
                                            class="flex flex-col gap-0.5"
                                        >
                                            <span
                                                class="text-[10px] font-semibold uppercase"
                                                :class="day.is_today ? 'text-csc-red-ink' : 'text-csc-ink/50'"
                                            >
                                                {{ day.label }}
                                            </span>
                                            <select
                                                class="rounded border border-csc-line bg-white px-1.5 py-1 text-xs text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                                                :value="registration.attendance[day.day]?.status ?? ''"
                                                @change="setAttendance(registration, day.day, $event.target.value)"
                                            >
                                                <option value="">—</option>
                                                <option
                                                    v-for="option in attendanceStatuses"
                                                    :key="option.value"
                                                    :value="option.value"
                                                >
                                                    {{ option.label }}
                                                </option>
                                            </select>
                                        </label>
                                    </div>
                                    <span v-else class="text-xs text-csc-ink/50">—</span>

                                    <p
                                        v-if="isMarkable(registration) && training.duration_days > 1"
                                        class="mt-1 text-2xs text-csc-ink/55"
                                    >
                                        {{ registration.credited_days }} of {{ training.duration_days }} days
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                    <template v-if="registration.status === 'pending'">
                                        <button
                                            type="button"
                                            class="rounded text-xs font-semibold text-success hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                            @click="decide(registration, 'approved')"
                                        >
                                            Approve
                                        </button>
                                        <span class="px-2 text-csc-line">|</span>
                                        <button
                                            type="button"
                                            class="rounded text-xs font-semibold text-warning hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                            @click="decide(registration, 'waitlisted')"
                                        >
                                            Waitlist
                                        </button>
                                        <span class="px-2 text-csc-line">|</span>
                                        <button
                                            type="button"
                                            class="rounded text-xs font-semibold text-danger hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                            @click="decide(registration, 'rejected')"
                                        >
                                            Reject
                                        </button>
                                    </template>

                                    <AppButton
                                        v-else-if="registration.status === 'approved'"
                                        size="sm"
                                        variant="ghost"
                                        @click="markComplete(registration)"
                                    >
                                        {{ registration.can_complete ? 'Mark Complete' : 'Complete (Override)' }}
                                    </AppButton>

                                    <template v-else-if="registration.status === 'completed'">
                                        <span
                                            v-if="registration.certificate_number"
                                            class="font-mono text-2xs text-csc-ink/60"
                                        >
                                            {{ registration.certificate_number }}
                                        </span>
                                        <!--
                                            A promissory note gets someone into
                                            the room but not onto a certificate,
                                            so the button is replaced by the
                                            reason rather than left to fail.
                                        -->
                                        <span
                                            v-else-if="!registration.fee_cleared"
                                            class="text-2xs text-warning"
                                        >
                                            Fee outstanding
                                        </span>
                                        <AppButton
                                            v-else
                                            size="sm"
                                            variant="ghost"
                                            @click="releaseCertificate(registration.id)"
                                        >
                                            Issue Certificate
                                        </AppButton>
                                    </template>

                                    <span v-else class="text-xs text-csc-ink/50">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                </template>
            </AppCard>
        </div>

        <AppPromptModal
            :open="prompt !== null"
            :title="prompt?.title ?? ''"
            :description="prompt?.description"
            :label="prompt?.label"
            :hint="prompt?.hint"
            :confirm-label="prompt?.confirmLabel"
            :min-length="prompt?.minLength ?? 1"
            :processing="promptBusy"
            @confirm="confirmPrompt"
            @close="closePrompt"
        />
    </AuthenticatedLayout>
</template>
