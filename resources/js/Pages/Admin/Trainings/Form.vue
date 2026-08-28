<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    training: { type: Object, default: null },
    // Set when this form is creating the replacement for a run that was called
    // off, so the page can say which one and how many people are waiting on it.
    rescheduling: { type: Object, default: null },
    statuses: { type: Array, required: true },
    modes: { type: Array, required: true },
    levels: { type: Array, required: true },
    curricula: { type: Array, required: true },
    experts: { type: Array, default: () => [] },
    expertsUrl: { type: String, default: '/admin/smes' },
});

/*
 * Keyed on the id rather than on `training` being present, because a
 * reschedule arrives here with the old run's details prefilled but no id: it is
 * a new record that happens to start life as a copy, and treating it as an edit
 * would overwrite the very run the office is trying to preserve.
 */
const isEdit = computed(() => Boolean(props.training?.id));

const pageTitle = computed(() => {
    if (props.rescheduling) return 'Reschedule Training';

    return isEdit.value ? 'Edit Training' : 'New Training';
});

const form = useForm({
    title: props.training?.title ?? '',
    training_code: props.training?.training_code ?? '',
    description: props.training?.description ?? '',
    category: props.training?.category ?? '',
    level: props.training?.level ?? '',
    venue: props.training?.venue ?? '',
    venue_details: props.training?.venue_details ?? '',
    meeting_link: props.training?.meeting_link ?? '',
    mode: props.training?.mode ?? 'face_to_face',
    starts_at: props.training?.starts_at ?? '',
    ends_at: props.training?.ends_at ?? '',
    duration_days: props.training?.duration_days ?? '',
    registration_opens_at: props.training?.registration_opens_at ?? '',
    registration_closes_at: props.training?.registration_closes_at ?? '',
    capacity: props.training?.capacity ?? '',
    signatory_name: props.training?.signatory_name ?? '',
    // Days arrive as null for "the whole run"; the checkbox group needs an
    // array, and syncExperts() reads an empty one back as null again.
    subject_matter_experts: (props.training?.subject_matter_experts ?? []).map((assignment) => ({
        id: assignment.id,
        topic: assignment.topic ?? '',
        days: assignment.days ?? [],
    })),
    prerequisites: props.training?.prerequisites ?? '',
    target_participants: props.training?.target_participants ?? '',
    payment_required: props.training?.payment_required ?? false,
    payment_amount: props.training?.payment_amount ?? '',
    accepts_promissory: props.training?.accepts_promissory ?? true,
    // Off unless asked for. A walk-in waives both the deadline and the slot
    // limit, so it is a decision about one event rather than a default.
    accepts_walk_ins: props.training?.accepts_walk_ins ?? false,
    is_supervisory: props.training?.is_supervisory ?? false,
    status: props.training?.status ?? 'draft',
    // Provenance, carried on a rescheduled copy so the new run knows which one
    // it replaces. Null everywhere else; the server ignores it on an edit.
    rescheduled_from_training_id: props.training?.rescheduled_from_training_id ?? null,
});

// Anyone attending remotely needs a link, so hybrid asks for one too — the
// server enforces the same rule, this only keeps the field from sitting there
// unanswerable on a purely face-to-face run.
const needsMeetingLink = computed(() => form.mode !== 'face_to_face');

// Face-to-face is the only mode where the venue is unambiguously an address;
// online runs name the platform here and put the join URL in meeting_link.
const venueLabel = computed(() =>
    form.mode === 'online' ? 'Platform' : 'Location / Venue'
);

const venueHint = computed(() =>
    form.mode === 'online'
        ? 'The platform participants will join on — Zoom, Google Meet, MS Teams.'
        : 'Where participants physically report.'
);

// Registration cannot be earlier than "now" in any useful sense, and an end
// date before the start is the mistake v1 guarded hardest against.
const minEnd = computed(() => form.starts_at || undefined);

/*
 * The day checkboxes follow whatever duration the form currently holds, not
 * what was saved — shortening a run while editing has to take the ticks for the
 * days that no longer exist with it, or HRD is left ticking day 5 of a 3-day
 * training. The server drops out-of-range days too; this is what stops them
 * being offered in the first place.
 */
const dayCount = computed(() => {
    const stated = Number(form.duration_days);

    if (Number.isFinite(stated) && stated >= 1) return Math.min(stated, 60);

    // Blank duration means "derive it from the dates", which is what the server
    // does on save — mirrored here so the pickers are right before saving.
    if (!form.starts_at || !form.ends_at) return 1;

    const start = new Date(form.starts_at);
    const end = new Date(form.ends_at);
    const days = Math.floor((end.setHours(0, 0, 0, 0) - start.setHours(0, 0, 0, 0)) / 86400000) + 1;

    return Math.min(Math.max(days, 1), 60);
});

