<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLogo from '@/Components/AppLogo.vue';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';

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

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Sign in" />

    <div class="min-h-screen lg:grid lg:grid-cols-2">
        <!-- Left: branding. Hidden below lg. -->
        <aside class="relative hidden overflow-hidden lg:flex lg:min-h-screen lg:flex-col lg:justify-center">
            <div
                class="absolute inset-0 bg-cover bg-center"
                style="background-image: url('/images/cscbg_facade.jpeg')"
                aria-hidden="true"
            />
            <div
                class="absolute inset-0"
                style="
                    background: linear-gradient(
                        160deg,
                        rgba(26, 31, 94, 0.93) 0%,
                        rgba(42, 51, 143, 0.87) 55%,
                        rgba(30, 37, 112, 0.95) 100%
                    );
                "
                aria-hidden="true"
            />
            <svg class="pointer-events-none absolute inset-0 size-full opacity-[0.08]" aria-hidden="true">
                <defs>
                    <pattern id="auth-pattern" width="64" height="64" patternUnits="userSpaceOnUse">
                        <circle cx="32" cy="32" r="18" fill="none" stroke="white" stroke-width="1" />
                        <path d="M0 32h64M32 0v64" stroke="white" stroke-width="0.5" />
                    </pattern>
                </defs>
                <rect width="100%" height="100%" fill="url(#auth-pattern)" />
            </svg>
            <div
                class="pointer-events-none absolute -bottom-24 -left-24 size-80 rounded-full bg-csc-red/20 blur-3xl"
                aria-hidden="true"
            />

            <div class="relative px-12 py-16 xl:px-20">
                <Link href="/" class="inline-block rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">
                    <AppLogo variant="light" size="lg" />
                    <span class="sr-only">Back to CSC TIMS home</span>
                </Link>

                <div class="mt-16 max-w-lg">
                    <span class="flex items-center gap-2" aria-hidden="true">
                        <span class="inline-block h-1 w-12 bg-white" />
                        <span class="inline-block h-1 w-4 bg-csc-red" />
                    </span>

                    <h1 class="mt-8 text-4xl leading-tight font-semibold tracking-tight text-balance text-white xl:text-5xl">
                        Welcome back to CSC TIMS
                    </h1>

                    <p class="mt-6 text-base leading-relaxed text-pretty text-white/75 xl:text-lg">
                        Access training programs, nominations, and completion records for your agency —
                        all from one secure account.
                    </p>
                </div>
            </div>
        </aside>

        <!-- Mobile brand strip. Replaces the left panel below lg. -->
        <div class="bg-csc-blue px-4 py-6 sm:px-6 lg:hidden">
            <Link href="/" class="inline-block rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">
                <AppLogo variant="light" size="md" />
                <span class="sr-only">Back to CSC TIMS home</span>
            </Link>
        </div>

        <!-- Right: form -->
        <main class="flex items-center justify-center bg-white px-4 py-12 sm:px-6 lg:min-h-screen lg:px-12 lg:py-16">
            <div class="w-full max-w-md">
                <h2 class="text-2xl font-semibold tracking-tight text-csc-blue sm:text-3xl">
                    Sign in to your account
                </h2>
                <p class="mt-2 text-sm text-csc-ink/70">
                    Use the credentials issued by your agency's TIMS coordinator.
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
                        label="Email address"
                        type="email"
                        autocomplete="username"
                        placeholder="juan.dela.cruz@csc.gov.ph"
                        :error="form.errors.email"
                        required
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
                            Remember me
                        </label>

                        <a
                            href="/#contact"
                            class="rounded text-sm font-medium text-csc-blue transition-colors duration-150 hover:text-csc-red-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        >
                            Forgot password?
                        </a>
                    </div>

                    <AppButton type="submit" size="lg" block :loading="form.processing">
                        {{ form.processing ? 'Signing in…' : 'Sign in' }}
                    </AppButton>
                </form>

                <p class="mt-8 text-center text-sm text-csc-ink/70">
                    No account yet?
                    <a href="/#contact" class="font-medium text-csc-blue transition-colors duration-150 hover:text-csc-red-ink">
                        Contact your TIMS coordinator
                    </a>
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
            </div>
        </main>
    </div>
</template>
