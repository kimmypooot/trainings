<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import PrivacyNoticeModal from '@/Components/PrivacyNoticeModal.vue';
import AppBrandBackdrop from '@/Components/AppBrandBackdrop.vue';
import AppButton from '@/Components/AppButton.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppInput from '@/Components/AppInput.vue';
import AppSelect from '@/Components/AppSelect.vue';
import HeroPhotoStack from '@/Components/HeroPhotoStack.vue';
import ProgramCard from '@/Components/ProgramCard.vue';
import ProgramDetailModal from '@/Components/ProgramDetailModal.vue';

const props = defineProps({
    stats: { type: Array, default: () => [] },
    openProgramCount: { type: Number, default: 0 },
    programs: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    filterOptions: { type: Object, default: () => ({ modes: [], categories: [], statuses: [] }) },
    meta: { type: Object, default: () => ({ current_page: 1, last_page: 1, total: 0, showing: 0 }) },
});

// Every server-shared prop this page reads comes through here.
const page = usePage();

/*
 * Absolute base for the og:* and canonical tags.
 *
 * Shared from the server (HandleInertiaRequests) rather than read off
 * window.location. The old form ran at module scope, so it was one `npm i
 * @inertiajs/server` away from throwing in Node before the page rendered — and
 * a canonical built from the browser's address bar can disagree with the one
 * the sitemap and every mailed link use, which is the whole thing a canonical
 * exists to prevent.
 */
const origin = computed(() => page.props.appUrl ?? '');

// Arrangement follows the RO VIII portal: a single centred row of official
// seals above the headline, with the CSC logo in the middle. The transparency
// and COR seals live in the footer instead.
const seals = [
    { src: '/images/bagong_pilipinas.png', alt: 'Bagong Pilipinas' },
    { src: '/images/csc-logo-256.png', alt: 'Civil Service Commission' },
    { src: '/images/lingkod_bayani.png', alt: 'Lingkod Bayani' },
];

/*
 * Each feature is a whole-card link.
 *
 * Three of the four need an account and so land on sign-in, and the card now
 * says which those are. It used to be silent about it: a visitor tapping "Your
 * Certificates" from a page whose whole pitch is "everything in one account"
 * got a login form, with no way to have known that was coming and nothing to
 * distinguish it from a dead end.
 */
const features = [
    {
        title: 'Register for Trainings',
        description: 'Browse the programs offered by the CSC and sign up online while slots last.',
        path: 'M4 6h16M4 12h16M4 18h10',
        href: '/register',
        requiresAccount: false,
    },
    {
        title: 'Your Certificates',
        description: 'View and download the certificate for every training you complete.',
        path: 'M5 12.5l4.5 4.5L19 7.5',
        href: '/login',
        requiresAccount: true,
    },
    {
        title: 'Event QR Code',
        description: 'Get a personal QR code for fast check-in at large CSC events.',
        path: 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 18h2v2h-2z',
        href: '/login',
        requiresAccount: true,
    },
    {
        title: 'Your Profile',
        description: 'Keep your details and training history current in one account.',
        path: 'M4.5 20a7.5 7.5 0 0 1 15 0M12 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7',
        href: '/login',
        requiresAccount: true,
    },
];

/*
 * The headline figures band renders only when the controller has something
 * true to say.
 *
 * There used to be a fallbackStats array here — "12,400+", "96%" and so on —
 * standing by for the case where the controller passed nothing. It was
 * unreachable (stats() always returned four rows) but it was also the wrong
 * shape of answer: invented figures sitting in the source of a government
 * portal, one refactor away from being rendered as fact. HomeController now
 * withholds any figure it cannot stand behind and may return nothing at all,
 * and an empty band is simply not drawn.
 */
const hasStats = computed(() => props.stats.length > 0);

