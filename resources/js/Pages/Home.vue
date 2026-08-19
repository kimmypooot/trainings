<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import PrivacyNoticeModal from '@/Components/PrivacyNoticeModal.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppInput from '@/Components/AppInput.vue';
import AppSelect from '@/Components/AppSelect.vue';
import ProgramCard from '@/Components/ProgramCard.vue';
import ProgramDetailModal from '@/Components/ProgramDetailModal.vue';

const props = defineProps({
    stats: { type: Array, default: () => [] },
    programs: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    filterOptions: { type: Object, default: () => ({ modes: [], categories: [], statuses: [] }) },
    meta: { type: Object, default: () => ({ current_page: 1, last_page: 1, total: 0, showing: 0 }) },
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

// The program whose detail modal is open, or null when closed. The card opens
// the modal instead of sending anonymous visitors straight to sign-in; the
// sign-in call-to-action lives in the modal footer.
const selected = ref(null);

/*
 * Catalogue filtering, which used to be a separate /programs page.
 *
 * It reloads '/' rather than a catalogue route, so `only` is doing real work
 * here: without it every keystroke would also re-ship the stats block and the
 * whole hero payload to repaint five cards.
 */
const search = ref(props.filters.search ?? '');
const mode = ref(props.filters.mode ?? '');
const category = ref(props.filters.category ?? '');
const status = ref(props.filters.status ?? '');

// A filter change always starts from the first page; staying on, say, page 3 of
// a narrowed search reads as "nothing found". preserveState keeps the fields
// from flashing while the new result set loads, and preserveScroll matters more
// here than it did on /programs — the results sit a full hero below the top of
// the page, so a scroll reset would throw the visitor back to the photo.
const applyFilters = () => {
    router.get(
        '/',
        {
            search: search.value.trim() || undefined,
            mode: mode.value || undefined,
            category: category.value || undefined,
            status: status.value || undefined,
            page: 1,
        },
        {
            preserveState: true,
            replace: true,
            preserveScroll: true,
            only: ['programs', 'filters', 'meta'],
        }
    );
};

// Only the text box is debounced — a keystroke pauses while the dropdowns act
// on click, where a 300ms lag reads as a missed tap.
let searchDebounce;
watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(applyFilters, 300);
});

watch([mode, category, status], applyFilters);

const hasActiveFilters = computed(
    () => search.value.trim() !== '' || Boolean(mode.value || category.value || status.value)
);

const clearFilters = () => {
    search.value = '';
    mode.value = '';
    category.value = '';
    status.value = '';
};

/*
 * Page links, built here rather than from a server-side paginator payload.
 *
 * The status filter is applied after pagination (it is derived, not a column —
 * see HomeController), so the server's own "showing x of y" would count rows
 * this page never rendered. Pages stay honest; the result line below reports
 * what is actually on screen.
 */
const pageLink = (target) => ({
    search: search.value.trim() || undefined,
    mode: mode.value || undefined,
    category: category.value || undefined,
    status: status.value || undefined,
    page: target,
});

