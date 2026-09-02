<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppInput from '@/Components/AppInput.vue';
import AppModal from '@/Components/AppModal.vue';
import AppPromptModal from '@/Components/AppPromptModal.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTabs from '@/Components/AppTabs.vue';
import AppTextarea from '@/Components/AppTextarea.vue';
// The roster renders every participant twice — a table on a wide screen,
// stacked cards on a narrow one. These three hold everything the two layouts
// must agree on, because for a while they did not: Record Payment, Cancel, the
// OR number and the evaluation count all existed only in the table.
import RosterActions from '@/Components/RosterActions.vue';
import RosterDocument from '@/Components/RosterDocument.vue';
import RosterEvaluation from '@/Components/RosterEvaluation.vue';
// Issuing a door owns its own world — a form, a clipboard, and a code that
// exists for exactly one render — none of which is roster state.
import RosterScanStations from '@/Components/RosterScanStations.vue';
import RosterEvaluationCodes from '@/Components/RosterEvaluationCodes.vue';
import { formatDateRange } from '@/dateRange';
import { useDownload } from '@/useDownload';

const props = defineProps({
    training: { type: Object, required: true },
    registrations: { type: Array, required: true },
    summary: { type: Object, required: true },
    scopedTo: { type: String, default: null },
    attendanceStatuses: { type: Array, default: () => [] },
    supervisoryDocumentStatuses: { type: Array, default: () => [] },
    scanLinks: { type: Array, default: () => [] },
    /** Null for roles that may not cut evaluation codes; the panel is then absent. */
    evaluationCodes: { type: Object, default: null },
    transferTargets: { type: Array, default: () => [] },
    officeBreakdown: { type: Array, default: () => [] },
    revenue: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
    rescheduledTo: { type: Object, default: null },
    paymentMethods: { type: Array, default: () => [] },
    collectingOfficers: { type: Array, default: () => [] },
});

const page = usePage();
const errors = computed(() => Object.values(page.props.errors ?? {}));

const { downloading, start } = useDownload();

// Built once each rather than inline, so the URL a button renders and the URL
// it starts are the same string — `downloading === url` is what shows the
// spinner, and it matches on identity, not on intent.
const rosterExportUrl = (format) =>
    `/admin/exports/trainings/${props.training.id}/roster${format === 'xlsx' ? '?format=xlsx' : ''}`;

const revenueExportUrl = computed(() => `/admin/exports/trainings/${props.training.id}/revenue`);

// The run's date, as a range where it spans more than one day — read by both
// the on-screen header and the printed attendance sheet, so a sheet printed
// for day 2 or 3 of a run still shows the whole span it belongs to.
const trainingDateRange = computed(() => formatDateRange(props.training.starts_at, props.training.ends_at));

/*
 * Anything that has to be justified opens the same dialog.
 *
 * `prompt` describes what is being decided; `onConfirm` receives the reason and
 * performs it. One piece of state, so two dialogs can never be open at once.
 */
const prompt = ref(null);
const promptBusy = ref(false);

const askFor = (config) => {
    prompt.value = config;
};

const closePrompt = () => {
    prompt.value = null;
    promptBusy.value = false;
};

const confirmPrompt = (reason) => {
    promptBusy.value = true;
    prompt.value.onConfirm(reason);
};

const post = (url, payload) =>
    router.post(url, payload, {
        preserveScroll: true,
        onSuccess: closePrompt,
        onFinish: () => (promptBusy.value = false),
    });

/**
 * Completion follows the attendance record. When it falls short the server
 * refuses, so the override path is explicit and has to carry a reason.
 */
const markComplete = (registration) => {
    if (registration.can_complete) {
        post(`/admin/registrations/${registration.id}/complete`, {});

        return;
    }

    askFor({
        title: 'Complete without a full attendance record',
        description:
            `${registration.name} was recorded for ${registration.credited_days} of ` +
            `${props.training.duration_days} day(s).`,
        label: 'Reason for the override',
        hint: 'Kept on the registration so the exception stays auditable.',
        confirmLabel: 'Complete anyway',
        minLength: 10,
        onConfirm: (remarks) =>
            post(`/admin/registrations/${registration.id}/complete`, { force: true, remarks }),
    });
};

const setAttendance = (registration, day, status) => {
    if (!status) {
        return;
    }

    router.post(
        `/admin/registrations/${registration.id}/attendance`,
        { training_day: day, status },
        { preserveScroll: true }
    );
};

// Only a participant holding a place can be marked, matching AttendanceService.
const isMarkable = (registration) => ['approved', 'completed'].includes(registration.status);

/*
 * The four statuses as a segmented control rather than a dropdown.
 *
 * A select costs three interactions to set one value — open, find, choose —
 * and a roster of twelve people is twenty-four of them for a single day. These
 * are one click each.
 *
 * The initial is never the only signal: every button carries the full word as
 * its accessible name and tooltip, so the meaning survives greyscale, print and
 * colour blindness.
 */
const attendanceChoices = computed(() =>
    props.attendanceStatuses.map((option) => ({
        ...option,
        short: option.label.charAt(0).toUpperCase(),
        active: {
            present: 'bg-success text-white',
            late: 'bg-warning text-white',
            absent: 'bg-danger text-white',
            excused: 'bg-info text-white',
        }[option.value] ?? 'bg-csc-blue text-white',
        idle: {
            present: 'text-success hover:bg-success-soft',
            late: 'text-warning hover:bg-warning-soft',
            absent: 'text-danger hover:bg-danger-soft',
            excused: 'text-info hover:bg-info-soft',
        }[option.value] ?? 'text-csc-ink-subtle hover:bg-csc-blue-tint',
    }))
);

const releaseCertificate = (id) =>
    router.post(`/admin/registrations/${id}/certificate`, {}, { preserveScroll: true });

const releaseAll = () =>
    router.post(`/admin/trainings/${props.training.id}/certificates`, {}, { preserveScroll: true });

const awaitingCertificates = computed(
    () => props.registrations.filter((r) => r.status === 'completed' && !r.certificate_number).length
);

const decide = (registration, decision) => {
    const url = `/admin/registrations/${registration.id}/review`;

    // A rejection has to carry a reason, so it is the one path that asks.
    if (decision !== 'rejected') {
        post(url, { decision, remarks: null });

        return;
    }

    askFor({
        title: 'Reject this registration',
        description: `${registration.name} will be told the registration was not approved.`,
        label: 'Reason for rejection',
        hint: 'Recorded against the registration.',
        confirmLabel: 'Reject registration',
        minLength: 10,
        onConfirm: (remarks) => post(url, { decision, remarks }),
    });
};

/**
 * Verify or reject a supervisory supporting document.
 *
 * Rejecting asks for a reason, same as a registration rejection — the
 * participant reads it when they are told to fix the file.
 */
const decideDocument = (registration, decision) => {
    const url = `/admin/registrations/${registration.id}/supervisory-document`;

    if (decision !== 'rejected') {
        post(url, { decision, remarks: null });

        return;
    }

    askFor({
        title: 'Reject this supporting document',
        description: `${registration.name}'s document will be sent back for a replacement.`,
        label: 'Reason for rejection',
        hint: 'Shown to the participant, who can then upload a corrected document.',
        confirmLabel: 'Reject document',
        minLength: 10,
        onConfirm: (remarks) => post(url, { decision, remarks }),
    });
};

/**
 * Cancel on the participant's behalf — a phoned-in withdrawal, a duplicate, a
 * confirmed no-show. Always asks: the participant loses their place on someone
 * else's say-so, so the record has to carry whose and why.
 */
const cancelRegistration = (registration) => {
    askFor({
        title: 'Cancel this registration',
        description: `${registration.name} gives up their place, and the slot is released to the next applicant.`,
        label: 'Reason for cancellation',
        hint: 'Kept on the registration. You have a few seconds to take this back.',
        confirmLabel: 'Cancel registration',
        minLength: 10,
        onConfirm: (reason) => post(`/admin/registrations/${registration.id}/cancel`, { reason }),
    });
};

// Matches RegistrationStatus::isCancellable() — the server refuses the rest,
// and cancelling on a participant's behalf is HRD's decision, not every staff
// role that can read this roster.
const isCancellable = (registration) =>
    props.can.manage_roster && ['pending', 'approved', 'waitlisted'].includes(registration.status);

const pendingCount = computed(() => props.registrations.filter((r) => r.status === 'pending').length);

const restrictions = computed(() => props.registrations.filter((r) => r.food_restrictions));

/* -------------------------------------------------------------------------- */
/* Filtering, search, "not checked in today", sorting                          */
/* -------------------------------------------------------------------------- */

const query = ref('');
const statusFilter = ref('all');
const onlyNotCheckedInToday = ref(false);
// The supervisory-document filter, meaningful only when the training is
// supervisory and someone has actually been asked to attach a document.
const docFilter = ref('all');
// And the evaluations tab's own filter — see evaluationChips.
const evalFilter = ref('all');

// The training day that is happening right now, if any. The "not checked in
// today" view has nothing to say on a day the training is not running.
const todayDay = computed(() => props.training.days.find((day) => day.is_today)?.day ?? null);

