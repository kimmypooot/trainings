<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
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

// The three blocks below follow the standard GOVPH footer template used across
// Philippine government sites: Republic of the Philippines, About GOVPH, and
// Government Links.
const aboutGovphLinks = [
    { label: 'GOV.PH', href: 'https://www.gov.ph' },
    { label: 'Open Data Portal', href: 'https://data.gov.ph' },
    { label: 'Official Gazette', href: 'https://www.officialgazette.gov.ph' },
];

const governmentLinks = [
    { label: 'Office of the President', href: 'https://op-proper.gov.ph' },
    { label: 'Office of the Vice President', href: 'https://ovp.gov.ph' },
    { label: 'Senate of the Philippines', href: 'https://www.senate.gov.ph' },
    { label: 'House of Representatives', href: 'https://www.congress.gov.ph' },
    { label: 'Supreme Court', href: 'https://sc.judiciary.gov.ph' },
    { label: 'Court of Appeals', href: 'https://ca.judiciary.gov.ph' },
    { label: 'Sandiganbayan', href: 'https://sb.judiciary.gov.ph' },
];

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

                <div class="hidden md:block">
                    <AppButton href="/login" size="sm">Login</AppButton>
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
                    <AppButton href="/login" size="sm" block class="mt-2">Login</AppButton>
                </nav>
            </div>
        </header>

        <main class="flex-1">
            <slot />
        </main>

        <footer id="contact" class="bg-csc-blue-deep text-white/80">
            <div class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
                <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                    <!-- Agency identity + contact -->
                    <section>
                        <AppLogo variant="light" size="md" />
                        <p class="mt-4 max-w-xs text-sm leading-relaxed">
                            The Training Information Management System of the Civil Service Commission.
                        </p>
                        <address class="mt-4 space-y-1 text-sm not-italic">
                            <p>IBP Road, Constitution Hills</p>
                            <p>Quezon City, Philippines</p>
                            <p>
                                <a
                                    href="mailto:tims@csc.example.gov"
                                    class="rounded transition-colors hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                                >
                                    tims@csc.example.gov
                                </a>
                            </p>
                        </address>
                    </section>

                    <!-- System -->
                    <section>
                        <h2 class="text-sm font-semibold tracking-wide text-white uppercase">System</h2>
                        <ul class="mt-4 space-y-2 text-sm">
                            <li><a href="/login" class="transition-colors hover:text-white">Sign in</a></li>
                            <li><a href="/#programs" class="transition-colors hover:text-white">Programs</a></li>
                            <li><a href="/#about" class="transition-colors hover:text-white">About TIMS</a></li>
                            <li><a href="/#contact" class="transition-colors hover:text-white">Help desk</a></li>
                        </ul>
                    </section>

                    <!-- About GOVPH -->
                    <section>
                        <h2 class="text-sm font-semibold tracking-wide text-white uppercase">About GOVPH</h2>
                        <p class="mt-4 text-sm leading-relaxed">
                            Learn more about the Philippine government, its structure, how government works and the
                            people behind it.
                        </p>
                        <ul class="mt-4 space-y-2 text-sm">
                            <li v-for="link in aboutGovphLinks" :key="link.href">
                                <a
                                    :href="link.href"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="rounded transition-colors hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                                >
                                    {{ link.label }}
                                </a>
                            </li>
                        </ul>
                    </section>

                    <!-- Government links -->
                    <section>
                        <h2 class="text-sm font-semibold tracking-wide text-white uppercase">Government Links</h2>
                        <ul class="mt-4 space-y-2 text-sm">
                            <li v-for="link in governmentLinks" :key="link.href">
                                <a
                                    :href="link.href"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="rounded transition-colors hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                                >
                                    {{ link.label }}
                                </a>
                            </li>
                        </ul>
                    </section>
                </div>

                <!-- Seals + public domain notice -->
                <div
                    class="mt-12 flex flex-col gap-6 border-t border-white/15 pt-8 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex flex-wrap items-center gap-6 sm:gap-8">
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
                                class="h-16 w-auto opacity-80 transition-opacity duration-150 hover:opacity-100 sm:h-20"
                            />
                        </a>
                    </div>

                    <p class="max-w-sm text-sm leading-relaxed sm:text-right">
                        <span class="font-semibold text-white">Republic of the Philippines.</span>
                        All content is in the public domain unless otherwise stated.
                    </p>
                </div>

                <!-- Bottom bar -->
                <div
                    class="mt-8 flex flex-col gap-3 border-t border-white/15 pt-6 text-sm sm:flex-row sm:items-center sm:justify-between"
                >
                    <p>&copy; {{ new Date().getFullYear() }} Civil Service Commission.</p>
                    <p class="flex flex-wrap items-center gap-x-4 gap-y-2">
                        <Link href="/privacy-policy" class="transition-colors hover:text-white">Privacy Policy</Link>
                        <span class="inline-block h-1 w-4 bg-csc-red" aria-hidden="true" />
                        <Link href="/terms-of-service" class="transition-colors hover:text-white">
                            Terms of Service
                        </Link>
                    </p>
                </div>
            </div>
        </footer>
    </div>
</template>
