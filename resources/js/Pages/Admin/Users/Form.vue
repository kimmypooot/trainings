<script setup>
import { computed, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AppSelect from '@/Components/AppSelect.vue';

const props = defineProps({
    staffUser: { type: Object, default: null },
    roles: { type: Array, required: true },
    fieldOffices: { type: Array, required: true },
});

const isEdit = computed(() => props.staffUser !== null);

const form = useForm({
    name: props.staffUser?.name ?? '',
    email: props.staffUser?.email ?? '',
    role: props.staffUser?.role ?? 'admin',
    field_office_id: props.staffUser?.field_office_id ?? '',
    is_active: props.staffUser?.is_active ?? true,
    password: '',
    password_confirmation: '',
});

const needsOffice = computed(() => form.role === 'field-office');

// Only a field-office account carries an office assignment.
watch(
    () => form.role,
    (role) => {
        if (role !== 'field-office') {
            form.field_office_id = '';
        }
    }
);

const submit = () => {
    if (isEdit.value) {
        form.put(`/admin/users/${props.staffUser.id}`);

        return;
    }

    form.post('/admin/users');
};
</script>

<template>
    <Head :title="isEdit ? 'Edit Staff Account' : 'New Staff Account'" />

    <AuthenticatedLayout :title="isEdit ? 'Edit Staff Account' : 'New Staff Account'" current="admin-users">
        <div class="mx-auto max-w-4xl space-y-5">
            <AppAlert v-if="Object.keys(form.errors).length" tone="danger">
                {{ form.errors.role ?? 'Please review the highlighted fields below.' }}
            </AppAlert>

            <form class="space-y-5" novalidate @submit.prevent="submit">
                <AppCard title="Account">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <AppInput v-model="form.name" label="Full Name" :error="form.errors.name" required />
                        <AppInput
                            v-model="form.email"
                            label="Email Address"
                            type="email"
                            :error="form.errors.email"
                            required
                        />
                    </div>
                </AppCard>

                <AppCard title="Role and Scope">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <AppSelect
                            v-model="form.role"
                            label="Role"
                            :options="roles"
                            :error="form.errors.role"
                            required
                        />
                        <AppSelect
                            v-if="needsOffice"
                            v-model="form.field_office_id"
                            label="Field Office"
                            :options="fieldOffices"
                            hint="This account will only see participants from this office."
                            :error="form.errors.field_office_id"
                            required
                        />
                    </div>

                    <p v-if="!needsOffice" class="mt-4 text-xs leading-relaxed text-csc-ink/60">
                        This role sees participants across the whole region. Only a Field Office account is
                        limited to one office.
                    </p>
                </AppCard>

                <AppCard :title="isEdit ? 'Reset Password' : 'Password'">
                    <p v-if="isEdit" class="mb-4 text-xs leading-relaxed text-csc-ink/60">
                        Leave blank to keep the current password.
                    </p>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <AppInput
                            v-model="form.password"
                            label="Password"
                            type="password"
                            hint="At least 8 characters, including a letter and a number."
                            :error="form.errors.password"
                            :required="!isEdit"
                        />
                        <AppInput
                            v-model="form.password_confirmation"
                            label="Confirm Password"
                            type="password"
                            :error="form.errors.password_confirmation"
                            :required="!isEdit"
                        />
                    </div>
                </AppCard>

                <AppCard v-if="isEdit" title="Status">
                    <label class="flex items-start gap-3 text-sm text-csc-ink">
                        <input
                            v-model="form.is_active"
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0 rounded border-csc-line accent-csc-blue"
                        />
                        <span class="leading-relaxed">
                            Active — a deactivated account cannot sign in, but its records stay intact.
                        </span>
                    </label>
                </AppCard>

                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <AppButton href="/admin/users" variant="ghost" size="lg">Cancel</AppButton>
                    <AppButton type="submit" size="lg" :loading="form.processing" icon="check">
                        {{ isEdit ? 'Save Changes' : 'Create Account' }}
                    </AppButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
