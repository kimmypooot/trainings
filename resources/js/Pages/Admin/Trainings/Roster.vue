<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

const props = defineProps({
    training: { type: Object, required: true },
    registrations: { type: Array, required: true },
    summary: { type: Object, required: true },
    scopedTo: { type: String, default: null },
    attendanceStatuses: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash?.success);
const errors = computed(() => Object.values(page.props.errors ?? {}));

/**
 * Completion follows the attendance record. When it falls short the server
 * refuses, so the override path is explicit and has to carry a reason.
 */
const markComplete = (registration) => {
    let payload = {};

    if (!registration.can_complete) {
        const remarks = window.prompt(
            `${registration.name} was recorded for ${registration.credited_days} of ` +
                `${training.duration_days} day(s). Give a reason to complete anyway:`
        );

        if (!remarks) {
            return;
        }

        payload = { force: true, remarks };
    }

    router.post(`/admin/registrations/${registration.id}/complete`, payload, { preserveScroll: true });
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

const decide = (id, decision) => {
    // A rejection has to carry a reason, so it is the one path that prompts.
    let remarks = null;

    if (decision === 'rejected') {
        remarks = window.prompt('Reason for rejecting this registration:');

        if (!remarks) {
            return;
        }
    }

    router.post(`/admin/registrations/${id}/review`, { decision, remarks }, { preserveScroll: true });
};

const pendingCount = computed(() => props.registrations.filter((r) => r.status === 'pending').length);

const restrictions = computed(() => props.registrations.filter((r) => r.food_restrictions));
</script>

<template>
    <Head :title="`Roster — ${training.title}`" />

    <AuthenticatedLayout title="Roster" current="admin-trainings">
        <div class="mx-auto max-w-5xl space-y-5">
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

            <AppAlert v-if="flash" tone="success">{{ flash }}</AppAlert>

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

            <AppCard title="Participants" :padded="!registrations.length">
                <AppEmptyState
                    v-if="!registrations.length"
                    title="No one has registered yet"
                    description="Registrations will appear here as participants sign up."
                    icon="M3 20a6 6 0 0 1 12 0M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7"
                />

                <div v-else class="-mx-5 overflow-x-auto sm:-mx-6">
                    <table class="w-full min-w-160 text-left text-sm">
                        <thead class="border-y border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Participant</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Agency</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Field Office</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Status</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Attendance</th>
                                <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink/70">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-csc-line">
                            <tr v-for="registration in registrations" :key="registration.id">
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
                                        class="mt-1 text-[11px] text-csc-ink/55"
                                    >
                                        {{ registration.credited_days }} of {{ training.duration_days }} days
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                    <template v-if="registration.status === 'pending'">
                                        <button
                                            type="button"
                                            class="rounded text-xs font-semibold text-success hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                            @click="decide(registration.id, 'approved')"
                                        >
                                            Approve
                                        </button>
                                        <span class="px-2 text-csc-line">|</span>
                                        <button
                                            type="button"
                                            class="rounded text-xs font-semibold text-warning hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                            @click="decide(registration.id, 'waitlisted')"
                                        >
                                            Waitlist
                                        </button>
                                        <span class="px-2 text-csc-line">|</span>
                                        <button
                                            type="button"
                                            class="rounded text-xs font-semibold text-danger hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                            @click="decide(registration.id, 'rejected')"
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
                                            class="font-mono text-[11px] text-csc-ink/60"
                                        >
                                            {{ registration.certificate_number }}
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
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