/*
 * Attendance is taken one day at a time.
 *
 * The column used to render a control per training day, so a five-day run put
 * five dropdowns in every row and the sheet grew taller the longer the course.
 * That laid the grid out as "one person, every day", which is the retrospective
 * case. At the venue the job is the opposite — one day, every person — so the
 * page picks a day and the rows carry a single control for it.
 *
 * Lands where the work is: today while the run is on, the last day that has
 * actually happened once it is over — which is where a correction starts — and
 * day one for a training that has not begun, since nothing else has happened
 * yet to look at.
 */
const activeDay = ref(
    todayDay.value ??
        props.training.days.filter((day) => day.is_past).at(-1)?.day ??
        props.training.days[0]?.day ??
        1
);

const activeDayLabel = computed(
    () => props.training.days.find((day) => day.day === activeDay.value)?.label ?? ''
);

/*
 * The whole per-participant grid, for fixing a day that is not the one on
 * screen. Occasional work, so it opens on demand rather than living in a column.
 *
 * Held as an id and looked up on every render rather than captured as an
 * object: marking a day reloads the props, and a captured row would keep
 * showing the state it had when the dialog opened.
 */
const correcting = ref(null);

const correctingRow = computed(
    () => props.registrations.find((registration) => registration.id === correcting.value) ?? null
);

const notCheckedInToday = (registration) =>
    todayDay.value !== null &&
    isMarkable(registration) &&
    !(registration.attendance[todayDay.value]?.time_in);

const statusCounts = computed(() => {
    const counts = { all: props.registrations.length };

    for (const registration of props.registrations) {
        counts[registration.status] = (counts[registration.status] ?? 0) + 1;
    }

    return counts;
});

const notCheckedInCount = computed(
    () => props.registrations.filter(notCheckedInToday).length
);

/*
 * Chips in display order. Statuses that never occur on this roster simply read
 * as zero, which is easier to reason about than a chip vanishing mid-session.
 *
 * Each carries its own label rather than printing the enum value: the stored
 * string is `pending`, but nothing the office reads should be lowercase machine
 * vocabulary, and the badge in the row beside it already says Pending Approval.
 * The two now agree.
 */
const statusChips = [
    { value: 'pending', label: 'Pending Approval' },
    { value: 'approved', label: 'Approved' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'waitlisted', label: 'Waitlisted' },
];

/*
 * Where a participant stands on the evaluations they owe.
 *
 * The Evaluations tab needed its own vocabulary. It had been showing the
 * registration status chips, which is how it came to offer "Pending Approval"
 * on a list headed Evaluation Responses — a phrase that reads as an evaluation
 * awaiting a verdict, when it actually meant the person's *registration* had
 * not been approved yet. Nothing in this app approves an evaluation; a form is
 * submitted or it is not.
 *
 * Three states, and they partition the roster: nothing is expected of this
 * person, something is expected and still missing, or everything expected has
 * arrived. `expected` is 0 for anyone holding no slot, which is what makes the
 * first bucket the honest home for a cancelled or rejected registration rather
 * than putting it on a chase list nobody can act on.
 *
 * The labels say submitted and not submitted, because that is the only thing
 * that ever happens to one of these forms. "Outstanding" was the word here and
 * in the office table, meaning an unsubmitted form in one place and an unpaid
 * fee in the other — a word doing two jobs is a word doing neither.
 */
const evaluationState = (registration) => {
    if (!registration.evaluation?.expected) {
        return 'not-required';
    }

    return registration.evaluation.outstanding.length ? 'not-submitted' : 'submitted';
};

// Not submitted first: it is the only one of the three anybody opens this tab
// to act on.
const evaluationChips = [
    { value: 'not-submitted', label: 'Not Submitted' },
    // "All", because a two-day run collects two forms and one of them arriving
    // is not the same as being done.
    { value: 'submitted', label: 'All Submitted' },
    { value: 'not-required', label: 'Not Required' },
];

const evaluationCounts = computed(() => {
    const counts = { all: props.registrations.length };

    for (const registration of props.registrations) {
        const state = evaluationState(registration);
        counts[state] = (counts[state] ?? 0) + 1;
    }

    return counts;
});

const sortKey = ref(null);
const sortDir = ref('asc');

function toggleSort(key) {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = key;
        sortDir.value = 'asc';
    }
}

const sortIndicator = (key) => {
    if (sortKey.value !== key) {
        return '';
    }

    return sortDir.value === 'asc' ? ' ↑' : ' ↓';
};

const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();

    let rows = props.registrations.filter((registration) => {
        const matchesQuery =
            !needle ||
            `${registration.name} ${registration.email} ${registration.organization ?? ''}`
                .toLowerCase()
                .includes(needle);
        const matchesStatus = statusFilter.value === 'all' || registration.status === statusFilter.value;
        const matchesDoc = docFilter.value === 'all'
            || registration.supervisory_document?.status === docFilter.value;
        const matchesToday = !onlyNotCheckedInToday.value || notCheckedInToday(registration);
        const matchesEvaluation = evalFilter.value === 'all'
            || evaluationState(registration) === evalFilter.value;

        return matchesQuery && matchesStatus && matchesToday && matchesDoc && matchesEvaluation;
    });

    if (sortKey.value) {
        const dir = sortDir.value === 'asc' ? 1 : -1;

        // Dotted keys resolve a nested value (e.g. the document status label);
        // the fallback keeps a null document from sorting above a present one.
        const valueAt = (row, key) =>
            key.split('.').reduce((acc, part) => (acc == null ? null : acc[part]), row);

        rows = [...rows].sort((a, b) => {
            const av = valueAt(a, sortKey.value) ?? '';
            const bv = valueAt(b, sortKey.value) ?? '';

            return (typeof av === 'number' ? av - bv : String(av).localeCompare(String(bv))) * dir;
        });
    }

    return rows;
});

const hasActiveFilters = computed(
    () =>
        Boolean(query.value.trim()) ||
        statusFilter.value !== 'all' ||
        onlyNotCheckedInToday.value ||
        docFilter.value !== 'all' ||
        evalFilter.value !== 'all'
);

const clearFilters = () => {
    query.value = '';
    statusFilter.value = 'all';
    onlyNotCheckedInToday.value = false;
    docFilter.value = 'all';
    evalFilter.value = 'all';
};

// Document status chips for a supervisory training, with live counts.
const docStatusCounts = computed(() => {
    const counts = { all: props.registrations.filter((r) => r.supervisory_document).length };

    for (const registration of props.registrations) {
        const status = registration.supervisory_document?.status;

        if (status) {
            counts[status] = (counts[status] ?? 0) + 1;
        }
    }

    return counts;
});

/* -------------------------------------------------------------------------- */
/* Tabs                                                                        */
/* -------------------------------------------------------------------------- */

/*
 * The roster is four jobs, not one screen.
 *
 * It used to be all of them at once: the payment buttons and the attendance
 * control sat in the same row, the evaluation codes and the office split under
 * it, and an officer taking money at the desk had a day-picker in front of them
 * that had nothing to do with the task. Reading the page meant deciding which
 * half of every row to ignore. So each job gets a tab, and the roster's columns
 * follow the tab rather than all showing at once.
 *
 * The list and the filters stay shared across the tabs — it is the same twelve
 * people either way, and re-typing a search to go from paying someone to
 * marking them present would be its own annoyance.
 *
 * A tab that would be empty is absent, never disabled: a run with no experts
 * has no evaluations to chase, and a single-office run has nothing to split.
 */
const tabs = computed(() => {
    const list = [
        {
            key: 'participants',
            label: 'Participants',
            icon: 'users',
            count: props.registrations.length,
        },
        {
            key: 'attendance',
            label: 'Attendance',
            icon: 'check-circle',
            count: props.summary.checked_in_today,
        },
    ];

    if (props.training.collects_evaluations || props.evaluationCodes) {
        list.push({
            key: 'evaluations',
            label: 'Evaluations',
            icon: 'clipboard',
            // The chase list, not the response rate — the same figure the
            // summary tile above carries.
            count: props.training.collects_evaluations
                ? props.summary.evaluations_outstanding
                : null,
        });
    }

    if (props.officeBreakdown.length > 1) {
        list.push({
            key: 'offices',
            label: 'By Field Office',
            icon: 'building',
            count: props.officeBreakdown.length,
        });
    }

    return list;
});

/*
 * The open tab lives in the URL fragment, so a reload, the back button and a
 * link pasted to a colleague all land on the section that was being worked in.
 * Validated against the tabs that actually exist — a stale `#offices` on a
 * one-office run falls back rather than rendering nothing.
 */
const tabInHash = () => {
    const key = window.location.hash.replace('#', '');

    return tabs.value.some((entry) => entry.key === key) ? key : null;
};

const tab = ref(tabInHash() ?? 'participants');

/*
 * A fragment can change without the document reloading — someone edits the
 * address bar, or follows a link to `#attendance` from this same page. Without
 * this the URL would say one section and the page would show another.
 */
