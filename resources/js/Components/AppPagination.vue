<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';

/**
 * Previous/next + numbered page navigation for a server-side paginator.
 *
 * Navigation goes through the *current* query string, so whatever filters the
 * user has set (search, status) ride along onto the next page — each screen
 * does not have to teach the component about its own filters.
 */
const props = defineProps({
    /*
     * Either a serialized LengthAwarePaginator (data + links + current_page …)
     * or a { data, meta } shape; `meta.current_page` / `meta.last_page` /
     * `meta.total` are what actually drive the buttons.
     */
    pagination: { type: Object, required: true },
    // Keep the viewport where it is instead of returning to the top. The
    // default is what a fresh page of results wants.
    preserveScroll: { type: Boolean, default: false },
    label: { type: String, default: 'results' },
});

const meta = computed(() => props.pagination.meta ?? props.pagination);

const current = computed(() => meta.value.current_page ?? 1);
const last = computed(() => meta.value.last_page ?? 1);
const total = computed(() => meta.value.total ?? 0);
const from = computed(() => meta.value.from ?? 0);
const to = computed(() => meta.value.to ?? 0);

const hasPages = computed(() => last.value > 1);

/** Numbered window with an ellipsis at each end once it outgrows the screen. */
const pages = computed(() => {
    const around = 1;
    const result = [];

    for (let page = 1; page <= last.value; page++) {
        const edge = page === 1 || page === last.value;
        const near = Math.abs(page - current.value) <= around;

        if (edge || near) {
            result.push(page);
        } else if (result[result.length - 1] !== '…') {
            result.push('…');
        }
    }

    return result;
});

const go = (page) => {
    if (page < 1 || page > last.value || page === current.value) return;

    const url = new URL(window.location.href);
    url.searchParams.set('page', String(page));

    router.get(url.pathname + url.search, {}, { preserveScroll: props.preserveScroll });
};
</script>

<template>
    <nav
        v-if="total > 0"
        aria-label="Pagination"
        class="flex flex-col items-center gap-3 sm:flex-row sm:justify-between"
    >
        <p class="text-xs text-csc-ink-subtle">
            Showing <span class="font-semibold text-csc-ink">{{ from }}</span>–<span
                class="font-semibold text-csc-ink"
            >{{ to }}</span> of <span class="font-semibold text-csc-ink">{{ total }}</span> {{ label }}
        </p>

        <div v-if="hasPages" class="flex flex-wrap items-center gap-1">
            <!--
                The inactive branch keeps text-csc-ink/40 where the rest of the
                app moved to the --color-csc-ink-* tokens. WCAG exempts disabled
                controls from the contrast floor, and this one is genuinely
                disabled — `current <= 1`, with disabled:opacity-40 on top. The
                faintness is the affordance: it is what tells someone there is
                no previous page to go to.
            -->
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue disabled:cursor-not-allowed disabled:opacity-40"
                :class="
                    current > 1
                        ? 'text-csc-ink-muted ring-1 ring-csc-line hover:text-csc-blue'
                        : 'bg-csc-blue-tint/60 text-csc-ink/40'
                "
                :disabled="current <= 1"
                aria-label="Previous page"
                @click="go(current - 1)"
            >
                <AppIcon name="chevron-left" size="sm" />
            </button>

            <template v-for="(page, index) in pages" :key="index">
                <button
                    v-if="page !== '…'"
                    type="button"
                    class="min-w-8 rounded-lg px-2 py-1.5 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    :class="
                        page === current
                            ? 'bg-csc-blue text-white'
                            : 'text-csc-ink-muted ring-1 ring-csc-line hover:text-csc-blue'
                    "
                    :aria-label="`Page ${page}`"
                    :aria-current="page === current ? 'page' : undefined"
                    @click="go(page)"
                >
                    {{ page }}
                </button>
                <!--
                    Left at /40 with the disabled arrows above it, and for the
                    same reason: this is aria-hidden filler standing for "some
                    pages", not something anyone reads or acts on. Raising it to
                    the body-text token would make a gap look like a control.
                -->
                <span v-else class="px-1 text-csc-ink/40" aria-hidden="true">…</span>
            </template>

            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue disabled:cursor-not-allowed disabled:opacity-40"
                :class="
                    current < last
                        ? 'text-csc-ink-muted ring-1 ring-csc-line hover:text-csc-blue'
                        : 'bg-csc-blue-tint/60 text-csc-ink/40'
                "
                :disabled="current >= last"
                aria-label="Next page"
                @click="go(current + 1)"
            >
                <AppIcon name="chevron-right" size="sm" />
            </button>
        </div>
    </nav>
</template>
