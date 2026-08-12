<script setup>
import { Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import PrivacyNoticeModal from '@/Components/PrivacyNoticeModal.vue';
import AppButton from '@/Components/AppButton.vue';

// Arrangement follows the RO VIII portal: a single centred row of official
// seals sitting above the eyebrow badge and headline, with the CSC logo in
// the middle. The transparency and COR seals live in the footer instead.
const seals = [
    { src: '/images/bagong_pilipinas.png', alt: 'Bagong Pilipinas' },
    { src: '/images/csc-logo.png', alt: 'Civil Service Commission' },
    { src: '/images/lingkod_bayani.png', alt: 'Lingkod Bayani' },
];

const features = [
    {
        title: 'Training Catalogue',
        description: 'Browse accredited programs, schedules, and slot availability in one place.',
        path: 'M4 6h16M4 12h16M4 18h10',
    },
    {
        title: 'Enrolment & Tracking',
        description: 'Submit nominations and follow each application through to completion.',
        path: 'M5 12.5l4.5 4.5L19 7.5',
    },
    {
        title: 'Records & Reporting',
        description: 'Generate certificates and agency-level training reports on demand.',
        path: 'M6 20V10m6 10V4m6 16v-6',
    },
];

const stats = [
    { figure: '12,400+', label: 'Personnel enrolled' },
    { figure: '320', label: 'Programs delivered' },
    { figure: '96%', label: 'Completion rate' },
    { figure: '17', label: 'Regional offices' },
];
</script>

<template>
    <Head title="Home" />

    <PrivacyNoticeModal />

    <PublicLayout current="home">
        <!-- Hero -->
        <section class="relative overflow-hidden text-white">
            <div
                class="absolute inset-0 bg-cover bg-center"
                style="background-image: url('/images/cscbg_facade.jpeg')"
                aria-hidden="true"
            />
            <!-- Brand gradient overlay keeps the facade readable behind white text -->
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

            <div class="relative mx-auto max-w-4xl px-4 py-20 text-center sm:px-6 sm:py-28 lg:px-8">
                <!-- Official seals -->
                <div class="mb-8 flex flex-wrap items-center justify-center gap-4 sm:gap-6">
                    <img
                        v-for="seal in seals"
                        :key="seal.src"
                        :src="seal.src"
                        :alt="seal.alt"
                        class="h-14 w-auto drop-shadow-md sm:h-20"
                    />
                </div>

                <p
                    class="mb-6 inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3.5 py-1.5 text-xs font-semibold tracking-widest text-white/85 uppercase backdrop-blur-sm"
                >
                    <span class="inline-block size-1.5 rounded-full bg-csc-red" aria-hidden="true" />
                    Civil Service Commission
                </p>

                <h1 class="text-3xl leading-tight font-bold tracking-tight text-balance sm:text-5xl lg:text-6xl">
                    Training Information
                    <span class="block font-semibold text-white/70">Management System</span>
                </h1>

                <p class="mx-auto mt-6 max-w-xl text-base leading-relaxed text-pretty text-white/75 sm:text-lg">
                    A single record of every training program, nomination, and certificate across the
                    Commission — accessible to agencies, coordinators, and personnel alike.
                </p>

                <div class="mt-10 flex flex-col justify-center gap-3 sm:flex-row">
                    <AppButton href="/login" size="lg" on-dark>Get Started</AppButton>
                    <AppButton href="/#programs" variant="ghost" size="lg" on-dark>Learn More</AppButton>
                </div>
            </div>

            <!-- Wave divider into the next section -->
            <div class="pointer-events-none relative h-10 overflow-hidden">
                <svg
                    viewBox="0 0 1440 40"
                    preserveAspectRatio="none"
                    class="absolute inset-0 size-full"
                    fill="#eef0f9"
                    aria-hidden="true"
                >
                    <path
                        d="M0 40L60 36C120 32 240 20 360 18C480 16 600 24 720 28C840 32 960 30 1080 24C1200 20 1320 24 1380 28L1440 32V40H0Z"
                    />
                </svg>
            </div>
        </section>

        <!-- Features -->
        <section id="programs" class="bg-csc-blue-tint py-20 lg:py-24">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-semibold tracking-tight text-balance text-csc-blue sm:text-4xl">
                        Everything the training cycle needs
                    </h2>
                    <p class="mt-4 text-base leading-relaxed text-pretty text-csc-ink/70">
                        From the first nomination to the final certificate, TIMS keeps one authoritative record.
                    </p>
                </div>

                <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <article
                        v-for="feature in features"
                        :key="feature.title"
                        class="group relative overflow-hidden rounded-xl border border-csc-line bg-white p-7 transition-shadow duration-200 hover:shadow-md"
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
                    </article>
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
                    Ready to manage your agency's training records?
                </h2>
                <p class="mx-auto mt-4 max-w-xl text-base leading-relaxed text-pretty text-csc-ink/70">
                    Sign in with your CSC-issued account to view programs, nominate personnel, and track completions.
                </p>
                <div class="mt-8">
                    <AppButton href="/login" variant="accent" size="lg">Sign in to TIMS</AppButton>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
