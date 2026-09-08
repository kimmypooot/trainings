<script setup>
/**
 * The reading machinery behind a guide: search, day-one index, accordions,
 * scroll-spy and deep links.
 *
 * Extracted when the staff guide arrived, because the alternative was a second
 * copy of two hundred lines whose every awkward detail had been arrived at by
 * getting it wrong first — the scroll that has to verify and correct itself,
 * the `scroll-mt-40!` that needs its important modifier, the search bar's bleed
 * that has to be cancelled inside a grid column. A copy would have started
 * correct and drifted the first time one of them was fixed.
 *
 * What stays with the caller is everything that is *about* a particular
 * audience: the sections themselves, the intro, and whatever a section needs
 * beyond steps and questions — the participant guide's status badges and file
 * limits arrive through the per-section `extra-<id>` slot rather than being
 * understood here.
 *
 * A section is:
 *   { id, title, icon, summary, keywords?, steps?: [{heading, body}],
 *     problems?: [{q, a}] }
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    sections: { type: Array, required: true },
    searchPlaceholder: { type: String, default: 'Search this guide' },
    /** Shown under the "nothing matched" empty state. */
    searchHint: { type: String, default: 'Try a simpler word.' },
    /** Label for the aside's escape hatch; it scrolls to the `contact` anchor. */
    contactLabel: { type: String, default: 'Still stuck?' },
    contactHint: { type: String, default: null },
});

/*
 * Search over the section, not the page.
 *
 * Matching whole sections rather than highlighting words is what keeps this
 * usable: a hit gives a heading the reader recognises and can read in order,
 * instead of a scattering of fragments they have to reassemble. The haystack
 * includes each section's `keywords`, which carry the words people actually
 * type and the prose does not use.
 */
const query = ref('');

const haystacks = computed(() =>
    props.sections.map((section) =>
        [
            section.title,
            section.summary,
            ...(section.keywords ?? []),
            ...(section.steps ?? []).flatMap((s) => [s.heading, s.body]),
            ...(section.problems ?? []).flatMap((p) => [p.q, p.a]),
        ]
            .join(' ')
            .toLowerCase(),
    ),
);

const matches = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return props.sections;

    // Every word has to appear somewhere in the section, so a two-word query
    // narrows rather than widens.
    const words = q.split(/\s+/);

    return props.sections.filter((section, i) => words.every((w) => haystacks.value[i].includes(w)));
});

const searching = computed(() => query.value.trim().length > 0);

/*
 * Everything starts open. A guide that opens as a column of closed headings
 * makes the reader guess which one holds their answer before they can read any
 * of it, and not knowing is why they are here. Collapsing is for the reader who
 * has found their section and wants the rest out of the way.
 */
const open = ref(new Set());

// Rebuilt when the sections change, which for the staff guide happens per role.
watch(
    () => props.sections,
    (list) => (open.value = new Set(list.map((s) => s.id))),
    { immediate: true },
);

const isOpen = (id) => open.value.has(id);

const toggle = (id) => {
    const next = new Set(open.value);
    next.has(id) ? next.delete(id) : next.add(id);
    open.value = next;
};

const setAll = (openThem) => {
    open.value = openThem ? new Set(props.sections.map((s) => s.id)) : new Set();
};

// A searched-for section is opened, or a match would be a heading with its
// answer folded away behind it.
watch(searching, (on) => {
    if (on) open.value = new Set(props.sections.map((s) => s.id));
});

/*
 * Scroll to a section, then keep checking until it has actually arrived.
 *
 * The naive version — open it, wait a frame, scrollIntoView — lands in the
 * wrong place. The shell wraps every page in a fade-and-rise (.page-enter,
 * 0.18s) during which content is translated, so a position measured
 * mid-animation is real but wrong; the page is also thousands of pixels of
 * accordions whose heights settle as fonts load.
 *
 * So it verifies rather than predicts. The loop stops the moment the target is
 * at its scroll margin, which is the common case and costs one check. Its other
 * exit is *being at maximum scroll*, not "the number stopped changing" — the
 * latter reads identically to a page that has not finished laying out, and an
 * earlier version gave up on the first attempt because of it.
 */
