<script setup>
/**
 * The participant's guide.
 *
 * Written for somebody who is not confident with software and is here because
 * something did not work — which sets every design decision on the page. The
 * search filters *sections*, not words, so a failed search never leaves an
 * empty screen; the sections are collapsible but a matched one opens itself, so
 * finding an answer never costs a second click; and the contact card is at the
 * end of every route through the page, because the honest last step of any
 * guide is "ask a person".
 *
 * Deep-linkable: every section is an anchor (`/help#payments`), so a screen
 * that confuses somebody can send them to the paragraph about it rather than to
 * the top of a long page. Landing on a hash opens that section and scrolls to
 * it.
 *
 * The facts come from the server (HelpController) rather than being typed here
 * — file limits, the password floor, the office's own contact details — so the
 * guide cannot drift from what the application enforces.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppEmptyState from '@/Components/AppEmptyState.vue';
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    uploadLimits: { type: Array, required: true },
    statuses: { type: Array, required: true },
    passwordMinimum: { type: Number, required: true },
    hasPassword: { type: Boolean, default: true },
});

const page = usePage();
const office = computed(() => page.props.office ?? {});

/*
 * The guide's contents.
 *
 * Data rather than markup, for the reason the sidebar's nav is data: the search
 * has to read every section's text to filter on it, and a template cannot be
 * searched. `keywords` carries the words people actually type that do not
 * appear in the prose — "OR" for a receipt, "cancel" for a withdrawal.
 */
