<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppIcon from '@/Components/AppIcon.vue';
import AppInput from '@/Components/AppInput.vue';
import AppModal from '@/Components/AppModal.vue';
import AppPagination from '@/Components/AppPagination.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppSkeleton from '@/Components/AppSkeleton.vue';
import TrainingDetailSections from '@/Components/TrainingDetailSections.vue';
import TrainingRegistrationForm from '@/Components/TrainingRegistrationForm.vue';
import { formatDateRange, spansMultipleDays } from '@/dateRange';
import { registrationCardToneFor } from '@/statusTone';

const props = defineProps({
    trainings: { type: Object, required: true },
    filters: { type: Object, required: true },
    filterOptions: { type: Object, required: true },
    registeredCount: { type: Number, required: true },
    chargeOptions: { type: Array, required: true },
    // Arrives only on the partial reload that asks for it: the full picture of
    // the training whose card the participant opened.
    details: { type: Object, default: null },
});

const page = usePage();

const search = ref(props.filters.search ?? '');
const mode = ref(props.filters.mode ?? '');
const category = ref(props.filters.category ?? '');
const openOnly = ref(Boolean(props.filters.open));
const sort = ref(props.filters.sort ?? '');

const modeOptions = computed(() => props.filterOptions.modes.map((option) => ({ value: option.value, label: option.label })));
const categoryOptions = computed(() =>
    props.filterOptions.categories.map((option) => ({ value: option.value, label: option.label }))
);
const sortOptions = [{ value: 'closing', label: 'Closing soon' }];

// A filter change always starts from the first page; staying on, say, page 4 of
// a narrowed search reads as "nothing found". preserveState keeps the page from
// flashing while the new filter set loads.
const applyFilters = () => {
    router.get(
        '/trainings',
        {
            search: search.value.trim() || undefined,
            mode: mode.value || undefined,
            category: category.value || undefined,
            open: openOnly.value ? 1 : undefined,
            sort: sort.value || undefined,
            page: 1,
        },
        { preserveState: true, replace: true }
    );
};

// Only the text box is debounced — a keystroke pauses while the dropdowns and
// toggle act on click, where a 300ms lag reads as a missed tap.
let searchDebounce;
watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(applyFilters, 300);
});

watch([mode, category, openOnly, sort], applyFilters);

const hasActiveFilters = computed(
    () => search.value.trim() !== '' || Boolean(mode.value || category.value || openOnly.value || sort.value)
);

const clearFilters = () => {
    search.value = '';
    mode.value = '';
    category.value = '';
    openOnly.value = false;
    sort.value = '';
};

const formatFee = (amount) => `PHP ${new Intl.NumberFormat('en-PH').format(Number(amount))}`;

/*
 * Detail modal.
 *
 * A card opens a modal instead of navigating to the Show page, mirroring the
 * public homepage. The full picture lives on the server in an optional
 * `details` prop, so opening a card is a partial reload of this same page that
 * asks for just that one training — no new route, no URL change, and nine full
 * descriptions never ride along with the card list. `preserveUrl` keeps the
 * address bar clean so the `details` id never leaks into pagination.
 *
 * The loader is layered so a cold click still reads as instant:
 *  - the card summary already answers most of the modal, so the grid renders
 *    the moment the dialog opens and only the long-form text waits;
 *  - hovering or focusing a card preloads its picture, so the wait is usually
 *    already over before the click;
 *  - each fetched picture is cached by id, so reopening a training never
 *    fetches again.
 */
const selected = ref(null);

// id => full picture. The server only ships the one `details` prop a reload
// asked for, so previously opened (or hover-preloaded) trainings live here.
const detailsCache = ref({});

const fetchDetails = (training) => {
    if (detailsCache.value[training.id]) return;

    router.reload({
        only: ['details'],
        data: { details: training.id },
        preserveState: true,
        preserveScroll: true,
        preserveUrl: true,
        // A hover-preload is a courtesy, not a request the participant is
        // waiting on — if it fails (a network blip, or a stale asset version
        // after a fresh deploy, which Inertia already recovers from on its
        // own by reloading the page), there is nothing for this call to do
        // beyond not surfacing an unhandled rejection. A click still opens
        // the modal and asks again.
        onError: () => {},
    });
};

// The `details` prop is the single source of truth: whatever the last partial
// reload delivered. Watching it (rather than reading the prop inside a visit
// callback) captures the picture even when a visit is cancelled in flight —
// hover, focus, and click can each fire a reload, and an interrupted visit
// never runs its onSuccess. Keying the cache by the id the server returned
// means a stale response can never masquerade as a different training.
watch(
    () => page.props.details,
    (loaded) => {
        if (loaded?.id) detailsCache.value[loaded.id] = loaded;
    }
);

