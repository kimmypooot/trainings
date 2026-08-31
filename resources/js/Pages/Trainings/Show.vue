<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';
import TrainingRegistrationForm from '@/Components/TrainingRegistrationForm.vue';

const props = defineProps({
    training: { type: Object, required: true },
    registration: { type: Object, default: null },
    eligibility: { type: Object, required: true },
    chargeOptions: { type: Array, required: true },
});

const page = usePage();
const error = computed(() => page.props.errors?.registration);

const working = ref(false);
const confirmingCancel = ref(false);

const isActive = computed(
    () => props.registration && ['pending', 'approved', 'completed'].includes(props.registration.status)
);

// Why the join link is not on the page yet. The link itself never reaches the
// client until it is earned, so this only ever names the missing step.
const joinLockedReason = computed(() => {
    if (!props.training.has_meeting_link || props.training.meeting_link) {
        return null;
    }

    if (!props.registration) {
        return 'Register for this training to receive the join link.';
    }

    if (!props.registration.fee_settled) {
        return 'The join link is released once your payment has been verified.';
    }

    if (props.registration.status === 'pending') {
        return 'The join link is released once CSC approves your registration.';
    }

    return null;
});

const cancel = () => {
    working.value = true;
    router.delete(`/my/registrations/${props.registration.id}`, {
        onFinish: () => {
            working.value = false;
            confirmingCancel.value = false;
        },
    });
};
</script>

<template>
    <Head :title="training.title" />

    <AuthenticatedLayout title="Training Details" current="trainings">
        <div class="mx-auto max-w-4xl space-y-5">
            <Link
                href="/trainings"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-csc-blue transition-colors hover:text-csc-blue-deep focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
            >
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M15 6l-6 6 6 6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                All Trainings
            </Link>

            <AppAlert v-if="error" tone="danger">{{ error }}</AppAlert>

            <AppCard>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-xl font-semibold tracking-tight text-csc-blue sm:text-2xl">
                        {{ training.title }}
                    </h2>
                    <AppBadge v-if="training.is_supervisory" status="supervisory" />
                </div>
                <AppBadge v-if="isActive" :status="registration.status" />

                <dl class="mt-6 grid gap-5 border-t border-csc-line pt-5 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-csc-ink-subtle">Starts</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ training.starts_at }}</dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink-subtle">Ends</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ training.ends_at }}</dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink-subtle">Venue</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ training.venue }}</dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink-subtle">Slots</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">
                            <template v-if="training.capacity === null">No limit</template>
                            <template v-else>
                                {{ training.slots_remaining }} of {{ training.capacity }} remaining
                            </template>
                        </dd>
                    </div>
                    <div v-if="training.level_label">
                        <dt class="text-csc-ink-subtle">Level</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ training.level_label }}</dd>
                    </div>
                    <div v-if="training.registration_closes_at">
                        <dt class="text-csc-ink-subtle">Registration closes</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ training.registration_closes_at }}</dd>
                    </div>
                    <div v-if="training.venue_details" class="sm:col-span-2">
                        <dt class="text-csc-ink-subtle">Venue details</dt>
                        <dd class="mt-0.5 leading-relaxed whitespace-pre-line text-csc-ink-muted">
                            {{ training.venue_details }}
                        </dd>
                    </div>
                </dl>

                <!-- The join link, once it has been earned. -->
                <div v-if="training.meeting_link" class="mt-5 rounded-lg bg-info-soft p-4">
                    <h3 class="flex items-center gap-2 text-sm font-semibold text-info">
                        <AppIcon name="link" size="sm" />
                        Join link
                    </h3>
                    <a
                        :href="training.meeting_link"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-1.5 block text-sm font-medium break-all text-csc-blue underline underline-offset-2 transition-colors hover:text-csc-blue-deep focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    >
                        {{ training.meeting_link }}
                    </a>
                    <p class="mt-1.5 text-xs text-csc-ink-subtle">
                        Please keep this link to yourself — it is issued to you alone.
                    </p>
                </div>

                <p v-else-if="joinLockedReason" class="mt-5 flex items-start gap-2 rounded-lg bg-csc-blue-tint p-4 text-sm text-csc-ink-muted">
                    <AppIcon name="lock" size="sm" class="mt-0.5 shrink-0" />
                    {{ joinLockedReason }}
                </p>

                <AppAlert v-if="training.is_supervisory" tone="info" class="mt-5">
                    This is a Supervisory Development Course. You will be asked to submit an output
                    before your completion is credited.
                </AppAlert>

                <div v-if="training.description" class="mt-6 border-t border-csc-line pt-5">
                    <h3 class="text-sm font-semibold text-csc-blue">About this training</h3>
                    <p class="mt-2 text-sm leading-relaxed whitespace-pre-line text-csc-ink-muted">
                        {{ training.description }}
                    </p>
                </div>

                <template #footer>
                    <!-- Registered: offer withdrawal -->
                    <div v-if="isActive && ['pending', 'approved'].includes(registration.status)">
                        <div v-if="!confirmingCancel" class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <p
                                class="text-sm"
                                :class="registration.status === 'pending' ? 'text-warning' : 'text-success'"
                            >
                                <!--
                                    A pending registration on a paid run is not
                                    waiting on a person, it is waiting on the
                                    fee — the slot is confirmed by
                                    PaymentService::confirmSlotOnSettlement.
                                    Naming CSC as the blocker sent participants
                                    off to wait for a decision that was never
                                    going to arrive on its own.
                                -->
                                <template v-if="registration.status === 'pending' && !registration.fee_settled">
                                    Submitted on {{ registration.registered_at }} — your slot is held until
                                    the fee is settled.
                                </template>
                                <template v-else-if="registration.status === 'pending'">
                                    Submitted on {{ registration.registered_at }} — awaiting approval by CSC.
                                </template>
                                <template v-else>
                                    Approved. You registered on {{ registration.registered_at }}.
                                </template>
                            </p>
                            <AppButton variant="ghost" size="sm" @click="confirmingCancel = true">
                                Cancel Registration
                            </AppButton>
                        </div>

                        <AppAlert v-else tone="warning" title="Cancel your registration?">
                            Your slot will be released to another participant. You can register again later if
                            slots remain.
                            <template #action>
                                <div class="flex gap-2">
                                    <AppButton size="sm" variant="ghost" @click="confirmingCancel = false">
                                        Keep It
                                    </AppButton>
                                    <AppButton size="sm" variant="accent" :loading="working" icon="close" @click="cancel">
                                        Cancel
                                    </AppButton>
                                </div>
                            </template>
                        </AppAlert>
                    </div>

                    <p v-else-if="isActive" class="text-sm font-medium text-success">
                        You completed this training.
                    </p>

                    <p v-else-if="training.registration_closed" class="text-sm font-medium text-csc-ink-subtle">
                        Registration for this training has closed.
                    </p>

                    <p v-else-if="training.is_full" class="text-sm font-medium text-danger">
                        This training is full.
                    </p>

                    <!-- Handles its own barred / questions / confirm steps. -->
                    <TrainingRegistrationForm
                        v-else
                        :training="training"
                        :eligibility="eligibility"
                        :charge-options="chargeOptions"
                    />
                </template>
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