const sections = [
    {
        id: 'getting-started',
        title: 'Getting started',
        icon: 'home',
        summary: 'Signing in for the first time, and what to do next.',
        keywords: ['login', 'log in', 'sign in', 'google', 'first time', 'new account'],
        steps: [
            {
                heading: 'Sign in',
                body: 'Go to the sign-in page and enter the email address and password you registered with. If you created your account with Google, use the "Continue with Google" button instead — it must be the same Google account each time.',
            },
            {
                heading: 'Complete your profile',
                body: 'The first time you sign in you are asked to complete your profile. You cannot browse or register for trainings until this is done, because your certificate is printed from these details.',
            },
            {
                heading: 'Start from your dashboard',
                body: 'The dashboard shows your next training, anything waiting on you, and what you have done recently. If something needs your attention it appears there first.',
            },
        ],
    },
    {
        id: 'profile',
        title: 'Your profile',
        icon: 'user',
        summary: 'What CSC needs from you, and why it has to be right.',
        keywords: ['employer', 'agency', 'office', 'position', 'photo', 'details', 'name'],
        steps: [
            {
                heading: 'Keep your name exactly as it should be printed',
                body: 'Your certificate is generated from your profile, and a certificate that has been issued is not re-rendered when you change your details later. Check your name before you complete a training, not after.',
            },
            {
                heading: 'Choose your employer from the list',
                body: 'Start typing and pick your agency from the suggestions. Choosing from the list also fills in your sector and field office, so you do not have to know them. Only type your employer manually if it genuinely is not on the list.',
            },
            {
                heading: 'Changing your email address',
                body: 'You can change the email on your account from your profile. The change only takes effect when you open the confirmation link sent to the *new* address, and your old address is always told. Nothing changes until you confirm.',
            },
        ],
    },
    {
        id: 'registering',
        title: 'Finding and joining a training',
        icon: 'calendar',
        summary: 'Browsing the catalogue, registering, and what your status means.',
        keywords: ['register', 'enrol', 'enroll', 'apply', 'application', 'slot', 'waitlist', 'withdraw', 'cancel'],
        steps: [
            {
                heading: 'Find a training',
                body: 'Browse Trainings lists everything open. The Calendar shows the same runs by date, which is easier if you are working around your own schedule.',
            },
            {
                heading: 'Register',
                body: 'Open the training and use the register button. Some courses ask for a supporting document at this point — a supervisory course needs proof of your designation.',
            },
            {
                heading: 'Check your status',
                body: 'My Registrations lists everything you have joined, with a status badge on each. The statuses are explained below.',
            },
            {
                heading: 'If you can no longer attend',
                body: 'Use "Request withdrawal" on the registration. It is a request rather than an immediate cancellation, because CSC caters and prints against a confirmed head count — your slot is held until staff review it.',
            },
        ],
        statuses: true,
    },
    {
        id: 'payments',
        title: 'Paying a training fee',
        icon: 'card',
        summary: 'Depositing the fee, sending proof, and getting a receipt.',
        keywords: ['fee', 'pay', 'payment', 'deposit', 'bank', 'proof', 'receipt', 'refund', 'or', 'official receipt', 'gcash'],
        steps: [
            {
                heading: 'Check what you owe',
                body: 'The Payments page shows every fee still owed, what is waiting on CSC, and what has been confirmed. The deposit account to pay into is shown on that page — always read it there rather than reusing an account number from an old message.',
            },
            {
                heading: 'Send your proof of payment',
                body: 'After depositing, use Record Payment and attach a photo or PDF of the deposit slip. Make sure the amount, date and reference number are all readable — a blurry or cropped photo is the most common reason a payment sits unverified.',
            },
            {
                heading: 'Wait for verification',
                body: 'Your payment shows as pending until a CSC collecting officer matches it against the bank statement. You do not need to do anything while it is pending. If there is a problem, the reason appears on the payment itself.',
            },
            {
                /*
                 * Names the sidebar's word. That label used to read "Physical
                 * OR" and this line had to teach the abbreviation; the row is
                 * now called Official Receipts, so the guide simply says it.
                 * If the label moves again, this moves with it — a guide
                 * pointing at a menu item that does not exist is worse than one
                 * using an awkward word.
                 */
                heading: 'If you need the printed receipt',
                body: 'CSC issues an official receipt for every verified payment. If you need the printed copy couriered to you, ask for it on the Official Receipts page and attach proof that you have paid the courier fee.',
            },
        ],
    },
    {
        id: 'documents',
        title: 'Uploading documents',
        icon: 'upload',
        summary: 'File types, size limits, and what to do when an upload is refused.',
        keywords: ['upload', 'file', 'attach', 'document', 'pdf', 'photo', 'size', 'too large', 'rejected'],
        steps: [
            {
                heading: 'Check the size and type first',
                body: 'Each kind of document has its own limit, listed below. A photo taken on a recent phone is often larger than the limit — if yours is refused, that is usually why.',
            },
            {
                heading: 'If a document is returned',
                body: 'A document CSC cannot accept is marked with the reason. Where the workflow still allows it you will see a Re-upload button on the same row; use that rather than registering again.',
            },
        ],
        uploads: true,
    },
    {
        id: 'attending',
        title: 'At the training',
        icon: 'qr',
        summary: 'Your QR code, and how attendance is taken.',
        keywords: ['qr', 'code', 'attendance', 'check in', 'checkin', 'scan', 'venue', 'door'],
        steps: [
            {
                heading: 'Bring your QR code',
                body: 'My QR Code is your personal check-in code. It is the same code at every CSC event, and it works without an internet connection once the page has loaded — so open it before you travel if signal at the venue is poor.',
            },
            {
                heading: 'Save or print it',
                body: 'Use "Save or Print My Code" to keep a copy on your phone or on paper. Do not share screenshots of it: it identifies you at the door.',
            },
            {
                heading: 'If your code will not scan',
                body: 'Staff at the desk can check you in manually. If you think someone else has a copy of your code, issue a new one from the same page — the old one stops working immediately.',
            },
        ],
    },
    {
        id: 'after',
        title: 'After the training',
        icon: 'certificate',
        summary: 'Evaluations, outputs, and collecting your certificate.',
        keywords: ['evaluation', 'evaluate', 'feedback', 'certificate', 'output', 'download', 'verify'],
        steps: [
            {
                heading: 'Fill in the session evaluation',
                body: 'At the end of each training day you are asked to rate the experts who delivered it. Evaluations stay open after the day ends, so one you missed can still be answered. Session Evaluations lists what is still waiting on you.',
            },
            {
                heading: 'Submit your output, if the course requires one',
                body: 'A supervisory course is not finished when the sessions are — you owe a written output, which CSC reviews before the course counts as complete. Submit it from the registration in My Registrations.',
            },
            {
                heading: 'Download your certificate',
                body: 'Certificates appear on the Certificates page once CSC releases them. Each carries a certificate number and a public verification link you can give to an employer who wants to check it.',
            },
        ],
    },
    {
        id: 'account',
        title: 'Your account and security',
        icon: 'lock',
        summary: 'Passwords, signing out, and keeping the account yours.',
        keywords: ['password', 'security', 'forgot', 'reset', 'sign out', 'logout', 'hacked'],
        steps: [
            {
                heading: 'Password requirements',
                body: `A password must be at least ${props.passwordMinimum} characters long and contain both letters and numbers. It is also checked against known breached passwords, so a common one is refused even if it is long enough.`,
            },
            {
                heading: 'Changing your password',
                body: 'Use the account menu at the top right. Changing your password signs you out on every other device but keeps you signed in here, so a password change is also how you end a session you left open somewhere else.',
            },
            {
                heading: 'If you forget it',
                body: 'Use "Forgot password?" on the sign-in page. The reset link goes to your registered email address and expires, so use it promptly and request a fresh one if it has gone stale.',
            },
        ],
    },
    {
        id: 'troubleshooting',
        title: 'Common problems',
        icon: 'warning',
        summary: 'The things that usually go wrong, and what to do about each.',
        keywords: ['problem', 'error', 'broken', 'not working', 'stuck', 'help', 'cannot', 'wrong'],
        problems: [
            {
                q: 'I cannot sign in.',
                a: 'Check the email address first — it must be the one you registered with. If you created your account with Google, use the Google button rather than a password. After several failed attempts sign-in is locked briefly; wait a few minutes rather than retrying immediately.',
            },
            {
                q: 'I never received the password reset email.',
                a: 'Check your spam or junk folder, and confirm you used the address your account is registered under. Government mail systems sometimes hold messages for a few minutes.',
            },
            {
                q: 'My upload keeps failing.',
                a: 'Almost always the file is too large or the wrong type — see the table under "Uploading documents". A phone photo can exceed the limit; taking the picture at a lower resolution, or saving it as a PDF, usually solves it.',
            },
            {
                q: 'My payment still says pending.',
                a: 'That is normal until a collecting officer matches it against the bank statement, which is not instant. You do not need to resend it. If something is wrong, the reason appears on the payment itself.',
            },
            {
                q: 'My name or agency is wrong on my record.',
                a: 'Correct it in My Profile. Do it before your training is completed — a certificate is generated once, at release, and is not re-rendered afterwards.',
            },
            {
                q: 'I registered but there is no QR code on the training.',
                a: 'Your check-in code is released once the registration is approved, and where there is a fee, once payment has been verified. My QR Code always shows your standing code; the door checks whether this particular registration is cleared.',
            },
            {
                q: 'A page is not loading properly.',
                a: 'Reload the page first. If it persists, sign out and back in — and if the site is closed for maintenance you will see a notice saying so rather than an error.',
            },
        ],
    },
];

