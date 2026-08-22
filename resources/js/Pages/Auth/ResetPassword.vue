<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: null },
});

const form = useForm({
    token: props.token,
    email: props.email ?? '',
    password: '',
    password_confirmation: '',
});

// Same live guidance as the register screen (Password::min(8)->letters()->numbers()).
const passwordChecks = computed(() => [
    { label: 'At least 8 characters', passed: form.password.length >= 8 },
    { label: 'Contains a letter', passed: /[a-zA-Z]/.test(form.password) },
    { label: 'Contains a number', passed: /\d/.test(form.password) },
]);

// Spoken in place of the list on every change to it. Phrased as progress
// rather than as failure, because it fires while someone is still typing.
const passwordCheckSummary = computed(() => {
    const met = passwordChecks.value.filter((check) => check.passed).length;

    return `${met} of ${passwordChecks.value.length} password requirements met`;
});

// Stays silent until both fields hold something, so the field reads calm at rest.
const confirmationMatches = computed(
    () => form.password_confirmation.length > 0 && form.password === form.password_confirmation
);

const submit = () => {
    form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
};
</script>

<template>
    <Head title="Reset password" />

    <AuthLayout
        headline="Set a new password"
        tagline="Choose a strong password, then sign in with it. Your account keeps every training, certificate, and event QR in one place."
        :benefits="['Reserve a slot in CSC programs', 'Keep every certificate in one place', 'Check in to events with a personal QR code']"
    >
        <h2 class="text-2xl font-semibold tracking-tight text-csc-blue sm:text-3xl">
            Choose a new password
        </h2>
        <p class="mt-2 text-sm text-csc-ink-muted">
            The reset link works once, so pick something you'll remember and confirm it below.
        </p>

        <p
            v-if="form.errors.form"
            class="mt-6 flex items-start gap-2 rounded-lg border border-csc-red-ink/30 bg-csc-red-ink/5 px-4 py-3 text-sm font-medium text-csc-red-ink"
            role="alert"
        >
            <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="12" r="9" />
                <path d="M12 7v6M12 16.5v.5" stroke-linecap="round" />
            </svg>
            {{ form.errors.form }}
        </p>

        <form class="mt-8 space-y-5" novalidate @submit.prevent="submit">
            <input v-model="form.token" type="hidden" />

            <!-- Read-only: the address is tied to the emailed token, so there is
                 nothing to change and nothing to retype. -->
            <AppInput
                v-model="form.email"
                label="Email Address"
                type="email"
                autocomplete="username"
                :error="form.errors.email"
                required
                readonly
            />

            <div>
                <AppInput
                    v-model="form.password"
                    label="New Password"
                    type="password"
                    autocomplete="new-password"
                    placeholder="••••••••"
                    :error="form.errors.password"
                    required
                />

                <!--
                    Live requirement checks; hidden until the field has a first
                    character.
                
                    The list itself is no longer the live region. It used to be,
                    which meant every keystroke re-announced all three rules in
                    full — someone typing an eight-character password with a
                    screen reader heard twenty-four announcements to learn three
                    facts. The summary line below is the live region instead, so
                    what gets spoken is the thing that actually changed.
                -->
                <ul v-if="form.password.length > 0" class="mt-2 space-y-1.5" aria-hidden="true">
                    <li
                        v-for="check in passwordChecks"
                        :key="check.label"
                        class="flex items-center gap-2 text-xs"
                        :class="check.passed ? 'font-medium text-success' : 'text-csc-ink-subtle'"
                    >
                        <svg class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path v-if="check.passed" d="M5 12.5l4.5 4.5L19 7.5" />
                            <circle v-else cx="12" cy="12" r="9" />
                        </svg>
                        {{ check.label }}
                    </li>
                </ul>

                <p v-if="form.password.length > 0" class="sr-only" role="status">{{ passwordCheckSummary }}</p>
            </div>

            <div>
                <AppInput
                    v-model="form.password_confirmation"
                    label="Confirm New Password"
                    type="password"
                    autocomplete="new-password"
                    placeholder="••••••••"
                    :error="form.errors.password_confirmation"
                    required
                />

                <!-- Match indicator; appears once the confirmation field has a first character -->
                <p
                    v-if="form.password_confirmation.length > 0"
                    class="mt-2 flex items-center gap-2 text-xs font-medium"
                    :class="confirmationMatches ? 'text-success' : 'text-csc-red-ink'"
                    aria-live="polite"
                >
                    <svg class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path v-if="confirmationMatches" d="M5 12.5l4.5 4.5L19 7.5" />
                        <path v-else d="M6 6l12 12M18 6L6 18" />
                    </svg>
                    {{ confirmationMatches ? 'Passwords match' : 'Passwords do not match' }}
                </p>
            </div>

            <AppButton type="submit" size="lg" block :loading="form.processing" icon="arrow-right">
                {{ form.processing ? 'Resetting…' : 'Reset password' }}
            </AppButton>
        </form>

        <p class="mt-8 text-center text-sm text-csc-ink-muted">
            Remembered it?
            <Link href="/login" class="font-medium text-csc-blue transition-colors duration-150 hover:text-csc-red-ink">
                Back to sign in
            </Link>
        </p>
    </AuthLayout>
</template>