<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';

/*
 * The header search box.
 *
 * Staff used to navigate to a list before they could search it — the lookup
 * they actually wanted ("where is this person?", "which run was that?") cost
 * two page loads before the first keystroke. This answers both from anywhere
 * in the shell.
 *
 * The results are a flat, ordered list under the hood even though they render
 * in two labelled sections, because arrow keys have to walk *across* the
 * section boundary. Keeping one array and letting the template group it is
 * what stops the highlighted row and the row Enter opens from disagreeing.
 */

const MIN_TERM_LENGTH = 2;
// Long enough that a touch-typist's name does not fire five queries, short
// enough that the list feels attached to the keyboard.
const DEBOUNCE_MS = 250;

const term = ref('');
const open = ref(false);
const loading = ref(false);
const results = ref({ participants: [], trainings: [], more: {} });
const highlighted = ref(0);

const rootRef = ref(null);
const inputRef = ref(null);

// One ordered list for the keyboard; the template still renders two sections.
const flat = computed(() => [
    ...results.value.participants.map((item) => ({ ...item, kind: 'participant' })),
    ...results.value.trainings.map((item) => ({ ...item, kind: 'training' })),
]);

const termIsSearchable = computed(() => term.value.trim().length >= MIN_TERM_LENGTH);
const hasResults = computed(() => flat.value.length > 0);

let debounceTimer;
// Responses can land out of order; only the newest query may paint.
let latestQuery = 0;

const reset = () => {
    results.value = { participants: [], trainings: [], more: {} };
    highlighted.value = 0;
};

