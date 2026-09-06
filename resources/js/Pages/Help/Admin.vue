<script setup>
/**
 * The staff guide.
 *
 * The participant guide answers "how do I use this". This answers the question
 * a new field officer or collecting officer actually arrives with — what am I
 * allowed to do, and what happens when I do it — which the office had been
 * answering by sitting somebody next to them for a week.
 *
 * The reading machinery is AppGuide's, shared with the participant guide. What
 * lives here is the content, and the one thing this guide has that the other
 * does not: every section declares the roles it applies to, and a reader sees
 * only their own.
 *
 * **That filtering is relevance, not security.** The whole file ships in the
 * bundle whoever is reading it, and what stops a field office releasing a
 * certificate is the middleware on that route — see routes/web.php, which this
 * page describes rather than enforces. What it buys is that a collecting
 * officer is not handed thirteen sections of which four are theirs, which is
 * how a guide becomes something nobody opens twice.
 *
 * The role lists below are the ones on the routes. They were read off Laravel's
 * resolved middleware rather than from the routes file by eye, because reading
 * it by eye is how this session earlier concluded there was an authorization
 * gap that did not exist.
 */
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppAlert from '@/Components/AppAlert.vue';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppGuide from '@/Components/AppGuide.vue';
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    /** Seconds a roster decision stays reversible — UndoService. */
    undoWindow: { type: Number, required: true },
    /** Rows per review queue — Admin\RequestQueueController. */
    queueCap: { type: Number, required: true },
    /** Exports allowed per minute — the `exports` limiter. */
    exportLimit: { type: Number, required: true },
});

const page = usePage();
const user = computed(() => page.props.auth?.user ?? {});
const role = computed(() => user.value.role ?? 'participant');
const collectsPayments = computed(() => Boolean(user.value.collects_payments));

const ALL_STAFF = ['field-office', 'collecting-officer', 'admin', 'management', 'superadmin'];
const HRD = ['admin', 'superadmin'];
const VENUE = ['field-office', 'collecting-officer', 'admin', 'superadmin'];
const RECORDS = ['field-office', 'admin', 'superadmin'];