/*
 * Search over the section, not the page.
 *
 * Matching whole sections rather than highlighting words is what keeps this
 * usable for the reader it is written for: a hit gives them a heading they can
 * recognise and read in order, instead of a scattering of highlighted fragments
 * they have to reassemble. The haystack is built once per section and includes
 * the keywords, so "OR" finds the receipt section that never uses the phrase.
 */
const query = ref('');

const haystacks = sections.map((section) =>
    [
        section.title,
        section.summary,
        ...(section.keywords ?? []),
        ...(section.steps ?? []).flatMap((s) => [s.heading, s.body]),
        ...(section.problems ?? []).flatMap((p) => [p.q, p.a]),
    ]
        .join(' ')
        .toLowerCase()
);

const matches = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return sections;

    // Every word has to appear somewhere in the section, so a two-word query
    // narrows rather than widens.
    const words = q.split(/\s+/);

    return sections.filter((section, i) => words.every((w) => haystacks[i].includes(w)));
});

const searching = computed(() => query.value.trim().length > 0);

/*
 * Which sections are open.
 *
 * Everything starts open. A guide that starts as eight closed headings makes
 * the reader guess which one holds their answer before they can read any of it,
 * and the whole point of the page is that they do not know. Collapsing is there
 * for the reader who has found their section and wants the rest out of the way.
 */