const goTo = async (id, { animate = true } = {}) => {
    open.value = new Set([...open.value, id]);

    const frame = () => new Promise((resolve) => requestAnimationFrame(resolve));

    await frame();
    await frame();

    const target = document.getElementById(id);
    if (!target) return;

    const wanted = parseFloat(getComputedStyle(target).scrollMarginTop) || 0;

    target.scrollIntoView({ behavior: animate ? 'smooth' : 'instant', block: 'start' });

    for (let attempt = 0; attempt < 10; attempt++) {
        await new Promise((resolve) => setTimeout(resolve, 100));

        const top = Math.round(target.getBoundingClientRect().top);

        if (Math.abs(top - wanted) < 4) return;

        const maxScroll = document.documentElement.scrollHeight - window.innerHeight;

        if (window.scrollY >= maxScroll - 1) return;

        target.scrollIntoView({ behavior: 'instant', block: 'start' });
    }
};

/*
 * Which section the reader is in, for the aside's index.
 *
 * An IntersectionObserver rather than a scroll handler: the browser reports the
 * crossings, so nothing runs on the frames where nothing changed. The negative
 * top margin is the height of everything pinned above the content — the shell's
 * header plus this page's search bar — so a section becomes current when its
 * heading clears those bars rather than when it slides under them.
 */
const activeSection = ref(null);

let observer = null;

const watchSections = () => {
    observer?.disconnect();

    if (typeof IntersectionObserver === 'undefined') return;

    observer = new IntersectionObserver(
        (entries) => {
            const visible = entries
                .filter((entry) => entry.isIntersecting)
                .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);

            if (visible.length) activeSection.value = visible[0].target.id;
        },
        { rootMargin: '-160px 0px -55% 0px', threshold: 0 },
    );

    for (const section of props.sections) {
        const el = document.getElementById(section.id);
        if (el) observer.observe(el);
    }
};

onMounted(() => {
    activeSection.value = props.sections[0]?.id ?? null;
    watchSections();

    const hash = window.location.hash.replace('#', '');
    if (hash && props.sections.some((s) => s.id === hash)) goTo(hash, { animate: false });
});

onBeforeUnmount(() => observer?.disconnect());

defineExpose({ goTo });
</script>

