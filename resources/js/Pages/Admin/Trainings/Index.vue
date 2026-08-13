<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppPagination from '@/Components/AppPagination.vue';

const props = defineProps({
    trainings: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
});

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');

let debounce;
watch([search, status], () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            '/admin/trainings',
            {
                search: search.value || undefined,
                status: status.value || undefined,
                // A filter change starts from the first page; staying on, say,
                // page 4 of a narrowed search reads as "nothing found".
                page: 1,
            },
            { preserveState: true, replace: true }
        );
    }, 300);
});

const tones = {
    draft: 'bg-csc-blue-tint text-csc-ink',
    published: 'bg-success-soft text-success',
    closed: 'bg-warning-soft text-warning',
    completed: 'bg-info-soft text-info',
    cancelled: 'bg-danger-soft text-danger',
};
</script>

<template>
    <Head title="Manage Trainings" />

    <AuthenticatedLayout title="Manage Trainings" current="admin-trainings">
        <div class="mx-auto max-w-6xl space-y-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-1 flex-col gap-3 sm:flex-row">
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search by title…"
                        aria-label="Search trainings"
                        class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue sm:max-w-xs"
                    />
                    <select
                        v-model="status"
                        aria-label="Filter by status"
                        class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue sm:max-w-44"
                    >
                        <option value="">All statuses</option>
                        <option v-for="option in statuses" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <AppButton href="/admin/trainings/create">New Training</AppButton>
            </div>

            <AppCard v-if="!trainings.data.length" :padded="false">
                <AppEmptyState
                    title="No trainings found"
                    description="Create one, or clear the filters if you were searching."
                    icon="calendar"
                >
                    <template #action>
                        <AppButton href="/admin/trainings/create">Create Training</AppButton>
                    </template>
                </AppEmptyState>
            </AppCard>

            <template v-else>
                <!-- Table on wide screens; the same rows stack into cards below md -->
                <div class="hidden overflow-hidden rounded-xl border border-csc-line bg-white md:block">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Training</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Schedule</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Status</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Registered</th>
                                <th scope="col" class="px-5 py-3 text-right font-semibold text-csc-ink/70">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-csc-line">
                            <tr v-for="training in trainings.data" :key="training.id">
                                <td class="px-5 py-3.5">
                                    <p class="font-medium text-csc-ink">{{ training.title }}</p>
                                    <p class="mt-0.5 text-xs text-csc-ink/60">{{ training.venue }}</p>
                                </td>
                                <td class="px-5 py-3.5 text-csc-ink/75">{{ training.starts_at }}</td>
                                <td class="px-5 py-3.5">
                                    <span
                                        class="inline-block rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="tones[training.status]"
                                    >
                                        {{ training.status_label }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-csc-ink/75">
                                    {{ training.registered
                                    }}<template v-if="training.capacity"> / {{ training.capacity }}</template>
                                </td>
                                <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                    <Link :href="training.roster_url" class="text-xs font-semibold text-csc-blue hover:underline">
                                        Roster
                                    </Link>
                                    <span class="px-2 text-csc-line">|</span>
                                    <Link :href="training.edit_url" class="text-xs font-semibold text-csc-blue hover:underline">
                                        Edit
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <ul class="space-y-3 md:hidden">
                    <li
                        v-for="training in trainings.data"
                        :key="training.id"
                        class="rounded-xl border border-csc-line bg-white p-4"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-csc-ink">{{ training.title }}</p>
                                <p class="mt-0.5 text-xs text-csc-ink/60">{{ training.starts_at }}</p>
                                <p class="text-xs text-csc-ink/60">{{ training.venue }}</p>
                            </div>
                            <span
                                class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold"
                                :class="tones[training.status]"
                            >
                                {{ training.status_label }}
                            </span>
                        </div>
                        <div class="mt-3 flex items-center justify-between border-t border-csc-line pt-3">
                            <span class="text-xs text-csc-ink/60">
                                {{ training.registered
                                }}<template v-if="training.capacity"> / {{ training.capacity }}</template>
                                registered
                            </span>
                            <span class="flex gap-3">
                                <Link :href="training.roster_url" class="text-xs font-semibold text-csc-blue">Roster</Link>
                                <Link :href="training.edit_url" class="text-xs font-semibold text-csc-blue">Edit</Link>
                            </span>
                        </div>
                    </li>
                </ul>

                <AppPagination :pagination="trainings" label="trainings" class="pt-2" />
            </template>
        </div>
    </AuthenticatedLayout>
</template>
