<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { beginSignIn, dismiss } from '@/authSplash';

defineProps({
    // The address they have just authenticated with. Shown because it is the
    // single most useful jog to the memory: someone who registered with an
    // office address a year ago sees their personal Gmail here and recognises
    // that this is not the account the CSC writes to.
    googleEmail: { type: String, required: true },
});

// One flag for both buttons. They are opposite answers to the same question,
// and letting the second be clicked while the first is in flight is how a
// double submission turns into a duplicate account.
const deciding = ref(false);

const createAccount = () => {
    if (deciding.value) return;
    deciding.value = true;
    beginSignIn();

    router.post(
        '/auth/google/new',
        {},
        {
            onError: () => {
                dismiss();
                deciding.value = false;
            },
        }
    );
};

const signInInstead = () => {
    if (deciding.value) return;
    deciding.value = true;

    router.post(
        '/auth/google/new/cancel',
        {},
        {
            onFinish: () => {
                deciding.value = false;
            },
        }
    );
};
</script>

<template>
    <Head title="One quick question" />

    <AuthLayout
        headline="Just one thing before we continue"
        tagline="Keeping every training you attend under a single account is what lets your certificates, attendance, and event QR code stay together."
        :benefits="['One account holds your whole training history', 'Certificates stay in one place', 'You can sign in with Google either way']"
    >
        <p class="inline-flex items-center gap-1.5 rounded-full bg-csc-blue-tint px-3 py-1 text-2xs font-semibold tracking-widest text-csc-blue uppercase">
            Confirm
        </p>

        <h2 class="mt-4 text-2xl font-semibold tracking-tight text-csc-blue sm:text-3xl">
            Have you used CSC TIMS before?
        </h2>

        <p class="mt-3 text-sm leading-relaxed text-csc-ink-muted">
            You signed in with
            <span class="font-medium text-csc-ink">{{ googleEmail }}</span
            >, which we do not recognise yet. If you have registered before using a different email address and a
            password, connect this Google account to it instead — that keeps all your records together.
        </p>

        <div class="mt-8 space-y-4">
            <!-- The safe answer first, and given the weight, because it is the
                 one that is irreversible if answered wrongly. Creating a second
                 account is easy to do and awkward to undo. -->
            <div class="rounded-xl border border-csc-line bg-white p-5">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-csc-blue-tint">
                        <AppIcon name="link" class="text-csc-blue" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-csc-ink">Yes — I already have an account</p>
                        <p class="mt-1 text-sm leading-relaxed text-csc-ink-muted">
                            Sign in with your email and password. Then open your profile and choose
                            <span class="font-medium text-csc-ink">Connect</span> under Linked Accounts. After that,
                            this Google button will bring you straight back to that same account.
                        </p>
                    </div>
                </div>

                <AppButton
                    class="mt-4"
                    block
                    :disabled="deciding"
                    icon="arrow-right"
                    @click="signInInstead"
                >
                    Take me to sign in
                </AppButton>
            </div>

            <div class="rounded-xl border border-csc-line bg-white p-5">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-csc-blue-tint">
                        <AppIcon name="user" class="text-csc-blue" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-csc-ink">No — this is my first time</p>
                        <p class="mt-1 text-sm leading-relaxed text-csc-ink-muted">
                            We will create your account with this Google address and take you to the profile form.
                        </p>
                    </div>
                </div>

                <AppButton
                    class="mt-4"
                    variant="ghost"
                    block
                    :loading="deciding"
                    :disabled="deciding"
                    @click="createAccount"
                >
                    Create my account
                </AppButton>
            </div>
        </div>

        <p class="mt-6 border-t border-csc-line pt-6 text-center text-xs leading-relaxed text-csc-ink-subtle">
            Not sure? Choosing <span class="font-medium text-csc-ink-muted">Take me to sign in</span> is always safe —
            you can come back to this Google button at any time.
        </p>
    </AuthLayout>
</template>
