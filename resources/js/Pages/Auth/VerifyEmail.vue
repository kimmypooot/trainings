<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLogo from '@/Components/AppLogo.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    email: { type: String, required: true },
});

const sending = ref(false);
const resent = ref(false);
const resendError = ref('');

const resend = () => {
    sending.value = true;
    resent.value = false;
    resendError.value = '';

    router.post('/email/verification-notification', {}, {
        preserveScroll: true,
        onSuccess: () => {
            resent.value = true;
        },
        onError: () => {
            resendError.value = 'Could not resend the link right now. Try again shortly.';
        },
        onFinish: () => {
            sending.value = false;
        },
    });
};
</script>

<template>
    <Head title="Verify your email" />

    <div class="min-h-screen bg-csc-blue-tint">
        <header class="bg-csc-blue">
            <div class="mx-auto flex max-w-4xl items-center justify-between gap-4 px-6 py-3 sm:px-6">
                <AppLogo variant="light" size="md" />
                <AppButton
                    variant="ghost"
                    size="sm"
                    onDark
                    icon="sign-out"
                    @click="router.post('/logout')"
                >
                    Sign out
                </AppButton>
            </div>
        </header>

        <main class="mx-auto max-w-4xl px-4 py-10 sm:px-6 sm:py-14">
            <div class="mx-auto max-w-xl rounded-xl border border-csc-line bg-white p-8 text-center sm:p-12">
                <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-csc-blue-tint">
                    <AppIcon name="envelope" size="lg" class="text-csc-blue" />
                </span>

                <h1 class="mt-4 text-2xl font-semibold tracking-tight text-csc-blue sm:text-3xl">
                    Verify your email address
                </h1>

                <p class="mt-3 text-sm leading-relaxed text-csc-ink/70">
                    We sent a verification link to
                    <span class="font-semibold text-csc-ink">{{ props.email }}</span>. Click the link to activate your
                    account. You will be able to sign in once your email is verified.
                </p>

                <p v-if="resent" class="mt-4 rounded-lg bg-success-soft px-4 py-3 text-sm font-medium text-success">
                    A new verification link has been sent to your email.
                </p>
                <p v-else-if="resendError" class="mt-4 rounded-lg bg-danger-soft px-4 py-3 text-sm font-medium text-danger">
                    {{ resendError }}
                </p>

                <div class="mt-6 flex flex-col gap-3">
                    <AppButton block icon="envelope" :loading="sending" @click="resend">
                        Resend verification email
                    </AppButton>
                    <AppButton block variant="ghost" icon="arrow-right" href="/dashboard">
                        I've already verified — continue
                    </AppButton>
                </div>

                <p class="mt-6 border-t border-csc-line pt-5 text-xs leading-relaxed text-csc-ink/60">
                    Didn't receive it? Check your spam or junk folder, or make sure your email address was typed
                    correctly during registration. Typo in your address?
                    <a
                        href="/#contact"
                        class="font-medium text-csc-blue transition-colors duration-150 hover:text-csc-red-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    >
                        Contact the CSC office
                    </a>
                    to have it corrected.
                </p>
            </div>
        </main>
    </div>
</template>
