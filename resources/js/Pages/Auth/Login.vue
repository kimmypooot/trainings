<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AppAuthSplash from '@/Components/AppAuthSplash.vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';

defineProps({
    status: { type: String, default: null },
    googleEnabled: { type: Boolean, default: false },
});

const showPassword = ref(false);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

// The branded splash shows while the POST is in flight and flips to a welcome
// the moment the server accepts the session. Success redirects into the app, so
// this page (and the splash) unmounts on arrival; only a failed login turns it
// off by hand.
const showPreload = ref(false);
const welcome = ref(false);

const submit = () => {
    showPreload.value = true;
    form.post('/login', {
        onSuccess: () => {
            welcome.value = true;
        },
        onError: () => {
            showPreload.value = false;
        },
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Sign in" />

    <AuthLayout
        headline="Welcome back to CSC TIMS"
        tagline="Register for training programs, download your certificates, and pull up your event QR code — all from one secure account."
        :benefits="['Reserve a slot in CSC programs', 'Keep every certificate in one place', 'Check in to events with a personal QR code']"
    >
        <h2 class="text-2xl font-semibold tracking-tight text-csc-blue sm:text-3xl">
            Sign in to your account
        </h2>
        <p class="mt-2 text-sm text-csc-ink/70">
            Sign in to register for trainings and manage your records.
        </p>

                <p
                    v-if="status"
                    class="mt-6 rounded-lg border border-csc-blue/20 bg-csc-blue-tint px-4 py-3 text-sm text-csc-blue"
                    role="status"
                >
                    {{ status }}
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
                    class="mt-8 inline-flex w-full items-center justify-center gap-3 rounded-lg border border-csc-line bg-white px-5 py-3 text-sm font-semibold text-csc-ink transition-colors duration-150 hover:border-csc-blue/40 hover:bg-csc-blue-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    :class="googleEnabled ? '' : 'pointer-events-none opacity-50'"
                    :aria-disabled="googleEnabled ? undefined : 'true'"
                    :tabindex="googleEnabled ? undefined : -1"
                >
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
                    Continue with Google
                </a>

                <p v-if="!googleEnabled" class="mt-2 text-center text-xs text-csc-ink/50">
                    Google sign-in is not configured on this server yet.
                </p>

                <div class="my-6 flex items-center gap-4" aria-hidden="true">
                    <span class="h-px flex-1 bg-csc-line" />
                    <span class="text-xs font-medium tracking-wide text-csc-ink/50 uppercase">or</span>
                    <span class="h-px flex-1 bg-csc-line" />
                </div>

                <form class="space-y-5" novalidate @submit.prevent="submit">
                    <AppInput
                        v-model="form.email"
                        label="Email Address"
                        type="email"
                        autocomplete="username"
                        placeholder="juan.dela.cruz@csc.gov.ph"
                        :error="form.errors.email"
                        required
                        autofocus
                    />

                    <AppInput
                        v-model="form.password"
                        label="Password"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                        placeholder="••••••••"
                        :error="form.errors.password"
                        required
                    >
                        <template #affix>
                            <button
                                type="button"
                                class="rounded-md p-1.5 text-csc-ink/60 transition-colors duration-150 hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-csc-blue"
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

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <label class="inline-flex items-center gap-2 text-sm text-csc-ink">
                            <input
                                v-model="form.remember"
                                type="checkbox"
                                class="size-4 rounded border-csc-line text-csc-blue accent-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            />
                            Remember Me
                        </label>

                        <a
                            href="/forgot-password"
                            class="rounded text-sm font-medium text-csc-blue transition-colors duration-150 hover:text-csc-red-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        >
                            Forgot password?
                        </a>
                    </div>

                    <AppButton type="submit" size="lg" block :loading="form.processing" icon="arrow-right">
                        {{ form.processing ? 'Signing in…' : 'Sign in' }}
                    </AppButton>
                </form>

                <p class="mt-8 text-center text-sm text-csc-ink/70">
                    No account yet?
                    <Link href="/register" class="font-medium text-csc-blue transition-colors duration-150 hover:text-csc-red-ink">
                        Create one
                    </Link>
                </p>

                <p class="mt-6 border-t border-csc-line pt-6 text-center text-xs leading-relaxed text-csc-ink/60">
                    By signing in you agree to the
                    <Link href="/terms-of-service" class="font-medium text-csc-blue transition-colors hover:text-csc-red-ink">
                        Terms of Service
                    </Link>
                    and
                    <Link href="/privacy-policy" class="font-medium text-csc-blue transition-colors hover:text-csc-red-ink">
                        Privacy Policy </Link
                    >.
                </p>
    </AuthLayout>

    <!-- Branded splash while the sign-in request is in flight -->
    <AppAuthSplash :visible="showPreload">
        <Transition
            enter-active-class="transition-opacity duration-300 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-200 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
            mode="out-in"
        >
            <div v-if="welcome" key="welcome">
                <p class="text-xl font-semibold text-csc-blue">Welcome back!</p>
                <p class="mt-1 text-sm text-csc-ink/70">Taking you to your dashboard…</p>
            </div>
            <div v-else key="loading">
                <p class="text-xl font-semibold text-csc-blue">Signing you in</p>
                <p class="mt-1 text-sm text-csc-ink/70">Please wait a moment…</p>
            </div>
        </Transition>
    </AppAuthSplash>
</template>
