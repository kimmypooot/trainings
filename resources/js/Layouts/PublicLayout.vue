<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLogo from '@/Components/AppLogo.vue';
import AppButton from '@/Components/AppButton.vue';

const props = defineProps({
    current: { type: String, default: 'home' },
});

const nav = [
    { key: 'home', label: 'Home', href: '/' },
    { key: 'about', label: 'About', href: '/#about' },
    { key: 'programs', label: 'Programs', href: '/#programs' },
    { key: 'contact', label: 'Contact', href: '/#contact' },
];

// Governance seals belong with the transparency links in the footer, not the hero.
const footerSeals = [
    {
        src: '/images/transparency-seal.png',
        alt: 'Transparency Seal',
        href: 'https://www.csc.gov.ph/transparency-seal',
    },
    {
        src: '/images/CORSeal_CSC2.png',
        alt: 'Citizen’s Charter / Report Card Survey Seal',
        href: 'https://www.csc.gov.ph',
    },
    {
        src: '/images/govph_seal.png',
        alt: 'Official seal of the Republic of the Philippines',
        href: 'https://www.gov.ph',
    },
];

const quickLinks = [
    { label: 'Home', href: '/' },
    { label: 'Programs', href: '/#programs' },
    { label: 'About TIMS', href: '/#about' },
    { label: 'Create an account', href: '/register' },
    { label: 'Sign in', href: '/login' },
];

// Required GOVPH links for Philippine government sites.
const aboutGovphLinks = [
    { label: 'GOV.PH', href: 'https://www.gov.ph' },
    { label: 'Open Data Portal', href: 'https://data.gov.ph' },
    { label: 'Official Gazette', href: 'https://www.officialgazette.gov.ph' },
];

// Shared from HandleInertiaRequests; counted once per session.
const page = usePage();
const visitorCount = computed(() => page.props.visitors ?? 0);

const scrolled = ref(false);
const menuOpen = ref(false);

const onScroll = () => {
    scrolled.value = window.scrollY > 8;
};

onMounted(() => {
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
});

onBeforeUnmount(() => window.removeEventListener('scroll', onScroll));
</script>

