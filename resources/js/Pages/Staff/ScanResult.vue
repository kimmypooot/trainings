<script setup>
import { router, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppAvatar from '@/Components/AppAvatar.vue';
import AppButton from '@/Components/AppButton.vue';

const props = defineProps({
    participant: { type: Object, required: true },
    sessions: { type: Array, default: () => [] },
    checkInUrl: { type: String, required: true },
});

// Tracked per registration so one pending request cannot grey out the other
// buttons when a venue is running two trainings at once.
const submitting = ref(null);

const checkIn = (session) => {
    submitting.value = session.registration_id;

    router.post(
        props.checkInUrl,
        { registration_id: session.registration_id },
        { preserveScroll: true, onFinish: () => (submitting.value = null) }
    );
};
</script>

<template>
    <Head title="Scan Result" />

    <AuthenticatedLayout title="Scan Result" current="dashboard">
        <div class="mx-auto max-w-lg space-y-5">
            <AppCard>
                <div class="flex items-center gap-4">
                    <AppAvatar :name="participant.name" size="lg" />
                    <div class="min-w-0">
                        <p class="truncate text-lg font-semibold text-csc-blue">{{ participant.name }}</p>
                        <p v-if="participant.position" class="truncate text-sm text-csc-ink/70">
                            {{ participant.position }}
                        </p>
                        <p v-if="participant.organization" class="truncate text-sm text-csc-ink/60">
                            {{ participant.organization }}
                        </p>
                    </div>
                </div>

                <dl class="mt-6 grid gap-4 border-t border-csc-line pt-5 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-csc-ink/60">Email</dt>
                        <dd class="mt-0.5 font-medium break-words text-csc-ink">{{ participant.email }}</dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink/60">CSC Field Office</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ participant.field_office ?? '—' }}</dd>
                    </div>
                </dl>
            </AppCard>

            <AppAlert v-if="participant.food_restrictions" tone="warning" title="Food restrictions">
                {{ participant.food_restrictions }}
            </AppAlert>

            <AppCard title="Check In">
                <AppAlert v-if="!sessions.length" tone="info">
                    Identity confirmed, but this participant has no approved registration for a
                    training running today, so there is nothing to check in.
                </AppAlert>

                <ul v-else class="space-y-3">
                    <li
                        v-for="session in sessions"
                        :key="session.registration_id"
                        class="rounded-lg border border-csc-line p-4"
                    >
                        <p class="font-semibold text-csc-ink">{{ session.training }}</p>
                        <p class="mt-0.5 text-sm text-csc-ink/60">
                            {{ session.venue }} · Day {{ session.day }} of {{ session.of_days }}
                        </p>

                        <div class="mt-4">
                            <p
                                v-if="session.already_checked_in"
                                class="text-sm font-medium text-csc-blue"
                            >
                                Already checked in at {{ session.time_in }} — {{ session.status_label }}.
                            </p>
                            <AppButton
                                v-else
                                size="lg"
                                class="w-full sm:w-auto"
                                :loading="submitting === session.registration_id"
                                @click="checkIn(session)"
                            >
                                Check in for day {{ session.day }}
                            </AppButton>
                        </div>
                    </li>
                </ul>
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