const pages = computed(() => Array.from({ length: props.meta.last_page }, (_, i) => i + 1));
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
                    <!--
                        Points at #upcoming, not #programs. "Learn More" next to
                        "Create your account" is asking what is on offer, and
                        #programs is the feature grid — the anchor used to skip
                        the actual programs entirely.
                    -->
                    <AppButton href="/#upcoming" variant="ghost" size="lg" on-dark>See our programs</AppButton>
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

        <!--
            Programs on offer.

            The section renders unconditionally, empty state and all. It used to
            be v-if'd away on an empty list, which quietly turned the header's
            "Programs" link (/#upcoming) into a dead anchor on exactly the days
            a visitor most needed to be told there was nothing scheduled.
        -->
        <section id="upcoming" class="bg-white py-20 lg:py-24">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <!-- Small red eyebrow keeps this the single red accent of the block -->
                    <p class="inline-flex items-center gap-3 text-xs font-semibold tracking-widest text-csc-red-ink uppercase">
                        <span class="h-px w-8 bg-csc-red-ink" aria-hidden="true"></span>
                        Training calendar
                        <span class="h-px w-8 bg-csc-red-ink" aria-hidden="true"></span>
                    </p>
                    <h2 class="mt-4 text-3xl font-semibold tracking-tight text-balance text-csc-blue sm:text-4xl">
                        Programs we are offering
                    </h2>
                    <p class="mt-4 text-base leading-relaxed text-pretty text-csc-ink/70">
                        Every program currently on the Regional Office calendar — including those whose
                        registration has not opened yet, and those already full. Each card shows where it
                        stands.
                    </p>
                </div>

                <!-- Filters -->
                <div class="mt-12 rounded-2xl border border-csc-line bg-csc-blue-tint p-5 sm:p-6">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <AppInput
                            v-model="search"
                            label="Search"
                            type="search"
                            placeholder="Title, code, or venue"
                        />
                        <AppSelect
                            v-model="status"
                            label="Registration"
                            :options="filterOptions.statuses"
                            placeholder="Any status"
                        />
                        <AppSelect
                            v-model="category"
                            label="Curriculum"
                            :options="filterOptions.categories"
                            placeholder="Any curriculum"
                        />
                        <AppSelect
                            v-model="mode"
                            label="Mode"
                            :options="filterOptions.modes"
                            placeholder="Any mode"
                        />
                    </div>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                        <!--
                            Counts what is on screen, not what the query matched:
                            the status filter runs after pagination, so the two
                            can differ and only the former is checkable by eye.
                        -->
                        <p class="text-sm text-csc-ink/70" role="status" aria-live="polite">
                            Showing <span class="font-semibold text-csc-ink">{{ meta.showing }}</span>
                            {{ meta.showing === 1 ? 'program' : 'programs' }}
                            <template v-if="meta.last_page > 1">
                                · page {{ meta.current_page }} of {{ meta.last_page }}
                            </template>
                        </p>
                        <button
                            v-if="hasActiveFilters"
                            type="button"
                            class="text-sm font-semibold text-csc-blue underline underline-offset-4 hover:text-csc-blue-deep focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            @click="clearFilters"
                        >
                            Clear filters
                        </button>
                    </div>
                </div>

                <!-- Results -->
                <div v-if="programs.length" class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <ProgramCard
                        v-for="program in programs"
                        :key="program.id"
                        :program="program"
                        @open="selected = $event"
                    />
                </div>

                <!--
                    Two different empty states, because they need two different
                    answers: a filter that matched nothing wants the filters
                    cleared, an empty calendar wants an account so we can write
                    when the next batch opens. Nothing scheduled is a real,
                    ordinary state for a regional training calendar (between
                    quarters, after a batch closes), and saying so plainly is
                    more use to a visitor than an absent section.
                -->
                <div
                    v-else
                    class="mx-auto mt-10 max-w-xl rounded-2xl border border-csc-line bg-csc-blue-tint px-8 py-12 text-center"
                >
                    <span class="mx-auto inline-flex size-12 items-center justify-center rounded-full bg-white">
                        <svg class="size-6 text-csc-blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z" />
                        </svg>
                    </span>

                    <template v-if="hasActiveFilters">
                        <h3 class="mt-5 text-lg font-semibold text-csc-blue">No programs match those filters</h3>
                        <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-csc-ink/70">
                            Try widening your search, or clear the filters to see the whole calendar.
                        </p>
                        <div class="mt-6">
                            <AppButton @click="clearFilters">Clear filters</AppButton>
                        </div>
                    </template>
                    <!--
                        This used to promise "we will email you as soon as
                        registration opens". Nothing in the system sends that
                        mail, and nothing should: every notification we have is
                        transactional — it answers something a participant
                        already did. A catalogue announcement is the opposite,
                        one message fanned out to the entire user table, and at
                        a regional office's eventual roll size that is thousands
                        of sends against a daily quota measured in far fewer.
                        The first published program of the quarter would burn
                        the allowance that the registration receipts, payment
                        confirmations and certificate releases depend on.

                        So the copy now offers only what an account actually
                        does for someone standing in front of an empty
                        calendar: a completed profile is a prerequisite for
                        registering at all (EnsureProfileIsComplete gates the
                        whole participant area), so getting it out of the way
                        now is a real head start on a slot rather than a
                        subscription we would have to honour.
                    -->
                    <template v-else>
                        <h3 class="mt-5 text-lg font-semibold text-csc-blue">No programs scheduled right now</h3>
                        <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-csc-ink/70">
                            The next batch has not been published yet — new programs appear on this page as
                            soon as the Regional Office schedules them. Creating an account now gets your
                            profile ready, so you can reserve a slot the moment registration opens.
                        </p>
                        <div class="mt-6">
                            <AppButton href="/register">Create your account</AppButton>
                        </div>
                    </template>
                </div>

                <!-- Pagination -->
                <nav
                    v-if="meta.last_page > 1"
                    class="mt-12 flex flex-wrap items-center justify-center gap-2"
                    aria-label="Pagination"
                >
                    <Link
                        v-if="meta.current_page > 1"
                        href="/"
                        :data="pageLink(meta.current_page - 1)"
                        preserve-scroll
                        class="rounded-lg border border-csc-line px-4 py-2 text-sm font-semibold text-csc-blue hover:bg-csc-blue-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    >
                        Previous
                    </Link>
                    <Link
                        v-for="n in pages"
                        :key="n"
                        href="/"
                        :data="pageLink(n)"
                        preserve-scroll
                        class="rounded-lg border px-4 py-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        :class="
                            n === meta.current_page
                                ? 'border-csc-blue bg-csc-blue text-white'
                                : 'border-csc-line text-csc-blue hover:bg-csc-blue-tint'
                        "
                        :aria-current="n === meta.current_page ? 'page' : undefined"
                    >
                        {{ n }}
                    </Link>
                    <Link
                        v-if="meta.current_page < meta.last_page"
                        href="/"
                        :data="pageLink(meta.current_page + 1)"
                        preserve-scroll
                        class="rounded-lg border border-csc-line px-4 py-2 text-sm font-semibold text-csc-blue hover:bg-csc-blue-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    >
                        Next
                    </Link>
                </nav>
            </div>
        </section>

        <!-- The full catalogue view for anonymous visitors. -->
        <ProgramDetailModal :program="selected" @close="selected = null" />

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
                            <AppIcon name="arrow-forward" size="sm" />
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
