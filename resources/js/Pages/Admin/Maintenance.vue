<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppTextarea from '@/Components/AppTextarea.vue';

const props = defineProps({
    maintenance: {
        type: Object,
        required: true,
        default: () => ({ enabled: false, message: null, updated_by: null, updated_at: null }),
    },
});

const enabled = computed(() => props.maintenance.enabled);

const form = useForm({
    enabled: props.maintenance.enabled,
    message: props.maintenance.message ?? '',
});

// Turning the switch on is disruptive and immediate — every non-superadmin
// visitor is held on the maintenance page from the next request, so the
// superadmin confirms before it flips.
const confirmingEnable = ref(false);

const submit = () => {
    if (enabled.value) {
        form.enabled = false;
        form.post('/admin/maintenance', { preserveScroll: true });
        return;
    }

    confirmingEnable.value = true;
};

const confirmEnable = () => {
    form.enabled = true;
    confirmingEnable.value = false;
    form.post('/admin/maintenance', { preserveScroll: true });
};
</script>

<template>
    <Head title="Maintenance Mode" />

    <AuthenticatedLayout title="Maintenance Mode" current="admin-maintenance">
        <div class="mx-auto max-w-3xl space-y-5">
            <AppAlert
                v-if="enabled"
                tone="danger"
                title="The site is in maintenance"
            >
                Visitors and participants now see the maintenance page. CSC staff keep working,
                including this screen, so the switch can be turned back off at any time.
            </AppAlert>

            <AppAlert
                v-else
                tone="info"
                title="How maintenance mode works"
            >
                While it is on, the public pages and the participant portal are replaced by a
                maintenance notice. CSC staff are unaffected and keep working normally — the
                banner they see across the app is what keeps this from staying on by accident.
                Sign-in, emailed verification and reset links, certificate verification, and the
                venue attendance station stay open to everyone.
            </AppAlert>

            <AppCard title="Maintenance Mode" subtitle="Control who can reach the site.">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-csc-ink">Current status</p>
                        <span
                            class="mt-1.5 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                            :class="enabled ? 'bg-danger-soft text-danger' : 'bg-success-soft text-success'"
                        >
                            <span class="size-1.5 rounded-full bg-current" aria-hidden="true" />
                            {{ enabled ? 'Under maintenance' : 'Live' }}
                        </span>
                        <p v-if="maintenance.updated_by" class="mt-2 text-xs text-csc-ink/60">
                            Last changed by {{ maintenance.updated_by }}{{ maintenance.updated_at ? ` on ${maintenance.updated_at}` : '' }}
                        </p>
                    </div>
                </div>

                <form class="mt-6 space-y-5" novalidate @submit.prevent="submit">
                    <AppTextarea
                        v-model="form.message"
                        label="Message shown on the maintenance page"
                        hint="Optional. What visitors will read while the site is down."
                        placeholder="We are carrying out scheduled maintenance. Please check back shortly."
                        rows="3"
                        maxlength="500"
                        :error="form.errors.message"
                    />

                    <div class="flex flex-wrap items-center justify-end gap-2 border-t border-csc-line pt-5">
                        <p v-if="form.errors.enabled" class="mr-auto text-xs font-medium text-csc-red-ink">
                            {{ form.errors.enabled }}
                        </p>
                        <AppButton
                            v-if="enabled"
                            type="submit"
                            icon="check"
                            :loading="form.processing"
                        >
                            Bring the site back online
                        </AppButton>
                        <AppButton
                            v-else
                            type="submit"
                            variant="accent"
                            icon="lock"
                            :loading="form.processing"
                        >
                            Enter maintenance mode
                        </AppButton>
                    </div>
                </form>
            </AppCard>
        </div>

        <AppConfirmModal
            :open="confirmingEnable"
            title="Enter maintenance mode?"
            description="The public site and the participant portal will immediately show the maintenance notice for everyone except CSC staff. Confirm only when the site really needs to go down."
            confirm-label="Enter maintenance mode"
            :processing="form.processing"
            @confirm="confirmEnable"
            @close="confirmingEnable = false"
        />
    </AuthenticatedLayout>
</template>