const sections = [
    {
        id: 'getting-around',
        title: 'Getting around',
        icon: 'home',
        summary: 'The dashboard, the search box, and what the badges are telling you.',
        roles: ALL_STAFF,
        keywords: ['dashboard', 'search', 'badge', 'sidebar', 'menu', 'navigation'],
        steps: [
            {
                heading: 'The dashboard answers "what is happening now"',
                body: 'It shows this month against the same stretch of last month, the queues your role can actually clear, where registrations are stuck, and which upcoming runs are not filling. It deliberately does not repeat the analytics report, which answers "what happened" over a period you choose.',
            },
            {
                heading: 'The search box is for people and trainings',
                body: 'The box in the header searches participants and the training catalogue at once. Results are limited to your field office if you belong to one; the "see all" link carries your term into the full list.',
            },
            {
                heading: 'A number on a sidebar row is work waiting on you',
                body: 'Those counts are queues your role can clear, not general totals — a queue you cannot act on is left off the list rather than shown at zero. If a badge shows a number, the screen behind it contains that many items.',
            },
        ],
    },
    {
        id: 'trainings',
        title: 'Trainings',
        icon: 'calendar',
        summary: 'The catalogue, creating a run, and changing one that is already published.',
        roles: ALL_STAFF,
        keywords: ['training', 'course', 'programme', 'schedule', 'publish', 'capacity', 'reschedule'],
        steps: [
            {
                heading: 'Every staff role reads the catalogue',
                body: 'A training belongs to the region rather than to a branch, so the list is the whole region for everybody. The participant counts beside each run are yours only, if you belong to a field office — the notice above the list says so.',
            },
            {
                heading: 'Creating and editing is HRD\'s',
                body: 'The New Training button and the pencil on each row appear only for HRD and superadmin. Everyone else reads the same list and opens the same rosters; the controls are simply absent rather than shown and refused.',
            },
            {
                heading: 'Moving a published run',
                body: 'Rescheduling shows you who is affected before it does anything, and the preview allocates seats in the order people registered. The transfer that follows uses that same order, so what the screen names is what actually moves.',
            },
        ],
    },
    {
        id: 'roster',
        title: 'The roster',
        icon: 'users',
        summary: 'Four jobs behind four tabs, and the thirty seconds you have to change your mind.',
        roles: ALL_STAFF,
        keywords: ['roster', 'approve', 'reject', 'waitlist', 'undo', 'bulk', 'print', 'registration'],
        steps: [
            {
                heading: 'The tabs are four different jobs',
                body: 'Participants, Attendance, Evaluations and By Field Office. The columns follow the tab, so the payment buttons are not sitting beside the day picker while you are taking money at a desk. The people, the search and the status chips are shared across all four — it is the same roster either way.',
            },
            {
                heading: 'Deciding on a registration',
                body: 'Approve, waitlist or reject from the row. Approving is what releases the participant\'s check-in code, and where there is a fee, payment has to be verified before the code opens.',
            },
            {
                heading: 'Undo is real, and it is brief',
                body: `A decision can be taken back for ${props.undoWindow} seconds, from the toast that appears when you make it. The participant's email is held for exactly that long, so an undone decision is never mailed — which is why the window is short and why there is no way to extend it.`,
            },
            {
                heading: 'Printing',
                body: 'Print gives you every participant the current filters match, not just the page on screen. The page fetches those rows when you press it, so give it a moment on a large run.',
            },
        ],
        problems: [
            {
                q: 'A chip says twelve but the list looks empty.',
                a: 'The chips count the whole roster while the rows below are filtered and paged. A chip is an offer to go and look at twelve people — press it rather than reading the current page as the total.',
            },
            {
                q: 'I approved the wrong person and the toast has gone.',
                a: `Past the ${props.undoWindow} seconds there is no undo. Change the registration back from the row; the participant will have been emailed, so tell them.`,
            },
        ],
    },
    {
        id: 'attendance',
        title: 'Attendance at a venue',
        icon: 'qr',
        summary: 'The scanning station, shareable door links, and what to do when there is no signal.',
        roles: VENUE,
        keywords: ['scan', 'scanner', 'qr', 'check in', 'checkin', 'door', 'station', 'walk-in', 'offline'],
        steps: [
            {
                heading: 'Open the Scan Station before you leave',
                body: 'Attendance → Scan Station. Load it while you still have a network: it caches the roster onto the device and then works with no connection at all, which is the point of it.',
            },
            {
                heading: 'Scans queue on the device and sync later',
                body: 'Every scan is stored locally and sent when the network comes back. Syncing is safe to repeat — the same badge scanned twice in one flush counts as one arrival — so do not worry about pressing it again.',
            },
            {
                heading: 'A shareable station, for a phone that is not yours',
                body: 'From a training\'s roster you can issue a station link with a six-digit code, for a device that is not signed in. It can never see or do more than you can, and you can revoke it from the same card the moment a phone goes missing.',
            },
            {
                heading: 'Somebody at the door without a code',
                body: 'Mark them present by hand from the Attendance tab, or add a walk-in from the station. Both are recorded against you.',
            },
        ],
        problems: [
            {
                q: 'The camera will not start.',
                a: 'The station is one of only two screens allowed to use the camera, so a blocked permission is usually the browser\'s. Allow it for this site and reload. Manual marking on the Attendance tab works regardless.',
            },
            {
                q: 'I scanned all day with no signal — is it lost?',
                a: 'No. It is on the device until it syncs. Open the station somewhere with a network and let it flush before clearing the browser or handing the device on.',
            },
        ],
    },
    {
        id: 'payments',
        title: 'Payments and refunds',
        icon: 'card',
        summary: 'Verifying proof, official receipts, and refund claims.',
        roles: ALL_STAFF,
        designation: 'collects_payments',
        keywords: ['payment', 'verify', 'proof', 'refund', 'receipt', 'or number', 'deposit', 'bank'],
        steps: [
            {
                heading: 'This screen is a designation, not a role',
                body: 'Payments open for whoever is a collecting officer — that is a flag on the account rather than a rank, so an admin without it does not see the money screens and a field officer with it does.',
            },
            {
                heading: 'Verifying',
                body: 'The queue leads with what is still work: proof waiting to be checked, and refunds mid-pipeline. Match the slip against the bank statement, then verify or reject with a reason — the reason is shown to the participant on their own payments page, so write it for them.',
            },
            {
                heading: 'Official receipts',
                body: 'Record the OR number against the verified payment. A participant can then ask for the printed copy to be couriered, which arrives as its own queue with their proof of the courier fee.',
            },
            {
                heading: 'Refunds',
                body: 'A refund claim carries the payee\'s bank details, which are encrypted at rest and masked unless your account is the one cutting the transfer. Review the claim on its merits; the account number is only needed at the paying stage.',
            },
        ],
    },
    {
        id: 'requests',
        title: 'Request queues',
        icon: 'document',
        summary: 'Withdrawals, training suggestions and submitted outputs.',
        roles: ALL_STAFF,
        keywords: ['request', 'withdrawal', 'cancellation', 'output', 'suggestion', 'queue', 'review'],
        steps: [
            {
                heading: 'Three queues on one screen',
                body: 'Cancellations, training requests and submitted outputs. Each tab counts the database rather than the rows on screen, so the number on the tab and the number in the sidebar always agree.',
            },
            {
                heading: 'Reviewing',
                body: 'Field offices, collecting officers, HRD and superadmin can all decide on these. Approving a withdrawal frees the seat and starts a refund where one is due, so it is a decision rather than a tidy-up.',
            },
            {
                heading: 'Turning a suggestion into a run',
                body: 'Only HRD can convert a training request into an actual training. Everyone else can approve or decline the request itself.',
            },
        ],
        problems: [
            {
                q: 'The sidebar badge says there is work but I cannot see it.',
                a: `Each queue shows ${props.queueCap} rows, pending first, so the cap can only ever hide items that have already been decided. If a pending item is missing, it is a filter rather than the cap — clear it.`,
            },
        ],
    },
    {
        id: 'agency-requests',
        title: 'Agency requests',
        icon: 'building',
        summary: 'Correspondence with an agency asking CSC to run a programme.',
        roles: HRD,
        keywords: ['agency', 'letter', 'request', 'correspondence', 'ord', 'confirmation'],
        steps: [
            {
                heading: 'This is correspondence, not a review queue',
                body: 'An agency writes asking for a programme; the office writes back. The tabs sort by whose move it is — "Awaiting HRD" is your work, "Awaiting Agency" is theirs — so the queue never mixes the two.',
            },
            {
                heading: 'Attaching the office\'s reply',
                body: 'Upload the response letter and, where the flow needs one, the blank confirmation form. Both go onto the private disk and are only ever served through a controller that checks who is asking.',
            },
        ],
    },
    {
        id: 'certificates',
        title: 'Certificates',
        icon: 'certificate',
        summary: 'Looking one up, releasing them, and why a released one cannot be corrected.',
        roles: ALL_STAFF,
        keywords: ['certificate', 'release', 'issue', 'resend', 'verify', 'download'],
        steps: [
            {
                heading: 'Anyone on staff can look one up',
                body: 'The register is open to every staff role deliberately — the office fields "where is my certificate?" on whichever phone rings, and whoever picks up should be able to answer it.',
            },
            {
                heading: 'Releasing is HRD\'s',
                body: 'Release one at a time from a roster row, or the whole training at once. Issuing is idempotent, so pressing it twice does not produce two certificates.',
            },
            {
                heading: 'A released certificate is fixed',
                body: 'The PDF is rendered once, at release, from the participant\'s profile as it stood at that moment, and is never re-rendered. Correcting a name afterwards does not change the document — check the roster before you release, not after.',
            },
        ],
        problems: [
            {
                q: 'A participant says their name is wrong on the certificate.',
                a: 'The issued document cannot be corrected by editing the profile. Fix the profile so future documents are right, then ask HRD what the office does about the one already issued.',
            },
        ],
    },
    {
        id: 'evaluations',
        title: 'Session evaluations',
        icon: 'clipboard',
        summary: 'Day codes, who has answered, and how the response rate is counted.',
        roles: ['admin', 'management', 'superadmin'],
        keywords: ['evaluation', 'sme', 'expert', 'feedback', 'code', 'response rate'],
        steps: [
            {
                heading: 'Evaluations are per training day, not per training',
                body: 'Each day that closes a stretch of an expert\'s teaching collects one form. An expert present across two days is rated once, at the end — so a two-day run with one expert throughout collects one form, not two.',
            },
            {
                heading: 'Day codes',
                body: 'Each open day has a code participants use to reach its form. Regenerate one if it has been shared beyond the room.',
            },
            {
                heading: 'Reading the response rate',
                body: 'The denominator is the days that actually collect a form, not the length of the run. A three-day training can legitimately have two evaluation days.',
            },
        ],
    },
    {
        id: 'reports',
        title: 'Reports and exports',
        icon: 'analytics',
        summary: 'The analytics page, the ten exports, and what scoping does to them.',
        roles: ALL_STAFF,
        keywords: ['report', 'analytics', 'export', 'excel', 'csv', 'download', 'revenue', 'scope'],
        steps: [
            {
                heading: 'Analytics answers "what happened"',
                body: 'Choose a period and read the registration curve, the category and office splits, the demographic cuts and revenue. It is the counterpart to the dashboard, which answers what is happening today.',
            },
            {
                heading: 'Every export is scoped the way your screen is',
                body: 'If you belong to a field office, an export contains your office\'s people and nobody else\'s — the download always matches the rows you were looking at.',
            },
            {
                heading: 'Downloads are rate limited',
                body: `Exports run a full query and stream the result, so they are capped at ${props.exportLimit} a minute per account. Ordinary use is nowhere near it; a script would notice.`,
            },
            {
                heading: 'Money is gated separately',
                body: 'The revenue exports check the collecting-officer designation inside the controller, so they are absent for an account without it even where the rest of the report is available.',
            },
        ],
        problems: [
            {
                q: 'The export button did nothing.',
                a: 'It holds a pending state until the file starts arriving, and refuses a second press while the first is in flight — this is what stops a slow export being run three times. Give it a moment.',
            },
        ],
    },
    {
        id: 'reference',
        title: 'Reference lists',
        icon: 'list',
        summary: 'Field offices, agencies, subject matter experts and email templates.',
        roles: HRD,
        keywords: ['field office', 'agency', 'employer', 'sme', 'expert', 'email', 'template', 'reference'],
        steps: [
            {
                heading: 'These are lists the rest of the app picks from',
                body: 'Maintaining them is part of running trainings rather than administering the system, which is why they sit apart from Users and the site settings.',
            },
            {
                heading: 'Agencies, and the panel above the list',
                body: 'The agency list feeds the employer picker on the profile form. Above it sits what participants typed because they could not find their employer — each one is a click from becoming a proper row, and resolving it moves the people who typed it. Working through that panel is how the list stops drifting.',
            },
            {
                heading: 'Changing an agency\'s field office moves people',
                body: 'It is a visibility change, not a relabel: everybody filed under that agency moves into that office\'s view. The form tells you how many before you confirm.',
            },
            {
                heading: 'Experts and email copy',
                body: 'Subject matter experts are reference data — deactivate rather than delete, because evaluations point at them. Email templates let you edit outgoing wording without a deployment; what actually went out is recorded.',
            },
        ],
    },
    {
        id: 'accounts',
        title: 'Staff accounts and roles',
        icon: 'shield',
        summary: 'Who may sign in, what each role can do, and how access actually ends.',
        roles: HRD,
        keywords: ['user', 'account', 'role', 'staff', 'deactivate', 'password', 'permission', 'access'],
        steps: [
            {
                heading: 'HRD reads the directory; superadmin changes it',
                body: 'Creating an account, changing a role and switching an account off are superadmin\'s. The page drops those controls for anybody else rather than offering them and refusing.',
            },
            {
                heading: 'The roles, briefly',
                body: 'Field office runs its own office\'s participants. Collecting officer works doors and, with the designation, money. HRD (admin) runs trainings, certificates and the reference lists. Management reads — oversight without a control. Superadmin administers the system itself.',
            },
            {
                heading: 'Deactivating actually ends access',
                body: 'Switching an account off ejects it on its next request, drops its sessions and invalidates its remembered sign-in — a deactivated account cannot keep working from a browser it left open. Sending a password reset does the same.',
            },
        ],
    },
    {
        id: 'system',
        title: 'System administration',
        icon: 'settings',
        summary: 'The audit trail, the office\'s own details, maintenance mode and backups.',
        roles: ['superadmin'],
        keywords: ['activity', 'audit', 'log', 'maintenance', 'office', 'backup', 'restore', 'settings'],
        steps: [
            {
                heading: 'The activity log',
                body: 'Every decision that changes something is recorded with who did it and why. It deliberately does not record sign-ins — those go to their own log file, because the volume would bury the decisions worth auditing. It never records a password or the contents of a profile field, only which fields moved.',
            },
            {
                heading: 'Office details',
                body: 'The office name, address, telephone and email here are what appear on certificates, in outgoing mail and on both help pages. There is no second copy anywhere to keep in step.',
            },
            {
                heading: 'Maintenance mode',
                body: 'Closes the site to participants and visitors while leaving staff able to work. You will see a banner across the top for as long as it is on — it is there because staff pass straight through and would otherwise not notice it had been left on for days.',
            },
            {
                heading: 'Backups are a scheduled job, not a button',
                body: 'The nightly archive and its restore are command-line jobs, covered in docs/deployment.md. Worth knowing that they depend on a cron entry and a supervised queue worker: without either, nothing errors — the work simply never runs.',
            },
        ],
        problems: [
            {
                q: 'Participants say they never received an email.',
                a: 'Mail is queued, so it needs a running queue worker. If the queue is not being worked nothing errors and the jobs table simply grows. Run the deployment check (tims:doctor), which looks for exactly this.',
            },
        ],
    },
];

