<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import PrivacyNoticeModal from '@/Components/PrivacyNoticeModal.vue';
import AppButton from '@/Components/AppButton.vue';
import AppModal from '@/Components/AppModal.vue';

const props = defineProps({
    stats: { type: Array, default: () => [] },
    upcomingTrainings: { type: Array, default: () => [] },
});

// Absolute URLs for og:* and canonical; only meaningful client-side, which is
// all Inertia renders here.
const origin = window.location.origin;

// Arrangement follows the RO VIII portal: a single centred row of official
// seals above the headline, with the CSC logo in the middle. The transparency
// and COR seals live in the footer instead.
const seals = [
    { src: '/images/bagong_pilipinas.png', alt: 'Bagong Pilipinas' },
    { src: '/images/csc-logo-256.png', alt: 'Civil Service Commission' },
    { src: '/images/lingkod_bayani.png', alt: 'Lingkod Bayani' },
];

// Each feature is a whole-card link. The certificates/QR/profile destinations
// need an account, so they land on sign-in; the register card is the one
// anonymous action the page is really selling.
const features = [
    {
        title: 'Register for Trainings',
        description: 'Browse the programs offered by the CSC and sign up online while slots last.',
        path: 'M4 6h16M4 12h16M4 18h10',
        href: '/register',
    },
    {
        title: 'Your Certificates',
        description: 'View and download the certificate for every training you complete.',
        path: 'M5 12.5l4.5 4.5L19 7.5',
        href: '/login',
    },
    {
        title: 'Event QR Code',
        description: 'Get a personal QR code for fast check-in at large CSC events.',
        path: 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 18h2v2h-2z',
        href: '/login',
    },
    {
        title: 'Your Profile',
        description: 'Keep your details and training history current in one account.',
        path: 'M4.5 20a7.5 7.5 0 0 1 15 0M12 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7',
        href: '/login',
    },
];

// Failsafe copy when the controller passes nothing (e.g. the page is rendered
// outside the Laravel app). The served figures normally come from HomeController.
const fallbackStats = [
    { figure: '12,400+', label: 'Personnel enrolled' },
    { figure: '320', label: 'Programs delivered' },
    { figure: '96%', label: 'Completion rate' },
    { figure: '17', label: 'Regional offices' },
];

const stats = computed(() => (props.stats.length ? props.stats : fallbackStats));

// null capacity means the run has no cap, so there is nothing to count down.
const slotsLabel = (remaining) =>
    remaining === null ? 'Open enrolment' : `${remaining} ${remaining === 1 ? 'slot' : 'slots'} left`;

// The training whose detail modal is open, or null when closed. The card opens
// the modal instead of sending anonymous visitors straight to sign-in; the
// sign-in call-to-action lives in the modal footer.
const selected = ref(null);
const closeModal = () => {
    selected.value = null;
};

const modalSubtitle = computed(() =>
    selected.value ? `${selected.value.mode} · Starts ${selected.value.starts_at}` : ''
);

const slotsDetail = (training) =>
    training.capacity === null ? 'No limit' : `${training.slots_remaining} of ${training.capacity} remaining`;

const money = (value) =>
    Number(value).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
</script>