const open = ref(new Set(sections.map((s) => s.id)));

const isOpen = (id) => open.value.has(id);

const toggle = (id) => {
    const next = new Set(open.value);
    next.has(id) ? next.delete(id) : next.add(id);
    open.value = next;
};

const setAll = (openThem) => {
    open.value = openThem ? new Set(sections.map((s) => s.id)) : new Set();
};

// A searched-for section is opened, or the match would be a heading with its
// answer folded away behind it.
watch(searching, (on) => {
    if (on) open.value = new Set(sections.map((s) => s.id));
});

/*
 * Deep links. /help#payments opens that section and scrolls to it, so a page
 * elsewhere in the app can point at the paragraph about itself.
 *
 * Two frames, not one, and the reason is the same one useChartMount.ts records:
 * Vue applies the state change and the browser can collapse it into the current
 * frame, so a measurement taken in the first rAF is of a layout that has not
 * settled. The section has to be open *and* laid out before there is a position
 * to scroll to.
 *
 * `smooth` is deliberate on a click and deliberately absent on arrival.
 * app.css sets `scroll-behavior: smooth` globally, so a chip press animates
 * without asking. On mount it actively broke the feature: a smooth scroll of
 * two thousand pixels, begun while the page was still settling, was cancelled
 * before it arrived and left the reader at the top of a long page they had
 * asked to be dropped into the middle of. Somebody following a deep link has
 * already said where they want to be; the journey is not the point.
 */
/*
 * Scroll to a section, and keep scrolling until it has actually arrived.
 *
 * The naive version — open the section, wait a frame, scrollIntoView — lands in
 * the wrong place, and it took three attempts to stop guessing at why. The
 * shell wraps every page in a fade-and-rise (.page-enter, 0.18s) during which
 * the content is translated, so a position measured mid-animation is not the
 * position the element ends up at; the page is also 5,000px of accordions whose
 * heights settle as fonts load.
 *
 * Awaiting `document.getAnimations()` looked like the precise answer and was
 * not reliable in practice, so this does the honest thing instead: scroll, then
 * check, and correct if the target is not where it should be. `verify` runs a
 * few frames apart and costs nothing when the first attempt was already right,
 * which is the common case. No magic sleep, and nothing coupled to the CSS.
 */
const goTo = async (id, { animate = true } = {}) => {
    open.value = new Set([...open.value, id]);

    const frame = () => new Promise((resolve) => requestAnimationFrame(resolve));

    // Vue has to render the opened section before it can be measured, and one
    // frame is not always enough for a panel that was closed.
    await frame();
    await frame();

    const target = document.getElementById(id);
    if (!target) return;

    // Where the section should sit once it has landed: its own scroll margin,
    // which is what clears the header and the search bar above it.
    const wanted = parseFloat(getComputedStyle(target).scrollMarginTop) || 0;

    target.scrollIntoView({ behavior: animate ? 'smooth' : 'instant', block: 'start' });

    /*
     * Then keep checking for a second, correcting whenever the target is not
     * where it should be.
     *
     * The window is a second rather than a couple of frames because the page is
     * still growing while the scroll happens: 5,000px of accordions whose
     * heights settle as fonts load, under a shell that fades and rises on every
     * navigation. Scrolling into a document that has not finished laying out
     * clamps to whatever the height is at that instant, which on a fresh load
     * is how a deep link lands at the top of the page instead of the section it
     * named.
     *
     * The loop stops the moment it has arrived, so the common case costs one
     * check. A target that cannot reach its mark — the last section, with less
     * than a screen of content below it — simply runs out the attempts against
     * the bottom of the page, which is the right answer and not worth
     * distinguishing.
     */
    for (let attempt = 0; attempt < 10; attempt++) {
        await new Promise((resolve) => setTimeout(resolve, 100));

        const top = Math.round(target.getBoundingClientRect().top);

        if (Math.abs(top - wanted) < 4) return;

        /*
         * The last section sits less than a screen from the end of the
         * document, so it can never reach the mark — the page runs out first.
         * That is the right answer rather than a failure, and the test for it
         * is being at maximum scroll, not "the number stopped changing": an
         * early version used the latter and quietly gave up the first time a
         * correction had not landed yet, which is the same reading a page still
         * laying itself out produces.
         */
        const maxScroll = document.documentElement.scrollHeight - window.innerHeight;

        if (window.scrollY >= maxScroll - 1) return;

        target.scrollIntoView({ behavior: 'instant', block: 'start' });
    }
};

