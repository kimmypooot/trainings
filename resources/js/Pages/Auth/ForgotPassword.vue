<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';

defineProps({
    status: { type: String, default: null },
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post('/forgot-password', { onSuccess: () => form.reset() });
};
</script>

<template>
    <Head title="Forgot password" />

    <AuthLayout
        headline="We'll help you back in"
        tagline="Enter the email on your CSC TIMS account and we'll send a link to set a new password."
        :benefits="['Reserve a slot in CSC programs', 'Keep every certificate in one place', 'Check in to events with a personal QR code']"
    >
        <h2 class="text-2xl font-semibold tracking-tight text-csc-blue sm:text-3xl">
            Forgot your password?
        </h2>
        <p class="mt-2 text-sm text-csc-ink-muted">
            No problem. Tell us the address you registered with and we'll email you a reset link.
        </p>

        <!-- The server always sends back the same message, so a visitor cannot
             tell which addresses have accounts from this page. -->
        <p
            v-if="status"
            class="mt-6 rounded-lg border border-csc-blue/20 bg-csc-blue-tint px-4 py-3 text-sm text-csc-blue"
            role="status"
        >
            {{ status }}
        </p>

        <p
            v-if="form.errors.email"
            class="mt-6 flex items-start gap-2 rounded-lg border border-csc-red-ink/30 bg-csc-red-ink/5 px-4 py-3 text-sm font-medium text-csc-red-ink"
            role="alert"
        >
            <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="12" r="9" />
                <path d="M12 7v6M12 16.5v.5" stroke-linecap="round" />
            </svg>
            {{ form.errors.email }}
        </p>

        <form class="mt-8 space-y-5" novalidate @submit.prevent="submit">
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

            <AppButton type="submit" size="lg" block :loading="form.processing" icon="arrow-right">
                {{ form.processing ? 'Sending…' : 'Send reset link' }}
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
