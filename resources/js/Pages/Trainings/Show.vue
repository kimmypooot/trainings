<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';

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
const registering = ref(false);

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

// Registration is a short form now, not a single button: finance needs to know
// who the fee is billed to before the receipt is cut, HRD needs to know whether
// to print a certificate, and a supervisory course needs proof of the job.
const registrationForm = useForm({
    charge_to: 'personal',
    needs_certificate: true,
    supporting_document: null,
});

const startRegistering = () => {
    registrationForm.reset();
    registrationForm.clearErrors();
    registering.value = true;
};

const register = () =>
    registrationForm.post(`/trainings/${props.training.id}/register`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            registering.value = false;
            registrationForm.reset();
        },
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
        <div class="mx-auto max-w-3xl space-y-5">
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
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <h2 class="text-xl font-semibold tracking-tight text-csc-blue sm:text-2xl">
                        {{ training.title }}
                    </h2>
                    <AppBadge v-if="isActive" :status="registration.status" />
                </div>

                <dl class="mt-6 grid gap-5 border-t border-csc-line pt-5 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-csc-ink/60">Starts</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ training.starts_at }}</dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink/60">Ends</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ training.ends_at }}</dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink/60">Venue</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ training.venue }}</dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink/60">Slots</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">
                            <template v-if="training.capacity === null">No limit</template>
                            <template v-else>
                                {{ training.slots_remaining }} of {{ training.capacity }} remaining
                            </template>
                        </dd>
                    </div>
                    <div v-if="training.level_label">
                        <dt class="text-csc-ink/60">Level</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ training.level_label }}</dd>
                    </div>
                    <div v-if="training.registration_closes_at">
                        <dt class="text-csc-ink/60">Registration closes</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ training.registration_closes_at }}</dd>
                    </div>
                    <div v-if="training.venue_details" class="sm:col-span-2">
                        <dt class="text-csc-ink/60">Venue details</dt>
                        <dd class="mt-0.5 leading-relaxed whitespace-pre-line text-csc-ink/75">
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
                    <p class="mt-1.5 text-xs text-csc-ink/60">
                        Please keep this link to yourself — it is issued to you alone.
                    </p>
                </div>

                <p v-else-if="joinLockedReason" class="mt-5 flex items-start gap-2 rounded-lg bg-csc-blue-tint p-4 text-sm text-csc-ink/70">
                    <AppIcon name="lock" size="sm" class="mt-0.5 shrink-0" />
                    {{ joinLockedReason }}
                </p>

                <AppAlert v-if="training.is_supervisory" tone="info" class="mt-5">
                    This is a Supervisory Development Course. You will be asked to submit an output
                    before your completion is credited.
                </AppAlert>

                <div v-if="training.description" class="mt-6 border-t border-csc-line pt-5">
                    <h3 class="text-sm font-semibold text-csc-blue">About this training</h3>
                    <p class="mt-2 text-sm leading-relaxed whitespace-pre-line text-csc-ink/75">
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
                                <template v-if="registration.status === 'pending'">
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

                    <p v-else-if="training.registration_closed" class="text-sm font-medium text-csc-ink/60">
                        Registration for this training has closed.
                    </p>

                    <p v-else-if="training.is_full" class="text-sm font-medium text-danger">
                        This training is full.
                    </p>

                    <!-- Turned away before the form is offered at all. -->
                    <AppAlert
                        v-else-if="eligibility.barred"
                        tone="warning"
                        title="You are not eligible for this course"
                    >
                        {{ eligibility.barred_reason }}
                    </AppAlert>

                    <AppButton
                        v-else-if="!registering"
                        size="lg"
                        block
                        icon="clipboard"
                        @click="startRegistering"
                    >
                        Register for This Training
                    </AppButton>

                    <form v-else class="space-y-4" @submit.prevent="register">
                        <fieldset>
                            <legend class="mb-2 text-sm font-medium text-csc-ink">
                                Who is paying the training fee?
                            </legend>
                            <div class="space-y-2">
                                <label
                                    v-for="option in chargeOptions"
                                    :key="option.value"
                                    class="flex cursor-pointer gap-3 rounded-lg border p-3 transition-colors"
                                    :class="
                                        registrationForm.charge_to === option.value
                                            ? 'border-csc-blue bg-csc-blue-tint'
                                            : 'border-csc-line hover:border-csc-blue/40'
                                    "
                                >
                                    <input
                                        v-model="registrationForm.charge_to"
                                        type="radio"
                                        name="charge_to"
                                        :value="option.value"
                                        class="mt-0.5 accent-csc-blue"
                                    />
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium text-csc-ink">
                                            {{ option.label }}
                                        </span>
                                        <span class="block text-xs text-csc-ink/60">
                                            {{ option.description }}
                                        </span>
                                    </span>
                                </label>
                            </div>
                            <p
                                v-if="registrationForm.errors.charge_to"
                                class="mt-1.5 text-xs font-medium text-csc-red-ink"
                            >
                                {{ registrationForm.errors.charge_to }}
                            </p>
                        </fieldset>

                        <label class="flex cursor-pointer gap-3">
                            <input
                                v-model="registrationForm.needs_certificate"
                                type="checkbox"
                                class="mt-0.5 accent-csc-blue"
                            />
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-csc-ink">
                                    Issue me a certificate on completion
                                </span>
                                <span class="block text-xs text-csc-ink/60">
                                    Uncheck if you are attending for the content and do not need one.
                                </span>
                            </span>
                        </label>

                        <div v-if="eligibility.needs_supporting_document">
                            <label
                                for="supporting-document"
                                class="mb-1.5 block text-sm font-medium text-csc-ink"
                            >
                                Proof of supervisory function
                                <span class="text-csc-red-ink" aria-hidden="true">*</span>
                            </label>
                            <input
                                id="supporting-document"
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                required
                                class="w-full rounded-lg border border-csc-line bg-white px-4 py-2.5 text-sm text-csc-ink file:mr-3 file:rounded file:border-0 file:bg-csc-mist file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-csc-blue"
                                aria-describedby="supporting-document-hint"
                                @change="registrationForm.supporting_document = $event.target.files[0]"
                            />
                            <p id="supporting-document-hint" class="mt-1.5 text-xs text-csc-ink/55">
                                {{ eligibility.supporting_document_hint }}
                            </p>
                            <p
                                v-if="registrationForm.errors.supporting_document"
                                class="mt-1.5 text-xs font-medium text-csc-red-ink"
                            >
                                {{ registrationForm.errors.supporting_document }}
                            </p>
                        </div>

                        <div class="flex gap-2">
                            <AppButton
                                type="button"
                                variant="ghost"
                                @click="registering = false"
                            >
                                Cancel
                            </AppButton>
                            <AppButton
                                type="submit"
                                block
                                icon="clipboard"
                                :loading="registrationForm.processing"
                            >
                                Submit Registration
                            </AppButton>
                        </div>
                    </form>
                </template>
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