const followHash = () => {
    tab.value = tabInHash() ?? tab.value;
};

onMounted(() => window.addEventListener('hashchange', followHash));
onBeforeUnmount(() => window.removeEventListener('hashchange', followHash));

watch(tab, (value) => {
    window.history.replaceState(null, '', `#${value}`);

    /*
     * A filter belonging to the tab being left is dropped rather than left
     * running invisibly. "Not checked in today" hides most of the roster and
     * its chip only exists on Attendance, so carrying it into Participants
     * would be a short list with no visible reason for being short.
     */
    if (value !== 'attendance') {
        onlyNotCheckedInToday.value = false;
    }

    if (value !== 'participants') {
        docFilter.value = 'all';
        selected.value = new Set();
    }

    if (value !== 'evaluations') {
        evalFilter.value = 'all';
    }
});

/*
 * Which columns this tab is about. Both layouts read this one object, so the
 * table and the cards cannot end up showing different fields — the drift the
 * shared Roster* components were pulled out to stop.
 */
const shows = computed(() => ({
    /*
     * The checkboxes exist for the bulk bar, and every bulk action — approve,
     * waitlist, reject, complete, move to another run — is HRD's. A reader who
     * cannot apply one is offered no selection to apply it to.
     */
    select: tab.value === 'participants' && props.can.manage_roster,
    document: props.training.is_supervisory && tab.value === 'participants',
    attendance: tab.value === 'attendance',
    evaluation: props.training.collects_evaluations && tab.value === 'evaluations',
    actions: tab.value === 'participants',
}));

const rosterCardTitle = computed(() => ({
    participants: 'Participants',
    attendance: `Attendance · ${activeDayLabel.value}`,
    evaluations: 'Evaluation Responses',
}[tab.value] ?? 'Participants'));

const rosterCardSubtitle = computed(() => ({
    /*
     * The subtitle names what is actually in front of *this* reader. Payments
     * are a designation, not a role — a field office that has not been made a
     * collecting officer gets no Record Payment button and no Revenue panel, so
     * promising "and payments" listed a thing that is not on their screen and
     * sent them looking for it.
     */
    participants: props.can.record_payment
        ? 'Registrations, supporting documents and payments.'
        : 'Registrations and supporting documents.',
    attendance: 'Mark the day in front of you. Nothing here changes a payment.',
    evaluations: 'Who has submitted, and who still owes a form.',
}[tab.value] ?? null));

/* -------------------------------------------------------------------------- */
/* Selection                                                                   */
/* -------------------------------------------------------------------------- */

/*
 * Selection.
 *
 * Only rows a bulk action could actually apply to are selectable — a checkbox
 * beside a cancelled registration is a promise the server will refuse to keep.
 */
const selectable = computed(() =>
    props.registrations.filter((r) => ['pending', 'approved'].includes(r.status))
);

const selected = ref(new Set());

// A selection is only meaningful for the rows currently on screen; when the
// roster reloads after an action, anything no longer selectable drops out.
watch(
    () => props.registrations,
    () => {
        const ids = new Set(selectable.value.map((r) => r.id));
        selected.value = new Set([...selected.value].filter((id) => ids.has(id)));
    }
);

const isSelected = (id) => selected.value.has(id);

