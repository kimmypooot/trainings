<script setup>
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppInput from '@/Components/AppInput.vue';
import AppPagination from '@/Components/AppPagination.vue';
import AppRowActions from '@/Components/AppRowActions.vue';
import AppStatTile from '@/Components/AppStatTile.vue';
import AppSelect from '@/Components/AppSelect.vue';
import { useFilters, filteringClass } from '@/useFilters';

const props = defineProps({
    users: { type: Object, required: true },
    filters: { type: Object, required: true },
    roles: { type: Array, required: true },
    // The whole staff roll, not the filtered page — see the controller.
    summary: { type: Object, required: true },
    // HRD sees the same directory without the controls — administering the
    // accounts is superadmin's. The routes enforce it; this keeps the page
    // from offering buttons that would 403.
    canManage: { type: Boolean, default: false },
});

const page = usePage();
const error = computed(() => page.props.errors?.user);

const search = ref(props.filters.search ?? '');
const role = ref(props.filters.role ?? '');

// `roles` is the dropdown's own option list and `canManage` a role check —
// neither moves when the directory is narrowed, so neither is reloaded.
const { filtering } = useFilters({
    url: '/admin/users',
    only: ['users', 'filters'],
    watch: [search, role],
    query: () => ({
        search: search.value || undefined,
        role: role.value || undefined,
    }),
});

const confirming = ref(null);
const processing = ref(false);

/*
 * What can be done to one staff account, listed once for both layouts.
 *
 * An account cannot deactivate itself — signing yourself out of the system
 * you administer is never the thing that was meant — so its own row offers
 * the edit alone.
 */
const actionsFor = (user) => [
    { label: 'Edit', icon: 'pencil', href: user.edit_url },
    ...(user.is_self
        ? []
        : [
              user.is_active
                  ? { label: 'Deactivate', icon: 'lock', tone: 'danger', onClick: () => (confirming.value = user) }
                  : { label: 'Activate', icon: 'check', tone: 'success', onClick: () => (confirming.value = user) },
          ]),
];

const dialog = computed(() => {
    if (!confirming.value) return null;

    const user = confirming.value;
    const who = user.name ?? user.email;

    return user.is_active
        ? {
              title: `Deactivate ${who}?`,
              description: 'They will not be able to sign in. Their role and office assignment are kept.',
              confirmLabel: 'Deactivate',
          }
        : {
              title: `Activate ${who}?`,
              description: 'They will be able to sign in again.',
              confirmLabel: 'Activate',
          };
});