/*
 * What this reader sees.
 *
 * The same shape the sidebar uses — a role list, plus the one designation that
 * is not a role — so the guide and the nav agree about who a screen is for. A
 * section for a screen you cannot open would be a guide to somebody else's job.
 */
const visible = computed(() =>
    sections.filter(
        (section) =>
            section.roles.includes(role.value) &&
            (!section.designation || collectsPayments.value),
    ),
);
</script>

<template>
    <Head title="Staff Guide" />

    <AuthenticatedLayout title="Staff Guide" current="admin-help">
        <AppGuide
            :sections="visible"
            search-placeholder="Search — try “undo”, “scanner”, “export”, “refund”"
            search-hint="Try a simpler word — “roster”, “payment”, “certificate”. Sections for roles other than yours are not shown."
        >
            <template #intro>
                <p class="text-sm leading-relaxed text-csc-ink-muted">
                    How this system is run, for the people running it. You are shown the parts that apply to
                    <strong class="font-semibold text-csc-ink">{{ user.role_label }}</strong
                    >; other roles have their own. Participants have a separate guide written for them.
                </p>
            </template>

            <template #footer>
                <AppCard id="contact" class="scroll-mt-40!" title="What this guide does not decide">
                    <AppAlert tone="info">
                        This page describes what each role may do; it does not control it. Access is enforced on
                        the routes themselves, so a section missing here means the screen is not yours — and a
                        screen you can open is yours whether or not this guide mentions it.
                    </AppAlert>

                    <p class="mt-4 text-sm leading-relaxed text-csc-ink-muted">
                        For anything about deployment, backups or the scheduled jobs, see
                        <span class="font-medium text-csc-ink">docs/deployment.md</span> in the repository —
                        those are command-line operations rather than screens.
                    </p>

                    <template #footer>
                        <div class="flex flex-wrap gap-2">
                            <AppButton href="/admin" size="sm" variant="ghost" icon="home">
                                Back to dashboard
                            </AppButton>
                            <AppButton href="/help" size="sm" variant="ghost" icon="info">
                                The participant guide
                            </AppButton>
                        </div>
                    </template>
                </AppCard>
            </template>
        </AppGuide>
    </AuthenticatedLayout>
</template>
