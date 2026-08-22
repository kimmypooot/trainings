<script setup>
/** Create or edit one subject matter expert. */
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AppTextarea from '@/Components/AppTextarea.vue';

const props = defineProps({
    expert: { type: Object, default: null },
});

const isEdit = computed(() => Boolean(props.expert?.id));

const form = useForm({
    name: props.expert?.name ?? '',
    position: props.expert?.position ?? '',
    organization: props.expert?.organization ?? 'Civil Service Commission RO VIII',
    email: props.expert?.email ?? '',
    contact_number: props.expert?.contact_number ?? '',
    expertise: props.expert?.expertise ?? '',
    bio: props.expert?.bio ?? '',
    remarks: props.expert?.remarks ?? '',
    is_active: props.expert?.is_active ?? true,
});

const submit = () => {
    if (isEdit.value) {
        form.put(`/admin/smes/${props.expert.id}`);

        return;
    }

    form.post('/admin/smes');
};
</script>

<template>
    <Head :title="isEdit ? 'Edit Expert' : 'New Expert'" />

    <AuthenticatedLayout :title="isEdit ? 'Edit Expert' : 'New Expert'" current="admin-smes">
        <div class="mx-auto max-w-3xl space-y-5">
            <form class="space-y-5" @submit.prevent="submit">
                <AppCard title="Identity">
                    <div class="grid gap-5">
                        <AppInput
                            v-model="form.name"
                            label="Full Name"
                            hint="As it should appear on the participant's evaluation form."
                            :error="form.errors.name"
                            required
                        />
                        <div class="grid gap-5 sm:grid-cols-2">
                            <AppInput
                                v-model="form.position"
                                label="Position or Designation"
                                placeholder="e.g. Chief HR Specialist"
                                :error="form.errors.position"
                            />
                            <AppInput
                                v-model="form.organization"
                                label="Office or Organization"
                                :error="form.errors.organization"
                            />
                        </div>
                    </div>
                </AppCard>

                <AppCard title="Contact" subtitle="Internal only — never shown to participants.">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <AppInput
                            v-model="form.email"
                            label="Email"
                            type="email"
                            :error="form.errors.email"
                        />
                        <AppInput
                            v-model="form.contact_number"
                            label="Contact Number"
                            :error="form.errors.contact_number"
                        />
                    </div>
                </AppCard>

                <AppCard title="Background">
                    <div class="grid gap-5">
                        <AppTextarea
                            v-model="form.expertise"
                            label="Areas of Expertise"
                            rows="2"
                            hint="What this expert is usually invited to deliver. Shown on the directory."
                            :error="form.errors.expertise"
                        />
                        <AppTextarea
                            v-model="form.bio"
                            label="Short Biography"
                            rows="4"
                            :error="form.errors.bio"
                        />
                        <AppTextarea
                            v-model="form.remarks"
                            label="Internal Remarks"
                            rows="2"
                            hint="Scheduling notes, availability, honorarium arrangements."
                            :error="form.errors.remarks"
                        />

                        <label class="flex items-start gap-3">
                            <input
                                v-model="form.is_active"
                                type="checkbox"
                                class="mt-0.5 size-4 rounded border-csc-line text-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                            />
                            <span class="text-sm text-csc-ink">
                                Available for assignment
                                <span class="mt-0.5 block text-xs text-csc-ink-subtle">
                                    Unchecking hides them from the picker on new trainings. Runs they are
                                    already on, and the evaluations filed against them, are untouched.
                                </span>
                            </span>
                        </label>
                    </div>
                </AppCard>

                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <AppButton href="/admin/smes" variant="ghost" size="lg">Cancel</AppButton>
                    <AppButton type="submit" size="lg" :loading="form.processing" icon="check">
                        {{ isEdit ? 'Save Changes' : 'Add Expert' }}
                    </AppButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