const dayNumbers = computed(() => Array.from({ length: dayCount.value }, (_, i) => i + 1));

const unassignedExperts = computed(() =>
    props.experts.filter(
        (expert) => !form.subject_matter_experts.some((assignment) => assignment.id === expert.value)
    )
);

/**
 * Options for one row: everyone not already picked, plus this row's own
 * selection — without it the select would have no option matching its value and
 * would render blank.
 */
const availableExperts = (index) => {
    const chosen = form.subject_matter_experts[index]?.id;

    return props.experts.filter(
        (expert) =>
            expert.value === chosen ||
            !form.subject_matter_experts.some((assignment) => assignment.id === expert.value)
    );
};

const addExpert = () => {
    const next = unassignedExperts.value[0];

    if (!next) return;

    form.subject_matter_experts.push({ id: next.value, topic: '', days: [] });
};

const removeExpert = (index) => {
    form.subject_matter_experts.splice(index, 1);
};

/**
 * Where this assignment's evaluation lands, mirroring
 * Training::evaluationDaysForExpert(): an expert is rated once per unbroken
 * stretch of days they are present for, at the end of that stretch.
 *
 * Shown live rather than left for HRD to infer, because ticking days 1 and 2 is
 * the moment somebody decides whether they meant "one session over two days" or
 * "two sessions" — and the difference is only visible in what gets asked of the
 * room afterwards.
 */
const evaluationDays = (assignment) => {
    const days = assignment.days?.length
        ? [...assignment.days].sort((a, b) => a - b)
        : dayNumbers.value;

    return days.filter((day) => !days.includes(day + 1));
};

const evaluationNote = (assignment) => {
    const on = evaluationDays(assignment);

    if (on.length === 0) return null;

    // One rating per day they attend is the un-noteworthy case: saying it would
    // put a line of explanation under every single-day assignment.
    if (on.length === (assignment.days?.length || dayNumbers.value.length)) return null;

    return on.length === 1
        ? `Evaluated once, at the end of day ${on[0]}.`
        : `Evaluated at the end of days ${on.join(' and ')}.`;
};

const submit = () => {
    if (isEdit.value) {
        form.put(`/admin/trainings/${props.training.id}`);

        return;
    }

    form.post('/admin/trainings');
};
</script>

