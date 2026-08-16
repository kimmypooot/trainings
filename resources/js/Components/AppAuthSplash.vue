<script setup>
import AppLoadingDots from '@/Components/AppLoadingDots.vue';

/**
 * Full-screen branded splash for auth transitions — shown while signing in
 * (Login) and signing out (AuthenticatedLayout).
 *
 * The backdrop blobs, spinning brand rings, and pulsing dots are folded in here
 * rather than split into three one-use components — this overlay is the only
 * surface that needs them. The message rides the default slot so callers own
 * the copy, and can swap it mid-flight (e.g. "Signing you in" then a welcome).
 *
 * Brand colours are fine on this surface: it is a transition between the app
 * and an auth screen, not an in-app panel, so the inside-app rule about
 * retiring the brand red does not apply.
 */
defineProps({
    visible: { type: Boolean, default: false },
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-300 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-200 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="visible"
                class="splash fixed inset-0 z-(--z-modal) flex items-center justify-center overflow-hidden"
            >
                <!-- Ambient brand blobs, drifting behind the card -->
                <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
                    <span class="blob blob-1 absolute -top-20 -left-20 size-72 rounded-full opacity-20" />
                    <span class="blob blob-2 absolute -right-16 -bottom-16 size-96 rounded-full opacity-15" />
                    <span class="blob blob-3 absolute top-1/3 right-1/4 size-48 rounded-full opacity-10" />
                </div>

                <div
                    class="relative mx-4 w-full max-w-sm rounded-2xl border border-white/40 bg-white/80 p-10 text-center shadow-lg backdrop-blur-xl"
                >
                    <!-- Spinning brand rings around the CSC seal -->
                    <div class="relative mx-auto mb-6 size-28">
                        <svg class="absolute inset-0 size-28 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="12" cy="12" r="10" stroke="var(--color-csc-line)" stroke-width="2.5" />
                            <circle
                                cx="12" cy="12" r="10"
                                stroke="var(--color-csc-blue)" stroke-width="2.5"
                                stroke-linecap="round" stroke-dasharray="62.832" stroke-dashoffset="20"
                            />
                        </svg>
                        <svg
                            class="absolute inset-2 size-24 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"
                            style="animation-duration: 2s; animation-direction: reverse"
                        >
                            <circle cx="12" cy="12" r="8" stroke="var(--color-csc-line)" stroke-width="1.5" />
                            <circle
                                cx="12" cy="12" r="8"
                                stroke="var(--color-csc-red)" stroke-width="1.5"
                                stroke-linecap="round" stroke-dasharray="50.265" stroke-dashoffset="15"
                            />
                        </svg>
                        <img
                            src="/images/csc-logo-256.png"
                            alt=""
                            aria-hidden="true"
                            class="absolute top-1/2 left-1/2 size-12 -translate-x-1/2 -translate-y-1/2 rounded-full bg-white object-contain p-1.5 shadow-sm"
                        />
                    </div>

                    <slot />
                    <AppLoadingDots />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
/*
 * Tinted spread of the brand palette, mirroring the auth screens' feel rather
 * than the flat app background — expressed in tokens so it tracks the theme.
 */
.splash {
    background: linear-gradient(
        135deg,
        var(--color-csc-blue-tint) 0%,
        var(--color-csc-blue-tint) 50%,
        var(--color-danger-soft) 100%
    );
}

.blob-1 {
    background: radial-gradient(circle, var(--color-csc-blue) 0%, transparent 70%);
    animation: brand-float 8s ease-in-out infinite;
}

.blob-2 {
    background: radial-gradient(circle, var(--color-csc-red) 0%, transparent 70%);
    animation: brand-float 10s ease-in-out infinite reverse;
}

.blob-3 {
    background: radial-gradient(circle, var(--color-csc-blue) 0%, transparent 70%);
    animation: brand-float 12s ease-in-out infinite 2s;
}

@keyframes brand-float {
    0%,
    100% {
        transform: translate(0, 0) scale(1);
    }
    33% {
        transform: translate(30px, -30px) scale(1.05);
    }
    66% {
        transform: translate(-20px, 20px) scale(0.95);
    }
}
</style>