<template>
    <!--
        6xl, one step narrower than the 7xl the app's list screens use, and
        chosen rather than inherited.

        Those screens are tables and card grids, which use width. This is prose,
        and prose has a measure: at 7xl, minus the 15rem index and the gutter,
        a line runs to about 940px — comfortably past the point where the eye
        loses its place returning to the left margin. 6xl lands around 800px,
        which is the top of the readable range and still wider than the 4xl the
        guide started at.

        Both guides share it, so they match each other; the one-step difference
        from the surrounding app is the deliberate part.
    -->
    <div class="mx-auto max-w-6xl">
        <slot name="intro" />

        <!--
            Two columns from lg up, one below it. The index moves into space a
            centred column was leaving empty and stays there while the reader
            scrolls, so "where am I, and what else is here" stops costing a trip
            back to the top.

            `items-start` is what lets the aside stick: a grid item stretches to
            the row height by default, and a sticky element inside a full-height
            box has nothing to stick against.
        -->
        <div class="mt-5 grid items-start gap-6 lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-8">
            <!--
                Hidden below lg rather than reflowed above the content: on a
                phone this would be a column of links between the reader and the
                answer they came for, and the chip strip below serves that case
                in one line.
            -->
            <aside class="sticky top-20 hidden self-start lg:block" aria-label="Guide contents">
                <nav class="rounded-xl border border-csc-line bg-white p-2">
                    <p class="px-3 py-2 text-2xs font-semibold tracking-wider text-csc-ink-subtle uppercase">
                        On this page
                    </p>
                    <ul>
                        <li v-for="section in sections" :key="section.id">
                            <button
                                type="button"
                                class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-csc-blue"
                                :class="
                                    activeSection === section.id
                                        ? 'bg-csc-blue-tint text-csc-blue'
                                        : 'text-csc-ink-muted hover:bg-csc-blue-tint/50 hover:text-csc-blue'
                                "
                                :aria-current="activeSection === section.id ? 'true' : undefined"
                                @click="goTo(section.id)"
                            >
                                <AppIcon :name="section.icon" size="sm" class="shrink-0" />
                                <span class="min-w-0 flex-1">{{ section.title }}</span>
                            </button>
                        </li>
                    </ul>
                </nav>

                <!--
                    The escape hatch, kept in view. It does not repeat the card
                    at the foot of the page; it is a way of reaching it from
                    wherever the reader gave up, which on a page this long is
                    what was missing.
                -->
                <button
                    v-if="contactHint"
                    type="button"
                    class="mt-3 flex w-full items-start gap-2.5 rounded-xl border border-csc-line bg-csc-mist/40 p-3 text-left transition-colors hover:border-csc-blue/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    @click="goTo('contact')"
                >
                    <span class="mt-0.5 inline-flex size-7 shrink-0 items-center justify-center rounded-lg bg-white text-csc-blue">
                        <AppIcon name="envelope" size="sm" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-xs font-semibold text-csc-ink">{{ contactLabel }}</span>
                        <span class="mt-0.5 block text-2xs leading-relaxed text-csc-ink-subtle">
                            {{ contactHint }}
                        </span>
                    </span>
                </button>
            </aside>

            <div class="min-w-0 space-y-5">
                <!--
                    Search first, because somebody arriving with a problem has a
                    word for it. The negative margin lets the pinned bar's
                    background reach the edges of the content area; it is
                    cancelled at lg, where this sits in a grid column and
                    bleeding would put the bar over the aside beside it.
                -->
                <div
                    class="sticky top-16 z-10 -mx-4 bg-csc-blue-tint/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6 lg:mx-0 lg:px-0"
                >
                    <label for="guide-search" class="sr-only">Search this guide</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-csc-ink-subtle">
                            <AppIcon name="search" size="sm" />
                        </span>
                        <input
                            id="guide-search"
                            v-model="query"
                            type="search"
                            :placeholder="searchPlaceholder"
                            class="w-full rounded-lg border border-csc-line bg-white py-2.5 pr-4 pl-10 text-sm text-csc-ink placeholder:text-csc-ink-subtle focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                        />
                    </div>

                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs text-csc-ink-subtle" aria-live="polite">
                            <template v-if="searching">
                                {{ matches.length }} {{ matches.length === 1 ? 'section' : 'sections' }} match
                                “{{ query.trim() }}”
                            </template>
                            <template v-else>{{ sections.length }} sections</template>
                        </p>
                        <div v-if="!searching" class="flex gap-1">
                            <button
                                type="button"
                                class="rounded px-2 py-1 text-xs font-medium text-csc-blue transition-colors hover:bg-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                @click="setAll(true)"
                            >
                                Expand all
                            </button>
                            <button
                                type="button"
                                class="rounded px-2 py-1 text-xs font-medium text-csc-blue transition-colors hover:bg-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                @click="setAll(false)"
                            >
                                Collapse all
                            </button>
                        </div>
                    </div>
                </div>

                <!--
                    The same index for screens too narrow for the aside. Hidden
                    while searching, because the results below already are the
                    filtered list.
                -->
                <nav v-if="!searching" aria-label="Guide sections" class="flex flex-wrap gap-2 lg:hidden">
                    <button
                        v-for="section in sections"
                        :key="section.id"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-csc-ink-muted ring-1 ring-csc-line transition-colors hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        @click="goTo(section.id)"
                    >
                        <AppIcon :name="section.icon" size="sm" />
                        {{ section.title }}
                    </button>
                </nav>

                <AppCard v-if="searching && !matches.length" :padded="false">
                    <AppEmptyState icon="search" title="Nothing matched that" :description="searchHint">
                        <template #action>
                            <AppButton variant="ghost" icon="close" @click="query = ''">Clear search</AppButton>
                        </template>
                    </AppEmptyState>
                </AppCard>

                <!--
                    Deeper scroll clearance than app.css's
                    `[id] { scroll-margin-top: 5rem }`, and the `!` is
                    load-bearing rather than lazy. That 5rem clears the shell's
                    header, which is all most pages pin; this one also pins the
                    search bar, so 5rem lands a heading behind the very control
                    somebody just used to find it. The important modifier is
                    needed because that rule is unlayered CSS, and in Tailwind v4
                    unlayered styles beat layered utilities whatever their
                    specificity — a plain `scroll-mt-40` silently loses.
                -->
                <section
                    v-for="section in matches"
                    :id="section.id"
                    :key="section.id"
                    class="scroll-mt-40! rounded-xl border border-csc-line bg-white"
                >
                    <h2>
                        <button
                            type="button"
                            class="flex w-full items-start gap-3 rounded-xl px-4 py-4 text-left transition-colors hover:bg-csc-blue-tint/40 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-csc-blue sm:px-5"
                            :aria-expanded="isOpen(section.id)"
                            :aria-controls="`${section.id}-panel`"
                            @click="toggle(section.id)"
                        >
                            <span class="mt-0.5 inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-csc-blue-tint text-csc-blue">
                                <AppIcon :name="section.icon" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-csc-blue">{{ section.title }}</span>
                                <span class="mt-0.5 block text-xs leading-relaxed text-csc-ink-subtle">
                                    {{ section.summary }}
                                </span>
                            </span>
                            <AppIcon
                                name="chevron-down"
                                size="sm"
                                class="mt-2 shrink-0 text-csc-ink-subtle transition-transform duration-150"
                                :class="isOpen(section.id) ? 'rotate-180' : ''"
                            />
                        </button>
                    </h2>

                    <div
                        v-show="isOpen(section.id)"
                        :id="`${section.id}-panel`"
                        class="border-t border-csc-line px-4 py-4 sm:px-5"
                    >
                        <!--
                            Numbered, because these are procedures. The counter
                            is drawn rather than left to the list marker so it
                            can sit in the brand tint at a readable size.
                        -->
                        <ol v-if="section.steps" class="space-y-4">
                            <li v-for="(step, index) in section.steps" :key="step.heading" class="flex gap-3">
                                <span
                                    class="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-csc-blue-tint text-xs font-semibold text-csc-blue"
                                    aria-hidden="true"
                                >
                                    {{ index + 1 }}
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-csc-ink">{{ step.heading }}</p>
                                    <p class="mt-1 text-sm leading-relaxed text-csc-ink-muted">{{ step.body }}</p>
                                </div>
                            </li>
                        </ol>

                        <!-- Whatever this section needs beyond steps and questions. -->
                        <slot :name="`extra-${section.id}`" :section="section" />

                        <!-- Troubleshooting reads as question and answer, not steps. -->
                        <dl v-if="section.problems" class="space-y-4" :class="section.steps ? 'mt-5 border-t border-csc-line pt-4' : ''">
                            <div v-for="problem in section.problems" :key="problem.q">
                                <dt class="flex gap-2 text-sm font-semibold text-csc-ink">
                                    <AppIcon name="warning" size="sm" class="mt-0.5 shrink-0 text-warning" />
                                    {{ problem.q }}
                                </dt>
                                <dd class="mt-1 pl-6 text-sm leading-relaxed text-csc-ink-muted">{{ problem.a }}</dd>
                            </div>
                        </dl>
                    </div>
                </section>

                <slot name="footer" :searching="searching" :matches="matches" />
            </div>
        </div>
    </div>
</template>