const closeModal = () => {
    selected.value = null;
};

const openDetails = (training) => {
    selected.value = training;
    fetchDetails(training);
};

// Hovering a card preloads its picture so the modal usually opens already
// full. Debounced and cancelled on leave so a slow drag across the grid does
// not fire a request per card, and skipped on touch where hover is meaningless.
let preloadTimer;
const preloadDetails = (training) => {
    if (window.matchMedia('(hover: none)').matches) return;
    clearTimeout(preloadTimer);
    preloadTimer = setTimeout(() => fetchDetails(training), 150);
};

const cancelPreload = () => clearTimeout(preloadTimer);

// The card summary already answers most of the modal; the cached detail layer
// adds the long-form fields. Merging the two means the grid is complete the
// moment the dialog opens, before the fetch has landed.
const modalTraining = computed(() => {
    const summary = selected.value;
    const detail = summary ? detailsCache.value[summary.id] : null;

    return detail ? { ...summary, ...detail } : summary;
});

// True once the long-form text (description, prerequisites, venue details)
// has arrived; until then a compact skeleton sits where those sections go.
const detailLoaded = computed(() => {
    const summary = selected.value;
    return Boolean(summary && detailsCache.value[summary.id]);
});

const slotsDetail = (training) =>
    training.capacity === null
        ? 'No limit'
        : `${training.slots_remaining} of ${training.capacity} remaining`;

// A registered card is tinted by its status — see resources/js/statusTone.js
// for why "approved" reads green here specifically, distinct from the info
// tone AppBadge gives it in the footer badge on the same card.
const cardTone = (training) => registrationCardToneFor(training.is_registered, training.registration_status);
</script>

