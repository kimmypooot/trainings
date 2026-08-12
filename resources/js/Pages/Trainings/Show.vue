<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';

const props = defineProps({
    training: { type: Object, required: true },
    registration: { type: Object, default: null },
});

const page = usePage();
const flash = computed(() => page.props.flash?.success);
const error = computed(() => page.props.errors?.registration);

const working = ref(false);
const confirmingCancel = ref(false);

const isActive = computed(
    () => props.registration && ['pending', 'approved', 'completed'].includes(props.registration.status)
);

const register = () => {
    working.value = true;
    router.post(`/trainings/${props.training.id}/register`, {}, { onFinish: () => (working.value = false) });
};

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

            <AppAlert v-if="flash" tone="success">{{ flash }}</AppAlert>
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
                    <div v-if="training.registration_closes_at" class="sm:col-span-2">
                        <dt class="text-csc-ink/60">Registration closes</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ training.registration_closes_at }}</dd>
                    </div>
                </dl>

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
                                    <AppButton size="sm" variant="accent" :loading="working" @click="cancel">
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

                    <AppButton v-else size="lg" block :loading="working" @click="register">
                        Register for This Training
                    </AppButton>
                </template>
            </AppCard>
        </div>
    </AuthenticatedLayout>
</template>
