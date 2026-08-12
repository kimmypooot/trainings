<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AppTextarea from '@/Components/AppTextarea.vue';

const props = defineProps({
    training: { type: Object, default: null },
    statuses: { type: Array, required: true },
    modes: { type: Array, required: true },
});

const isEdit = computed(() => props.training !== null);

const form = useForm({
    title: props.training?.title ?? '',
    training_code: props.training?.training_code ?? '',
    description: props.training?.description ?? '',
    category: props.training?.category ?? '',
    venue: props.training?.venue ?? '',
    mode: props.training?.mode ?? 'face_to_face',
    starts_at: props.training?.starts_at ?? '',
    ends_at: props.training?.ends_at ?? '',
    duration_days: props.training?.duration_days ?? '',
    registration_opens_at: props.training?.registration_opens_at ?? '',
    registration_closes_at: props.training?.registration_closes_at ?? '',
    capacity: props.training?.capacity ?? '',
    facilitator_name: props.training?.facilitator_name ?? '',
    facilitator_contact: props.training?.facilitator_contact ?? '',
    objectives: props.training?.objectives ?? '',
    prerequisites: props.training?.prerequisites ?? '',
    target_participants: props.training?.target_participants ?? '',
    payment_required: props.training?.payment_required ?? false,
    payment_amount: props.training?.payment_amount ?? '',
    status: props.training?.status ?? 'draft',
});

// Online runs have no venue address, but they do have a platform or link, so
// the field stays required and only its wording changes.
const venueLabel = computed(() => (form.mode === 'online' ? 'Platform or Meeting Link' : 'Venue'));

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
        <div class="mx-auto max-w-3xl space-y-5">
            <AppAlert v-if="Object.keys(form.errors).length" tone="danger">
                Please review the highlighted fields below.
            </AppAlert>

            <form class="space-y-5" novalidate @submit.prevent="submit">
                <AppCard title="Training Details">
                    <div class="grid gap-5">
                        <AppInput
                            v-model="form.title"
                            label="Title"
                            :error="form.errors.title"
                            required
                        />

                        <div class="grid gap-5 sm:grid-cols-2">
                            <AppInput
                                v-model="form.training_code"
                                label="Training Code"
                                hint="Generated automatically if left blank."
                                :error="form.errors.training_code"
                            />
                            <AppInput
                                v-model="form.category"
                                label="Category"
                                placeholder="Leadership, Technical, Values…"
                                :error="form.errors.category"
                            />
                        </div>

                        <AppTextarea
                            v-model="form.description"
                            label="Description"
                            :error="form.errors.description"
                        />

                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="mode" class="mb-1.5 block text-sm font-medium text-csc-ink">
                                    Delivery Mode
                                </label>
                                <select
                                    id="mode"
                                    v-model="form.mode"
                                    class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                                >
                                    <option v-for="option in modes" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </div>

                            <AppInput
                                v-model="form.venue"
                                :label="venueLabel"
                                :error="form.errors.venue"
                                required
                            />
                        </div>
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
                            v-model="form.objectives"
                            label="Objectives"
                            rows="3"
                            :error="form.errors.objectives"
                        />
                        <AppTextarea
                            v-model="form.prerequisites"
                            label="Prerequisites"
                            rows="3"
                            :error="form.errors.prerequisites"
                        />
                        <AppTextarea
                            v-model="form.target_participants"
                            label="Target Participants"
                            rows="3"
                            :error="form.errors.target_participants"
                        />
                    </div>
                </AppCard>

                <AppCard title="Schedule and Capacity">
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
                            :error="form.errors.ends_at"
                            required
                        />
                        <AppInput
                            v-model="form.duration_days"
                            label="Duration (Days)"
                            type="number"
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
                            label="Registration Closes At"
                            type="datetime-local"
                            hint="Defaults to the start time if left blank."
                            :error="form.errors.registration_closes_at"
                        />
                        <AppInput
                            v-model="form.capacity"
                            label="Capacity"
                            type="number"
                            hint="Leave blank for no limit."
                            :error="form.errors.capacity"
                        />

                        <div>
                            <label for="status" class="mb-1.5 block text-sm font-medium text-csc-ink">
                                Status <span class="text-danger" aria-hidden="true">*</span>
                            </label>
                            <select
                                id="status"
                                v-model="form.status"
                                class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                            >
                                <option v-for="option in statuses" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                            <p class="mt-1.5 text-xs text-csc-ink/60">
                                Only published trainings are visible to participants.
                            </p>
                        </div>
                    </div>
                </AppCard>

                <AppCard title="Payment">
                    <div class="grid gap-5">
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

                        <AppInput
                            v-if="form.payment_required"
                            v-model="form.payment_amount"
                            label="Amount (PHP)"
                            type="number"
                            :error="form.errors.payment_amount"
                            required
                        />
                    </div>
                </AppCard>

                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <AppButton href="/admin/trainings" variant="ghost" size="lg">Cancel</AppButton>
                    <AppButton type="submit" size="lg" :loading="form.processing">
                        {{ isEdit ? 'Save Changes' : 'Create Training' }}
                    </AppButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
