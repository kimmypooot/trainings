<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import PrivacyNoticeModal from '@/Components/PrivacyNoticeModal.vue';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AppSelect from '@/Components/AppSelect.vue';
import ProgramCard from '@/Components/ProgramCard.vue';
import ProgramDetailModal from '@/Components/ProgramDetailModal.vue';

/**
 * The public training catalogue.
 *
 * The anonymous half of a pair: /trainings serves the same calendar to
 * signed-in participants with their own registration state attached. This one
 * knows nothing about who is reading it, so every card is identical for every
 * visitor and the only call to action is sign-in.
 */
const props = defineProps({
    programs: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    filterOptions: { type: Object, default: () => ({ modes: [], categories: [], statuses: [] }) },
    meta: { type: Object, default: () => ({ current_page: 1, last_page: 1, total: 0, showing: 0 }) },
});

const origin = window.location.origin;

const search = ref(props.filters.search ?? '');
const mode = ref(props.filters.mode ?? '');
const category = ref(props.filters.category ?? '');
const status = ref(props.filters.status ?? '');

// A filter change always starts from the first page; staying on, say, page 3 of
// a narrowed search reads as "nothing found". preserveState keeps the fields
// from flashing while the new result set loads.
const applyFilters = () => {
    router.get(
        '/programs',
        {
            search: search.value.trim() || undefined,
            mode: mode.value || undefined,
            category: category.value || undefined,
            status: status.value || undefined,
            page: 1,
        },
        { preserveState: true, replace: true, preserveScroll: true }
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
 * see ProgramController), so the server's own "showing x of y" would count rows
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

const selected = ref(null);
</script>

<template>
    <Head title="Programs">
        <meta
            name="description"
            content="The full calendar of training programs offered by the Civil Service Commission Regional Office VIII — dates, venues, fees, and whether registration is open."
        />
        <meta property="og:site_name" content="CSC RO VIII - Training Information Management System" />
        <meta property="og:type" content="website" />
        <meta property="og:title" content="Training Programs - CSC TIMS" />
        <meta
            property="og:description"
            content="The full calendar of training programs offered by the Civil Service Commission Regional Office VIII."
        />
        <meta property="og:url" :content="origin + '/programs'" />
        <meta property="og:image" :content="origin + '/images/csc-logo-512.png'" />
        <meta name="twitter:card" content="summary" />
        <link rel="canonical" :href="origin + '/programs'" />
    </Head>

    <PrivacyNoticeModal />

    <PublicLayout current="programs">
        <!--
            A compact banner, not the home page's full-viewport hero. Someone
            who came here came to read a list, and a second tall photo between
            them and it would only be in the way.
        -->
        <section class="relative overflow-hidden bg-csc-blue-deep text-white">
            <div
                class="absolute inset-0"
                style="
                    background: linear-gradient(
                        160deg,
                        var(--color-csc-blue-deep) 0%,
                        var(--color-csc-blue) 60%,
                        var(--color-csc-blue-deep) 100%
                    );
                "
                aria-hidden="true"
            />
            <div class="relative mx-auto max-w-7xl px-4 pt-28 pb-16 sm:px-6 lg:px-8 lg:pt-32">
                <!-- Breadcrumb: the page is one level down, and search engines read it. -->
                <nav aria-label="Breadcrumb" class="mb-5 text-sm text-white/70">
                    <ol class="flex items-center gap-2">
                        <li><Link href="/" class="hover:text-white hover:underline">Home</Link></li>
                        <li aria-hidden="true">/</li>
                        <li class="font-medium text-white" aria-current="page">Programs</li>
                    </ol>
                </nav>

                <h1 class="max-w-3xl text-3xl leading-tight font-bold tracking-tight text-balance sm:text-4xl lg:text-5xl">
                    Training programs
                </h1>
                <p class="mt-4 max-w-2xl text-base leading-relaxed text-pretty text-white/85 sm:text-lg">
                    Everything currently on the Regional Office VIII calendar — including programs whose
                    registration has not opened yet, and those already full. Create an account to reserve a slot.
                </p>
            </div>
        </section>

        <section class="bg-white py-14 lg:py-16">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <!-- Filters -->
                <div class="rounded-2xl border border-csc-line bg-csc-blue-tint p-5 sm:p-6">
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
                    when the next batch opens.
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
                        <h2 class="mt-5 text-lg font-semibold text-csc-blue">No programs match those filters</h2>
                        <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-csc-ink/70">
                            Try widening your search, or clear the filters to see the whole calendar.
                        </p>
                        <div class="mt-6">
                            <AppButton @click="clearFilters">Clear filters</AppButton>
                        </div>
                    </template>
                    <template v-else>
                        <h2 class="mt-5 text-lg font-semibold text-csc-blue">No programs scheduled right now</h2>
                        <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-csc-ink/70">
                            The next batch of training programs has not been published yet. Create an account
                            now and we will email you as soon as registration opens.
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
                        :href="'/programs'"
                        :data="pageLink(meta.current_page - 1)"
                        preserve-scroll
                        class="rounded-lg border border-csc-line px-4 py-2 text-sm font-semibold text-csc-blue hover:bg-csc-blue-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    >
                        Previous
                    </Link>
                    <Link
                        v-for="n in pages"
                        :key="n"
                        :href="'/programs'"
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
                        :href="'/programs'"
                        :data="pageLink(meta.current_page + 1)"
                        preserve-scroll
                        class="rounded-lg border border-csc-line px-4 py-2 text-sm font-semibold text-csc-blue hover:bg-csc-blue-tint focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    >
                        Next
                    </Link>
                </nav>
            </div>
        </section>

        <ProgramDetailModal :program="selected" @close="selected = null" />

        <!-- CTA -->
        <section class="bg-csc-blue-tint py-16">
            <div class="mx-auto max-w-3xl px-4 text-center sm:px-6 lg:px-8">
                <h2 class="text-2xl font-semibold tracking-tight text-balance text-csc-blue sm:text-3xl">
                    Ready to reserve a slot?
                </h2>
                <p class="mx-auto mt-4 max-w-xl text-base leading-relaxed text-pretty text-csc-ink/70">
                    Registration takes a few minutes. Your certificates and training history stay in the same
                    account afterwards.
                </p>
                <div class="mt-8">
                    <AppButton href="/register" variant="accent" size="lg">Create your account</AppButton>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
