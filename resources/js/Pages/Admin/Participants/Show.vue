<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppAvatar from '@/Components/AppAvatar.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';

defineProps({
    participant: { type: Object, required: true },
    registrations: { type: Array, required: true },
});

const personal = [
    ['Date of Birth', 'date_of_birth'],
    ['Sex', 'sex'],
    ['Civil Status', 'civil_status'],
    ['Person with Disability', 'is_pwd'],
    ['Mobile Number', 'mobile_number'],
];

const employment = [
    ['Position Title', 'position_title'],
    ['Salary Grade', 'salary_grade'],
    ['Agency', 'organization_name'],
    ['Agency Unit / Division', 'agency_unit'],
    ['Sector', 'sector'],
    ['Region', 'region'],
    ['Province', 'province'],
    ['City / Municipality', 'city_municipality'],
    ['CSC Field Office', 'csc_field_office'],
    ['Position Level', 'position_level'],
    ['Nature of Appointment', 'employment_status'],
    ['Agency Address', 'organization_address'],
    ['Home Address', 'home_address'],
];
</script>

<template>
    <Head :title="participant.name ?? participant.email" />

    <AuthenticatedLayout title="Participant" current="admin-participants">
        <div class="mx-auto max-w-4xl space-y-5">
            <Link
                href="/admin/participants"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-csc-blue hover:text-csc-blue-deep"
            >
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M15 6l-6 6 6 6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                Manage Participants
            </Link>

            <AppCard>
                <div class="flex items-center gap-4">
                    <AppAvatar :name="participant.name" size="lg" />
                    <div class="min-w-0">
                        <p class="text-lg font-semibold text-csc-blue">{{ participant.name ?? '—' }}</p>
                        <p class="text-sm text-csc-ink/70">{{ participant.email }}</p>
                    </div>
                </div>
            </AppCard>

            <AppAlert v-if="!participant.profile_complete" tone="warning">
                This participant has not completed their profile yet.
            </AppAlert>

            <AppAlert v-if="participant.profile?.food_restrictions" tone="warning" title="Food restrictions">
                {{ participant.profile.food_restrictions }}
            </AppAlert>

            <template v-if="participant.profile">
                <AppCard title="Personal Information">
                    <dl class="grid gap-4 text-sm sm:grid-cols-2">
                        <div v-for="[label, key] in personal" :key="key">
                            <dt class="text-csc-ink/60">{{ label }}</dt>
                            <dd class="mt-0.5 font-medium text-csc-ink">{{ participant.profile[key] ?? '—' }}</dd>
                        </div>
                    </dl>
                </AppCard>

                <AppCard title="Employment Details">
                    <dl class="grid gap-4 text-sm sm:grid-cols-2">
                        <div v-for="[label, key] in employment" :key="key">
                            <dt class="text-csc-ink/60">{{ label }}</dt>
                            <dd class="mt-0.5 font-medium text-csc-ink">{{ participant.profile[key] ?? '—' }}</dd>
                        </div>
                    </dl>
                </AppCard>
            </template>

            <AppCard title="Training History" :padded="registrations.length > 0">
                <ul v-if="registrations.length" class="divide-y divide-csc-line">
                    <li
                        v-for="registration in registrations"
                        :key="registration.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3.5"
                    >
                        <div>
                            <p class="text-sm font-medium text-csc-ink">{{ registration.title }}</p>
                            <p class="mt-0.5 text-xs text-csc-ink/60">{{ registration.starts_at }}</p>
                        </div>
                        <AppBadge :status="registration.status" />
                    </li>
                </ul>

                <AppEmptyState
                    v-else
                    title="No training history"
                    description="This participant has not registered for any training yet."
                    icon="bookmark"
                />
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