const runSearch = async (value) => {
    const query = ++latestQuery;
    loading.value = true;

    try {
        const response = await fetch(`/admin/search?q=${encodeURIComponent(value)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(`Search failed: ${response.status}`);
        }

        const data = await response.json();

        if (query !== latestQuery) {
            return;
        }

        results.value = data;
        highlighted.value = 0;
    } catch (error) {
        if (query === latestQuery) {
            reset();
        }
    } finally {
        if (query === latestQuery) {
            loading.value = false;
        }
    }
};

watch(term, (value) => {
    clearTimeout(debounceTimer);

    if (value.trim().length < MIN_TERM_LENGTH) {
        // Bump the counter so an in-flight response for a longer term cannot
        // paint results under a box the user has just cleared.
        latestQuery += 1;
        loading.value = false;
        reset();

        return;
    }

    open.value = true;
    debounceTimer = setTimeout(() => runSearch(value.trim()), DEBOUNCE_MS);
});

const close = () => {
    open.value = false;
    highlighted.value = 0;
};

const go = (item) => {
    if (!item) {
        return;
    }

    term.value = '';
    close();
    inputRef.value?.blur();
    router.visit(item.url);
};

const move = (delta) => {
    if (!hasResults.value) {
        return;
    }

    const count = flat.value.length;
    highlighted.value = (highlighted.value + delta + count) % count;
};

const onKeydown = (event) => {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        move(1);

        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        move(-1);

        return;
    }

    if (event.key === 'Enter') {
        if (hasResults.value) {
            event.preventDefault();
            go(flat.value[highlighted.value]);
        }

        return;
    }

    if (event.key === 'Escape') {
        // First Escape closes the list; a second clears the box. Closing and
        // clearing at once loses a term someone is still editing.
        if (open.value) {
            close();

            return;
        }

        term.value = '';
        inputRef.value?.blur();
    }
};

// Ctrl/Cmd-K from anywhere in the shell.
const onWindowKeydown = (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        inputRef.value?.focus();
        inputRef.value?.select();
    }
};

const onPointerDown = (event) => {
    if (open.value && !rootRef.value?.contains(event.target)) {
        close();
    }
};

let stopNavigateListener;

onMounted(() => {
    window.addEventListener('keydown', onWindowKeydown);
    document.addEventListener('pointerdown', onPointerDown);
    stopNavigateListener = router.on('navigate', () => {
        term.value = '';
        close();
    });
});

onBeforeUnmount(() => {
    clearTimeout(debounceTimer);
    window.removeEventListener('keydown', onWindowKeydown);
    document.removeEventListener('pointerdown', onPointerDown);
    stopNavigateListener?.();
});
</script>

<template>
    <div ref="rootRef" class="relative w-full max-w-md">
        <label for="global-search" class="sr-only">Search participants and trainings</label>

        <div class="relative">
            <AppIcon
                name="search"
                size="sm"
                class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-csc-ink-subtle"
            />
            <input
                id="global-search"
                ref="inputRef"
                v-model="term"
                type="search"
                role="combobox"
                autocomplete="off"
                placeholder="Search participants and trainings"
                class="w-full rounded-lg border border-csc-line bg-csc-blue-tint/60 py-2 pr-14 pl-9 text-sm text-csc-ink transition-colors placeholder:text-csc-ink-placeholder focus:border-csc-blue focus:bg-white focus:outline-none"
                :aria-expanded="open"
                aria-controls="global-search-results"
                @keydown="onKeydown"
                @focus="open = termIsSearchable"
            />
            <!--
                The shortcut hint is aria-hidden: it is an affordance for people
                already using a keyboard, and read aloud it is noise between the
                field and its results.
            -->
            <kbd
                class="pointer-events-none absolute top-1/2 right-3 hidden -translate-y-1/2 rounded border border-csc-line bg-white px-1.5 py-0.5 text-2xs font-medium text-csc-ink-subtle lg:block"
                aria-hidden="true"
            >
                Ctrl K
            </kbd>
        </div>

        <div
            v-if="open && termIsSearchable"
            id="global-search-results"
            class="absolute inset-x-0 top-full z-(--z-popover) mt-2 overflow-hidden rounded-xl border border-csc-line bg-white shadow-lg"
            role="listbox"
        >
            <p v-if="loading && !hasResults" class="px-4 py-6 text-center text-sm text-csc-ink-subtle">
                Searching…
            </p>

            <p v-else-if="!hasResults" class="px-4 py-6 text-center text-sm text-csc-ink-subtle">
                Nothing matches “{{ term.trim() }}”.
            </p>

            <div v-else class="max-h-96 overflow-y-auto">
                <template v-for="section in ['participants', 'trainings']" :key="section">
                    <div v-if="results[section].length">
                        <p
                            class="border-b border-csc-line bg-csc-blue-tint/50 px-4 py-1.5 text-2xs font-semibold tracking-wider text-csc-ink-muted uppercase"
                        >
                            {{ section === 'participants' ? 'Participants' : 'Trainings' }}
                        </p>

                        <button
                            v-for="item in results[section]"
                            :key="`${section}-${item.id}`"
                            type="button"
                            role="option"
                            class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors"
                            :class="
                                flat[highlighted]?.url === item.url
                                    ? 'bg-csc-blue-tint'
                                    : 'hover:bg-csc-blue-tint/60'
                            "
                            :aria-selected="flat[highlighted]?.url === item.url"
                            @click="go(item)"
                            @mousemove="highlighted = flat.findIndex((row) => row.url === item.url)"
                        >
                            <AppIcon
                                :name="section === 'participants' ? 'user' : 'calendar'"
                                size="sm"
                                class="shrink-0 text-csc-ink-subtle"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-csc-ink">
                                    {{ item.label }}
                                </span>
                                <span v-if="item.meta" class="block truncate text-xs text-csc-ink-subtle">
                                    {{ item.meta }}
                                </span>
                            </span>
                        </button>

                        <a
                            :href="results.more?.[section]"
                            class="block border-t border-csc-line px-4 py-2 text-xs font-semibold text-csc-blue transition-colors hover:bg-csc-blue-tint"
                        >
                            See all {{ section }} matching “{{ term.trim() }}”
                        </a>
                    </div>
                </template>
            </div>
        </div>

        <!-- Result count for screen readers; the list itself is visual. -->
        <p class="sr-only" aria-live="polite">
            <template v-if="open && termIsSearchable && !loading">
                {{ flat.length }} result{{ flat.length === 1 ? '' : 's' }} for {{ term.trim() }}
            </template>
        </p>
    </div>
</template>
