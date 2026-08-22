<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppInput from '@/Components/AppInput.vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { beginRedirect, beginSignIn, dismiss, welcome } from '@/authSplash';

const props = defineProps({
    status: { type: String, default: null },
    googleEnabled: { type: Boolean, default: false },
    // Set when the login was refused because the email is unverified; the card
    // below offers to resend the link to this exact address.
    unverified_email: { type: String, default: null },
});

const showPassword = ref(false);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

// The blocked-login address, local copy so typing a new one dismisses the card.
const blockedEmail = ref(props.unverified_email);
watch(
    () => props.unverified_email,
    (value) => {
        blockedEmail.value = value;
        resent.value = false;
        resendError.value = '';
    }
);
watch(
    () => form.email,
    (value) => {
        if (value && value !== blockedEmail.value) blockedEmail.value = null;
    }
);

const resending = ref(false);
const resent = ref(false);
const resendError = ref('');

const resend = () => {
    resending.value = true;
    resent.value = false;
    resendError.value = '';

    router.post('/email/resend', { email: blockedEmail.value }, {
        preserveScroll: true,
        onSuccess: () => {
            resent.value = true;
        },
        onError: (errors) => {
            resendError.value = errors.email ?? 'Could not resend the verification link. Try again shortly.';
        },
        onFinish: () => {
            resending.value = false;
        },
    });
};

// The splash is raised the moment the request leaves and flips to the welcome
// once the server accepts the session. It is mounted beside the app rather than
// on this page, so it keeps running after Inertia has swapped this component
// out — the sequence and its timings live in @/authSplash.

// Google sign-in is a plain document navigation, so the splash covers a browser
// page load rather than an XHR. The browser tears this document down when the
// redirect leaves, which is what ends it.
const handoffToGoogle = () => {
    if (!props.googleEnabled) return;
    beginRedirect('Taking you to Google…');
};

// Backing out of Google restores this document from the back/forward cache
// exactly as it was — splash included — which would leave the sign-in form
// unreachable behind a permanent overlay. `persisted` is true only on that
// restore, so this costs nothing on a normal load.
const onPageShow = (event) => {
    if (event.persisted) dismiss();
};

onMounted(() => window.addEventListener('pageshow', onPageShow));
onBeforeUnmount(() => window.removeEventListener('pageshow', onPageShow));

const submit = () => {
    beginSignIn();
    form.post('/login', {
        onSuccess: (page) => {
            // A refused login (e.g. unverified email) redirects right back to
            // this page rather than erroring, so success alone is not proof the
            // sign-in took. Only play the welcome when it actually left.
            if (page.component === 'Auth/Login') {
                dismiss();
                return;
            }

            // The redirect's own props carry the freshly signed-in user, so the
            // greeting needs no extra request to learn the name.
            welcome(page.props.auth?.user?.first_name ?? null);
        },
        onError: dismiss,
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
        <p class="mt-2 text-sm text-csc-ink-muted">
            Sign in to register for trainings and manage your records.
        </p>

                <p
                    v-if="status"
                    class="mt-6 rounded-lg border border-csc-blue/20 bg-csc-blue-tint px-4 py-3 text-sm text-csc-blue"
                    role="status"
                >
                    {{ status }}
                </p>

                <div
                    v-if="blockedEmail"
                    class="mt-6 rounded-lg border border-warning/40 bg-warning-soft px-4 py-4"
                    role="alert"
                >
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-warning/15">
                            <AppIcon name="warning" class="text-warning" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-warning">Email Not Verified</p>
                            <p class="mt-1 text-sm leading-relaxed text-csc-ink-muted">
                                Your email address has not yet been verified. Please check your email and click the
                                verification link to activate your account.
                            </p>
                            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1">
                                <button
                                    type="button"
                                    :disabled="resending"
                                    class="rounded text-sm font-medium text-csc-blue transition-colors duration-150 hover:text-csc-red-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue disabled:cursor-not-allowed disabled:opacity-60"
                                    @click="resend"
                                >
                                    {{ resending ? 'Sending…' : 'Resend verification email' }}
                                </button>
                                <span v-if="resent" class="text-xs font-medium text-success">
                                    A new verification link has been sent.
                                </span>
                                <span v-else-if="resendError" class="text-xs font-medium text-danger">
                                    {{ resendError }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

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
                    @click="handoffToGoogle"
                >
                    <!--
                        Blue, not red, and deliberately so. --color-csc-red-ink
                        is the same hex as --color-danger, and the sign-in error
                        banner sits a few pixels above this button wearing it —
                        a red pill here would say something positive in the
                        page's own error vocabulary. Blue is instead the colour
                        of the primary action on this page (the Sign in button),
                        which is the very claim the badge is making.

                        It sits inside the anchor so it dims with the button
                        when Google is not configured, and so its text joins the
                        link's accessible name — "Continue with Google,
                        Recommended" is exactly what it is there to say.
                    -->
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
                    Continue with Google
                </a>

                <p v-if="!googleEnabled" class="mt-2 text-center text-xs text-csc-ink-subtle">
                    Google sign-in is not configured on this server yet.
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

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <label class="inline-flex items-center gap-2 text-sm text-csc-ink">
                            <input
                                v-model="form.remember"
                                type="checkbox"
                                class="size-4 rounded border-csc-line text-csc-blue accent-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            />
                            Remember Me
                        </label>

                        <!--
                            An Inertia <Link>, not a plain <a>. As a document
                            navigation this was the one slow hop on the sign-in
                            screen, and it threw away the email already typed —
                            precisely the address the visitor is about to have
                            to type again on the next page.
                        -->
                        <Link
                            href="/forgot-password"
                            class="rounded text-sm font-medium text-csc-blue transition-colors duration-150 hover:text-csc-red-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        >
                            Forgot password?
                        </Link>
                    </div>

                    <AppButton type="submit" size="lg" block :loading="form.processing" icon="arrow-right">
                        {{ form.processing ? 'Signing in…' : 'Sign in' }}
                    </AppButton>
                </form>

                <p class="mt-8 text-center text-sm text-csc-ink-muted">
                    No account yet?
                    <Link href="/register" class="font-medium text-csc-blue transition-colors duration-150 hover:text-csc-red-ink">
                        Create one
                    </Link>
                </p>

                <p class="mt-6 border-t border-csc-line pt-6 text-center text-xs leading-relaxed text-csc-ink-subtle">
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
</template>
