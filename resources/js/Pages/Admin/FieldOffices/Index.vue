<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

const props = defineProps({
    offices: { type: Array, required: true },
});

const confirming = ref(null);
const processing = ref(false);

const dialog = computed(() => {
    if (!confirming.value) return null;

    const office = confirming.value;
    const who = office.name;

    if (office.is_active) {
        // Deactivation is reversible, but it blocks new profile selections, so
        // the people it would affect are named before the button is pressed.
        return {
            title: `Deactivate ${who}?`,
            description: `${office.participants} participant${office.participants === 1 ? '' : 's'} and ${office.staff} staff member${office.staff === 1 ? '' : 's'} are assigned to this office. Deactivating keeps them on existing records but stops the office being chosen on new profiles.`,
            confirmLabel: 'Deactivate',
        };
    }

    return {
        title: `Activate ${who}?`,
        description: 'Participants will be able to select this office on their profile again.',
        confirmLabel: 'Activate',
    };
});

const confirm = () => {
    processing.value = true;
    router.post(
        `/admin/field-offices/${confirming.value.id}/toggle`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
                confirming.value = null;
            },
        }
    );
};
</script>

<template>
    <Head title="Field Offices" />

    <AuthenticatedLayout title="Field Offices" current="admin-field-offices">
        <div class="mx-auto max-w-7xl space-y-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm leading-relaxed text-csc-ink/70">
                    Offices participants select on their profile. Deactivate rather than delete — existing
                    profiles point at these records.
                </p>
                        <AppButton href="/admin/field-offices/create" icon="plus">New Office</AppButton>
            </div>

            <AppCard v-if="!offices.length" :padded="false">
                <AppEmptyState
                    title="No field offices yet"
                    description="Add the offices participants can be assigned to."
                    icon="building"
                >
                    <template #action>
                <AppButton href="/admin/field-offices/create" icon="plus">New Office</AppButton>
                    </template>
                </AppEmptyState>
            </AppCard>

            <template v-else>
                <div class="hidden overflow-hidden rounded-xl border border-csc-line bg-white md:block">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Office</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Jurisdiction</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Head</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Participants</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Staff</th>
                                <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink/70">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-csc-line">
                            <tr v-for="office in offices" :key="office.id" :class="office.is_active ? '' : 'opacity-60'">
                                <td class="px-5 py-3.5">
                                    <Link :href="office.view_url" class="font-medium text-csc-blue hover:underline">
                                        {{ office.name }}
                                    </Link>
                                    <p class="mt-0.5 text-xs text-csc-ink/60">
                                        {{ office.code.toUpperCase() }} · {{ office.type_label }}
                                        <span v-if="!office.is_active" class="ml-1 font-semibold text-danger">
                                            · Inactive
                                        </span>
                                    </p>
                                    <p v-if="office.email" class="mt-0.5 text-xs text-csc-ink/55">{{ office.email }}</p>
                                </td>
                                <td class="px-5 py-3.5 text-xs text-csc-ink/70">
                                    {{ office.jurisdiction.join(', ') || office.province }}
                                </td>
                                <td class="px-5 py-3.5 text-csc-ink/75">
                                    {{ office.head_name ?? '—' }}
                                    <p v-if="office.head_position" class="mt-0.5 text-xs text-csc-ink/55">
                                        {{ office.head_position }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-csc-ink/75">{{ office.participants }}</td>
                                <td class="px-5 py-3.5 text-csc-ink/75">{{ office.staff }}</td>
                                <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                    <Link :href="office.view_url" class="text-xs font-semibold text-csc-blue hover:underline">
                                        View
                                    </Link>
                                    <span class="px-2 text-csc-line">|</span>
                                    <Link :href="office.edit_url" class="text-xs font-semibold text-csc-blue hover:underline">
                                        Edit
                                    </Link>
                                    <span class="px-2 text-csc-line">|</span>
                                    <button
                                        type="button"
                                        class="rounded text-xs font-semibold hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                        :class="office.is_active ? 'text-danger' : 'text-success'"
                                        @click="confirming = office"
                                    >
                                        {{ office.is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <ul class="space-y-3 md:hidden">
                    <li
                        v-for="office in offices"
                        :key="office.id"
                        class="rounded-xl border border-csc-line bg-white p-4"
                        :class="office.is_active ? '' : 'opacity-60'"
                    >
                        <p class="text-sm font-semibold text-csc-ink">{{ office.name }}</p>
                        <p class="mt-0.5 text-xs text-csc-ink/60">
                            {{ office.code.toUpperCase() }} · {{ office.type_label }}
                            <span v-if="!office.is_active" class="ml-1 font-semibold text-danger">· Inactive</span>
                        </p>
                        <p class="mt-2 text-xs text-csc-ink/70">{{ office.head_name ?? '—' }}</p>
                        <div class="mt-3 flex items-center justify-between border-t border-csc-line pt-3">
                            <span class="text-xs text-csc-ink/60">
                                {{ office.participants }} participants · {{ office.staff }} staff
                            </span>
                            <span class="flex gap-3">
                                <Link :href="office.view_url" class="text-xs font-semibold text-csc-blue">View</Link>
                                <Link :href="office.edit_url" class="text-xs font-semibold text-csc-blue">Edit</Link>
                                <button
                                    type="button"
                                    class="text-xs font-semibold"
                                    :class="office.is_active ? 'text-danger' : 'text-success'"
                                    @click="confirming = office"
                                >
                                    {{ office.is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </span>
                        </div>
                    </li>
                </ul>
            </template>
        </div>

        <AppConfirmModal
            v-if="dialog"
            :open="Boolean(confirming)"
            :title="dialog.title"
            :description="dialog.description"
            :confirm-label="dialog.confirmLabel"
            :processing="processing"
            @confirm="confirm"
            @close="confirming = null"
        />
    </AuthenticatedLayout>
</template>
