<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AppSelect from '@/Components/AppSelect.vue';

const props = defineProps({
    office: { type: Object, default: null },
    types: { type: Array, required: true },
});

const isEdit = computed(() => props.office !== null);

const form = useForm({
    code: props.office?.code ?? '',
    name: props.office?.name ?? '',
    type: props.office?.type ?? 'field_office',
    province: props.office?.province ?? '',
    jurisdiction: props.office?.jurisdiction ?? '',
    address: props.office?.address ?? '',
    contact_number: props.office?.contact_number ?? '',
    email: props.office?.email ?? '',
    head_name: props.office?.head_name ?? '',
    head_position: props.office?.head_position ?? '',
    is_active: props.office?.is_active ?? true,
    remarks: props.office?.remarks ?? '',
});

const submit = () => {
    if (isEdit.value) {
        form.put(`/admin/field-offices/${props.office.id}`);

        return;
    }

    form.post('/admin/field-offices');
};
</script>

<template>
    <Head :title="isEdit ? 'Edit Field Office' : 'New Field Office'" />

    <AuthenticatedLayout
        :title="isEdit ? 'Edit Field Office' : 'New Field Office'"
        current="admin-field-offices"
    >
        <div class="mx-auto max-w-3xl space-y-5">
            <AppAlert v-if="Object.keys(form.errors).length" tone="danger">
                Please review the highlighted fields below.
            </AppAlert>

            <form class="space-y-5" novalidate @submit.prevent="submit">
                <AppCard title="Office Details">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <AppInput
                            v-model="form.code"
                            label="Code"
                            hint="Short identifier, e.g. lfoi."
                            :error="form.errors.code"
                            required
                        />
                        <AppSelect
                            v-model="form.type"
                            label="Type"
                            :options="types"
                            :error="form.errors.type"
                            required
                        />

                        <div class="sm:col-span-2">
                            <AppInput v-model="form.name" label="Name" :error="form.errors.name" required />
                        </div>

                        <AppInput
                            v-model="form.province"
                            label="Primary Province"
                            :error="form.errors.province"
                            required
                        />
                        <AppInput
                            v-model="form.jurisdiction"
                            label="Jurisdiction"
                            hint="Comma-separated provinces covered."
                            :error="form.errors.jurisdiction"
                        />
                    </div>
                </AppCard>

                <AppCard title="Contact">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <AppInput v-model="form.email" label="Email" type="email" :error="form.errors.email" />
                        <AppInput
                            v-model="form.contact_number"
                            label="Contact Number"
                            :error="form.errors.contact_number"
                        />
                        <div class="sm:col-span-2">
                            <AppInput v-model="form.address" label="Address" :error="form.errors.address" />
                        </div>
                        <AppInput v-model="form.head_name" label="Office Head" :error="form.errors.head_name" />
                        <AppInput
                            v-model="form.head_position"
                            label="Head Position"
                            :error="form.errors.head_position"
                        />
                    </div>
                </AppCard>

                <AppCard title="Status">
                    <label class="flex items-start gap-3 text-sm text-csc-ink">
                        <input
                            v-model="form.is_active"
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0 rounded border-csc-line accent-csc-blue"
                        />
                        <span class="leading-relaxed">
                            Active — participants can select this office on their profile. Inactive offices stay
                            on existing records but cannot be newly chosen.
                        </span>
                    </label>
                </AppCard>

                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <AppButton href="/admin/field-offices" variant="ghost" size="lg">Cancel</AppButton>
                    <AppButton type="submit" size="lg" :loading="form.processing" icon="check">
                        {{ isEdit ? 'Save Changes' : 'Add Office' }}
                    </AppButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