/*
 * The hero's photographs.
 *
 * Content, so it lives here beside the seals rather than inside the component
 * that draws it — swapping a photograph should not mean opening a component
 * that owns angles and shadows.
 *
 * The alt text is written for someone who cannot see the stack, and so
 * describes what the photograph shows rather than restating "photo of
 * training". These are decorative in the strict sense — nothing here is
 * information the page states nowhere else — but a real description costs one
 * line and an empty alt on three large images tells a screen reader the hero is
 * emptier than it is.
 *
 * The class on each is its place in the stack: position, size, tilt, and
 * z-order. Later entries sit on top of earlier ones.
 */
const heroPhotos = [
    {
        src: '/images/training-01.jpg',
        alt: 'Participants at a Civil Service Commission training session',
        className: 'top-0 left-0 z-10 w-[60%] aspect-4/3 -rotate-6',
    },
    {
        src: '/images/training-02.jpg',
        alt: 'A resource speaker addressing a room of government personnel',
        className: 'top-[16%] right-0 z-20 w-[56%] aspect-3/4 rotate-5',
    },
    {
        src: '/images/training-03.jpg',
        alt: 'Certificates being awarded at the close of a training program',
        className: 'bottom-0 left-[10%] z-30 w-[62%] aspect-4/3 -rotate-2',
    },
];

/*
 * Whether the hero has a right-hand column at all.
 *
 * The photographs are files an office drops in and swaps around, so "the markup
 * lists three" and "three exist" are different claims. HeroPhotoStack reports
 * back when every one of them has failed to load, and the hero then falls back
 * to the centred single column it was before — the same rule hasStats applies
 * to the figures band: an absent thing is not drawn, and nothing is arranged
 * around the space where it would have been. A left-aligned headline with an
 * empty half beside it reads as a page that failed to load.
 */
const photosLoaded = ref(true);
const showPhotos = computed(() => heroPhotos.length > 0 && photosLoaded.value);

// The figures band sizes its grid to what actually survived the controller,
// so a withheld figure leaves no gap.
const statsColumns = computed(
    () =>
        ({
            1: 'grid-cols-1',
            2: 'grid-cols-2',
            3: 'grid-cols-2 lg:grid-cols-3',
            4: 'grid-cols-2 lg:grid-cols-4',
        })[props.stats.length] ?? 'grid-cols-2 lg:grid-cols-4'
);

// Office identity, shared from HandleInertiaRequests; the About copy names the
// operating office rather than asserting a generic "CSC" that could be any of
// seventeen.
const office = computed(() => page.props.office ?? {});

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

/*
 * A window of page numbers, not all of them.
 *
 * This used to render every page in the set. At twelve cards a page a busy
 * quarter puts thirty numbered buttons on the landing page, which wraps into a
 * block of digits taller than the pagination row it belongs to and gives a
 * keyboard user thirty tab stops to cross on the way out of the section.
 *
 * The window always keeps the first and last page reachable — those are the
 * two a visitor actually aims for — plus the immediate neighbours of where
 * they are. A null in the returned list is a gap, rendered as an ellipsis
 * rather than as a button, so nothing focusable stands for "some pages".
 */