<template>
    <Head title="Home">
        <meta
            name="description"
            content="Browse and register for training programs offered by the Civil Service Commission, keep your certificates in one place, and check in to events with your own QR code."
        />
        <meta property="og:site_name" content="CSC RO VIII - Training Information Management System" />
        <meta property="og:type" content="website" />
        <meta property="og:title" content="CSC TIMS - Training Information Management System" />
        <meta
            property="og:description"
            content="Browse and register for training programs offered by the Civil Service Commission, keep your certificates in one place, and check in to events with your own QR code."
        />
        <meta property="og:url" :content="origin + '/'" />
        <meta property="og:image" :content="origin + '/images/csc-logo-512.png'" />
        <meta name="twitter:card" content="summary" />
        <link rel="canonical" :href="origin + '/'" />
    </Head>

    <PrivacyNoticeModal />

    <PublicLayout current="home">
        <!-- Hero -->
        <section class="relative flex min-h-svh flex-col overflow-hidden text-white">
            <!--
                The hero photo is this page's LCP element, so it is a real <img>
                with an explicit high fetch priority — a CSS background would
                only be discovered after the stylesheet parses. WebP first, JPEG
                fallback; the preload in app.blade.php already warms the WebP.
            -->
            <picture class="absolute inset-0" aria-hidden="true">
                <source srcset="/images/cscbg_facade.webp" type="image/webp" />
                <img
                    src="/images/cscbg_facade.jpeg"
                    alt=""
                    fetchpriority="high"
                    decoding="async"
                    class="absolute inset-0 size-full object-cover"
                />
            </picture>
            <!-- Brand gradient overlay keeps the facade readable behind white text -->
            <div
                class="absolute inset-0"
                style="
                    background: linear-gradient(
                        160deg,
                        color-mix(in srgb, var(--color-csc-blue-deep) 93%, transparent) 0%,
                        color-mix(in srgb, var(--color-csc-blue) 87%, transparent) 55%,
                        color-mix(in srgb, var(--color-csc-blue-deep) 95%, transparent) 100%
                    );
                "
                aria-hidden="true"
            />

            <!--
                flex-1 pushes the wave to the very bottom so the hero reads as
                one full viewport page. The copy is top-aligned (justify-start)
                with only a small pad so it clears the sticky header comfortably;
                the generous top padding that used to sit here pushed the block
                back toward the middle on short viewports, so it now hugs the
                upper portion instead.
            -->
            <div
                class="relative mx-auto flex w-full max-w-4xl flex-1 flex-col items-center justify-start px-4 pt-14 pb-16 text-center sm:px-6 sm:pt-22 sm:pb-16 lg:px-8"
            >
                <!-- Official seals -->
                <div class="mb-8 flex flex-wrap items-center justify-center gap-4 sm:gap-6">
                    <img
                        v-for="seal in seals"
                        :key="seal.src"
                        :src="seal.src"
                        :alt="seal.alt"
                        decoding="async"
                        class="h-14 w-auto drop-shadow-md sm:h-20"
                    />
                </div>

                <h1 class="text-3xl leading-tight font-bold tracking-tight text-balance sm:text-5xl lg:text-6xl">
                    Training Information
                    <span class="block font-semibold text-white/85">Management System</span>
                </h1>

                <p class="mx-auto mt-6 max-w-xl text-base leading-relaxed text-pretty text-white/85 sm:text-lg">
                    Create your account to register for training programs offered by the Commission, keep your
                    certificates in one place, and check in to events with your own QR code.
                </p>

                <div class="mt-10 flex flex-col justify-center gap-3 sm:flex-row">
                    <AppButton href="/register" size="lg" on-dark>Create your account</AppButton>
                    <AppButton href="/#programs" variant="ghost" size="lg" on-dark>Learn More</AppButton>
                </div>
            </div>

            <!-- Wave divider into the next section -->
            <div class="pointer-events-none relative h-10 overflow-hidden">
                <svg
                    viewBox="0 0 1440 40"
                    preserveAspectRatio="none"
                    class="absolute inset-0 size-full fill-csc-blue-tint"
                    aria-hidden="true"
                >
                    <path
                        d="M0 40L60 36C120 32 240 20 360 18C480 16 600 24 720 28C840 32 960 30 1080 24C1200 20 1320 24 1380 28L1440 32V40H0Z"
                    />
                </svg>
            </div>
        </section>

        <!-- Upcoming programs -->
        <section id="upcoming" v-if="upcomingTrainings.length" class="bg-white py-20 lg:py-24">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <!-- Small red eyebrow keeps this the single red accent of the block -->
                    <p class="inline-flex items-center gap-3 text-xs font-semibold tracking-widest text-csc-red-ink uppercase">
                        <span class="h-px w-8 bg-csc-red-ink" aria-hidden="true"></span>
                        Register online
                        <span class="h-px w-8 bg-csc-red-ink" aria-hidden="true"></span>
                    </p>
                    <h2 class="mt-4 text-3xl font-semibold tracking-tight text-balance text-csc-blue sm:text-4xl">
                        Upcoming programs
                    </h2>
                    <p class="mt-4 text-base leading-relaxed text-pretty text-csc-ink/70">
                        Training programs opening soon — sign in to reserve your slot while registration is open.
                    </p>
                </div>

                <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <button
                        v-for="training in upcomingTrainings"
                        :key="training.id"
                        type="button"
                        class="group relative flex cursor-pointer flex-col overflow-hidden rounded-2xl border border-csc-line bg-white p-7 text-left shadow-sm transition duration-200 hover:-translate-y-1 hover:border-csc-blue/30 hover:shadow-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        @click="selected = training"
                    >
                        <!--
                            A solid gradient top bar replaces the old hover-only
                            underline, so each card carries its identity even
                            before a visitor hovers.
                        -->
                        <span
                            class="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-csc-blue to-csc-blue-deep"
                            aria-hidden="true"
                        />

                        <div class="flex items-center justify-between gap-3">
                            <span class="inline-flex items-center rounded-full bg-csc-blue-tint px-3 py-1 text-xs font-semibold text-csc-blue">
                                {{ training.mode }}
                            </span>
                            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-csc-ink/70">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                                    <circle cx="9" cy="7" r="4" />
                                    <path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
                                </svg>
                                {{ slotsLabel(training.slots_remaining) }}
                            </span>
                        </div>

                        <h3 class="mt-5 text-lg leading-snug font-semibold text-csc-blue">{{ training.title }}</h3>

                        <div class="mt-5 space-y-2.5 text-sm text-csc-ink/70">
                            <div class="flex items-center gap-2">
                                <svg class="size-4 shrink-0 text-csc-blue/60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z" />
                                </svg>
                                <span>
                                    Starts on <span class="font-semibold text-csc-ink">{{ training.starts_at }}</span>
                                </span>
                            </div>
                            <div class="flex items-center gap-2">
                                <svg class="size-4 shrink-0 text-csc-blue/60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M12 21s-7-5.5-7-11a7 7 0 1 1 14 0c0 5.5-7 11-7 11Z" />
                                </svg>
                                <span>{{ training.venue }}</span>
                            </div>
                        </div>

                        <span class="mt-auto inline-flex items-center gap-1.5 pt-6 text-sm font-semibold text-csc-blue">
                            View details
                            <svg
                                class="size-4 transition-transform duration-200 group-hover:translate-x-1"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <path d="M5 12h14M13 5l7 7-7 7" />
                            </svg>
                        </span>
                    </button>
                </div>
            </div>
        </section>

        <!-- Training detail modal: the full catalogue view for anonymous visitors. -->
        <AppModal
            :open="selected !== null"
            :title="selected?.title"
            :subtitle="modalSubtitle"
            size="lg"
            @close="closeModal"
        >
            <template v-if="selected">
                <dl class="grid gap-x-6 gap-y-5 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-csc-ink/60">Date</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">
                            {{ selected.starts_at }}
                            <template v-if="selected.ends_at">
                                <span class="text-csc-ink/55">– {{ selected.ends_at }}</span>
                            </template>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink/60">Venue</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ selected.venue }}</dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink/60">Mode</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ selected.mode }}</dd>
                    </div>
                    <div v-if="selected.payment_required">
                        <dt class="text-csc-ink/60">Fee</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">₱{{ money(selected.payment_amount) }}</dd>
                    </div>
                    <div v-if="selected.category">
                        <dt class="text-csc-ink/60">Curriculum</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ selected.category }}</dd>
                    </div>
                    <div>
                        <dt class="text-csc-ink/60">Available slots</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ slotsDetail(selected) }}</dd>
                    </div>
                    <div v-if="selected.duration_days">
                        <dt class="text-csc-ink/60">Duration</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">
                            {{ selected.duration_days }} day{{ selected.duration_days === 1 ? '' : 's' }}
                        </dd>
                    </div>
                    <div v-if="selected.level_label">
                        <dt class="text-csc-ink/60">Level</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ selected.level_label }}</dd>
                    </div>
                    <div v-if="selected.registration_closes_at">
                        <dt class="text-csc-ink/60">Registration closes</dt>
                        <dd class="mt-0.5 font-medium text-csc-ink">{{ selected.registration_closes_at }}</dd>
                    </div>
                    <div v-if="selected.venue_details" class="sm:col-span-2">
                        <dt class="text-csc-ink/60">Venue details</dt>
                        <dd class="mt-0.5 leading-relaxed whitespace-pre-line text-csc-ink/75">
                            {{ selected.venue_details }}
                        </dd>
                    </div>
                </dl>

                <div v-if="selected.description" class="mt-6 border-t border-csc-line pt-5">
                    <h3 class="text-sm font-semibold text-csc-blue">Description</h3>
                    <p class="mt-2 text-sm leading-relaxed whitespace-pre-line text-csc-ink/75">
                        {{ selected.description }}
                    </p>
                </div>

                <div v-if="selected.target_participants" class="mt-6 border-t border-csc-line pt-5">
                    <h3 class="text-sm font-semibold text-csc-blue">Target participants</h3>
                    <p class="mt-2 text-sm leading-relaxed whitespace-pre-line text-csc-ink/75">
                        {{ selected.target_participants }}
                    </p>
                </div>

                <div v-if="selected.prerequisites" class="mt-6 border-t border-csc-line pt-5">
                    <h3 class="text-sm font-semibold text-csc-blue">Prerequisites</h3>
                    <p class="mt-2 text-sm leading-relaxed whitespace-pre-line text-csc-ink/75">
                        {{ selected.prerequisites }}
                    </p>
                </div>

                <p
                    v-if="selected.is_supervisory"
                    class="mt-6 flex items-start gap-2 rounded-lg bg-csc-blue-tint p-4 text-sm text-csc-ink/70"
                >
                    <svg class="mt-0.5 size-4 shrink-0 text-csc-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 16v-4M12 8h.01M12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18Z" />
                    </svg>
                    This is a Supervisory Development Course. You will be asked to submit an output before
                    your completion is credited.
                </p>
            </template>

            <template #footer>
                <AppButton href="/login" size="lg" block>Sign in to register</AppButton>
            </template>
        </AppModal>

        <!-- Features -->
        <section id="programs" class="bg-csc-blue-tint py-20 lg:py-24">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-semibold tracking-tight text-balance text-csc-blue sm:text-4xl">
                        Everything you need in one account
                    </h2>
                    <p class="mt-4 text-base leading-relaxed text-pretty text-csc-ink/70">
                        From signing up for a program to downloading the certificate that proves you finished it.
                    </p>
                </div>

                <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <Link
                        v-for="feature in features"
                        :key="feature.title"
                        :href="feature.href"
                        class="group relative flex flex-col overflow-hidden rounded-xl border border-csc-line bg-white p-7 transition-shadow duration-200 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    >
                        <span
                            class="absolute inset-x-0 top-0 h-1 origin-left scale-x-0 bg-csc-red transition-transform duration-200 group-hover:scale-x-100"
                            aria-hidden="true"
                        />

                        <span class="inline-flex size-12 items-center justify-center rounded-full bg-csc-blue">
                            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path :d="feature.path" />
                            </svg>
                        </span>

                        <h3 class="mt-5 text-lg font-semibold text-csc-blue">{{ feature.title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-csc-ink/75">{{ feature.description }}</p>

                        <span
                            class="mt-auto inline-flex items-center gap-1 pt-5 text-sm font-semibold text-csc-blue opacity-0 transition-opacity duration-200 group-hover:opacity-100 group-focus-visible:opacity-100"
                            aria-hidden="true"
                        >
                            Learn more
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M5 12h14M13 5l7 7-7 7" />
                            </svg>
                        </span>
                    </Link>
                </div>
            </div>
        </section>

        <!-- Stats -->
        <section id="about" class="bg-csc-blue py-14">
            <div class="mx-auto grid max-w-7xl grid-cols-2 gap-8 px-4 sm:px-6 lg:grid-cols-4 lg:px-8">
                <div v-for="stat in stats" :key="stat.label" class="text-center">
                    <p class="text-3xl font-semibold tracking-tight text-white sm:text-4xl">{{ stat.figure }}</p>
                    <p class="mt-2 text-sm text-white/70">{{ stat.label }}</p>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="bg-csc-blue-tint py-20">
            <div class="mx-auto max-w-3xl px-4 text-center sm:px-6 lg:px-8">
                <h2 class="text-3xl font-semibold tracking-tight text-balance text-csc-blue sm:text-4xl">
                    Ready to sign up for your next training?
                </h2>
                <p class="mx-auto mt-4 max-w-xl text-base leading-relaxed text-pretty text-csc-ink/70">
                    Register an account to browse programs offered by the CSC, reserve your slot, and keep every
                    certificate you earn.
                </p>
                <div class="mt-8">
                    <AppButton href="/register" variant="accent" size="lg">Create your account</AppButton>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
