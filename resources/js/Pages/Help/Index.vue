<script setup>
/**
 * The participant's guide.
 *
 * Written for somebody who is not confident with software and is here because
 * something did not work, which sets every decision in the content: plain
 * words, one procedure per step, and the contact card at the end of every route
 * through the page, because the honest last step of any guide is "ask a
 * person".
 *
 * The reading machinery — search, the index, the accordions, deep links — is
 * AppGuide's, shared with the staff guide. What lives here is what is about
 * participants: the sections, and the two blocks a section needs beyond steps,
 * which arrive through AppGuide's per-section slot.
 *
 * The facts come from the server (HelpController) rather than being typed here
 * — file limits, the password floor, the office's own contact details — so the
 * guide cannot drift from what the application enforces.
 */
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppBadge from '@/Components/AppBadge.vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppGuide from '@/Components/AppGuide.vue';
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    uploadLimits: { type: Array, required: true },
    statuses: { type: Array, required: true },
    passwordMinimum: { type: Number, required: true },
    hasPassword: { type: Boolean, default: true },
});

const page = usePage();
const office = computed(() => page.props.office ?? {});

// Read once, for the courier-delivery step below: PhysicalOrRequestService
// refuses the option to anyone whose profile places them inside this region,
// so the guide names the same boundary the server enforces rather than
// describing the option as open to everyone and leaving the refusal
// unexplained.
const officeRegion = office.value.region ?? 'this region';

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
                body: `CSC issues an official receipt for every verified payment. This option is for participants outside ${officeRegion} who cannot come to the counter for it — ask for it on the Official Receipts page and attach proof that you have paid the courier fee. Participants inside the region are expected to collect the printed copy in person.`,
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
);</script>

<template>
    <Head title="Help & Guide" />

    <AuthenticatedLayout title="Help & Guide" current="help">
        <AppGuide
            :sections="sections"
            search-placeholder="Search — try “payment”, “QR code”, “certificate”"
            search-hint="Try a simpler word — “payment”, “certificate”, “QR” — or ask the office using the details below."
            :contact-hint="`Ask ${office.short_name ?? 'the office'} directly.`"
        >
            <template #intro>
                <p class="text-sm leading-relaxed text-csc-ink-muted">
                    How to use CSC TIMS, from signing in to downloading your certificate. If you cannot find
                    what you need here, {{ office.short_name ?? 'the office' }} is happy to help — the contact
                    details are at the bottom of this page.
                </p>
            </template>

            <!--
                The one warning worth interrupting for. A certificate is rendered
                once and never re-rendered, so "fix it later" is advice that does
                not work here.
            -->
            <template #extra-profile>
                <AppAlert tone="warning" class="mt-5">
                    Certificates are produced from your profile at the moment they are released, and an
                    already-issued certificate is not updated when you edit your details. Check your name
                    and agency before a training finishes.
                </AppAlert>
            </template>

            <!-- What each registration badge means, using the real badge. -->
            <template #extra-registering>
                <div class="mt-5 border-t border-csc-line pt-4">
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
            </template>

            <!-- The real limits, from the controllers that enforce them. -->
            <template #extra-documents>
                <div class="mt-5 border-t border-csc-line pt-4">
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
            </template>

            <template #footer="{ searching, matches }">
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
            </template>
        </AppGuide>
    </AuthenticatedLayout>
</template>