<template>
    <Head title="Trainings" />

    <AuthenticatedLayout title="Trainings" current="trainings">
        <div class="mx-auto max-w-7xl space-y-5">
            <p class="text-sm leading-relaxed text-csc-ink-muted">
                Programs offered by the Civil Service Commission. Slots are taken on a first-come basis.
            </p>

            <!-- Your registrations, at a glance -->
            <div v-if="registeredCount > 0" class="flex items-center gap-2 rounded-xl border border-csc-line bg-white px-4 py-3 text-sm text-csc-ink-muted">
                <span>
                    You are registered in
                    <Link
                        href="/my/registrations"
                        class="font-semibold text-csc-blue hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                    >
                        {{ registeredCount }} upcoming {{ registeredCount === 1 ? 'training' : 'trainings' }}
                    </Link>.
                    Open your registrations to track approvals and payments.
                </span>
            </div>

            <!-- Search + filters -->
            <div class="flex flex-col gap-3">
                <AppInput
                    v-model="search"
                    label=""
                    type="search"
                    placeholder="Search by title, code, or venue…"
                    aria-label="Search trainings"
                    class="lg:max-w-xs"
                />

                <div class="flex flex-wrap items-end gap-3">
                    <AppSelect
                        v-model="mode"
                        class="w-full sm:w-40"
                        label="Mode"
                        :options="modeOptions"
                        placeholder="All modes"
                    />
                    <AppSelect
                        v-model="category"
                        class="w-full sm:w-56"
                        label="Category"
                        :options="categoryOptions"
                        placeholder="All categories"
                    />
                    <AppSelect
                        v-model="sort"
                        class="w-full sm:w-44"
                        label="Sort by"
                        :options="sortOptions"
                        placeholder="Start date"
                    />

                    <label
                        class="flex cursor-pointer items-center gap-2 rounded-lg border border-csc-line bg-white px-3 py-2.5 text-sm font-medium text-csc-ink-muted hover:border-csc-blue/40"
                    >
                        <input
                            v-model="openOnly"
                            type="checkbox"
                            class="size-4 shrink-0 rounded border-csc-line accent-csc-blue"
                        />
                        Open registration
                    </label>

                    <AppButton v-if="hasActiveFilters" variant="ghost" size="sm" @click="clearFilters">
                        Clear filters
                    </AppButton>
                </div>
            </div>

            <div v-if="trainings.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <article
                    v-for="training in trainings.data"
                    :key="training.id"
                    class="relative flex cursor-pointer flex-col overflow-hidden rounded-xl border transition-shadow duration-150 hover:shadow-md"
                    :class="cardTone(training)"
                    @mouseenter="preloadDetails(training)"
                    @mouseleave="cancelPreload"
                    @focusin="preloadDetails(training)"
                >
                    <div class="flex items-start gap-4 p-5">
                        <!-- Date block reads faster than a formatted string in a grid -->
                        <div class="flex size-14 shrink-0 flex-col items-center justify-center rounded-lg bg-csc-blue text-white">
                            <span class="text-lg leading-none font-bold">{{ training.day }}</span>
                            <span class="mt-0.5 text-2xs font-medium uppercase">{{ training.month }}</span>
                        </div>

                        <div class="min-w-0 flex-1">
                            <!-- The stretched-link overlay makes the whole card a
                                 target; both it and View Details open the modal. -->
                            <button
                                type="button"
                                class="after:absolute after:inset-0 text-left text-sm leading-snug font-semibold text-csc-blue hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                @click="openDetails(training)"
                            >
                                {{ training.title }}
                            </button>
                            <p class="mt-1 text-xs text-csc-ink-subtle">{{ training.venue }}</p>
                            <!-- The run's range stays on a single row: a dash
                                 joins start and end when the run spans days. -->
                            <p class="mt-0.5 text-xs text-csc-ink-subtle">
                                {{ formatDateRange(training.starts_at, training.ends_at) }}
                            </p>

                            <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                                <span class="rounded-full bg-csc-blue-tint px-2 py-0.5 font-medium text-csc-blue">
                                    {{ training.mode_label }}
                                </span>
                                <AppBadge v-if="training.is_supervisory" status="supervisory" />
                                <span v-if="training.duration_days" class="text-csc-ink-subtle">
                                    {{ training.duration_days }} {{ training.duration_days === 1 ? 'day' : 'days' }}
                                </span>
                                <span class="font-medium text-csc-ink-subtle">
                                    {{ training.payment_amount ? formatFee(training.payment_amount) : 'Free' }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!--
                        flex-wrap: the button grew a visible border and padding
                        when it stopped being a bare text link, and "Opens
                        {date}" is long enough that the two together can outrun
                        a narrow phone's card width. Wrapping to a second line
                        beats the alternative of either one clipping.
                    -->
                    <div class="mt-auto flex flex-wrap items-center justify-between gap-2 border-t border-csc-line px-5 py-3">
                        <AppBadge v-if="training.is_registered" :status="training.registration_status" />
                        <span v-else-if="training.is_full" class="text-xs font-semibold text-danger">Full</span>
                        <span v-else-if="training.registration_closed" class="text-xs font-semibold text-csc-ink-subtle">
                            Registration closed
                        </span>
                        <span v-else-if="training.registration_not_yet_open" class="text-xs font-semibold text-csc-ink-subtle">
                            Opens {{ training.registration_opens_at }}
                        </span>
                        <span v-else-if="training.slots_remaining !== null" class="text-xs font-medium text-csc-ink-subtle">
                            {{ training.slots_remaining }} slot{{ training.slots_remaining === 1 ? '' : 's' }} left
                        </span>
                        <span v-else class="text-xs font-medium text-csc-ink-subtle">Open</span>

                        <AppButton variant="ghost" size="sm" @click="openDetails(training)">
                            View Details
                        </AppButton>
                    </div>
                </article>
            </div>

            <AppPagination v-if="trainings.data.length" :pagination="trainings" label="trainings" class="pt-1" />

            <!-- Filtered search that came up empty deserves its own message -->
            <AppCard v-else-if="hasActiveFilters" :padded="false">
                <AppEmptyState
                    title="No trainings match your search"
                    description="Try a different keyword, or clear the filters to see everything coming up."
                    icon="calendar"
                >
                    <template #action>
                        <AppButton variant="ghost" size="sm" @click="clearFilters">Clear filters</AppButton>
                    </template>
                </AppEmptyState>
            </AppCard>

            <AppCard v-else :padded="false">
                <AppEmptyState
                    title="No trainings available right now"
                    description="When the Commission publishes a new program, it will appear here."
                    icon="calendar"
                />
            </AppCard>

            <!-- Training detail modal: View Details opens this instead of a page. -->
            <AppModal
                :open="selected !== null"
                :title="selected?.title"
                :subtitle="selected ? `${selected.mode_label} · Starts ${selected.starts_at}` : null"
                size="lg"
                @close="closeModal"
            >
                <template v-if="modalTraining">
                    <dl class="grid gap-x-6 gap-y-5 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-csc-ink-subtle">Date</dt>
                            <dd class="mt-0.5 font-medium text-csc-ink">
                                {{ modalTraining.starts_at }}
                                <template v-if="spansMultipleDays(modalTraining.starts_at, modalTraining.ends_at)">
                                    <span class="text-csc-ink-subtle">– {{ modalTraining.ends_at }}</span>
                                </template>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-csc-ink-subtle">Venue</dt>
                            <dd class="mt-0.5 font-medium text-csc-ink">{{ modalTraining.venue }}</dd>
                        </div>
                        <div>
                            <dt class="text-csc-ink-subtle">Mode</dt>
                            <dd class="mt-0.5 font-medium text-csc-ink">{{ modalTraining.mode_label }}</dd>
                        </div>
                        <div v-if="modalTraining.payment_required">
                            <dt class="text-csc-ink-subtle">Fee</dt>
                            <dd class="mt-0.5 font-medium text-csc-ink">{{ formatFee(modalTraining.payment_amount) }}</dd>
                        </div>
                        <div v-if="modalTraining.category">
                            <dt class="text-csc-ink-subtle">Curriculum</dt>
                            <dd class="mt-0.5 font-medium text-csc-ink">{{ modalTraining.category }}</dd>
                        </div>
                        <div>
                            <dt class="text-csc-ink-subtle">Available slots</dt>
                            <dd class="mt-0.5 font-medium text-csc-ink">{{ slotsDetail(modalTraining) }}</dd>
                        </div>
                        <div v-if="modalTraining.duration_days">
                            <dt class="text-csc-ink-subtle">Duration</dt>
                            <dd class="mt-0.5 font-medium text-csc-ink">
                                {{ modalTraining.duration_days }} day{{ modalTraining.duration_days === 1 ? '' : 's' }}
                            </dd>
                        </div>
                        <div v-if="modalTraining.level_label">
                            <dt class="text-csc-ink-subtle">Level</dt>
                            <dd class="mt-0.5 font-medium text-csc-ink">{{ modalTraining.level_label }}</dd>
                        </div>
                        <div v-if="modalTraining.registration_not_yet_open && modalTraining.registration_opens_at">
                            <dt class="text-csc-ink-subtle">Registration opens</dt>
                            <dd class="mt-0.5 font-medium text-csc-ink">{{ modalTraining.registration_opens_at }}</dd>
                        </div>
                        <div v-if="modalTraining.registration_closes_at">
                            <dt class="text-csc-ink-subtle">Registration closes</dt>
                            <dd class="mt-0.5 font-medium text-csc-ink">{{ modalTraining.registration_closes_at }}</dd>
                        </div>
                        <div v-if="modalTraining.is_registered" class="sm:col-span-2">
                            <dt class="text-csc-ink-subtle">Your registration</dt>
                            <dd class="mt-1">
                                <AppBadge :status="modalTraining.registration_status" />
                            </dd>
                        </div>
                    </dl>

                    <!-- The long-form text rides in with the fetched detail;
                         until it lands, a compact skeleton holds the shape
                         below the grid that was already instant. -->
                    <TrainingDetailSections
                        v-if="detailLoaded"
                        :training="modalTraining"
                        class="mt-6"
                    />

                    <div v-else class="mt-6 border-t border-csc-line pt-5">
                        <AppSkeleton variant="text" :count="3" label="Loading training details" />
                    </div>
                </template>

                <template v-if="modalTraining" #footer>
                    <AppButton v-if="modalTraining.is_registered" :href="modalTraining.url" size="lg" block>
                        View your registration
                    </AppButton>
                    <p v-else-if="modalTraining.registration_closed" class="text-sm font-medium text-csc-ink-subtle">
                        Registration for this training has closed.
                    </p>
                    <p v-else-if="modalTraining.is_full" class="text-sm font-medium text-danger">
                        This training is full.
                    </p>
                    <!--
                        The form itself, not a link to the page that has it.
                        Sending the participant to Trainings/Show from here
                        meant reading the same details twice before reaching a
                        single short form. It appears only once the detail
                        payload has landed, because eligibility travels with it
                        and a form that does not yet know whether to ask for a
                        supporting document must not be offered.
                    -->
                    <!--
                        Its own scroll, because AppModal's footer is shrink-0
                        inside an overflow-hidden dialog: on a short viewport
                        (a phone held sideways) the questions are taller than
                        the space left over, and without this the Submit button
                        is clipped off the bottom with no way to reach it.
                    -->
                    <div v-else-if="detailLoaded" class="max-h-[60vh] overflow-y-auto">
                        <TrainingRegistrationForm
                            :training="modalTraining"
                            :eligibility="modalTraining.eligibility"
                            :charge-options="chargeOptions"
                            @registered="closeModal"
                        />
                    </div>
                </template>
            </AppModal>
        </div>
    </AuthenticatedLayout>
</template>