/*
 * Which section the reader is currently in, for the index in the aside.
 *
 * An IntersectionObserver rather than a scroll handler: the browser reports the
 * crossings itself, so nothing runs on the frames where nothing changed, which
 * on a 5,000px page being scrolled is most of them.
 *
 * The top margin is negative by the height of everything pinned above the
 * content — the shell's h-16 header plus this page's own search bar — so a
 * section counts as "current" when its heading clears those bars rather than
 * when it technically enters the viewport underneath them. The bottom margin
 * keeps the last section from taking over the moment it appears at the foot of
 * a tall screen.
 */
const activeSection = ref(sections[0].id);

let observer = null;

const watchSections = () => {
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

    for (const section of sections) {
        const el = document.getElementById(section.id);
        if (el) observer.observe(el);
    }
};

onMounted(() => {
    watchSections();

    const hash = window.location.hash.replace('#', '');
    if (hash && sections.some((s) => s.id === hash)) goTo(hash, { animate: false });
});

onBeforeUnmount(() => observer?.disconnect());

// The contact card renders only what the office has actually configured — the
// phone number has no default in config/office.php and is routinely absent.
const contacts = computed(() =>
    [
        office.value.email
            ? { icon: 'envelope', label: 'Email', value: office.value.email, href: `mailto:${office.value.email}` }
            : null,
        office.value.phone
            ? { icon: 'phone', label: 'Telephone', value: office.value.phone, href: `tel:${office.value.phone}` }
            : null,
        office.value.address
            ? { icon: 'map-pin', label: 'Office', value: office.value.address, href: null }
            : null,
    ].filter(Boolean)
);
</script>