<template>
    <Head :title="pageTitle" />

    <AuthenticatedLayout :title="pageTitle" current="admin-trainings">
        <div class="mx-auto max-w-4xl space-y-5">
            <AppAlert v-if="Object.keys(form.errors).length" tone="danger">
                Please review the highlighted fields below.
            </AppAlert>

            <!--
                Says what is being preserved as much as what is being created.
                The dates are the only fields deliberately left blank, and the
                count is here because it is what makes the capacity decision
                below a real one rather than a copied number.
            -->
            <AppAlert
                v-if="rescheduling"
                tone="info"
                :title="`New schedule for “${rescheduling.title}”`"
            >
                The original run on {{ rescheduling.starts_at }} keeps its record and its history.
                Set the new dates below, then move its
                {{ rescheduling.affected }} registered participant(s) across from the affected list.
                Publish this run before moving anyone — participants cannot be moved onto a draft.
            </AppAlert>

            <form class="space-y-5" novalidate @submit.prevent="submit">
                <AppCard title="Basic Information">
                    <div class="grid gap-5">
                        <AppInput
                            v-model="form.title"
                            label="Training Name"
                            :error="form.errors.title"
                            required
                        />

                        <div class="grid gap-5 sm:grid-cols-2">
                            <AppInput
                                v-model="form.training_code"
                                label="Training Code"
                                placeholder="TRN-2026-0001"
                                hint="Generated automatically if left blank."
                                :error="form.errors.training_code"
                            />
                            <AppSelect
                                v-model="form.category"
                                label="Curriculum"
                                :options="curricula"
                                placeholder="Not specified"
                                hint="Technical, Leadership and Management, or Foundation."
                                :error="form.errors.category"
                            />
                        </div>

                        <AppTextarea
                            v-model="form.description"
                            label="Description"
                            rows="4"
                            placeholder="Describe the training content and expected outcomes…"
                            :error="form.errors.description"
                        />

                        <div class="grid gap-5 sm:grid-cols-2">
                            <AppSelect
                                v-model="form.level"
                                label="Training Level"
                                :options="levels"
                                placeholder="Not specified"
                                hint="How much prior experience the run assumes."
                                :error="form.errors.level"
                            />
                            <AppSelect
                                v-model="form.mode"
                                label="Delivery Mode"
                                :options="modes"
                                :error="form.errors.mode"
                                required
                            />
                        </div>
                    </div>
                </AppCard>

                <AppCard title="Schedule and Location">
                    <div class="grid gap-5">
                        <div class="grid gap-5 sm:grid-cols-2">
                            <AppInput
                                v-model="form.starts_at"
                                label="Starts At"
                                type="datetime-local"
                                :error="form.errors.starts_at"
                                required
                            />
                            <AppInput
                                v-model="form.ends_at"
                                label="Ends At"
                                type="datetime-local"
                                :min="minEnd"
                                :error="form.errors.ends_at"
                                required
                            />
                            <AppInput
                                v-model="form.duration_days"
                                label="Training Days"
                                type="number"
                                min="1"
                                hint="Attendance is taken once per day. Derived from the dates if left blank."
                                :error="form.errors.duration_days"
                            />
                            <AppInput
                                v-model="form.registration_opens_at"
                                label="Registration Opens At"
                                type="datetime-local"
                                hint="Leave blank to open immediately."
                                :error="form.errors.registration_opens_at"
                            />
                            <AppInput
                                v-model="form.registration_closes_at"
                                label="Registration Deadline"
                                type="datetime-local"
                                hint="Defaults to the start time if left blank."
                                :error="form.errors.registration_closes_at"
                            />
                        </div>

                        <AppInput
                            v-model="form.venue"
                            :label="venueLabel"
                            :hint="venueHint"
                            :error="form.errors.venue"
                            required
                        />

                        <AppTextarea
                            v-model="form.venue_details"
                            label="Venue Details"
                            rows="3"
                            placeholder="Room number, directions, parking, what to bring…"
                            :error="form.errors.venue_details"
                        />

                        <AppInput
                            v-if="needsMeetingLink"
                            v-model="form.meeting_link"
                            label="Meeting Link"
                            type="url"
                            placeholder="https://meet.google.com/…"
                            hint="Shown to approved participants. Required for online and hybrid runs."
                            :error="form.errors.meeting_link"
                            required
                        />
                    </div>
                </AppCard>

                <AppCard
                    title="Subject Matter Experts"
                    subtitle="Who delivers this training. Participants evaluate them at the end of each training day."
                >
                    <div class="grid gap-5">
                        <AppAlert v-if="!experts.length" tone="warning" title="No experts on file">
                            Add resource persons under
                            <Link :href="expertsUrl" class="font-semibold underline">
                                Subject Matter Experts
                            </Link>
                            first — a training with none assigned collects no evaluations.
                        </AppAlert>

                        <p v-if="form.errors.subject_matter_experts" class="text-sm text-danger">
                            {{ form.errors.subject_matter_experts }}
                        </p>

                        <div
                            v-for="(assignment, index) in form.subject_matter_experts"
                            :key="index"
                            class="rounded-xl border border-csc-line p-4"
                        >
                            <div class="grid gap-4 sm:grid-cols-2">
                                <AppSelect
                                    v-model="assignment.id"
                                    label="Expert"
                                    :options="availableExperts(index)"
                                    :error="form.errors[`subject_matter_experts.${index}.id`]"
                                    required
                                />
                                <AppInput
                                    v-model="assignment.topic"
                                    label="Topic or Session"
                                    placeholder="e.g. Plenary and workshop"
                                    :error="form.errors[`subject_matter_experts.${index}.topic`]"
                                />
                            </div>

                            <!--
                                Day pickers only on a multi-day run. On a
                                one-day training every assignment covers the
                                only day there is, and the control would be a
                                single checkbox that must never be unticked.
                            -->
                            <fieldset v-if="dayNumbers.length > 1" class="mt-4">
                                <legend class="text-xs font-semibold text-csc-ink-muted">
                                    Days present
                                </legend>
                                <p class="mt-0.5 text-xs text-csc-ink-subtle">
                                    Leave all unticked if this expert is present for the whole run.
                                    Consecutive days count as one session — participants rate it
                                    once, at the end of the last of them.
                                </p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <label
                                        v-for="day in dayNumbers"
                                        :key="day"
                                        class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors"
                                        :class="
                                            assignment.days.includes(day)
                                                ? 'border-csc-blue bg-csc-blue-tint text-csc-blue'
                                                : 'border-csc-line text-csc-ink-muted hover:bg-csc-blue-tint/50'
                                        "
                                    >
                                        <input
                                            v-model="assignment.days"
                                            type="checkbox"
                                            :value="day"
                                            class="size-3.5 rounded border-csc-line text-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                                        />
                                        Day {{ day }}
                                    </label>
                                </div>

                                <p
                                    v-if="evaluationNote(assignment)"
                                    class="mt-2 flex items-center gap-1.5 text-xs text-csc-ink-muted"
                                >
                                    <AppIcon name="clipboard" class="size-3.5 shrink-0" aria-hidden="true" />
                                    {{ evaluationNote(assignment) }}
                                </p>
                            </fieldset>

                            <div class="mt-3 flex justify-end">
                                <button
                                    type="button"
                                    class="text-xs font-semibold text-danger hover:underline"
                                    @click="removeExpert(index)"
                                >
                                    Remove
                                </button>
                            </div>
                        </div>

                        <div>
                            <AppButton
                                type="button"
                                variant="ghost"
                                size="sm"
                                icon="plus"
                                :disabled="!unassignedExperts.length"
                                @click="addExpert"
                            >
                                Add Expert
                            </AppButton>
                            <p v-if="!unassignedExperts.length && experts.length" class="mt-2 text-xs text-csc-ink-subtle">
                                Every expert on file is already assigned to this run.
                            </p>
                        </div>
                    </div>
                </AppCard>

                <AppCard title="Audience and Credit">
                    <div class="grid gap-5">
                        <AppInput
                            v-model="form.signatory_name"
                            label="Certificate Signatory"
                            hint="Printed on the signature line of this run's certificates. Usually the Regional Director, not an expert above."
                            :error="form.errors.signatory_name"
                        />

                        <AppTextarea
                            v-model="form.target_participants"
                            label="Target Participants"
                            rows="2"
                            placeholder="e.g. Second-level personnel of national and local government agencies."
                            :error="form.errors.target_participants"
                        />
                        <AppTextarea
                            v-model="form.prerequisites"
                            label="Prerequisites"
                            rows="3"
                            :error="form.errors.prerequisites"
                        />

                        <label class="flex items-start gap-3">
                            <input
                                v-model="form.is_supervisory"
                                type="checkbox"
                                class="mt-0.5 size-4 rounded border-csc-line text-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                            />
                            <span class="text-sm text-csc-ink">
                                Supervisory Development Course (SDC)
                                <span class="mt-0.5 block text-xs text-csc-ink-subtle">
                                    Participants must submit an output before completion is credited.
                                </span>
                            </span>
                        </label>
                    </div>
                </AppCard>

                <AppCard title="Capacity and Fees">
                    <div class="grid gap-5">
                        <AppInput
                            v-model="form.capacity"
                            label="Available Slots"
                            type="number"
                            min="1"
                            hint="Maximum participants. Leave blank for no limit."
                            :error="form.errors.capacity"
                        />

                        <label class="flex items-start gap-3">
                            <input
                                v-model="form.payment_required"
                                type="checkbox"
                                class="mt-0.5 size-4 rounded border-csc-line text-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                            />
                            <span class="text-sm text-csc-ink">
                                This training requires payment
                                <span class="mt-0.5 block text-xs text-csc-ink-subtle">
                                    Participants are asked to upload proof of payment after registering.
                                </span>
                            </span>
                        </label>

                        <template v-if="form.payment_required">
                            <AppInput
                                v-model="form.payment_amount"
                                label="Training Fee (PHP)"
                                type="number"
                                min="0"
                                step="0.01"
                                :error="form.errors.payment_amount"
                                required
                            />

                            <label class="flex items-start gap-3">
                                <input
                                    v-model="form.accepts_promissory"
                                    type="checkbox"
                                    class="mt-0.5 size-4 rounded border-csc-line text-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                                />
                                <span class="text-sm text-csc-ink">
                                    Accept promissory notes
                                    <span class="mt-0.5 block text-xs text-csc-ink-subtle">
                                        A slot is held while the agency settles the fee later.
                                    </span>
                                </span>
                            </label>
                        </template>

                        <!--
                            Outside the paid-only block above on purpose: a free
                            training gets walk-ins too, and gating this on a fee
                            would quietly make them impossible on exactly the
                            events most likely to attract them.
                        -->
                        <label class="flex items-start gap-3">
                            <input
                                v-model="form.accepts_walk_ins"
                                type="checkbox"
                                class="mt-0.5 size-4 rounded border-csc-line text-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                            />
                            <span class="text-sm text-csc-ink">
                                Accept walk-in participants
                                <span class="mt-0.5 block text-xs text-csc-ink-subtle">
                                    Staff can admit someone at the venue after registration closes, even
                                    once the slots are taken. Going over the limit is allowed and flagged
                                    at the desk, so plan for extra chairs and meals.
                                </span>
                            </span>
                        </label>
                    </div>
                </AppCard>

                <AppCard title="Training Status">
                    <AppSelect
                        v-model="form.status"
                        label="Status"
                        :options="statuses"
                        hint="Only published trainings are visible to participants. Keep a run in draft until it is ready."
                        :error="form.errors.status"
                        required
                    />
                </AppCard>

                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <AppButton href="/admin/trainings" variant="ghost" size="lg">Cancel</AppButton>
                    <AppButton type="submit" size="lg" :loading="form.processing" icon="check">
                        {{ rescheduling ? 'Create New Schedule' : isEdit ? 'Save Changes' : 'Create Training' }}
                    </AppButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
