<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';

defineProps({
    googleEnabled: { type: Boolean, default: false },
});

const showPassword = ref(false);
const showConfirmation = ref(false);

const form = useForm({
    email: '',
    password: '',
    password_confirmation: '',
    consent: false,
});

// Live password guidance mirrors the server rule exactly (Password::min(8)->letters()->numbers()),
// so a field that reads green here can never bounce on submit.
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
    form.post('/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Create your account" />

    <AuthLayout
        headline="Create your CSC TIMS account"
        tagline="One account lets you register for training programs, download your certificates, and check in to events with your own QR code."
        :benefits="['Reserve a slot in CSC programs', 'Keep every certificate in one place', 'Check in to events with a personal QR code']"
    >
        <p class="inline-flex items-center gap-1.5 rounded-full bg-csc-blue-tint px-3 py-1 text-2xs font-semibold tracking-widest text-csc-blue uppercase">
            Step 1 of 2 · Account details
        </p>

                <h2 class="mt-4 text-2xl font-semibold tracking-tight text-csc-blue sm:text-3xl">Create your account</h2>
                <p class="mt-2 text-sm text-csc-ink-muted">
                    Register to sign up for trainings offered by the Civil Service Commission. We will ask for your
                    details on the next step.
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

                <!-- Google OAuth — full page load, not an Inertia visit -->
                <a
                    href="/auth/google"
                    class="relative mt-8 inline-flex w-full items-center justify-center gap-3 rounded-lg border border-csc-line bg-white px-5 py-3 text-sm font-semibold text-csc-ink transition-colors duration-150 hover:border-csc-blue/40 hover:bg-csc-blue-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    :class="googleEnabled ? '' : 'pointer-events-none opacity-50'"
                    :aria-disabled="googleEnabled ? undefined : 'true'"
                    :tabindex="googleEnabled ? undefined : -1"
                >
                    <!-- See Login.vue: same pill, same reasoning. -->
                    <span
                        class="absolute -top-2.5 right-4 rounded-full bg-csc-blue px-2 py-0.5 text-2xs font-semibold tracking-wide text-white uppercase shadow-sm"
                    >
                        Recommended
                    </span>

                    <svg class="size-5 shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                        <path
                            fill="#4285F4"
                            d="M23.52 12.27c0-.79-.07-1.54-.2-2.27H12v4.51h6.47a5.54 5.54 0 0 1-2.4 3.63v3.02h3.88c2.27-2.09 3.57-5.17 3.57-8.89Z"
                        />
                        <path
                            fill="#34A853"
                            d="M12 24c3.24 0 5.95-1.08 7.94-2.91l-3.88-3.01c-1.08.72-2.45 1.15-4.06 1.15-3.12 0-5.77-2.11-6.71-4.95H1.28v3.1A12 12 0 0 0 12 24Z"
                        />
                        <path
                            fill="#FBBC05"
                            d="M5.29 14.28a7.2 7.2 0 0 1 0-4.56v-3.1H1.28a12 12 0 0 0 0 10.76l4.01-3.1Z"
                        />
                        <path
                            fill="#EA4335"
                            d="M12 4.77c1.77 0 3.35.61 4.6 1.8l3.44-3.44C17.95 1.2 15.24 0 12 0A12 12 0 0 0 1.28 6.62l4.01 3.1C6.23 6.88 8.88 4.77 12 4.77Z"
                        />
                    </svg>
                    Sign up with Google
                </a>

                <p v-if="!googleEnabled" class="mt-2 text-center text-xs text-csc-ink-subtle">
                    Google sign-up is not configured on this server yet.
                </p>

                <!-- See Login.vue: same warning, aimed at the same wrong turn. -->
                <p v-else class="mt-3 text-center text-xs leading-relaxed text-csc-ink-subtle">
                    Already registered with an email and password?
                    <Link href="/login" class="font-medium text-csc-blue hover:text-csc-red-ink">Sign in</Link>
                    instead — you can connect Google to that account afterwards.
                </p>

                <div class="my-6 flex items-center gap-4" aria-hidden="true">
                    <span class="h-px flex-1 bg-csc-line" />
                    <span class="text-xs font-medium tracking-wide text-csc-ink-subtle uppercase">or</span>
                    <span class="h-px flex-1 bg-csc-line" />
                </div>

                <form class="space-y-5" novalidate @submit.prevent="submit">
                    <AppInput
                        v-model="form.email"
                        label="Email Address"
                        type="email"
                        autocomplete="username"
                        placeholder="juan.delacruz@example.com"
                        :error="form.errors.email"
                        hint="Use an address you can access — certificates and event notices are sent here."
                        required
                        autofocus
                    />

                    <div>
                        <AppInput
                            v-model="form.password"
                            label="Password"
                            :type="showPassword ? 'text' : 'password'"
                            autocomplete="new-password"
                            placeholder="••••••••"
                            :error="form.errors.password"
                            required
                        >
                            <template #affix>
                                <button
                                    type="button"
                                    class="rounded-md p-1.5 text-csc-ink-subtle transition-colors duration-150 hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-csc-blue"
                                    :aria-pressed="showPassword"
                                    @click="showPassword = !showPassword"
                                >
                                    <span class="sr-only">{{ showPassword ? 'Hide password' : 'Show password' }}</span>
                                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
                                        <circle cx="12" cy="12" r="3" />
                                        <path v-if="showPassword" d="M4 20 20 4" stroke-linecap="round" />
                                    </svg>
                                </button>
                            </template>
                        </AppInput>

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
                            label="Confirm Password"
                            :type="showConfirmation ? 'text' : 'password'"
                            autocomplete="new-password"
                            placeholder="••••••••"
                            :error="form.errors.password_confirmation"
                            required
                        >
                            <template #affix>
                                <button
                                    type="button"
                                    class="rounded-md p-1.5 text-csc-ink-subtle transition-colors duration-150 hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-csc-blue"
                                    :aria-pressed="showConfirmation"
                                    @click="showConfirmation = !showConfirmation"
                                >
                                    <span class="sr-only">
                                        {{ showConfirmation ? 'Hide confirmation' : 'Show confirmation' }}
                                    </span>
                                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
                                        <circle cx="12" cy="12" r="3" />
                                        <path v-if="showConfirmation" d="M4 20 20 4" stroke-linecap="round" />
                                    </svg>
                                </button>
                            </template>
                        </AppInput>

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

                    <div>
                        <label class="flex items-start gap-3 text-sm text-csc-ink">
                            <input
                                v-model="form.consent"
                                type="checkbox"
                                class="mt-0.5 size-4 shrink-0 rounded border-csc-line accent-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                :aria-invalid="form.errors.consent ? 'true' : undefined"
                                :aria-describedby="form.errors.consent ? 'consent-error' : undefined"
                            />
                            <span class="leading-relaxed">
                                I have read and accept the
                                <Link href="/terms-of-service" class="font-medium text-csc-blue hover:text-csc-red-ink">
                                    Terms of Service
                                </Link>
                                and
                                <Link href="/privacy-policy" class="font-medium text-csc-blue hover:text-csc-red-ink">
                                    Privacy Policy</Link
                                >, and consent to the processing of my personal data.
                            </span>
                        </label>
                        <p v-if="form.errors.consent" id="consent-error" class="mt-1.5 text-xs font-medium text-csc-red-ink">
                            {{ form.errors.consent }}
                        </p>
                    </div>

                    <AppButton type="submit" size="lg" block :loading="form.processing" icon="arrow-right">
                        {{ form.processing ? 'Creating account…' : 'Create account' }}
                    </AppButton>
                </form>

                <p class="mt-8 text-center text-sm text-csc-ink-muted">
                    Already have an account?
                    <Link href="/login" class="font-medium text-csc-blue transition-colors duration-150 hover:text-csc-red-ink">
                        Sign in
                    </Link>
                </p>
    </AuthLayout>
</template>