<template>
    <Head title="Help & Guide" />

    <AuthenticatedLayout title="Help & Guide" current="help">
        <div class="mx-auto max-w-6xl">
            <p class="text-sm leading-relaxed text-csc-ink-muted">
                How to use CSC TIMS, from signing in to downloading your certificate. If you cannot find
                what you need here, {{ office.short_name ?? 'the office' }} is happy to help — the contact
                details are at the bottom of this page.
            </p>

            <!--
                Two columns from lg up, one below it.

                The guide was a 4xl column centred in a 7xl shell, which on a
                laptop left a third of the window empty beside a page whose main
                difficulty is that it is long. The index moves into that space
                and stays there while the reader scrolls, so "where am I, and
                what else is here" stops costing a scroll back to the top.

                `items-start` is what lets the aside stick: a grid item
                stretches to the row height by default, and a sticky element
                inside a full-height box has nothing to stick against.
            -->
            <div class="mt-5 grid items-start gap-6 lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-8">
                <!--
                    Hidden below lg rather than reflowed above the content: on a
                    phone this would be nine rows of links between the reader and
                    the answer they came for, and the chip strip in the main
                    column already serves that case in one line.
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
                        The escape hatch, kept in view. The full contact card is
                        at the foot of the page and this does not repeat it — it
                        is a way of getting there from wherever the reader has
                        given up, which on a page this long is the part that was
                        missing.
                    -->
                    <button
                        type="button"
                        class="mt-3 flex w-full items-start gap-2.5 rounded-xl border border-csc-line bg-csc-mist/40 p-3 text-left transition-colors hover:border-csc-blue/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                        @click="goTo('contact')"
                    >
                        <span class="mt-0.5 inline-flex size-7 shrink-0 items-center justify-center rounded-lg bg-white text-csc-blue">
                            <AppIcon name="envelope" size="sm" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-xs font-semibold text-csc-ink">Still stuck?</span>
                            <span class="mt-0.5 block text-2xs leading-relaxed text-csc-ink-subtle">
                                Ask {{ office.short_name ?? 'the office' }} directly.
                            </span>
                        </span>
                    </button>
                </aside>

                <div class="min-w-0 space-y-5">

            <!--
                Search first, because somebody arriving with a problem has a word
                for it. Type=search so a phone offers the right keyboard and a
                clear button.
            -->
            <!--
                The negative margin is what lets the pinned bar's background
                reach the edges of the content area instead of leaving a strip
                of scrolling page either side of it. It is cancelled at lg,
                where this sits in a grid column: bleeding there would put the
                bar over the gutter and the aside beside it.
            -->
            <div
                class="sticky top-16 z-10 -mx-4 bg-csc-blue-tint/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6 lg:mx-0 lg:px-0"
            >
                <label for="help-search" class="sr-only">Search the guide</label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-csc-ink-subtle">
                        <AppIcon name="search" size="sm" />
                    </span>
                    <input
                        id="help-search"
                        v-model="query"
                        type="search"
                        placeholder="Search — try “payment”, “QR code”, “certificate”"
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
                The same index for screens too narrow for the aside. Hidden while
                searching — the results below already are the filtered list, and
                a full index above them would offer sections the search has just
                ruled out — and hidden from lg up, where the aside has it and two
                copies would be one more thing to keep in step.
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
                <AppEmptyState
                    icon="search"
                    title="Nothing matched that"
                    description="Try a simpler word — “payment”, “certificate”, “QR” — or ask the office using the details below."
                >
                    <template #action>
                        <AppButton variant="ghost" icon="close" @click="query = ''">Clear search</AppButton>
                    </template>
                </AppEmptyState>
            </AppCard>

            <!--
                Deeper scroll clearance than the app-wide
                `[id] { scroll-margin-top: 5rem }` in app.css, and the `!` is
                load-bearing rather than lazy.

                That 5rem clears the shell's h-16 header, which is all most
                pages have stuck to the top. This page also pins the search bar
                beneath it, so 5rem lands a section heading behind the very
                control somebody just used to find it — which is what it did.
                10rem clears both.

                The important modifier is needed because that rule is unlayered
                CSS: Tailwind v4 puts utilities in a layer, and unlayered styles
                beat layered ones whatever their specificity, so a plain
                `scroll-mt-40` here silently loses and the anchor keeps landing
                under the bar.
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

                <div v-show="isOpen(section.id)" :id="`${section.id}-panel`" class="border-t border-csc-line px-4 py-4 sm:px-5">
                    <!--
                        Numbered, because these are procedures. The counter is
                        drawn rather than left to the list marker so it can sit
                        in the brand tint at a readable size on a phone.
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

                    <!-- What each registration badge means, using the real badge. -->
                    <div v-if="section.statuses" class="mt-5 border-t border-csc-line pt-4">
                        <p class="mb-3 text-xs font-semibold tracking-wide text-csc-ink-subtle uppercase">
                            What your status means
                        </p>
                        <dl class="space-y-3">
                            <div v-for="row in statuses" :key="row.status" class="flex flex-col gap-1.5 sm:flex-row sm:gap-3">
                                <dt class="shrink-0 sm:w-40">
                                    <AppBadge :status="row.status" />
                                </dt>
                                <dd class="text-sm leading-relaxed text-csc-ink-muted">{{ row.means }}</dd>
                            </div>
                        </dl>
                    </div>

                    <!-- The real limits, from the controller that enforces them. -->
                    <div v-if="section.uploads" class="mt-5 border-t border-csc-line pt-4">
                        <p class="mb-3 text-xs font-semibold tracking-wide text-csc-ink-subtle uppercase">
                            File limits
                        </p>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr class="border-b border-csc-line text-xs text-csc-ink-subtle">
                                        <th scope="col" class="pb-2 pr-3 font-medium">Document</th>
                                        <th scope="col" class="pb-2 pr-3 font-medium">Largest size</th>
                                        <th scope="col" class="pb-2 font-medium">Accepted types</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-csc-line">
                                    <tr v-for="row in uploadLimits" :key="row.what">
                                        <td class="py-2.5 pr-3">
                                            <span class="font-medium text-csc-ink">{{ row.what }}</span>
                                            <span class="mt-0.5 block text-xs text-csc-ink-subtle">{{ row.where }}</span>
                                        </td>
                                        <td class="py-2.5 pr-3 whitespace-nowrap text-csc-ink-muted">{{ row.size }}</td>
                                        <td class="py-2.5 text-csc-ink-muted">{{ row.types }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Troubleshooting reads as question and answer, not steps. -->
                    <dl v-if="section.problems" class="space-y-4">
                        <div v-for="problem in section.problems" :key="problem.q">
                            <dt class="flex gap-2 text-sm font-semibold text-csc-ink">
                                <AppIcon name="warning" size="sm" class="mt-0.5 shrink-0 text-warning" />
                                {{ problem.q }}
                            </dt>
                            <dd class="mt-1 pl-6 text-sm leading-relaxed text-csc-ink-muted">{{ problem.a }}</dd>
                        </div>
                    </dl>

                    <!--
                        The one warning worth interrupting for. A certificate is
                        rendered once and never re-rendered, so "fix it later" is
                        advice that does not work here.
                    -->
                    <AppAlert v-if="section.id === 'profile'" tone="warning" class="mt-5">
                        Certificates are produced from your profile at the moment they are released, and an
                        already-issued certificate is not updated when you edit your details. Check your name
                        and agency before a training finishes.
                    </AppAlert>
                </div>
            </section>

            <!-- Need more help -->
            <AppCard v-if="!searching || matches.length" id="contact" class="scroll-mt-40!" title="Still stuck?">
                <p class="text-sm leading-relaxed text-csc-ink-muted">
                    If this guide has not answered your question, contact
                    {{ office.name ?? 'the Civil Service Commission' }} directly. Quote your name and the
                    training you are asking about — it is the fastest way to be helped.
                </p>

                <dl v-if="contacts.length" class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div
                        v-for="contact in contacts"
                        :key="contact.label"
                        class="flex items-start gap-3 rounded-lg border border-csc-line bg-csc-mist/40 p-3"
                    >
                        <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-white text-csc-blue">
                            <AppIcon :name="contact.icon" size="sm" />
                        </span>
                        <div class="min-w-0">
                            <dt class="text-xs text-csc-ink-subtle">{{ contact.label }}</dt>
                            <dd class="mt-0.5 text-sm font-medium break-words text-csc-ink">
                                <a
                                    v-if="contact.href"
                                    :href="contact.href"
                                    class="rounded hover:text-csc-blue hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-csc-blue"
                                >
                                    {{ contact.value }}
                                </a>
                                <template v-else>{{ contact.value }}</template>
                            </dd>
                        </div>
                    </div>
                </dl>

                <template #footer>
                    <div class="flex flex-wrap gap-2">
                        <AppButton href="/dashboard" size="sm" variant="ghost" icon="home">
                            Back to dashboard
                        </AppButton>
                        <AppButton href="/trainings" size="sm" variant="ghost" icon="calendar">
                            Browse trainings
                        </AppButton>
                    </div>
                </template>
            </AppCard>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