const toggle = (id) => {
    const next = new Set(selected.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selected.value = next;
};

// Select-all acts on the rows currently in front of the operator, not on the
// whole roster — checking one box and approving "all" when the roster was
// filtered is a scope the server would reject anyway.
const visibleSelectable = computed(() => filtered.value.filter((r) => selectable.value.includes(r)));

const allSelected = computed(
    () => visibleSelectable.value.length > 0 && selected.value.size === visibleSelectable.value.length
);

const toggleAll = () => {
    selected.value = allSelected.value ? new Set() : new Set(visibleSelectable.value.map((r) => r.id));
};

const selectedRows = computed(() => props.registrations.filter((r) => selected.value.has(r.id)));
const selectedPending = computed(() => selectedRows.value.filter((r) => r.status === 'pending').length);
const selectedApproved = computed(() => selectedRows.value.filter((r) => r.status === 'approved').length);

const applying = ref(false);

const sendBulk = (action, remarks = null) => {
    applying.value = true;

    router.post(
        `/admin/trainings/${props.training.id}/registrations/bulk`,
        { action, ids: [...selected.value], remarks },
        {
            preserveScroll: true,
            onSuccess: closePrompt,
            onFinish: () => {
                applying.value = false;
                promptBusy.value = false;
            },
        }
    );
};

const applyBulk = (action) => {
    if (action !== 'rejected') {
        sendBulk(action);

        return;
    }

    const count = selectedPending.value;

    askFor({
        title: `Reject ${count} registration(s)`,
        description: 'The same reason is recorded against every one of them.',
        label: 'Reason for rejection',
        confirmLabel: `Reject ${count}`,
        minLength: 10,
        onConfirm: (remarks) => sendBulk(action, remarks),
    });
};

/*
 * Moving a selection to another run.
 *
 * Its own dialog rather than another bulk action: it needs a target training
 * as well as a reason, and the server reports back per-participant which of
 * them could not be moved.
 */
const transferring = ref(false);

const transferForm = useForm({
    target_training_id: '',
    reason: '',
    ids: [],
});

const startTransfer = () => {
    transferForm.reset();
    transferForm.clearErrors();
    transferring.value = true;
};

const submitTransfer = () => {
    transferForm.ids = [...selected.value];

    transferForm.post(`/admin/trainings/${props.training.id}/registrations/transfer`, {
        preserveScroll: true,
        onSuccess: () => {
            transferring.value = false;
            selected.value = new Set();
            transferForm.reset();
        },
    });
};

/*
 * Money taken at the counter. The participant paid cash at the desk and left
 * with the receipt, so there is nothing to upload and nothing to review — the
 * officer enters what is on the OR stub and it lands verified.
 */
const paying = ref(null);

const paymentForm = useForm({
    amount: '',
    payment_method: 'cash',
    payment_date: '',
    reference_number: '',
    or_number: '',
    or_date: '',
    collecting_officer_id: '',
    remarks: '',
    prime_hrm_discount: false,
});

/*
 * The PRIME-HRM incentive, previewed for the officer. These are the same
 * figures the server computes — this is a display of the arithmetic, not the
 * source of it. The form posts only the flag; PaymentService derives what is
 * owed, so nothing here can steer the amount that is actually recorded.
 */
const PRIME_HRM_RATE = 0.2;

const money = (value) =>
    Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const primeHrm = computed(() => {
    const gross = Number(props.training.payment_amount ?? 0);
    const discount = Math.round(gross * PRIME_HRM_RATE * 100) / 100;

    return { gross, discount, net: Math.round((gross - discount) * 100) / 100 };
});

const today = () => new Date().toISOString().slice(0, 10);

const startPayment = (registration) => {
    paymentForm.reset();
    paymentForm.clearErrors();
    // Pre-filled with the run's fee and today's date — the overwhelmingly
    // common case is the exact amount, handed over now.
    paymentForm.amount = props.training.payment_amount ?? '';
    paymentForm.payment_date = today();
    paymentForm.or_date = today();
    paying.value = registration;
};

// Ticking the discount replaces the amount with the discounted price, and
// unticking restores the full fee. The field goes read-only while ticked — the
// figure is the office's to compute, not the officer's to type.
watch(
    () => paymentForm.prime_hrm_discount,
    (discounted) => {
        paymentForm.amount = discounted ? primeHrm.value.net : (props.training.payment_amount ?? '');
    }
);

// Whether this method has a reference number at all — not whether one is
// demanded, which nothing at this counter is. Read off the method list the
// server sent, not restated here: this was a hardcoded list of values, which
// meant adding a method left the field hidden on a rule that still wanted it.
const methodCollectsReference = computed(
    () => props.paymentMethods.find((method) => method.value === paymentForm.payment_method)
        ?.collects_reference ?? false
);
const methodIsSettlement = computed(() => paymentForm.payment_method !== 'promissory');

/*
 * Switching to a method that carries no reference — cash, a promissory note,
 * an official receipt — takes the field off the form, and anything typed into
 * it before the switch would otherwise be posted from behind the scenes. A
 * cheque number filed against a cash payment is not a typo anyone can see.
 */
watch(methodCollectsReference, (collects) => {
    if (!collects) {
        paymentForm.reference_number = '';
        paymentForm.clearErrors('reference_number');
    }
});

const submitPayment = () => {
    paymentForm.post(`/admin/registrations/${paying.value.id}/payment`, {
        preserveScroll: true,
        onSuccess: () => {
            paying.value = null;
            paymentForm.reset();
        },
    });
};

// Only where there is a fee, the viewer may post one, and nothing is already
// standing against it — a pending upload is reviewed on the payments screen,
// not overwritten here.
const canRecordPayment = (registration) =>
    props.can.record_payment &&
    props.training.payment_required &&
    !registration.payment.settled &&
    !registration.payment.awaiting_review &&
    registration.status !== 'cancelled';

// The attendance sheet is the same page in print — the interactive chrome
// carries the print:hidden class and the sheet the print-only section shows.
const printAttendanceSheet = () => window.print();

// Evaluated once when the page loads; for a printout that is the right "now".
const printedAt = new Date().toLocaleString();
</script>

<template>
    <Head :title="`Roster — ${training.title}`" />

    <AuthenticatedLayout title="Roster" current="admin-trainings">
        <div class="mx-auto max-w-7xl space-y-5">
            <Link
                href="/admin/trainings"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-csc-blue hover:text-csc-blue-deep"
            >
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M15 6l-6 6 6 6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                Manage Trainings
            </Link>

            <div class="flex flex-wrap gap-3 print:hidden">
                <AppButton
                    :href="rosterExportUrl('csv')"
                    variant="ghost"
                    size="sm"
                    icon="download"
                    external
                    :loading="downloading === rosterExportUrl('csv')"
                    @click.prevent="start(rosterExportUrl('csv'))"
                >
                    Export Roster (CSV)
                </AppButton>
                <AppButton
                    :href="rosterExportUrl('xlsx')"
                    variant="ghost"
                    size="sm"
                    icon="download"
                    external
                    :loading="downloading === rosterExportUrl('xlsx')"
                    @click.prevent="start(rosterExportUrl('xlsx'))"
                >
                    Export Roster (Excel)
                </AppButton>
                <AppButton variant="ghost" size="sm" icon="print" @click="printAttendanceSheet">
                    Print Attendance Sheet
                </AppButton>
                <!--
                    Two doors to the same workflow, and which one is offered
                    depends on whether the replacement run exists yet. Once it
                    does, the useful screen is the list of people stranded by
                    it — offering "Reschedule" again would invite a second
                    replacement nobody meant to create.
                -->
                <AppButton
                    v-if="can.reschedule && rescheduledTo"
                    variant="ghost"
                    size="sm"
                    icon="users"
                    :href="`/admin/trainings/${training.id}/affected`"
                >
                    Affected Participants
                </AppButton>
                <AppButton
                    v-else-if="can.reschedule"
                    variant="ghost"
                    size="sm"
                    icon="calendar"
                    :href="`/admin/trainings/${training.id}/reschedule`"
                >
                    Reschedule This Run
                </AppButton>
            </div>

            <AppAlert v-for="message in errors" :key="message" tone="danger">{{ message }}</AppAlert>

            <AppAlert v-if="scopedTo" tone="info">
                Showing participants from <strong>{{ scopedTo }}</strong> only.
            </AppAlert>

            <AppCard :title="training.title" :subtitle="`${trainingDateRange} · ${training.venue}`" class="print:hidden">
                <!--
                    Six tiles always, plus one each for a supervisory course and
                    a run that collects evaluations — so the track has to hold
                    eight without the last one wrapping alone onto its own row.
                -->
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-8">
                    <div>
                        <p class="text-2xl font-bold text-warning">{{ pendingCount }}</p>
                        <p class="text-xs text-csc-ink-subtle">Pending</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-csc-blue">{{ summary.checked_in_today }}</p>
                        <p class="text-xs text-csc-ink-subtle">Checked in today</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-csc-blue">{{ summary.active }}</p>
                        <p class="text-xs text-csc-ink-subtle">Holding a slot</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-success">{{ summary.completed }}</p>
                        <p class="text-xs text-csc-ink-subtle">Completed</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-danger">{{ summary.cancelled }}</p>
                        <p class="text-xs text-csc-ink-subtle">Cancelled</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-warning">{{ summary.with_food_restrictions }}</p>
                        <p class="text-xs text-csc-ink-subtle">Food restrictions</p>
                    </div>
                    <!-- Only meaningful on a supervisory course. -->
                    <div v-if="training.is_supervisory">
                        <p class="text-2xl font-bold text-warning">{{ summary.documents_to_review }}</p>
                        <p class="text-xs text-csc-ink-subtle">Docs to verify</p>
                    </div>
                    <!--
                        Participants still owing an evaluation. Scoped to this
                        office's own people, like every other figure here — it is
                        a chase list, not a measure of the training.
                    -->
                    <div v-if="training.collects_evaluations">
                        <p class="text-2xl font-bold text-warning">{{ summary.evaluations_outstanding }}</p>
                        <p class="text-xs text-csc-ink-subtle">Evaluations owed</p>
                    </div>
                </div>
            </AppCard>

            <AppTabs
                v-model="tab"
                :tabs="tabs"
                variant="underline"
                aria-label="Roster sections"
                class="print:hidden"
            />

            <AppAlert
                v-if="tab === 'participants' && awaitingCertificates && can.manage_roster"
                tone="info"
                title="Certificates ready to issue"
                class="print:hidden"
            >
                <p>
                    {{ awaitingCertificates }} completed participant(s) have no certificate yet.
                </p>
                <AppButton class="mt-3" size="sm" icon="certificate" @click="releaseAll">Issue All Certificates</AppButton>
            </AppAlert>

            <!-- Catering needs this as a list, not buried per-row -->
            <AppAlert
                v-if="tab === 'participants' && restrictions.length"
                tone="warning"
                title="Food restrictions for catering"
                class="print:hidden"
            >
                <ul class="mt-1 space-y-1">
                    <li v-for="item in restrictions" :key="item.id">
                        <span class="font-medium">{{ item.name }}</span> — {{ item.food_restrictions }}
                    </li>
                </ul>
            </AppAlert>

            <!--
                A door to scan people in belongs with the day it is scanning
                for, so the station sits at the top of Attendance rather than
                four screens below a roster nobody scrolled that far down.
            -->
            <RosterScanStations v-if="tab === 'attendance'" :training="training" :scan-links="scanLinks" />

            <!--
                The same argument at the other end of a session: the poster
                people scan on their way out heads the Evaluations tab.
                Withheld entirely from roles that cannot cut a code — the prop
                arrives null for them rather than the card rendering disabled
                controls.
            -->
            <RosterEvaluationCodes
                v-if="tab === 'evaluations' && evaluationCodes"
                :training="training"
                :codes="evaluationCodes"
            />

            <AppCard
                v-if="tab !== 'offices' && !(tab === 'evaluations' && !training.collects_evaluations)"
                :title="rosterCardTitle"
                :subtitle="rosterCardSubtitle"
                :padded="registrations.length > 0"
                class="print:hidden"
            >
                <AppEmptyState
                    v-if="!registrations.length"
                    title="No one has registered yet"
                    description="Registrations will appear here as participants sign up."
                    icon="users"
                />

                <template v-else>
                <!--
                    Client-side find and filter. The whole roster ships in the
                    page, so the narrowing happens locally — the operator at the
                    venue gets the answer without a round trip. Selection is not
                    affected by the filters; choosing rows always means choosing
                    rows in the roster.
                -->
                <div class="flex flex-col gap-3 border-b border-csc-line pb-4">
                    <input
                        v-model="query"
                        type="search"
                        :placeholder="`Find ${registrations.length} participant(s) by name, email or agency…`"
                        aria-label="Find participants"
                        class="w-full rounded-lg border border-csc-ink/20 bg-white px-4 py-2.5 text-sm text-csc-ink focus:border-csc-blue focus:outline-2 focus:outline-offset-1 focus:outline-csc-blue"
                    />

                    <!--
                        Every chip row says what it filters. Without the word in
                        front of it this one was just five bare statuses, and on
                        the Evaluations tab that read as the state of the *form*
                        rather than of the registration.
                    -->
                    <div v-if="tab !== 'evaluations'" class="flex flex-wrap items-center gap-1.5">
                        <span class="mr-1 text-xs font-semibold tracking-wide text-csc-ink-subtle uppercase">
                            Registration
                        </span>
                        <button
                            v-for="chip in [{ value: 'all', label: 'All' }, ...statusChips]"
                            :key="chip.value"
                            type="button"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            :class="
                                statusFilter === chip.value
                                    ? 'bg-csc-blue text-white shadow-sm'
                                    : 'bg-csc-blue-tint/60 text-csc-ink-muted hover:text-csc-ink'
                            "
                            @click="statusFilter = chip.value"
                        >
                            {{ chip.label }}
                            <span
                                class="ml-1 text-xs"
                                :class="statusFilter === chip.value ? 'text-white/80' : 'text-csc-ink-subtle'"
                            >
                                {{ statusCounts[chip.value] ?? 0 }}
                            </span>
                        </button>

                        <button
                            v-if="tab === 'attendance' && todayDay !== null"
                            type="button"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            :class="
                                onlyNotCheckedInToday
                                    ? 'bg-warning text-white shadow-sm'
                                    : 'bg-warning-soft text-warning-ink hover:text-csc-ink'
                            "
                            @click="onlyNotCheckedInToday = !onlyNotCheckedInToday"
                        >
                            Not checked in today
                            <span
                                class="ml-1 text-xs"
                                :class="onlyNotCheckedInToday ? 'text-white/80' : 'text-warning/70'"
                            >
                                {{ notCheckedInCount }}
                            </span>
                        </button>
                    </div>

                    <!--
                        The Evaluations tab's own vocabulary. A form has been
                        submitted or it has not; nothing here is awaiting
                        anyone's approval, and nothing here is about money.
                    -->
                    <div v-else class="flex flex-wrap items-center gap-1.5">
                        <span class="mr-1 text-xs font-semibold tracking-wide text-csc-ink-subtle uppercase">
                            Evaluation
                        </span>
                        <button
                            v-for="chip in [{ value: 'all', label: 'All' }, ...evaluationChips]"
                            :key="chip.value"
                            type="button"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            :class="
                                evalFilter === chip.value
                                    ? 'bg-csc-blue text-white shadow-sm'
                                    : 'bg-csc-blue-tint/60 text-csc-ink-muted hover:text-csc-ink'
                            "
                            @click="evalFilter = chip.value"
                        >
                            {{ chip.label }}
                            <span
                                class="ml-1 text-xs"
                                :class="evalFilter === chip.value ? 'text-white/80' : 'text-csc-ink-subtle'"
                            >
                                {{ evaluationCounts[chip.value] ?? 0 }}
                            </span>
                        </button>
                    </div>

                    <!--
                        Which day the attendance column is editing. Only worth
                        showing on a multi-day run — a one-day course has no
                        choice to make.
                    -->
                    <div
                        v-if="tab === 'attendance' && training.days.length > 1"
                        class="flex flex-wrap items-center gap-1.5 border-t border-csc-line pt-3 print:hidden"
                    >
                        <span class="mr-1 text-xs font-semibold tracking-wide text-csc-ink-subtle uppercase">
                            Marking
                        </span>
                        <button
                            v-for="day in training.days"
                            :key="day.day"
                            type="button"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            :class="
                                activeDay === day.day
                                    ? 'bg-csc-blue text-white shadow-sm'
                                    : 'bg-csc-blue-tint text-csc-blue hover:bg-csc-blue-tint/70'
                            "
                            :aria-pressed="activeDay === day.day"
                            @click="activeDay = day.day"
                        >
                            Day {{ day.day }}
                            <span
                                class="ml-1 text-xs"
                                :class="activeDay === day.day ? 'text-white/75' : 'text-csc-blue/60'"
                            >
                                {{ day.label }}
                            </span>
                            <span v-if="day.is_today" class="ml-1 text-2xs font-semibold uppercase">· today</span>
                        </button>
                    </div>

                    <!--
                        The supervisory-document lifecycle, on supervisory
                        trainings only. Filters the same local roster; the count
                        is per-status for the whole page.
                    -->
                    <div
                        v-if="shows.document"
                        class="flex flex-wrap items-center gap-1.5 border-t border-csc-line pt-3"
                    >
                        <span class="mr-1 text-xs font-semibold tracking-wide text-csc-ink-subtle uppercase">
                            Document
                        </span>
                        <button
                            v-for="chip in [{ value: 'all', label: 'All' }, ...supervisoryDocumentStatuses]"
                            :key="chip.value"
                            type="button"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                            :class="
                                docFilter === chip.value
                                    ? 'bg-csc-blue text-white shadow-sm'
                                    : 'bg-csc-blue-tint/60 text-csc-ink-muted hover:text-csc-ink'
                            "
                            @click="docFilter = chip.value"
                        >
                            {{ chip.label }}
                            <span
                                class="ml-1 text-xs"
                                :class="docFilter === chip.value ? 'text-white/80' : 'text-csc-ink-subtle'"
                            >
                                {{ docStatusCounts[chip.value] ?? 0 }}
                            </span>
                        </button>
                    </div>
                </div>

                <AppEmptyState
                    v-if="filtered.length === 0"
                    title="No participants match"
                    :description="
                        hasActiveFilters
                            ? 'Try another search, or clear the filters to see the full roster.'
                            : 'The roster is empty.'
                    "
                    icon="users"
                    class="pt-6"
                >
                    <template v-if="hasActiveFilters" #action>
                        <AppButton size="sm" variant="ghost" @click="clearFilters">Clear filters</AppButton>
                    </template>
                </AppEmptyState>

                <template v-if="filtered.length > 0">
                <!--
                    The bulk bar sticks to the bottom of the viewport rather
                    than the top of the table: on a long roster the selection is
                    made while scrolled well past any header.

                    Below md it has to clear the mobile tab bar, which is fixed
                    to the bottom at 3.5rem plus the safe-area inset; at md that
                    bar is gone and this one can sit flush.
                -->
                <div
                    v-if="shows.select && selected.size"
                    class="sticky bottom-[calc(3.5rem+env(safe-area-inset-bottom))] z-(--z-tabbar) -mx-5 flex flex-wrap items-center gap-3 border-t border-csc-line bg-white/95 px-5 py-3 backdrop-blur sm:-mx-6 sm:px-6 md:bottom-0 print:hidden"
                >
                    <p class="text-sm font-medium text-csc-ink" role="status">
                        {{ selected.size }} selected
                    </p>

                    <button
                        type="button"
                        class="rounded text-xs font-medium text-csc-ink-subtle underline hover:text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        @click="selected = new Set()"
                    >
                        Clear
                    </button>

                    <div class="ml-auto flex flex-wrap gap-2">
                        <AppButton
                            v-if="selectedPending"
                            size="sm"
                            variant="ghost"
                            :loading="applying"
                            @click="applyBulk('approved')"
                        >
                            Approve {{ selectedPending }}
                        </AppButton>
                        <AppButton
                            v-if="selectedPending"
                            size="sm"
                            variant="ghost"
                            :loading="applying"
                            @click="applyBulk('waitlisted')"
                        >
                            Waitlist {{ selectedPending }}
                        </AppButton>
                        <AppButton
                            v-if="selectedPending"
                            size="sm"
                            variant="ghost"
                            :loading="applying"
                            @click="applyBulk('rejected')"
                        >
                            Reject {{ selectedPending }}
                        </AppButton>
                        <AppButton
                            v-if="selectedApproved"
                            size="sm"
                            :loading="applying"
                            @click="applyBulk('completed')"
                        >
                            Mark {{ selectedApproved }} Complete
                        </AppButton>
                        <AppButton
                            v-if="transferTargets.length"
                            size="sm"
                            variant="ghost"
                            icon="calendar"
                            @click="startTransfer"
                        >
                            Move to Another Training
                        </AppButton>
                    </div>
                </div>

                <div class="-mx-5 hidden overflow-x-auto sm:-mx-6 md:block print:hidden">
                    <table class="w-full min-w-160 text-left text-sm">
                        <thead class="border-y border-csc-line bg-csc-blue-tint/60 text-xs uppercase">
                            <tr>
                                <th v-if="shows.select" scope="col" class="w-10 py-3 pl-5">
                                    <input
                                        type="checkbox"
                                        class="size-4 rounded border-csc-line text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                        :checked="allSelected"
                                        :indeterminate="selected.size > 0 && !allSelected"
                                        :disabled="!visibleSelectable.length"
                                        :aria-label="allSelected ? 'Clear selection' : 'Select all actionable participants'"
                                        @change="toggleAll"
                                    />
                                </th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-0.5 uppercase hover:text-csc-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                        @click="toggleSort('name')"
                                    >
                                        Participant{{ sortIndicator('name') }}
                                    </button>
                                </th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-0.5 uppercase hover:text-csc-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                        @click="toggleSort('organization')"
                                    >
                                        Agency{{ sortIndicator('organization') }}
                                    </button>
                                </th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-0.5 uppercase hover:text-csc-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                        @click="toggleSort('field_office')"
                                    >
                                        Field Office{{ sortIndicator('field_office') }}
                                    </button>
                                </th>
                                <th scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-0.5 uppercase hover:text-csc-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                        @click="toggleSort('status')"
                                    >
                                        Status{{ sortIndicator('status') }}
                                    </button>
                                </th>
                                <th v-if="shows.document" scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-0.5 uppercase hover:text-csc-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                        @click="toggleSort('supervisory_document.status_label')"
                                    >
                                        Document{{ sortIndicator('supervisory_document.status_label') }}
                                    </button>
                                </th>
                                <th v-if="shows.attendance" scope="col" class="px-5 py-3 font-semibold text-csc-ink-muted">
                                    Attendance
                                    <span
                                        v-if="training.days.length > 1"
                                        class="ml-1 font-normal text-csc-ink-subtle normal-case"
                                    >
                                        · {{ activeDayLabel }}
                                    </span>
                                </th>
                                <!--
                                    Only where there is a panel to evaluate. A
                                    run with no experts assigned has nothing to
                                    chase, and a column of dashes reads as a
                                    fault rather than as "not applicable".
                                -->
                                <th
                                    v-if="shows.evaluation"
                                    scope="col"
                                    class="px-5 py-3 font-semibold text-csc-ink-muted"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-0.5 uppercase hover:text-csc-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                        @click="toggleSort('evaluation.submitted')"
                                    >
                                        Evaluation{{ sortIndicator('evaluation.submitted') }}
                                    </button>
                                </th>
                                <th
                                    v-if="shows.actions"
                                    scope="col"
                                    class="px-5 py-3 text-right font-semibold text-csc-ink-muted"
                                >
                                    Action
                                </th>
                            </tr>
                        </thead>
                        <!--
                             A decision that moves a participant out of the current
                             status filter takes the row off the list, and the whole
                             point of the undo window is that this was a thing that
                             just happened and can still be caught. A row that simply
                             blinks out of existence does not read that way; one that
                             fades and slides does, and it lands in the same beat as
                             the Undo toast. Rows that stay are keyed by id, so
                             nothing animates on an ordinary re-sort or re-filter
                             beyond closing the gap the departed row left.
                        -->
                        <TransitionGroup tag="tbody" name="roster-row" class="divide-y divide-csc-line">
                            <tr
                                v-for="registration in filtered"
                                :key="registration.id"
                                :class="isSelected(registration.id) ? 'bg-csc-blue-tint/50' : ''"
                            >
                                <td v-if="shows.select" class="py-3.5 pl-5">
                                    <input
                                        v-if="['pending', 'approved'].includes(registration.status)"
                                        type="checkbox"
                                        class="size-4 rounded border-csc-line text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                        :checked="isSelected(registration.id)"
                                        :aria-label="`Select ${registration.name}`"
                                        @change="toggle(registration.id)"
                                    />
                                </td>
                                <td class="px-5 py-3.5">
                                    <p class="font-medium text-csc-ink">{{ registration.name }}</p>
                                    <p class="mt-0.5 text-xs text-csc-ink-subtle">{{ registration.email }}</p>
                                </td>
                                <td class="px-5 py-3.5 text-csc-ink-muted">
                                    {{ registration.organization ?? '—' }}
                                    <p v-if="registration.position" class="mt-0.5 text-xs text-csc-ink-subtle">
                                        {{ registration.position }}
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 text-xs text-csc-ink-muted">
                                    {{ registration.field_office ?? '—' }}
                                </td>
                                <td class="px-5 py-3.5">
                                    <AppBadge :status="registration.status" />
                                    <p
                                        v-if="registration.review_remarks"
                                        class="mt-1 max-w-48 text-xs text-csc-ink-subtle"
                                    >
                                        {{ registration.review_remarks }}
                                    </p>
                                </td>
                                <td v-if="shows.document" class="px-5 py-3.5">
                                    <RosterDocument
                                        :document="registration.supervisory_document"
                                        :name="registration.name"
                                        layout="row"
                                        @decide="(decision) => decideDocument(registration, decision)"
                                    />
                                </td>
                                <td v-if="shows.attendance" class="px-5 py-3.5">
                                    <template v-if="isMarkable(registration)">
                                        <div
                                            class="inline-flex overflow-hidden rounded-lg border border-csc-line"
                                            role="group"
                                            :aria-label="`Attendance for ${registration.name} on ${activeDayLabel}`"
                                        >
                                            <button
                                                v-for="option in attendanceChoices"
                                                :key="option.value"
                                                type="button"
                                                class="border-r border-csc-line px-2.5 py-1.5 text-xs font-semibold transition-colors duration-150 last:border-r-0 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-csc-blue"
                                                :class="
                                                    registration.attendance[activeDay]?.status === option.value
                                                        ? option.active
                                                        : `bg-white ${option.idle}`
                                                "
                                                :aria-pressed="registration.attendance[activeDay]?.status === option.value"
                                                :title="option.label"
                                                @click="setAttendance(registration, activeDay, option.value)"
                                            >
                                                <span aria-hidden="true">{{ option.short }}</span>
                                                <span class="sr-only">{{ option.label }}</span>
                                            </button>
                                        </div>

                                        <!--
                                            The running total doubles as the way
                                            into the other days — correcting one
                                            is occasional, so it opens on demand
                                            rather than occupying the column.
                                        -->
                                        <button
                                            v-if="training.duration_days > 1"
                                            type="button"
                                            class="mt-1 block rounded text-2xs text-csc-ink-subtle hover:text-csc-blue hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                            @click="correcting = registration.id"
                                        >
                                            {{ registration.credited_days }} of {{ training.duration_days }} days ·
                                            all days
                                        </button>
                                    </template>

                                    <span v-else class="text-xs text-csc-ink-subtle">—</span>
                                </td>
                                <td v-if="shows.evaluation" class="px-5 py-3.5">
                                    <RosterEvaluation :evaluation="registration.evaluation" layout="row" />
                                </td>
                                <td v-if="shows.actions" class="px-5 py-3.5 text-right">
                                    <RosterActions
                                        :registration="registration"
                                        layout="row"
                                        :can-manage="can.manage_roster"
                                        :can-record-payment="canRecordPayment(registration)"
                                        :cancellable="isCancellable(registration)"
                                        @decide="decide"
                                        @complete="markComplete"
                                        @issue="releaseCertificate"
                                        @record-payment="startPayment"
                                        @cancel="cancelRegistration"
                                    />
                                </td>
                            </tr>
                        </TransitionGroup>
                    </table>
                </div>

                <!--
                    Mobile: the same participants as stacked cards — and the
                    word "same" is load-bearing. Both layouts render the shared
                    Roster* components, so an action added to one is an action
                    in both. They had already drifted apart once.
                -->
                <TransitionGroup tag="ul" name="roster-row" class="space-y-3 md:hidden print:hidden">
                    <li
                        v-for="registration in filtered"
                        :key="registration.id"
                        class="rounded-xl border border-csc-line bg-white p-4"
                        :class="isSelected(registration.id) ? 'border-csc-blue/50 bg-csc-blue-tint/30' : ''"
                    >
                        <div class="flex items-start gap-3">
                            <input
                                v-if="shows.select && ['pending', 'approved'].includes(registration.status)"
                                type="checkbox"
                                class="mt-0.5 size-4 rounded border-csc-line text-csc-blue focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                :checked="isSelected(registration.id)"
                                :aria-label="`Select ${registration.name}`"
                                @change="toggle(registration.id)"
                            />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-sm font-semibold text-csc-ink">{{ registration.name }}</p>
                                    <AppBadge :status="registration.status" />
                                </div>
                                <p class="mt-0.5 text-xs text-csc-ink-subtle">{{ registration.email }}</p>
                                <p class="mt-1 text-xs text-csc-ink-muted">
                                    {{ registration.organization ?? '—' }}
                                    <template v-if="registration.position"> · {{ registration.position }}</template>
                                </p>
                                <p v-if="registration.field_office" class="text-xs text-csc-ink-subtle">
                                    {{ registration.field_office }}
                                </p>

                                <div v-if="shows.document && registration.supervisory_document" class="mt-2">
                                    <RosterDocument
                                        :document="registration.supervisory_document"
                                        :name="registration.name"
                                        layout="card"
                                        @decide="(decision) => decideDocument(registration, decision)"
                                    />
                                </div>

                                <p v-if="registration.review_remarks" class="mt-1 text-xs text-csc-ink-subtle">
                                    {{ registration.review_remarks }}
                                </p>

                                <div v-if="shows.evaluation" class="mt-2">
                                    <RosterEvaluation :evaluation="registration.evaluation" layout="card" />
                                </div>
                            </div>
                        </div>

                        <div v-if="shows.attendance && isMarkable(registration)" class="mt-3 border-t border-csc-line pt-3">
                            <p class="mb-1.5 text-2xs font-semibold tracking-wide text-csc-ink-subtle uppercase">
                                Attendance · {{ activeDayLabel }}
                            </p>
                            <div
                                class="inline-flex overflow-hidden rounded-lg border border-csc-line"
                                role="group"
                                :aria-label="`Attendance for ${registration.name} on ${activeDayLabel}`"
                            >
                                <button
                                    v-for="option in attendanceChoices"
                                    :key="option.value"
                                    type="button"
                                    class="border-r border-csc-line px-3 py-2 text-xs font-semibold transition-colors duration-150 last:border-r-0 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-csc-blue"
                                    :class="
                                        registration.attendance[activeDay]?.status === option.value
                                            ? option.active
                                            : `bg-white ${option.idle}`
                                    "
                                    :aria-pressed="registration.attendance[activeDay]?.status === option.value"
                                    :title="option.label"
                                    @click="setAttendance(registration, activeDay, option.value)"
                                >
                                    <span aria-hidden="true">{{ option.short }}</span>
                                    <span class="sr-only">{{ option.label }}</span>
                                </button>
                            </div>
                            <button
                                v-if="training.duration_days > 1"
                                type="button"
                                class="mt-2 block rounded text-2xs text-csc-ink-subtle hover:text-csc-blue hover:underline"
                                @click="correcting = registration.id"
                            >
                                {{ registration.credited_days }} of {{ training.duration_days }} days · all days
                            </button>
                        </div>

                        <div v-if="shows.actions" class="mt-3 border-t border-csc-line pt-3">
                            <RosterActions
                                :registration="registration"
                                layout="card"
                                :can-manage="can.manage_roster"
                                :can-record-payment="canRecordPayment(registration)"
                                :cancellable="isCancellable(registration)"
                                @decide="decide"
                                @complete="markComplete"
                                @issue="releaseCertificate"
                                @record-payment="startPayment"
                                @cancel="cancelRegistration"
                            />
                        </div>
                    </li>
                </TransitionGroup>
                </template>
                </template>
            </AppCard>

            <!--
                What this run earned. Every figure is summed from the payment
                rows, each of which froze its own gross and discount when it was
                taken — so repricing the course later cannot restate what was
                collected.
            -->
            <AppCard
                v-if="tab === 'participants' && training.payment_required && can.record_payment"
                title="Revenue"
                subtitle="Verified payments only. A pending upload is a claim, not money."
                collapsible
                remember-as="roster.revenue"
                class="print:hidden"
            >
                <template #action>
                    <AppButton
                        :href="revenueExportUrl"
                        external
                        size="sm"
                        variant="ghost"
                        icon="download"
                        :loading="downloading === revenueExportUrl"
                        @click.prevent="start(revenueExportUrl)"
                    >
                        Export
                    </AppButton>
                </template>

                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <p class="text-xs text-csc-ink-subtle">Assessed</p>
                        <p class="mt-0.5 text-lg font-semibold text-csc-ink">₱{{ money(revenue.gross ?? 0) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-csc-ink-subtle">PRIME-HRM Discount</p>
                        <p class="mt-0.5 text-lg font-semibold text-warning">
                            − ₱{{ money(revenue.discount ?? 0) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-csc-ink-subtle">Collected</p>
                        <p class="mt-0.5 text-lg font-semibold text-csc-blue">
                            ₱{{ money(revenue.collected ?? 0) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-csc-ink-subtle">On Promissory Note</p>
                        <p class="mt-0.5 text-lg font-semibold text-csc-ink-muted">
                            ₱{{ money(revenue.promissory ?? 0) }}
                        </p>
                        <p v-if="revenue.promissory_count" class="text-2xs text-csc-ink-subtle">
                            {{ revenue.promissory_count }} outstanding
                        </p>
                    </div>
                </div>

                <!-- Which participants, by name — the question the office asks. -->
                <div v-if="revenue.discounted?.length" class="mt-5 border-t border-csc-line pt-4">
                    <p class="text-xs font-semibold text-csc-ink">
                        PRIME-HRM discount granted to {{ revenue.discounted_count }} participant(s)
                    </p>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-csc-line text-xs uppercase">
                                <tr>
                                    <th scope="col" class="py-2 pr-4 font-semibold text-csc-ink-muted">Participant</th>
                                    <th scope="col" class="py-2 pr-4 font-semibold text-csc-ink-muted">OR No.</th>
                                    <th scope="col" class="py-2 pr-4 text-right font-semibold text-csc-ink-muted">
                                        Full Fee
                                    </th>
                                    <th scope="col" class="py-2 pr-4 text-right font-semibold text-csc-ink-muted">
                                        Discount
                                    </th>
                                    <th scope="col" class="py-2 text-right font-semibold text-csc-ink-muted">Paid</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-csc-line">
                                <tr v-for="row in revenue.discounted" :key="row.id">
                                    <td class="py-2.5 pr-4 text-csc-ink-muted">{{ row.participant }}</td>
                                    <td class="py-2.5 pr-4 font-mono text-xs text-csc-ink-subtle">
                                        {{ row.or_number ?? '—' }}
                                    </td>
                                    <td class="py-2.5 pr-4 text-right text-csc-ink-muted">₱{{ money(row.gross) }}</td>
                                    <td class="py-2.5 pr-4 text-right font-medium text-warning">
                                        − ₱{{ money(row.discount) }}
                                    </td>
                                    <td class="py-2.5 text-right font-medium text-csc-ink">₱{{ money(row.net) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </AppCard>

            <!--
                Who is coming, by field office, and what each still owes. The
                office that recruited a participant is the one HRD chases for an
                outstanding fee, so the split is what makes chasing possible.
            -->
            <!--
                Its own tab, because it answers a different question from the
                roster: not "who is coming" but "which office do we chase". It
                opens expanded — a tab someone deliberately clicked has nothing
                left to fold away.
            -->
            <AppCard
                v-if="tab === 'offices' && officeBreakdown.length > 1"
                title="By Field Office"
                subtitle="Participants counts everyone still holding a place; withdrawals have their own column. The office that recruited a participant is the one HRD chases for a fee still owing."
                class="print:hidden"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-csc-line text-xs uppercase">
                            <tr>
                                <th scope="col" class="py-2 pr-4 font-semibold text-csc-ink-muted">Field Office</th>
                                <th scope="col" class="py-2 pr-4 text-right font-semibold text-csc-ink-muted">
                                    Participants
                                </th>
                                <!-- Where the office's own work is: a decision
                                     nobody has taken, and the places it gave
                                     back. -->
                                <th scope="col" class="py-2 pr-4 text-right font-semibold text-csc-ink-muted">
                                    Pending
                                </th>
                                <th scope="col" class="py-2 pr-4 text-right font-semibold text-csc-ink-muted">
                                    Cancelled
                                </th>
                                <!-- No fee to have paid. Without this column the
                                     whole office read as Paid on a free run,
                                     which is a bill it was never sent. -->
                                <th
                                    v-if="!training.payment_required"
                                    scope="col"
                                    class="py-2 pr-4 text-right font-semibold text-csc-ink-muted"
                                >
                                    Free
                                </th>
                                <template v-else>
                                    <th scope="col" class="py-2 pr-4 text-right font-semibold text-csc-ink-muted">
                                        Paid
                                    </th>
                                    <!-- Money promised but not received. Kept as
                                         its own column, as v1 had it: folded into
                                         Paid it reads as an office that owes
                                         nothing. -->
                                    <th scope="col" class="py-2 pr-4 text-right font-semibold text-csc-ink-muted">
                                        On Note
                                    </th>
                                    <!-- Was "Outstanding", which in this office
                                         also means a pending request. This column
                                         has only ever been about money. -->
                                    <th scope="col" class="py-2 text-right font-semibold text-csc-ink-muted">
                                        Fee Unpaid
                                    </th>
                                </template>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-csc-line">
                            <tr v-for="office in officeBreakdown" :key="office.label">
                                <td class="py-2.5 pr-4 text-csc-ink-muted">{{ office.label }}</td>
                                <td class="py-2.5 pr-4 text-right font-medium text-csc-ink">{{ office.count }}</td>
                                <td
                                    class="py-2.5 pr-4 text-right font-medium"
                                    :class="office.pending ? 'text-warning' : 'text-csc-ink-subtle'"
                                >
                                    {{ office.pending }}
                                </td>
                                <td
                                    class="py-2.5 pr-4 text-right font-medium"
                                    :class="office.cancelled ? 'text-danger' : 'text-csc-ink-subtle'"
                                >
                                    {{ office.cancelled }}
                                </td>
                                <td
                                    v-if="!training.payment_required"
                                    class="py-2.5 pr-4 text-right text-csc-ink-muted"
                                >
                                    {{ office.free }}
                                </td>
                                <template v-else>
                                    <td class="py-2.5 pr-4 text-right text-csc-ink-muted">{{ office.settled }}</td>
                                    <td
                                        class="py-2.5 pr-4 text-right font-medium"
                                        :class="office.promissory ? 'text-info' : 'text-csc-ink-subtle'"
                                    >
                                        {{ office.promissory }}
                                    </td>
                                    <td
                                        class="py-2.5 text-right font-medium"
                                        :class="office.unpaid ? 'text-warning' : 'text-csc-ink-subtle'"
                                    >
                                        {{ office.unpaid }}
                                    </td>
                                </template>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <!--
                The printed attendance sheet. Everything interactive above is
                print:hidden; this section only exists in the @media print
                rendering and reflects the filters currently applied.
            -->
            <section class="hidden print:block">
                <header class="mb-6 text-center">
                    <p class="text-sm font-semibold">
                        Republic of the Philippines · Civil Service Commission · Regional Office
                    </p>
                    <h1 class="mt-2 text-xl font-bold">{{ training.title }}</h1>
                    <p class="mt-1 text-sm">
                        {{ trainingDateRange }} · {{ training.venue }} · {{ training.status_label }}
                    </p>
                </header>

                <table class="w-full border-collapse text-xs">
                    <thead>
                        <tr>
                            <th scope="col" class="border border-black px-2 py-1.5 text-left font-semibold">#</th>
                            <th scope="col" class="border border-black px-2 py-1.5 text-left font-semibold">Participant</th>
                            <th scope="col" class="border border-black px-2 py-1.5 text-left font-semibold">Agency</th>
                            <th scope="col" class="border border-black px-2 py-1.5 text-left font-semibold">Field Office</th>
                            <th
                                v-for="day in training.days"
                                :key="day.day"
                                scope="col"
                                class="border border-black px-2 py-1.5 text-center font-semibold"
                            >
                                {{ day.label }}
                            </th>
                            <th scope="col" class="border border-black px-2 py-1.5 text-left font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(registration, index) in filtered" :key="registration.id">
                            <td class="border border-black px-2 py-1.5">{{ index + 1 }}</td>
                            <td class="border border-black px-2 py-1.5">{{ registration.name }}</td>
                            <td class="border border-black px-2 py-1.5">{{ registration.organization ?? '—' }}</td>
                            <td class="border border-black px-2 py-1.5">{{ registration.field_office ?? '—' }}</td>
                            <td
                                v-for="day in training.days"
                                :key="day.day"
                                class="border border-black px-2 py-1.5 text-center"
                            >
                                {{ registration.attendance[day.day]?.status_label ?? '—' }}
                            </td>
                            <td class="border border-black px-2 py-1.5">{{ registration.status_label }}</td>
                        </tr>
                    </tbody>
                </table>

                <p class="mt-4 text-right text-[10px]">Printed {{ printedAt }}</p>
            </section>
        </div>

        <AppPromptModal
            :open="prompt !== null"
            :title="prompt?.title ?? ''"
            :description="prompt?.description"
            :label="prompt?.label"
            :hint="prompt?.hint"
            :confirm-label="prompt?.confirmLabel"
            :min-length="prompt?.minLength ?? 1"
            :processing="promptBusy"
            @confirm="confirmPrompt"
            @close="closePrompt"
        />

        <AppModal
            :open="transferring"
            title="Move to another training"
            :subtitle="`${selected.size} participant(s) selected. Their registration date, attendance and any payment move with them.`"
            @close="transferring = false"
        >
            <form class="space-y-4" @submit.prevent="submitTransfer">
                <AppSelect
                    v-model="transferForm.target_training_id"
                    label="Move to"
                    :options="transferTargets"
                    placeholder="Choose a training"
                    :error="transferForm.errors.target_training_id"
                    required
                />

                <AppTextarea
                    v-model="transferForm.reason"
                    label="Why are they being moved?"
                    hint="Shown to every participant in the notification they receive."
                    :error="transferForm.errors.reason"
                    required
                />

                <p v-if="transferForm.errors.ids" class="text-xs font-medium text-csc-red-ink">
                    {{ transferForm.errors.ids }}
                </p>
                <p v-if="transferForm.errors.transfer" class="text-xs font-medium text-csc-red-ink">
                    {{ transferForm.errors.transfer }}
                </p>

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="transferring = false">Cancel</AppButton>
                    <AppButton type="submit" :loading="transferForm.processing">
                        Move {{ selected.size }} Participant(s)
                    </AppButton>
                </div>
            </form>
        </AppModal>

        <!--
            The full day-by-day grid for one participant. This is the axis the
            column used to be laid out on — one person, every day — kept for the
            case it actually serves: correcting a day that has already passed.
        -->
        <AppModal
            :open="correctingRow !== null"
            title="Attendance across every day"
            :subtitle="
                correctingRow
                    ? `${correctingRow.name} — ${correctingRow.credited_days} of ${training.duration_days} days credited.`
                    : ''
            "
            @close="correcting = null"
        >
            <ul v-if="correctingRow" class="divide-y divide-csc-line">
                <li
                    v-for="day in training.days"
                    :key="day.day"
                    class="flex flex-wrap items-center justify-between gap-3 py-3"
                >
                    <div>
                        <p class="text-sm font-medium text-csc-ink">
                            Day {{ day.day }}
                            <span class="ml-1 text-xs font-normal text-csc-ink-subtle">{{ day.label }}</span>
                        </p>
                        <p v-if="day.is_today" class="text-2xs font-semibold text-csc-blue uppercase">Today</p>
                    </div>

                    <div class="inline-flex overflow-hidden rounded-lg border border-csc-line" role="group">
                        <button
                            v-for="option in attendanceChoices"
                            :key="option.value"
                            type="button"
                            class="border-r border-csc-line px-2.5 py-1.5 text-xs font-semibold transition-colors duration-150 last:border-r-0 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-csc-blue"
                            :class="
                                correctingRow.attendance[day.day]?.status === option.value
                                    ? option.active
                                    : `bg-white ${option.idle}`
                            "
                            :aria-pressed="correctingRow.attendance[day.day]?.status === option.value"
                            :title="option.label"
                            @click="setAttendance(correctingRow, day.day, option.value)"
                        >
                            <span aria-hidden="true">{{ option.short }}</span>
                            <span class="sr-only">{{ option.label }}</span>
                        </button>
                    </div>
                </li>
            </ul>

            <template #footer>
                <AppButton size="sm" variant="ghost" @click="correcting = null">Done</AppButton>
            </template>
        </AppModal>

        <AppModal
            :open="paying !== null"
            title="Record a payment"
            :subtitle="
                paying
                    ? `Money taken over the counter from ${paying.name}. This is verified on entry — there is nothing further to review.`
                    : ''
            "
            @close="paying = null"
        >
            <form class="space-y-4" @submit.prevent="submitPayment">
                <!--
                    The PRIME-HRM incentive. Ticking it hands the arithmetic to
                    the server; the breakdown below is a preview of what will be
                    recorded, not the figure that gets posted.
                -->
                <div class="rounded-lg border border-csc-line bg-csc-blue-tint/30 p-3">
                    <label class="flex items-start gap-3 text-sm text-csc-ink">
                        <input
                            v-model="paymentForm.prime_hrm_discount"
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0 rounded border-csc-line accent-csc-blue"
                        />
                        <span class="leading-relaxed">
                            PRIME-HRM 20% discount — the participant's agency is accredited and the
                            incentive applies to this fee.
                        </span>
                    </label>

                    <dl
                        v-if="paymentForm.prime_hrm_discount"
                        class="mt-3 space-y-1 border-t border-csc-line pt-3 text-xs"
                    >
                        <div class="flex justify-between">
                            <dt class="text-csc-ink-subtle">Full fee</dt>
                            <dd class="font-medium text-csc-ink">₱{{ money(primeHrm.gross) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-csc-ink-subtle">Discount (20%)</dt>
                            <dd class="font-medium text-warning">− ₱{{ money(primeHrm.discount) }}</dd>
                        </div>
                        <div class="flex justify-between border-t border-csc-line pt-1">
                            <dt class="font-medium text-csc-ink">Payable</dt>
                            <dd class="font-semibold text-csc-blue">₱{{ money(primeHrm.net) }}</dd>
                        </div>
                    </dl>

                    <p v-if="paymentForm.errors.prime_hrm_discount" class="mt-2 text-xs font-medium text-csc-red-ink">
                        {{ paymentForm.errors.prime_hrm_discount }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput
                        v-model="paymentForm.amount"
                        label="Amount"
                        type="number"
                        step="0.01"
                        min="0.01"
                        :readonly="paymentForm.prime_hrm_discount"
                        :hint="paymentForm.prime_hrm_discount ? 'Set by the discount.' : null"
                        :error="paymentForm.errors.amount"
                        required
                    />
                    <AppSelect
                        v-model="paymentForm.payment_method"
                        label="Payment Method"
                        :options="paymentMethods"
                        :error="paymentForm.errors.payment_method"
                        required
                    />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput
                        v-model="paymentForm.payment_date"
                        label="Date Received"
                        type="date"
                        :error="paymentForm.errors.payment_date"
                        required
                    />
                    <!--
                        Optional: the OR beside it is this counter's receipt.
                        The transfer or cheque number is finance's cross-
                        reference and is often still on paper held by the
                        agency, so it is recorded when the officer has it.
                    -->
                    <AppInput
                        v-if="methodCollectsReference"
                        v-model="paymentForm.reference_number"
                        label="Reference Number (optional)"
                        hint="The transfer or cheque number, if you have it."
                        :error="paymentForm.errors.reference_number"
                    />
                </div>

                <!--
                    A promissory note has no receipt, because no money arrived.
                    Hiding the OR block rather than disabling it keeps the form
                    honest about what is being recorded.
                -->
                <div v-if="methodIsSettlement" class="grid gap-4 sm:grid-cols-2">
                    <AppInput
                        v-model="paymentForm.or_number"
                        label="OR Number"
                        hint="What finance reconciles on."
                        :error="paymentForm.errors.or_number"
                        required
                    />
                    <AppInput
                        v-model="paymentForm.or_date"
                        label="OR Date"
                        type="date"
                        :error="paymentForm.errors.or_date"
                    />
                </div>

                <AppSelect
                    v-if="methodIsSettlement"
                    v-model="paymentForm.collecting_officer_id"
                    label="Collecting Officer"
                    :options="collectingOfficers"
                    placeholder="You — the officer recording this"
                    hint="Only change this when entering money another office collected."
                    :error="paymentForm.errors.collecting_officer_id"
                />

                <AppTextarea
                    v-model="paymentForm.remarks"
                    label="Remarks"
                    :rows="2"
                    hint="Optional. Kept on the payment record."
                    :error="paymentForm.errors.remarks"
                />

                <p v-if="paymentForm.errors.payment" class="text-xs font-medium text-csc-red-ink">
                    {{ paymentForm.errors.payment }}
                </p>

                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="ghost" @click="paying = null">Cancel</AppButton>
                    <AppButton type="submit" :loading="paymentForm.processing">Record Payment</AppButton>
                </div>
            </form>
        </AppModal>
    </AuthenticatedLayout>
</template>
