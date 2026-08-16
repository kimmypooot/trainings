<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppAvatar from '@/Components/AppAvatar.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppConfirmModal from '@/Components/AppConfirmModal.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppStat from '@/Components/AppStat.vue';

const props = defineProps({
    participant: { type: Object, required: true },
    trainingStats: { type: Object, required: true },
    registrations: { type: Array, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const error = computed(() => page.props.errors?.participant);

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
    ['Sector', 'sector'],
    ['Region', 'region'],
    ['Province', 'province'],
    ['City / Municipality', 'city_municipality'],
    ['CSC Field Office', 'csc_field_office'],
    ['Position Level', 'position_level'],
    ['Nature of Appointment', 'employment_status'],
    ['Agency Address', 'organization_address'],
];

// How this participant gets in. Worth naming rather than assuming a password:
// a Google-only account has none, and the reset link would land them on a form
// they cannot use — which is why the server refuses it.
const signInMethod = computed(() => {
    if (props.participant.has_password && props.participant.has_google) return 'Password and Google';

    return props.participant.has_google ? 'Google only' : 'Password';
});

const confirming = ref(null);
const processing = ref(false);

const dialog = computed(() => {
    if (!confirming.value) return null;

    const who = props.participant.name ?? props.participant.email;

    if (confirming.value === 'reset') {
        return {
            title: 'Send a password reset link?',
            description: `${who} will receive a single-use link at ${props.participant.email}. Their current password keeps working until they use it.`,
            confirmLabel: 'Send reset link',
        };
    }

    return props.participant.is_active
        ? {
              title: `Deactivate ${who}?`,
              description:
                  'They will not be able to sign in. Existing registrations and certificates are left as they are.',
              confirmLabel: 'Deactivate',
          }
        : {
              title: `Activate ${who}?`,
              description: 'They will be able to sign in again.',
              confirmLabel: 'Activate',
          };
});

const confirm = () => {
    const url =
        confirming.value === 'reset'
            ? `/admin/participants/${props.participant.id}/password-reset`
            : `/admin/participants/${props.participant.id}/toggle`;

    processing.value = true;
    router.post(
        url,
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

            <AppAlert v-if="error" tone="danger">{{ error }}</AppAlert>

            <AppCard>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <AppAvatar :name="participant.name" :src="participant.avatar" size="lg" />
                        <div class="min-w-0">
                            <p class="text-lg font-semibold text-csc-blue">{{ participant.name ?? '—' }}</p>
                            <p class="text-sm text-csc-ink/70">{{ participant.email }}</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <AppBadge
                                    :status="participant.is_active ? 'verified' : 'cancelled'"
                                    :label="participant.is_active ? 'Active' : 'Deactivated'"
                                />
                                <AppBadge
                                    :status="participant.email_verified ? 'verified' : 'pending'"
                                    :label="participant.email_verified ? 'Email verified' : 'Email unverified'"
                                />
                            </div>
                        </div>
                    </div>

                    <div v-if="can.manage" class="flex flex-wrap gap-2">
                        <AppButton :href="participant.edit_url" size="sm" variant="ghost">Edit Profile</AppButton>
                        <AppButton size="sm" variant="ghost" icon="lock" @click="confirming = 'reset'">
                            Reset Password
                        </AppButton>
                        <AppButton size="sm" variant="ghost" @click="confirming = 'toggle'">
                            {{ participant.is_active ? 'Deactivate' : 'Activate' }}
                        </AppButton>
                    </div>
                </div>

                <dl class="mt-5 grid gap-4 border-t border-csc-line pt-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-csc-ink/60">Sign-in Method</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ signInMethod }}</dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink/60">Last Sign-in</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ participant.last_login_at ?? 'Never' }}</dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink/60">Registered On</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ participant.joined_at ?? '—' }}</dd>
                    </div>
                </dl>
            </AppCard>

            <AppAlert v-if="!participant.profile_complete" tone="warning">
                This participant has not completed their profile yet.
            </AppAlert>

            <AppAlert v-if="participant.profile?.food_restrictions" tone="warning" title="Food restrictions">
                {{ participant.profile.food_restrictions }}
            </AppAlert>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <AppStat label="Total Registrations" :value="trainingStats.total" />
                <AppStat label="Fees Settled" :value="trainingStats.settled" />
                <AppStat label="Awaiting Payment" :value="trainingStats.awaiting_payment" />
                <AppStat label="Promissory Notes" :value="trainingStats.promissory" />
            </div>

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
                <!--
                    The whole record as a file, for when a participant asks what
                    they have attended or an agency asks what CSC has delivered
                    to its staff. Offered only when there is something to export.
                -->
                <template v-if="registrations.length" #action>
                    <AppButton
                        :href="participant.history_export_url"
                        external
                        size="sm"
                        variant="ghost"
                        icon="download"
                    >
                        Export History
                    </AppButton>
                </template>

                <ul v-if="registrations.length" class="divide-y divide-csc-line">
                    <li
                        v-for="registration in registrations"
                        :key="registration.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3.5"
                    >
                        <div class="min-w-0">
                            <Link
                                :href="registration.roster_url"
                                class="text-sm font-medium text-csc-blue hover:underline"
                            >
                                {{ registration.title }}
                            </Link>
                            <p class="mt-0.5 text-xs text-csc-ink/60">
                                Starts {{ registration.starts_at }} · Registered {{ registration.registered_at }}
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2">
                            <AppBadge :status="registration.payment.tone" :label="registration.payment.label" />
                            <AppBadge :status="registration.status" />
                        </div>
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
