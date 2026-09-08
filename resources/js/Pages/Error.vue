<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AppLogo from '@/Components/AppLogo.vue';
import AppButton from '@/Components/AppButton.vue';

const props = defineProps({
    status: { type: Number, default: 500 },
});

const page = usePage();

/*
 * One branded page for every status. The copy is kept per-status so a 404 does
 * not claim a 503 is the user's fault; the layout is always the same card, so
 * the eye recognises "an error happened" before reading which one.
 *
 * `tone` says how alarmed the reader should be, and it is not decoration —
 * every status used to render in the same blue as an ordinary link, so a 500
 * server failure and a 404 typo looked equally serious (or equally calm).
 * `info` is a routine "you're in the wrong place" (401 asks you to sign in,
 * 404 is usually a stale link); `warning` is friction that clears on its own
 * (400/405 malformed requests, 419 an expired session, 429 rate limiting, 503
 * a maintenance window); `danger` is an actual failure or a door closed on
 * purpose (403, 500). The title and message carry the same information, so
 * tone is reinforcement, never the only signal.
 */
const copy = {
    400: { title: 'Bad Request', message: 'The request could not be processed as it was sent.', tone: 'warning' },
    401: { title: 'Unauthorized', message: 'Please sign in to view this page.', tone: 'info' },
    403: { title: 'Access denied', message: 'Your account does not have permission to view this page.', tone: 'danger' },
    404: { title: 'Page not found', message: 'We could not find that page. It may have moved or never existed.', tone: 'info' },
    405: { title: 'Method not allowed', message: 'That action cannot be performed this way.', tone: 'warning' },
    419: { title: 'Session expired', message: 'Your session has expired. Reload the page to continue where you left off.', tone: 'warning' },
    429: { title: 'Slow down', message: 'Too many requests in a short time. Wait a moment and try again.', tone: 'warning' },
    500: { title: 'Something went wrong', message: 'Our server hit an unexpected error. Please try again in a moment.', tone: 'danger' },
    503: { title: 'Service unavailable', message: 'We are briefly offline for maintenance. Please check back soon.', tone: 'warning' },
};

const detail = computed(
    () =>
        copy[props.status] ?? {
            title: 'Unexpected error',
            message: 'Something went wrong while loading this page.',
            tone: 'warning',
        }
);

// The three semantic tones this app already draws every badge and alert from —
// see AppBadge and the design rules in CLAUDE.md. Never invented colours, just
// this page's first use of more than one of them at once.
const TONE_CLASSES = {
    info: { text: 'text-info', bar: 'bg-info' },
    warning: { text: 'text-warning', bar: 'bg-warning' },
    danger: { text: 'text-danger', bar: 'bg-danger' },
};
const tone = computed(() => TONE_CLASSES[detail.value.tone]);

// Signed-in users have a natural next step; guests go back to the landing page.
// Staff (any non-participant role) live at /admin, as the nav expects.
const homeUrl = computed(() => {
    const role = page.props.auth?.user?.role;
    if (role === 'participant') return '/dashboard';
    if (role) return '/admin';

    return '/';
});

// A guest's primary action already *is* "/" — showing the ghost "Go home"
// button as well would put two identical actions side by side, which is
// exactly what the Blade twin of this page (errors/error-shell.blade.php)
// already avoids for the same reason.
const showGoHome = computed(() => homeUrl.value !== '/');

// 419 means the session token is gone, so even a dashboard link resolves to the
// login redirect; a hard reload is the honest recovery.
const reload = () => window.location.reload();
</script>

<template>
    <Head :title="detail.title" />

    <div class="flex min-h-screen flex-col items-center justify-center bg-gradient-to-b from-white to-csc-blue-tint px-4 py-12">
        <div class="w-full max-w-md overflow-hidden rounded-2xl border border-csc-line bg-white text-center shadow-md">
            <!-- A quiet severity cue above the fold, never the only one — the
                 title below says the same thing in words. -->
            <div class="h-1.5" :class="tone.bar" aria-hidden="true" />

            <div class="p-8 sm:p-10">
                <AppLogo size="lg" class="mx-auto" />

                <p class="mt-8 text-6xl font-bold tracking-tight" :class="tone.text" aria-hidden="true">
                    {{ status }}
                </p>

                <h1 class="mt-5 text-xl font-semibold tracking-tight text-csc-ink">{{ detail.title }}</h1>
                <p class="mt-2 text-sm leading-relaxed text-csc-ink-muted">{{ detail.message }}</p>

                <div v-if="status === 419" class="mt-7 flex flex-col justify-center gap-3 sm:flex-row">
                    <AppButton size="lg" icon="refresh" @click="reload">Reload page</AppButton>
                    <AppButton href="/" variant="ghost" size="lg" icon="home">Go home</AppButton>
                </div>
                <div v-else class="mt-7 flex flex-col justify-center gap-3 sm:flex-row">
                    <AppButton :href="homeUrl" size="lg" icon="arrow-left">
                        Back to {{ homeUrl === '/' ? 'home' : 'dashboard' }}
                    </AppButton>
                    <AppButton v-if="showGoHome" href="/" variant="ghost" size="lg" icon="home">
                        Go home
                    </AppButton>
                </div>
            </div>
        </div>
    </div>
</template>