const pages = computed(() => {
    const last = props.meta.last_page;
    const current = props.meta.current_page;

    // Below the window size there is nothing to elide; showing 1…7 in full is
    // both shorter and easier to scan than any abbreviation of it.
    if (last <= 7) {
        return Array.from({ length: last }, (_, i) => i + 1);
    }

    // Not named `window`: that shadows the global inside this function, which
    // is fine today and a trap the first time someone reaches for window.* here.
    const keep = new Set([1, last, current, current - 1, current + 1]);

    // Keep the row a stable width as the visitor moves through the set:
    // at either end the window would otherwise collapse to four entries.
    if (current <= 3) [2, 3, 4].forEach((n) => keep.add(n));
    if (current >= last - 2) [last - 3, last - 2, last - 1].forEach((n) => keep.add(n));

    const shown = [...keep].filter((n) => n >= 1 && n <= last).sort((a, b) => a - b);

    return shown.flatMap((n, i) => (i > 0 && n - shown[i - 1] > 1 ? [null, n] : [n]));
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
        <!--
            The facade photograph, not the logo. og:image was the 512px square
            seal, which every platform renders as a small thumbnail beside the
            text — the least prominent card a link can get, on the channel where
            a regional office actually reaches people. The building is a real
            landscape image and reads as a place, which is what a preview is
            for. Dimensions are declared so a crawler can lay the card out
            before it has finished downloading it.

            It is 4:3, so it will be cropped to fit the 1.91:1 card. A
            purpose-made 1200x630 image is still the better answer here.
        -->
        <meta property="og:image" :content="origin + '/images/cscbg_facade.jpeg'" />
        <meta property="og:image:width" content="1920" />
        <meta property="og:image:height" content="1440" />
        <meta
            property="og:image:alt"
            content="The Civil Service Commission Regional Office VIII building"
        />
        <meta name="twitter:card" content="summary_large_image" />
        <link rel="canonical" :href="origin + '/'" />
    </Head>

    <PrivacyNoticeModal />

    <PublicLayout current="home">
        <!-- Hero -->
        <section class="relative flex min-h-svh flex-col overflow-hidden text-white">
            <!--
                The facade and its gradient wash, shared with the verification
                screens. `priority` marks it as this page's LCP element — it is
                the largest paint on the site, and the preload in
                app.blade.php is aimed at it.
            -->
            <AppBrandBackdrop priority />

            <!--
                flex-1 pushes the wave to the very bottom so the hero reads as
                one full viewport page. The copy is top-aligned (justify-start)
                with only a small pad so it clears the sticky header comfortably;
                the generous top padding that used to sit here pushed the block
                back toward the middle on short viewports, so it now hugs the
                upper portion instead.

                It is max-w-7xl in the two-column case, matching every section
                below it. The hero was the one band on the page with its own
                width, so the headline started a hundred pixels inboard of
                "Programs we are offering" — a misalignment invisible on either
                screen alone but read as drift while scrolling between them. The
                single-column fallback stays narrower on purpose: centred copy
                needs a short measure, not a full-width one.
            -->
            <div
                class="relative mx-auto flex w-full flex-1 flex-col justify-start px-4 pt-14 pb-16 sm:px-6 sm:pt-22 sm:pb-16 lg:px-8"
                :class="showPhotos ? 'max-w-7xl' : 'max-w-4xl'"
            >
                <!--
                    One column or two, decided by whether the photographs are
                    actually there. The copy only goes left-aligned in the
                    two-column case: left-aligned text under centred seals with
                    nothing to its right is just misaligned.
                -->
                <div
                    class="grid items-center gap-10"
                    :class="showPhotos ? 'lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)] lg:gap-14' : ''"
                >
                    <div :class="showPhotos ? 'text-center lg:text-left' : 'text-center'">
                        <!-- Official seals -->
                        <div
                            class="mb-8 flex flex-wrap items-center justify-center gap-4 sm:gap-6"
                            :class="showPhotos ? 'lg:justify-start' : ''"
                        >
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

                        <p
                            class="mx-auto mt-6 max-w-xl text-base leading-relaxed text-pretty text-white/85 sm:text-lg"
                            :class="showPhotos ? 'lg:mx-0' : ''"
                        >
                            Create your account to register for training programs offered by the Commission, keep
                            your certificates in one place, and check in to events with your own QR code.
                        </p>

                        <div
                            class="mt-10 flex flex-col justify-center gap-3 sm:flex-row"
                            :class="showPhotos ? 'lg:justify-start' : ''"
                        >
                            <AppButton href="/register" size="lg" on-dark>Create your account</AppButton>
                            <!--
                                Points at #upcoming, not #programs. "Learn More" next to
                                "Create your account" is asking what is on offer, and
                                #programs is the feature grid — the anchor used to skip
                                the actual programs entirely.
                            -->
                            <AppButton href="/#upcoming" variant="ghost" size="lg" on-dark>
                                See our programs
                            </AppButton>
                        </div>

                        <!--
                            The hero's one live fact, and the reason the
                            photographs are allowed to take the whole right-hand
                            column: without this line a visitor cannot tell from
                            the first screen whether there is anything to
                            register for, and has to scroll a full viewport to
                            find out. It is withheld rather than shown as a zero
                            — "0 programs open" under a button saying "create
                            your account" is an argument against doing so.
                        -->
                        <p
                            v-if="openProgramCount > 0"
                            class="mt-6 flex items-center justify-center gap-2.5 text-sm text-white/85"
                            :class="showPhotos ? 'lg:justify-start' : ''"
                        >
                            <span class="relative flex size-2.5" aria-hidden="true">
                                <span class="absolute inline-flex size-full animate-ping rounded-full bg-white/60" />
                                <span class="relative inline-flex size-2.5 rounded-full bg-white" />
                            </span>
                            <Link
                                href="/#upcoming"
                                class="font-semibold text-white underline underline-offset-4 hover:text-white/85 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                            >
                                {{ openProgramCount }}
                                {{ openProgramCount === 1 ? 'program' : 'programs' }} open for registration
                            </Link>
                        </p>
                    </div>

                    <!--
                        The photographs come last in the DOM, so on a phone they
                        sit below the call to action rather than pushing it under
                        the fold — the stacking order is the accessibility
                        decision here, not the column order.
                    -->
                    <HeroPhotoStack v-if="showPhotos" :photos="heroPhotos" @empty="photosLoaded = false" />
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
                    <p class="mt-4 text-base leading-relaxed text-pretty text-csc-ink-muted">
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
                        <p class="text-sm text-csc-ink-muted" role="status" aria-live="polite">
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
                        <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-csc-ink-muted">
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
                        <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-csc-ink-muted">
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
                        :only="['programs', 'filters', 'meta']"
                        preserve-scroll
                        class="rounded-lg border border-csc-line px-4 py-2 text-sm font-semibold text-csc-blue hover:bg-csc-blue-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    >
                        Previous
                    </Link>
                    <template v-for="(n, i) in pages" :key="n ?? `gap-${i}`">
                        <!-- A gap in the window. Not focusable, not a control. -->
                        <span v-if="n === null" class="px-2 text-sm text-csc-ink-subtle" aria-hidden="true">…</span>
                        <Link
                            v-else
                            href="/"
                            :data="pageLink(n)"
                            :only="['programs', 'filters', 'meta']"
                            preserve-scroll
                            class="rounded-lg border px-4 py-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            :class="
                                n === meta.current_page
                                    ? 'border-csc-blue bg-csc-blue text-white'
                                    : 'border-csc-line text-csc-blue hover:bg-csc-blue-tint'
                            "
                            :aria-current="n === meta.current_page ? 'page' : undefined"
                            :aria-label="`Page ${n}`"
                        >
                            {{ n }}
                        </Link>
                    </template>
                    <Link
                        v-if="meta.current_page < meta.last_page"
                        href="/"
                        :data="pageLink(meta.current_page + 1)"
                        :only="['programs', 'filters', 'meta']"
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

        <!--
            Features.

            The id was "programs", which no longer described anything on this
            section and was actively misdirecting: the header nav, the footer
            quick links and the hero button all point at #upcoming (the actual
            catalogue), so /#programs was the one address that still resolved
            here — to a grid of feature tiles — for anyone holding an old
            bookmark. Renamed to what it is.
        -->
        <section id="features" class="bg-csc-blue-tint py-20 lg:py-24">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-semibold tracking-tight text-balance text-csc-blue sm:text-4xl">
                        Everything you need in one account
                    </h2>
                    <p class="mt-4 text-base leading-relaxed text-pretty text-csc-ink-muted">
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
                        <p class="mt-2 text-sm leading-relaxed text-csc-ink-muted">{{ feature.description }}</p>

                        <!--
                            Visible at rest, not on hover: it is a precondition,
                            and a precondition a visitor only discovers after
                            clicking has not been disclosed. It also joins the
                            card's accessible name, so the link announces as
                            "Your Certificates … Sign in required" rather than
                            promising a page it will not open.
                        -->
                        <span
                            v-if="feature.requiresAccount"
                            class="mt-4 inline-flex w-fit items-center gap-1.5 rounded-full bg-csc-blue-tint px-2.5 py-1 text-2xs font-semibold tracking-wide text-csc-blue uppercase"
                        >
                            <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <rect x="4.5" y="10.5" width="15" height="10" rx="2" />
                                <path d="M8 10.5V7a4 4 0 0 1 8 0v3.5" stroke-linecap="round" />
                            </svg>
                            Sign in required
                        </span>

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

        <!--
            About.

            The header's "About" link used to land on the figures band alone —
            four numbers, no sentence, nothing that answers what TIMS is, who
            runs it, or who may register. That is the question an About link is
            asked, and it is a question a government service is expected to
            answer in plain words on the page rather than in a policy PDF.

            The section also has to exist unconditionally, for the same reason
            #upcoming does: the figures below it are now withheld when there is
            nothing true to report, and if the anchor lived on them, /#about
            would go dead on exactly the deployments that have the least other
            context to offer.
        -->
        <section id="about" class="bg-white py-20 lg:py-24">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-3xl">
                    <p class="inline-flex items-center gap-3 text-xs font-semibold tracking-widest text-csc-red-ink uppercase">
                        <span class="h-px w-8 bg-csc-red-ink" aria-hidden="true"></span>
                        About TIMS
                    </p>
                    <h2 class="mt-4 text-3xl font-semibold tracking-tight text-balance text-csc-blue sm:text-4xl">
                        The Commission’s training records, in one place
                    </h2>
                    <div class="mt-6 space-y-4 text-base leading-relaxed text-pretty text-csc-ink-muted">
                        <p>
                            The Training Information Management System is operated by the
                            <span class="font-semibold text-csc-ink">{{ office.name }}</span>
                            to publish its training calendar, take registrations, record attendance, and issue
                            the certificates that follow. It replaces the paper nomination forms and spreadsheets
                            that used to carry the same work.
                        </p>
                        <p>
                            Registration is open to personnel of national government agencies, local government
                            units, state universities and colleges, and government-owned corporations within
                            {{ office.region }}, as well as to CSC personnel. Some programs carry a fee and some
                            are limited to supervisory levels — each program page states which.
                        </p>
                        <p>
                            Every certificate issued here carries a verification code. Anyone can
                            <Link href="/verify" class="font-semibold text-csc-blue underline underline-offset-4 hover:text-csc-blue-deep">
                                check a code</Link>
                            against our records — so an employer or an auditor can confirm a certificate without
                            going through this office.
                        </p>
                    </div>

                    <div class="mt-8 flex flex-wrap gap-3">
                        <AppButton href="/#upcoming" variant="ghost">See the training calendar</AppButton>
                        <AppButton href="/#contact" variant="ghost">Contact the office</AppButton>
                    </div>
                </div>
            </div>
        </section>

        <!--
            Headline figures. Withheld entirely when HomeController has nothing
            it can stand behind — see hasStats. The column count follows the
            number of figures that survived, so three figures do not sit in a
            four-column grid with a hole where the fourth would be.
        -->
        <section v-if="hasStats" class="bg-csc-blue py-14">
            <div
                class="mx-auto grid max-w-7xl gap-8 px-4 sm:px-6 lg:px-8"
                :class="statsColumns"
            >
                <div v-for="stat in stats" :key="stat.label" class="text-center">
                    <p class="text-3xl font-semibold tracking-tight text-white sm:text-4xl">{{ stat.figure }}</p>
                    <p class="mt-2 text-sm text-white/75">{{ stat.label }}</p>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="bg-csc-blue-tint py-20">
            <div class="mx-auto max-w-3xl px-4 text-center sm:px-6 lg:px-8">
                <h2 class="text-3xl font-semibold tracking-tight text-balance text-csc-blue sm:text-4xl">
                    Ready to sign up for your next training?
                </h2>
                <p class="mx-auto mt-4 max-w-xl text-base leading-relaxed text-pretty text-csc-ink-muted">
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
