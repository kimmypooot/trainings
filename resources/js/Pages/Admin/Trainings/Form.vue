<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTextarea from '@/Components/AppTextarea.vue';

const props = defineProps({
    training: { type: Object, default: null },
    statuses: { type: Array, required: true },
    modes: { type: Array, required: true },
    levels: { type: Array, required: true },
    curricula: { type: Array, required: true },
});

const isEdit = computed(() => props.training !== null);

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
    facilitator_name: props.training?.facilitator_name ?? '',
    facilitator_contact: props.training?.facilitator_contact ?? '',
    prerequisites: props.training?.prerequisites ?? '',
    target_participants: props.training?.target_participants ?? '',
    payment_required: props.training?.payment_required ?? false,
    payment_amount: props.training?.payment_amount ?? '',
    accepts_promissory: props.training?.accepts_promissory ?? true,
    is_supervisory: props.training?.is_supervisory ?? false,
    status: props.training?.status ?? 'draft',
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

const submit = () => {
    if (isEdit.value) {
        form.put(`/admin/trainings/${props.training.id}`);

        return;
    }

    form.post('/admin/trainings');
};
</script>

<template>
    <Head :title="isEdit ? 'Edit Training' : 'New Training'" />

    <AuthenticatedLayout :title="isEdit ? 'Edit Training' : 'New Training'" current="admin-trainings">
        <div class="mx-auto max-w-4xl space-y-5">
            <AppAlert v-if="Object.keys(form.errors).length" tone="danger">
                Please review the highlighted fields below.
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

                <AppCard title="Facilitation and Audience">
                    <div class="grid gap-5">
                        <div class="grid gap-5 sm:grid-cols-2">
                            <AppInput
                                v-model="form.facilitator_name"
                                label="Facilitator"
                                :error="form.errors.facilitator_name"
                            />
                            <AppInput
                                v-model="form.facilitator_contact"
                                label="Facilitator Contact"
                                :error="form.errors.facilitator_contact"
                            />
                        </div>

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
                                <span class="mt-0.5 block text-xs text-csc-ink/60">
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
                                <span class="mt-0.5 block text-xs text-csc-ink/60">
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
                                    <span class="mt-0.5 block text-xs text-csc-ink/60">
                                        A slot is held while the agency settles the fee later.
                                    </span>
                                </span>
                            </label>
                        </template>
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
                        {{ isEdit ? 'Save Changes' : 'Create Training' }}
                    </AppButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