const confirm = () => {
    processing.value = true;
    router.post(
        `/admin/users/${confirming.value.id}/toggle`,
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
    <Head title="Users & Roles" />

    <AuthenticatedLayout title="Users &amp; Roles" current="admin-users">
        <div class="mx-auto max-w-7xl space-y-5">
            <AppAlert v-if="error" tone="danger">{{ error }}</AppAlert>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <AppStatTile label="Staff Accounts" :value="summary.total" icon="users" />
                <AppStatTile
                    label="Active"
                    :value="summary.active"
                    icon="check-circle"
                    tone="success"
                    :caption="
                        summary.total - summary.active > 0
                            ? `${summary.total - summary.active} deactivated`
                            : 'Nobody locked out'
                    "
                />
                <AppStatTile
                    label="Collecting Officers"
                    :value="summary.collectors"
                    icon="card"
                    caption="Hold the till by designation"
                />
                <!--
                    An account created and never used is either an onboarding
                    that stalled or a credential sitting unclaimed. Amber while
                    there are any, because both want chasing.
                -->
                <AppStatTile
                    label="Never Signed In"
                    :value="summary.never_signed_in"
                    icon="clock"
                    :tone="summary.never_signed_in > 0 ? 'warning' : 'success'"
                    :caption="summary.never_signed_in > 0 ? 'Created but never used' : 'Every account has been used'"
                />
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-1 flex-col gap-3 sm:flex-row">
                    <AppInput
                        v-model="search"
                        label=""
                        type="search"
                        placeholder="Search by name or email…"
                        aria-label="Search staff accounts"
                        class="sm:max-w-xs"
                    />
                    <AppSelect
                        v-model="role"
                        label=""
                        aria-label="Filter by role"
                        placeholder="All roles"
                        :options="roles"
                        class="sm:max-w-52"
                    />
                </div>

                <AppButton v-if="canManage" href="/admin/users/create" icon="plus">
                    New Staff Account
                </AppButton>
            </div>

            <!--
                 The results dim while a filtered visit is out. The controls above stay
                 live — narrowing further mid-request is the normal thing to do — but
                 these rows are already superseded, so they stop taking clicks until
                 they have been redrawn.
            -->
            <div :class="filteringClass(filtering)" :aria-busy="filtering" class="space-y-5">
                <AppCard v-if="!users.data.length" :padded="false">
                    <AppEmptyState
                        title="No staff accounts found"
                        :description="
                            canManage
                                ? 'Add an account, or clear the filters if you were searching.'
                                : 'Clear the filters if you were searching.'
                        "
                        icon="users"
                    >
                        <template v-if="canManage" #action>
                            <AppButton href="/admin/users/create" icon="plus">New Staff Account</AppButton>
                        </template>
                    </AppEmptyState>
                </AppCard>

                <template v-else>
                    <div class="hidden overflow-hidden rounded-xl border border-csc-line bg-white md:block">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                                <tr>
                                    <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Name</th>
                                    <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Role</th>
                                    <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Field Office</th>
                                    <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">Status</th>
                                    <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink-muted">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-csc-line">
                                <tr v-for="user in users.data" :key="user.id" :class="user.is_active ? '' : 'opacity-60'">
                                    <td class="px-5 py-3.5">
                                        <p class="font-medium text-csc-ink">
                                            {{ user.name ?? '—' }}
                                            <span v-if="user.is_self" class="ml-1 text-xs font-normal text-csc-ink-subtle">
                                                (you)
                                            </span>
                                        </p>
                                        <p class="mt-0.5 text-xs text-csc-ink-subtle">{{ user.email }}</p>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <span class="rounded-full bg-csc-blue-tint px-2.5 py-1 text-xs font-semibold text-csc-blue">
                                            {{ user.role_label }}
                                        </span>
                                        <p
                                            v-if="user.is_collecting_officer"
                                            class="mt-1 text-2xs font-medium text-csc-ink-subtle"
                                        >
                                            Collecting officer
                                        </p>
                                        <!--
                                            Left on the retired collecting-officer
                                            role. They can still take payments, but
                                            they have no office to be scoped to
                                            until someone gives them a real role.
                                        -->
                                        <p v-if="user.needs_reassignment" class="mt-1 text-2xs font-medium text-warning">
                                            Needs reassignment
                                        </p>
                                    </td>
                                    <td class="px-5 py-3.5 text-xs text-csc-ink-muted">{{ user.field_office ?? '—' }}</td>
                                    <td class="px-5 py-3.5">
                                        <span
                                            class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                            :class="user.is_active ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'"
                                        >
                                            {{ user.is_active ? 'Active' : 'Deactivated' }}
                                        </span>
                                        <p v-if="user.last_login_at" class="mt-1.5 text-2xs text-csc-ink-subtle">
                                            Last sign-in {{ user.last_login_at }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                        <span v-if="!canManage" class="text-xs text-csc-ink-subtle">—</span>
                                        <AppRowActions v-else :actions="actionsFor(user)" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <ul class="space-y-3 md:hidden">
                        <li
                            v-for="user in users.data"
                            :key="user.id"
                            class="rounded-xl border border-csc-line bg-white p-4"
                            :class="user.is_active ? '' : 'opacity-60'"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-csc-ink">{{ user.name ?? '—' }}</p>
                                    <p class="mt-0.5 text-xs text-csc-ink-subtle">{{ user.email }}</p>
                                    <p class="mt-1 text-xs text-csc-ink-muted">{{ user.role_label }}</p>
                                </div>
                                <span
                                    class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="user.is_active ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'"
                                >
                                    {{ user.is_active ? 'Active' : 'Off' }}
                                </span>
                            </div>
                            <p v-if="user.last_login_at" class="mt-2 text-xs text-csc-ink-subtle">
                                Last sign-in {{ user.last_login_at }}
                            </p>
                            <div class="mt-3 flex items-center justify-between border-t border-csc-line pt-3">
                                <span class="text-xs text-csc-ink-subtle">{{ user.field_office ?? '—' }}</span>
                                <AppRowActions v-if="canManage" :actions="actionsFor(user)" layout="card" />
                            </div>
                        </li>
                    </ul>

                    <AppPagination :pagination="users" label="staff accounts" class="pt-2" />
                </template>
            </div>
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
