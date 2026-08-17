<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppPagination from '@/Components/AppPagination.vue';

const props = defineProps({
    users: { type: Object, required: true },
    filters: { type: Object, required: true },
    roles: { type: Array, required: true },
    // HRD sees the same directory without the controls — administering the
    // accounts is superadmin's. The routes enforce it; this keeps the page
    // from offering buttons that would 403.
    canManage: { type: Boolean, default: false },
});

const page = usePage();
const error = computed(() => page.props.errors?.user);

const search = ref(props.filters.search ?? '');
const role = ref(props.filters.role ?? '');

let debounce;
watch([search, role], () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            '/admin/users',
            {
                search: search.value || undefined,
                role: role.value || undefined,
                // Start from the first page: paging on a new filter would drop
                // the user onto page N of a much smaller result set.
                page: 1,
            },
            { preserveState: true, replace: true }
        );
    }, 300);
});

const confirming = ref(null);
const processing = ref(false);

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

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-1 flex-col gap-3 sm:flex-row">
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search by name or email…"
                        aria-label="Search staff accounts"
                        class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue sm:max-w-xs"
                    />
                    <select
                        v-model="role"
                        aria-label="Filter by role"
                        class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue sm:max-w-52"
                    >
                        <option value="">All roles</option>
                        <option v-for="option in roles" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <AppButton v-if="canManage" href="/admin/users/create" icon="plus">
                    New Staff Account
                </AppButton>
            </div>

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
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Name</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Role</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Field Office</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Status</th>
                                <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink/70">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-csc-line">
                            <tr v-for="user in users.data" :key="user.id" :class="user.is_active ? '' : 'opacity-60'">
                                <td class="px-5 py-3.5">
                                    <p class="font-medium text-csc-ink">
                                        {{ user.name ?? '—' }}
                                        <span v-if="user.is_self" class="ml-1 text-xs font-normal text-csc-ink/50">
                                            (you)
                                        </span>
                                    </p>
                                    <p class="mt-0.5 text-xs text-csc-ink/60">{{ user.email }}</p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <span class="rounded-full bg-csc-blue-tint px-2.5 py-1 text-xs font-semibold text-csc-blue">
                                        {{ user.role_label }}
                                    </span>
                                    <p
                                        v-if="user.is_collecting_officer"
                                        class="mt-1 text-2xs font-medium text-csc-ink/60"
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
                                <td class="px-5 py-3.5 text-xs text-csc-ink/70">{{ user.field_office ?? '—' }}</td>
                                <td class="px-5 py-3.5">
                                    <span
                                        class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="user.is_active ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'"
                                    >
                                        {{ user.is_active ? 'Active' : 'Deactivated' }}
                                    </span>
                                    <p v-if="user.last_login_at" class="mt-1.5 text-2xs text-csc-ink/55">
                                        Last sign-in {{ user.last_login_at }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                    <span v-if="!canManage" class="text-xs text-csc-ink/45">—</span>
                                    <Link
                                        v-if="canManage"
                                        :href="user.edit_url"
                                        class="text-xs font-semibold text-csc-blue hover:underline"
                                    >
                                        Edit
                                    </Link>
                                    <template v-if="canManage && !user.is_self">
                                        <span class="px-2 text-csc-line">|</span>
                                        <button
                                            type="button"
                                            class="rounded text-xs font-semibold hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                            :class="user.is_active ? 'text-danger' : 'text-success'"
                                            @click="confirming = user"
                                        >
                                            {{ user.is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </template>
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
                                <p class="mt-0.5 text-xs text-csc-ink/60">{{ user.email }}</p>
                                <p class="mt-1 text-xs text-csc-ink/70">{{ user.role_label }}</p>
                            </div>
                            <span
                                class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold"
                                :class="user.is_active ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'"
                            >
                                {{ user.is_active ? 'Active' : 'Off' }}
                            </span>
                        </div>
                        <p v-if="user.last_login_at" class="mt-2 text-xs text-csc-ink/55">
                            Last sign-in {{ user.last_login_at }}
                        </p>
                        <div class="mt-3 flex items-center justify-between border-t border-csc-line pt-3">
                            <span class="text-xs text-csc-ink/60">{{ user.field_office ?? '—' }}</span>
                            <span v-if="canManage" class="flex gap-3">
                                <Link :href="user.edit_url" class="text-xs font-semibold text-csc-blue">Edit</Link>
                                <button
                                    v-if="!user.is_self"
                                    type="button"
                                    class="text-xs font-semibold"
                                    :class="user.is_active ? 'text-danger' : 'text-success'"
                                    @click="confirming = user"
                                >
                                    {{ user.is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </span>
                        </div>
                    </li>
                </ul>

                <AppPagination :pagination="users" label="staff accounts" class="pt-2" />
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