<template>
    <div class="flex min-h-screen flex-col bg-white">
        <header
            class="sticky top-0 z-50 border-b bg-white transition-shadow duration-200"
            :class="scrolled ? 'border-csc-line shadow-sm' : 'border-transparent'"
        >
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
                <Link href="/" class="rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-csc-blue">
                    <AppLogo size="md" />
                    <span class="sr-only">CSC TIMS home</span>
                </Link>

                <nav class="hidden items-center gap-8 md:flex" aria-label="Main">
                    <a
                        v-for="item in nav"
                        :key="item.key"
                        :href="item.href"
                        class="relative py-1 text-sm transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-csc-blue"
                        :class="
                            props.current === item.key
                                ? 'font-semibold text-csc-blue'
                                : 'font-medium text-csc-ink hover:text-csc-blue'
                        "
                        :aria-current="props.current === item.key ? 'page' : undefined"
                    >
                        {{ item.label }}
                        <span
                            v-if="props.current === item.key"
                            class="absolute -bottom-0.5 left-0 h-0.5 w-full bg-csc-red"
                            aria-hidden="true"
                        />
                    </a>
                </nav>

                <div class="hidden items-center gap-2 md:flex">
                    <AppButton href="/login" variant="ghost" size="sm">Sign in</AppButton>
                    <AppButton href="/register" size="sm">Register</AppButton>
                </div>

                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-lg p-2 text-csc-blue transition-colors duration-150 hover:bg-csc-blue-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue md:hidden"
                    :aria-expanded="menuOpen"
                    aria-controls="mobile-menu"
                    @click="menuOpen = !menuOpen"
                >
                    <span class="sr-only">{{ menuOpen ? 'Close menu' : 'Open menu' }}</span>
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path v-if="!menuOpen" d="M4 7h16M4 12h16M4 17h16" stroke-linecap="round" />
                        <path v-else d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
                    </svg>
                </button>
            </div>

            <div v-show="menuOpen" id="mobile-menu" class="border-t border-csc-line bg-white md:hidden">
                <nav class="mx-auto max-w-7xl space-y-1 px-4 py-3 sm:px-6" aria-label="Mobile">
                    <a
                        v-for="item in nav"
                        :key="item.key"
                        :href="item.href"
                        class="block rounded-lg px-3 py-2 text-sm transition-colors duration-150"
                        :class="
                            props.current === item.key
                                ? 'bg-csc-blue-tint font-semibold text-csc-blue'
                                : 'font-medium text-csc-ink hover:bg-csc-blue-tint hover:text-csc-blue'
                        "
                        :aria-current="props.current === item.key ? 'page' : undefined"
                        @click="menuOpen = false"
                    >
                        {{ item.label }}
                    </a>
                    <AppButton href="/login" variant="ghost" size="sm" block class="mt-2">Sign in</AppButton>
                    <AppButton href="/register" size="sm" block class="mt-2">Register</AppButton>
                </nav>
            </div>
        </header>

        <main class="flex-1">
            <Transition name="page" appear>
                <div :key="page.component">
                    <slot />
                </div>
            </Transition>
        </main>

        <footer id="contact" class="bg-csc-blue-deep">
            <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-4">
                    <!-- Col 1: Brand + seals -->
                    <div>
                        <div class="mb-3 flex items-center gap-2">
                            <img src="/images/csc-logo.png" alt="" class="h-7 w-7 object-contain" aria-hidden="true" />
                            <span class="text-sm font-semibold text-white">CSC TIMS</span>
                        </div>
                        <p class="text-xs leading-relaxed text-white/60">
                            Training Information Management System.<br />
                            Serving the Philippine civil service with integrity, competence, and excellence.
                        </p>

                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <a
                                v-for="seal in footerSeals"
                                :key="seal.src"
                                :href="seal.href"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white"
                            >
                                <img
                                    :src="seal.src"
                                    :alt="seal.alt"
                                    class="h-16 w-auto opacity-80 transition-opacity duration-150 hover:opacity-100"
                                />
                            </a>
                        </div>

                        <p class="mt-4 text-xs leading-relaxed text-white/40">
                            All content is in the public domain unless otherwise stated.
                        </p>
                    </div>

                    <!-- Col 2: Quick links -->
                    <div>
                        <h2 class="mb-3 text-xs font-semibold tracking-wider text-white uppercase">Quick Links</h2>
                        <ul class="space-y-2">
                            <li v-for="link in quickLinks" :key="link.href">
                                <a
                                    :href="link.href"
                                    class="rounded text-sm text-white/60 transition-colors hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                                >
                                    {{ link.label }}
                                </a>
                            </li>
                        </ul>
                    </div>

                    <!-- Col 3: GOVPH -->
                    <div>
                        <h2 class="mb-3 text-xs font-semibold tracking-wider text-white uppercase">GOVPH</h2>
                        <p class="mb-3 text-xs leading-relaxed text-white/50">
                            Learn more about the Philippine government, its structure, how government works and the
                            people behind it.
                        </p>
                        <ul class="space-y-2">
                            <li v-for="link in aboutGovphLinks" :key="link.href">
                                <a
                                    :href="link.href"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="rounded text-sm text-white/60 transition-colors hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                                >
                                    {{ link.label }}
                                </a>
                            </li>
                        </ul>
                    </div>

                    <!-- Col 4: Contact -->
                    <div>
                        <h2 class="mb-3 text-xs font-semibold tracking-wider text-white uppercase">Contact</h2>
                        <ul class="space-y-2 text-sm text-white/60">
                            <li class="flex items-start gap-2">
                                <svg
                                    class="mt-0.5 size-4 shrink-0 text-white/40"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    aria-hidden="true"
                                >
                                    <rect x="2.5" y="4.5" width="19" height="15" rx="2" />
                                    <path d="m3 6 9 6 9-6" stroke-linecap="round" />
                                </svg>
                                <a
                                    href="mailto:tims@csc.gov.ph"
                                    class="rounded transition-colors hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                                >
                                    tims@csc.gov.ph
                                </a>
                            </li>
                            <li class="flex items-start gap-2">
                                <svg
                                    class="mt-0.5 size-4 shrink-0 text-white/40"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    aria-hidden="true"
                                >
                                    <path
                                        d="M4 5c0-.6.4-1 1-1h3l2 5-2.5 1.5a12 12 0 0 0 5 5L14 13l5 2v3c0 .6-.4 1-1 1h-1A15 15 0 0 1 4 6V5Z"
                                        stroke-linejoin="round"
                                    />
                                </svg>
                                (02) 8931-8092
                            </li>
                            <li class="flex items-start gap-2">
                                <svg
                                    class="mt-0.5 size-4 shrink-0 text-white/40"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    aria-hidden="true"
                                >
                                    <path d="M12 21s7-5.6 7-11a7 7 0 1 0-14 0c0 5.4 7 11 7 11Z" stroke-linejoin="round" />
                                    <circle cx="12" cy="10" r="2.5" />
                                </svg>
                                Civil Service Commission, IBP Road, Constitution Hills, Quezon City
                            </li>
                        </ul>

                        <ul class="mt-6 space-y-2 text-sm">
                            <li>
                                <Link
                                    href="/privacy-policy"
                                    class="rounded text-white/60 transition-colors hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                                >
                                    Privacy Policy
                                </Link>
                            </li>
                            <li>
                                <Link
                                    href="/terms-of-service"
                                    class="rounded text-white/60 transition-colors hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                                >
                                    Terms of Service
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="mt-8 flex flex-col items-center justify-between gap-2 border-t border-white/10 pt-6 sm:flex-row">
                    <p class="text-xs text-white/40">
                        &copy; {{ new Date().getFullYear() }} Civil Service Commission. All rights reserved.
                    </p>

                    <p class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1">
                        <svg
                            class="size-3.5 text-white/40"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                            aria-hidden="true"
                        >
                            <path
                                d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <span class="text-2xs font-medium tracking-wide text-white/50">TOTAL VISITORS</span>
                        <span class="text-xs font-semibold text-white/80">{{ visitorCount.toLocaleString() }}</span>
                    </p>
                </div>
            </div>
        </footer>
    </div>
</template>
