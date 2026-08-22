<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AppLogo from '@/Components/AppLogo.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    status: { type: Number, default: 500 },
});

const page = usePage();

/*
 * One branded page for every status. The copy is kept per-status so a 404 does
 * not claim a 503 is the user's fault; the layout is always the same card, so
 * the eye recognises "an error happened" before reading which one.
 */
const copy = {
    400: { title: 'Bad Request', message: 'The request could not be processed as it was sent.', icon: 'close' },
    401: { title: 'Unauthorized', message: 'Please sign in to view this page.', icon: 'lock' },
    403: { title: 'Access denied', message: 'Your account does not have permission to view this page.', icon: 'lock' },
    404: { title: 'Page not found', message: 'We could not find that page. It may have moved or never existed.', icon: 'map-pin' },
    405: { title: 'Method not allowed', message: 'That action cannot be performed this way.', icon: 'close' },
    419: { title: 'Session expired', message: 'Your session has expired. Reload the page to continue where you left off.', icon: 'clock' },
    429: { title: 'Slow down', message: 'Too many requests in a short time. Wait a moment and try again.', icon: 'clock' },
    500: { title: 'Something went wrong', message: 'Our server hit an unexpected error. Please try again in a moment.', icon: 'warning' },
    503: { title: 'Service unavailable', message: 'We are briefly offline for maintenance. Please check back soon.', icon: 'warning' },
};

const detail = computed(
    () =>
        copy[props.status] ?? {
            title: 'Unexpected error',
            message: 'Something went wrong while loading this page.',
            icon: 'close',
        }
);

// Signed-in users have a natural next step; guests go back to the landing page.
// Staff (any non-participant role) live at /admin, as the nav expects.
const homeUrl = computed(() => {
    const role = page.props.auth?.user?.role;
    if (role === 'participant') return '/dashboard';
    if (role) return '/admin';

    return '/';
});

// 419 means the session token is gone, so even a dashboard link resolves to the
// login redirect; a hard reload is the honest recovery.
const reload = () => window.location.reload();
</script>

<template>
    <Head :title="detail.title" />

    <div class="flex min-h-screen flex-col items-center justify-center bg-csc-blue-tint px-4 py-12">
        <div class="w-full max-w-md rounded-2xl border border-csc-line bg-white p-8 text-center shadow-sm sm:p-10">
            <AppLogo size="lg" class="mx-auto" />

            <p class="mt-8 text-6xl font-bold tracking-tight text-csc-blue" aria-hidden="true">{{ status }}</p>

            <span class="mx-auto mt-5 inline-flex size-12 items-center justify-center rounded-full bg-csc-blue-tint text-csc-blue">
                <AppIcon :name="detail.icon" />
            </span>

            <h1 class="mt-4 text-xl font-semibold tracking-tight text-csc-ink">{{ detail.title }}</h1>
            <p class="mt-2 text-sm leading-relaxed text-csc-ink-muted">{{ detail.message }}</p>

            <div v-if="status === 419" class="mt-7 flex flex-col justify-center gap-3 sm:flex-row">
                <button
                    type="button"
                    class="relative inline-flex items-center justify-center gap-2 rounded-lg bg-csc-blue px-7 py-3.5 text-base font-semibold text-white transition-colors duration-150 hover:bg-csc-red-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-red"
                    @click="reload"
                >
                    Reload page
                </button>
                <AppButton href="/" variant="ghost" size="lg">Go home</AppButton>
            </div>
            <div v-else class="mt-7 flex flex-col justify-center gap-3 sm:flex-row">
                <AppButton :href="homeUrl" size="lg" icon="arrow-left">
                    Back to {{ homeUrl === '/' ? 'home' : 'dashboard' }}
                </AppButton>
                <AppButton href="/" variant="ghost" size="lg">Go home</AppButton>
            </div>
        </div>
    </div>
</template>