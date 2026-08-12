<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

const props = defineProps({
    participants: { type: Object, required: true },
    filters: { type: Object, required: true },
    scopedTo: { type: String, default: null },
});

const search = ref(props.filters.search ?? '');

let debounce;
watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            '/admin/participants',
            { search: search.value || undefined },
            { preserveState: true, replace: true }
        );
    }, 300);
});
</script>

<template>
    <Head title="Manage Participants" />

    <AuthenticatedLayout title="Manage Participants" current="admin-participants">
        <div class="mx-auto max-w-6xl space-y-5">
            <AppAlert v-if="scopedTo" tone="info">
                Showing participants assigned to <strong>{{ scopedTo }}</strong> only.
            </AppAlert>

            <input
                v-model="search"
                type="search"
                placeholder="Search by name, email, or agency…"
                aria-label="Search participants"
                class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue sm:max-w-md"
            />

            <AppCard v-if="!participants.data.length" :padded="false">
                <AppEmptyState
                    title="No participants found"
                    description="Participants appear here once they register an account."
                    icon="M3 20a6 6 0 0 1 12 0M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7"
                />
            </AppCard>

            <template v-else>
                <div class="hidden overflow-hidden rounded-xl border border-csc-line bg-white md:block">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Name</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Agency</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Field Office</th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink/70">Trainings</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-csc-line">
                            <tr v-for="participant in participants.data" :key="participant.id">
                                <td class="px-5 py-3.5">
                                    <Link :href="participant.url" class="font-medium text-csc-blue hover:underline">
                                        {{ participant.name ?? participant.email }}
                                    </Link>
                                    <p class="mt-0.5 text-xs text-csc-ink/60">{{ participant.email }}</p>
                                    <p v-if="!participant.profile_complete" class="mt-1 text-xs font-medium text-warning">
                                        Profile incomplete
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-csc-ink/75">
                                    {{ participant.organization ?? '—' }}
                                    <p v-if="participant.position" class="mt-0.5 text-xs text-csc-ink/55">
                                        {{ participant.position }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-xs text-csc-ink/70">
                                    {{ participant.field_office ?? '—' }}
                                </td>
                                <td class="px-5 py-3.5 text-csc-ink/75">{{ participant.registrations }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <ul class="space-y-3 md:hidden">
                    <li
                        v-for="participant in participants.data"
                        :key="participant.id"
                        class="rounded-xl border border-csc-line bg-white p-4"
                    >
                        <Link :href="participant.url" class="text-sm font-semibold text-csc-blue">
                            {{ participant.name ?? participant.email }}
                        </Link>
                        <p class="mt-0.5 text-xs text-csc-ink/60">{{ participant.email }}</p>
                        <p class="mt-1 text-xs text-csc-ink/70">{{ participant.organization ?? '—' }}</p>
                        <p class="mt-2 text-xs text-csc-ink/60">
                            {{ participant.registrations }} training{{ participant.registrations === 1 ? '' : 's' }}
                        </p>
                    </li>
                </ul>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
