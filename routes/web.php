<?php

use App\Http\Controllers\Admin\ActivityLogController as AdminActivityLogController;
use App\Http\Controllers\Admin\AgencyRequestController as AdminAgencyRequestController;
use App\Http\Controllers\Admin\AnalyticsController as AdminAnalyticsController;
use App\Http\Controllers\Admin\AttendanceController as AdminAttendanceController;
use App\Http\Controllers\Admin\CertificateController as AdminCertificateController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\EmailController as AdminEmailController;
use App\Http\Controllers\Admin\EvaluationCodeController;
use App\Http\Controllers\Admin\EvaluationController as AdminEvaluationController;
use App\Http\Controllers\Admin\ExportController as AdminExportController;
use App\Http\Controllers\Admin\FieldOfficeController as AdminFieldOfficeController;
use App\Http\Controllers\Admin\MaintenanceController as AdminMaintenanceController;
use App\Http\Controllers\Admin\ParticipantController as AdminParticipantController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\PhysicalOrRequestController as AdminPhysicalOrRequestController;
use App\Http\Controllers\Admin\RegistrationTransferController;
use App\Http\Controllers\Admin\RequestQueueController as AdminRequestQueueController;
use App\Http\Controllers\Admin\RosterBulkController;
use App\Http\Controllers\Admin\RosterController;
use App\Http\Controllers\Admin\ScanLinkController as AdminScanLinkController;
use App\Http\Controllers\Admin\ScannerController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SubjectMatterExpertController as AdminSubjectMatterExpertController;
use App\Http\Controllers\Admin\TrainingController as AdminTrainingController;
use App\Http\Controllers\Admin\UndoController as AdminUndoController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AgencyRequestController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\EmailChangeController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\CertificateVerificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\EvaluationScanController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PhysicalOrRequestController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfilePhotoController;
use App\Http\Controllers\QrCodeController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\RegistrationOutputController;
use App\Http\Controllers\ScanLinkController;
use App\Http\Controllers\TrainingController;
use App\Http\Controllers\TrainingRequestController;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureProfileIsComplete;
use App\Http\Middleware\EnsureUserCollectsPayments;
use App\Http\Middleware\EnsureUserIsStaff;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [HomeController::class, 'index'])->name('home');

/*
 * The public training catalogue used to live here. It now *is* the landing
 * page's calendar section — the two rendered identical cards from the same
 * source and differed only in that one could be filtered, so the filters moved
 * to / and this became a redirect rather than a second copy of the same grid.
 *
 * Permanent, and kept rather than deleted, because the URL was in the sitemap
 * and is the one a crawler or an existing bookmark already knows. The name
 * survives too, so route('programs') keeps resolving.
 *
 * The catalogue remains deliberately outside every auth group: the Commission's
 * programs are public information, and an agency deciding whom to nominate
 * should not have to create an account to read what is on offer. /trainings is
 * the signed-in equivalent and stays gated.
 */
Route::permanentRedirect('/programs', '/#upcoming')->name('programs');

/*
 * robots.txt and sitemap.xml come from routes rather than public/ so the
 * Sitemap location always carries the configured APP_URL, whatever the
 * environment (a static public/robots.txt would beat the route and hardcode a
 * dev hostname into production crawls). robots.txt must therefore never be
 * recreated as a file in public/.
 */
Route::get('/robots.txt', function () {
    return response("User-agent: *\nDisallow:\n\nSitemap: ".url('/sitemap.xml')."\n")
        ->header('Content-Type', 'text/plain');
})->name('robots');

Route::get('/sitemap.xml', function () {
    // No /programs: it is a permanent redirect to '/' now, and listing a URL
    // that only 301s tells a crawler to fetch the same page twice.
    $urls = array_map(fn (string $path) => url($path), [
        '/',
        '/login',
        '/register',
        '/forgot-password',
        '/privacy-policy',
        '/terms-of-service',
        '/accessibility',
        // The verification front door is a public entry point in its own right:
        // an employer searching for how to check a CSC certificate should be
        // able to land on it without holding a code first.
        '/verify',
    ]);

    return response(view('sitemap', ['urls' => $urls]))
        ->header('Content-Type', 'application/xml');
})->name('sitemap');

/*
 * Certificate verification is deliberately public and unauthenticated — the
 * point is that anyone holding the printed document can confirm it is genuine.
 * Throttled because it is the one public endpoint that touches issued records.
 */
Route::get('/verify', [CertificateVerificationController::class, 'form'])
    ->name('certificates.verify-form');

/*
 * Throttled harder than the GET below it. A form that answers "does this code
 * exist" is the one place on the site someone could sit and guess codes, and
 * unlike the QR route there is no printed document implied by the request.
 */
Route::post('/verify', [CertificateVerificationController::class, 'lookup'])
    ->middleware('throttle:10,1')
    ->name('certificates.lookup');

Route::get('/verify/{code}', [CertificateVerificationController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('certificates.verify');

/*
 * The public attendance station.
 *
 * Unauthenticated because the person on the door is usually a volunteer with no
 * account, but not unguarded: the URL token only identifies a link, and the
 * six-digit code exchanged at /unlock is what actually opens it. See
 * ScanLinkController for the full argument, and ScanLinkTest for the guard.
 *
 * Under /station rather than /scan deliberately. /scan/{token} is already the
 * authenticated landing page for a *participant's* QR code — a different token
 * namespace entirely — and putting these here would have shadowed it, silently
 * sending staff who scan a badge to a public station page instead.
 *
 * Throttled hardest at the gate, which is the only guessable step. The roster
 * and sync limits are set for a real door instead — a station flushing a long
 * queue in batches must not be throttled into stranding attendance on a device.
 */
Route::prefix('station')->name('station.')->group(function () {
    Route::get('/{token}', [ScanLinkController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('show');

    Route::post('/{token}/unlock', [ScanLinkController::class, 'unlock'])
        ->middleware('throttle:10,1')
        ->name('unlock');

    Route::get('/{token}/roster', [ScanLinkController::class, 'roster'])
        ->middleware('throttle:30,1')
        ->name('roster');

    Route::post('/{token}/sync', [ScanLinkController::class, 'sync'])
        ->middleware('throttle:120,1')
        ->name('sync');
});

Route::get('/privacy-policy', fn () => Inertia::render('Legal/PrivacyPolicy'))->name('privacy-policy');
Route::get('/terms-of-service', fn () => Inertia::render('Legal/TermsOfService'))->name('terms-of-service');

/*
 * The accessibility statement. Expected of a Philippine government site
 * alongside the privacy and terms pages — a visitor using assistive technology
 * needs somewhere to read what this site claims to conform to and where to
 * report a barrier, and that has to be a page, not a promise in a policy PDF.
 */
Route::get('/accessibility', fn () => Inertia::render('Legal/Accessibility'))->name('accessibility');

// The maintenance notice, rendered standalone. The page is normally served by
// EnsureSiteIsAvailable with a 503, but the route keeps the same page directly
// reachable without going through the closure — and, being named `maintenance`,
// sits on the middleware's exempt list so the notice never closes itself.
Route::get('/maintenance', fn () => Inertia::render('Maintenance', [
    // Kept in step with what EnsureSiteIsAvailable passes. The page shows the
    // office's contact details, and this route reaches it without going through
    // the middleware — so the details have to be handed over here too, or the
    // standalone notice is the one version with nobody to write to.
    'office' => [
        'name' => config('office.name'),
        'email' => config('office.email'),
        'phone' => config('office.phone'),
    ],
]))->name('maintenance');

Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');

Route::get('/register', [RegisterController::class, 'create'])->name('register');
// Throttled like every other unauthenticated state-change endpoint here —
// without it, account creation is the one public POST in this file a script
// could hit without limit, whether to spam mailbox verification or to probe
// the unique:users,email rule for account enumeration.
Route::post('/register', [RegisterController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('register.store');

/*
 * Password reset. The route names are the broker's defaults (password.request,
 * password.email, password.reset, password.store), which is what lets
 * sendResetLink generate the emailed URL without any extra wiring.
 */
Route::get('/forgot-password', [PasswordResetController::class, 'showForgotForm'])->name('password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('password.email');
Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.store');

Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

/*
 * Rotating the password from the header menu. Lives outside the
 * profile-completeness gate on purpose: staff are never asked to complete a
 * participant profile, yet the same menu must let them change their password.
 */
Route::middleware('auth')->post('/change-password', [ChangePasswordController::class, 'update'])
    ->name('password.change');

/*
 * Profile photo. Outside the completeness and verification gates for the same
 * reason as the password change: the avatar is drawn in the header on every
 * page, including the profile gate itself, so its stream route has to be
 * reachable from there or the image 302s to the gate and renders broken.
 * `show` is owner-or-staff, decided in the controller.
 */
Route::middleware('auth')->group(function () {
    Route::get('/profile/photo/{user}', [ProfilePhotoController::class, 'show'])->name('profile.photo.show');
    Route::post('/profile/photo', [ProfilePhotoController::class, 'update'])->name('profile.photo.update');
    Route::delete('/profile/photo', [ProfilePhotoController::class, 'destroy'])->name('profile.photo.destroy');
});

/*
 * Email verification. Self-registered participants must click the emailed link
 * before they can use the system, so every one of these lives outside the
 * profile-completeness and email-verified gates. The verify URL is signed (as
 * the notification expects) and open to guests — the link is usually clicked
 * from another browser or after the session has lapsed. The resends are rate
 * limited.
 */
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware('signed')
    ->name('verification.verify');

Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:3,1')
        ->name('verification.send');
});

// Resend from the login screen, where the visitor is not signed in.
Route::post('/email/resend', [EmailVerificationController::class, 'resend'])
    ->middleware('throttle:3,1')
    ->name('verification.resend');

/*
 * Changing the address the account lives at.
 *
 * Outside the completeness and verification gates, like the photo and password
 * routes: a participant whose agency inbox has died cannot verify the address
 * they are trying to leave, and gating the fix behind it would be a locked door
 * whose key is inside the room.
 *
 * `confirm` is a signed GET open to guests — it is opened from whichever
 * browser has the new mailbox, which is rarely the one holding the session —
 * and it is throttled because an unauthenticated route that reads a user id is
 * worth rate-limiting even when it discloses nothing.
 */
Route::get('/profile/email/confirm/{id}/{hash}', [EmailChangeController::class, 'confirm'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('profile.email.confirm');

Route::middleware('auth')->group(function () {
    Route::post('/profile/email', [EmailChangeController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('profile.email.store');
    Route::delete('/profile/email', [EmailChangeController::class, 'destroy'])->name('profile.email.destroy');
});

Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');

/*
 * The stop between "Google says who you are" and "you now have an account".
 *
 * A Google sign-in that matches no identity and no address used to create an
 * account on the spot, which is how one participant with an office address and
 * a personal Gmail quietly became two participants. These three routes are the
 * question asked in between — see GoogleController::PENDING_SIGNUP for why it
 * is asked of everyone rather than only of the people we suspect.
 *
 * Unauthenticated by definition: the account does not exist yet. What
 * authorises them is the verified Google payload parked in the session, which
 * is also the only thing the account is ever built from — so `create` and
 * `cancel` are POSTs carrying nothing but a CSRF token.
 */
Route::get('/auth/google/new', [GoogleController::class, 'confirmNew'])->name('auth.google.new');
Route::post('/auth/google/new', [GoogleController::class, 'storeNew'])
    ->middleware('throttle:10,1')
    ->name('auth.google.new.store');
Route::post('/auth/google/new/cancel', [GoogleController::class, 'cancelNew'])
    ->name('auth.google.new.cancel');

/*
 * Connecting Google to an account that already exists — the path for a
 * participant who registered with an email address and a password. Signed in
 * by definition, and outside the completeness gate for the same reason as the
 * photo routes: the Linked Accounts card sits on the profile page, which a
 * participant reaches before the gate is satisfied.
 *
 * `connect` is a GET because it ends in a redirect to Google's consent screen,
 * which a form POST cannot do cleanly; it is a state change, so it is throttled
 * and the callback pins the flow to the user who began it.
 */
Route::middleware('auth')->group(function () {
    Route::get('/profile/google/connect', [GoogleController::class, 'link'])
        ->middleware('throttle:10,1')
        ->name('profile.google.connect');
    Route::delete('/profile/google', [GoogleController::class, 'unlink'])->name('profile.google.disconnect');
});

/*
 * Staff area. Participant-facing pages stay under the profile gate; staff have
 * their own shell and are not asked to complete a participant profile.
 */
Route::middleware(['auth', EnsureUserIsStaff::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', AdminDashboardController::class)->name('dashboard');

        /*
         * The header search box. It reaches the participants directory and the
         * trainings catalogue, both of which every staff role already reads
         * from this same group — so it opens no door that was not already open,
         * and it carries the field-office scope inside GlobalSearch rather than
         * relying on this line to narrow it.
         */
        Route::get('/search', SearchController::class)->name('search');

        // Creating and editing trainings is HRD work; field offices and
        // management get the roster but not the pen.
        Route::middleware(EnsureUserIsStaff::class.':admin|superadmin')->group(function () {
            Route::get('/trainings/create', [AdminTrainingController::class, 'create'])->name('trainings.create');
            Route::post('/trainings', [AdminTrainingController::class, 'store'])->name('trainings.store');
            Route::get('/trainings/{training}/edit', [AdminTrainingController::class, 'edit'])
                ->name('trainings.edit');
            Route::put('/trainings/{training}', [AdminTrainingController::class, 'update'])
                ->name('trainings.update');

            /*
             * Rescheduling. The form that opens is the create form, prefilled —
             * a rescheduled run is a new record, because the original's dates,
             * attendance and collected fees are history that an edit would
             * falsify. It sits with create and edit for the same reason they do.
             */
            Route::get('/trainings/{training}/reschedule', [AdminTrainingController::class, 'reschedule'])
                ->name('trainings.reschedule');

            /*
             * Who the reschedule stranded: everyone still holding or waiting on
             * a slot, with what the office is holding their money against. Read
             * -only, but HRD-only rather than open to every staff role that can
             * read a roster — it exists to be acted on, and the action it leads
             * to is the transfer below.
             */
            Route::get('/trainings/{training}/affected', [AdminTrainingController::class, 'affected'])
                ->name('trainings.affected');
            Route::post('/registrations/{registration}/review', [RosterController::class, 'review'])
                ->name('registrations.review');
            Route::post('/registrations/{registration}/complete', [RosterController::class, 'complete'])
                ->name('registrations.complete');

            // Cancelling on a participant's behalf — a phoned-in withdrawal, a
            // duplicate, a confirmed no-show. Reviewing a cancellation the
            // participant *filed* is a different thing and lives in the request
            // queue; this one starts the decision, so it is HRD's alone.
            Route::post('/registrations/{registration}/cancel', [RosterController::class, 'cancelRegistration'])
                ->name('registrations.cancel');

            // One decision applied to a roster selection.
            // Moving a roster selection to another run — rescheduling and
            // splitting are HRD's calls, so it sits with the same roles that
            // create trainings rather than with everyone who can read a roster.
            Route::post('/trainings/{training}/registrations/transfer', RegistrationTransferController::class)
                ->name('trainings.registrations.transfer');
            Route::post('/trainings/{training}/registrations/bulk', RosterBulkController::class)
                ->name('registrations.bulk');

            // Takes back the decision just made, within its window.
            Route::post('/undo', AdminUndoController::class)->name('undo');

            // Issuing certificates is HRD's call, in bulk or one at a time.
            Route::post('/trainings/{training}/certificates', [AdminCertificateController::class, 'releaseTraining'])
                ->name('certificates.release-training');
            Route::post('/registrations/{registration}/certificate', [AdminCertificateController::class, 'release'])
                ->name('certificates.release');
        });

        Route::get('/trainings', [AdminTrainingController::class, 'index'])->name('trainings.index');
        Route::get('/trainings/{training}/roster', [RosterController::class, 'show'])
            ->name('trainings.roster');

        /*
         * Venue work — everything below is done standing at a door with a
         * training in progress, so it belongs to every staff role that runs
         * one: a field office holding its own session should not need HRD on
         * the phone.
         *
         * Management is the exception, and is named out rather than left to
         * fall through. It is an oversight role — see the participants desk
         * below, where the same exclusion is spelled out — so it reads rosters
         * and reports and records nothing. Listing the roles is what the rest
         * of this file does; the alternative, letting management inherit
         * whatever no group happens to narrow, is how it ended up holding a
         * pen it was never meant to have.
         */
        Route::middleware(EnsureUserIsStaff::class.':field-office|collecting-officer|admin|superadmin')
            ->group(function () {
                Route::post('/registrations/{registration}/attendance', [AdminAttendanceController::class, 'store'])
                    ->name('attendance.store');
                Route::post('/registrations/{registration}/attendance/check-out', [AdminAttendanceController::class, 'checkOut'])
                    ->name('attendance.check-out');

                /*
                 * Verifying the supporting document on a supervisory-course
                 * registration is monitoring, not an HRD decision — a field
                 * office is the one that knows whether a designation is real.
                 * The controller re-resolves the registration against the
                 * field-office scope, exactly as the roster does.
                 */
                Route::post('/registrations/{registration}/supervisory-document', [RosterController::class, 'reviewSupervisoryDocument'])
                    ->name('registrations.supervisory-document');

                /*
                 * The venue scanning station. The whole screen exists to check
                 * people in, so it is gated as one thing rather than leaving a
                 * role able to open a station it cannot sync.
                 *
                 * The roster download hands a whole training's participants to
                 * a device that will then work without a network, which is why
                 * it stays inside this authenticated group and is never
                 * reachable as a public page.
                 */
                Route::get('/scanner', [ScannerController::class, 'index'])->name('scanner');
                Route::get('/scanner/trainings/{training}/roster', [ScannerController::class, 'roster'])
                    ->name('scanner.roster');
                Route::post('/scanner/sync', [ScannerController::class, 'sync'])->name('scanner.sync');

                /*
                 * Admitting a walk-in. Sits with the scanner because that is
                 * where it happens — the operator has just scanned a valid
                 * code that is not on the roster, and this is the answer to
                 * it — but it is a different kind of action from the three
                 * above, which only ever record attendance for somebody
                 * already approved. This one enrols a person and, on a paid
                 * run, issues a promissory note in their name.
                 *
                 * That is why it is here and not on the volunteer station:
                 * every other endpoint in this group is reachable by a signed
                 * in staff member whose name lands on the record, and a
                 * financial obligation must never be created by an
                 * unauthenticated door link. See ScanLinkController, which
                 * deliberately does not gain this.
                 */
                Route::post('/scanner/walk-in', [ScannerController::class, 'walkIn'])
                    ->name('scanner.walk-in');

                /*
                 * Issuing a station to someone without an account. Kept with
                 * scanning itself, because deciding who works a door is the
                 * same job as working it — and a link can never grant more
                 * than its issuer already holds, so no role gains reach by
                 * having this. Revocation is here rather than superadmin-only
                 * for the same reason it matters at all: a phone goes missing
                 * mid-session and the person at the venue has to be able to
                 * kill the link themselves.
                 */
                Route::post('/trainings/{training}/scan-links', [AdminScanLinkController::class, 'store'])
                    ->name('scan-links.store');
                Route::delete('/scan-links/{scanLink}', [AdminScanLinkController::class, 'destroy'])
                    ->name('scan-links.destroy');
            });

        /*
         * The staff directory, ported from v1's admin/hrd/collecting-officers
         * page — which was HRD's, read-only, and listed the active field-office
         * and HRD accounts so the office could see who was serving where. That
         * reading is ordinary HRD work: knowing which office has a collecting
         * officer is how a payment gets routed to the right desk. Making the
         * whole screen superadmin-only took it away, so the list comes back to
         * HRD and the account administration below does not.
         *
         * Nothing sensitive is on it — name, office, role, whether the account
         * is active, last sign-in — which is the same set v1 showed.
         */
        Route::middleware(EnsureUserIsStaff::class.':admin|superadmin')->group(function () {
            Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        });

        // Creating accounts, changing a role, and switching an account off stay
        // superadmin-only: those are the decisions the directory only reports.
        Route::middleware(EnsureUserIsStaff::class.':superadmin')->group(function () {
            Route::get('/users/create', [AdminUserController::class, 'create'])->name('users.create');
            Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
            Route::get('/users/{user}/edit', [AdminUserController::class, 'edit'])->name('users.edit');
            Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
            Route::post('/users/{user}/toggle', [AdminUserController::class, 'toggle'])->name('users.toggle');

            // The audit trail records what every other role did, so it sits
            // behind the one role that is not itself reviewed through it.
            // Read-only by design — no delete, no export.
            Route::get('/activity', [AdminActivityLogController::class, 'index'])->name('activity');

            // The maintenance switch. Held by superadmin alone because it is
            // the only role EnsureSiteIsAvailable lets through while the site
            // is down — the person who flips it has to be able to reach the
            // screen that flips it back.
            Route::get('/maintenance', [AdminMaintenanceController::class, 'index'])->name('maintenance');
            Route::post('/maintenance', [AdminMaintenanceController::class, 'update'])->name('maintenance.update');
        });

        /*
         * Money is the designated collecting officer's remit, shared with HRD
         * leadership. Gated on the designation rather than a role — see
         * EnsureUserCollectsPayments — because a field office's collecting
         * officer is a field-office user, and has to keep their office scoping
         * while taking payments. This is the only group in the file that is not
         * a role list, which is why it names its own middleware.
         */
        Route::middleware(EnsureUserCollectsPayments::class)->group(function () {
            Route::get('/payments', [AdminPaymentController::class, 'index'])->name('payments.index');
            Route::post('/payments/{payment}/review', [AdminPaymentController::class, 'review'])
                ->name('payments.review');
            /*
             * Clearing a batch of promissory notes, which is what a walk-in
             * event leaves behind. Notes only — see the controller for why a
             * batch cannot issue official receipts — so this adds throughput,
             * not a second way to verify money.
             */
            Route::post('/payments/bulk', [AdminPaymentController::class, 'bulk'])
                ->name('payments.bulk');
            Route::post('/refunds/{refundRequest}/review', [AdminPaymentController::class, 'reviewRefund'])
                ->name('refunds.review');
            // The bank account participants are told to deposit into. Held by
            // the same roles that handle money, because the account is where
            // the money goes.
            Route::post('/payments/settings', [AdminPaymentController::class, 'updateSettings'])
                ->name('payments.settings');
            /*
             * Money taken over the counter, ported from v1's payment-actions.
             * It sits with the payment queue rather than the roster because it
             * is the same act as verifying one — an officer entering what is on
             * an OR stub — and the same people are accountable for it. The
             * controller re-resolves the registration against the field-office
             * scope, so a scoped officer cannot record against another office.
             */
            Route::post('/registrations/{registration}/payment', [AdminPaymentController::class, 'record'])
                ->name('registrations.payment');
        });

        /*
         * Physical-OR delivery is HRD admin work, not money-handling — the
         * training fee was settled long ago and the courier fee is verified
         * against a GCash screenshot, not banked here. So this queue sits with
         * the same roles that manage trainings, and the settings form lives
         * with it (the GCash details are what participants are asked to pay).
         */
        Route::middleware(EnsureUserIsStaff::class.':admin|superadmin')->group(function () {
            Route::get('/physical-or', [AdminPhysicalOrRequestController::class, 'index'])
                ->name('physical-or.index');
            Route::post('/physical-or/{physicalOrRequest}/review', [AdminPhysicalOrRequestController::class, 'review'])
                ->name('physical-or.review');
            Route::post('/physical-or/settings', [AdminPhysicalOrRequestController::class, 'updateSettings'])
                ->name('physical-or.settings');
        });

        // Outbound mail is HRD's to send and everyone's to audit.
        Route::middleware(EnsureUserIsStaff::class.':admin|superadmin')->group(function () {
            Route::get('/emails', [AdminEmailController::class, 'index'])->name('emails.index');
            Route::post('/emails', [AdminEmailController::class, 'store'])->name('emails.store');
            Route::post('/emails/test', [AdminEmailController::class, 'test'])->name('emails.test');
            Route::post('/emails/templates', [AdminEmailController::class, 'storeTemplate'])
                ->name('emails.templates.store');
            Route::delete('/emails/templates/{emailTemplate}', [AdminEmailController::class, 'destroyTemplate'])
                ->name('emails.templates.destroy');
        });

        // Reference data is admin-managed.
        Route::middleware(EnsureUserIsStaff::class.':admin|superadmin')->group(function () {
            Route::get('/field-offices', [AdminFieldOfficeController::class, 'index'])
                ->name('field-offices.index');
            Route::get('/field-offices/create', [AdminFieldOfficeController::class, 'create'])
                ->name('field-offices.create');
            Route::post('/field-offices', [AdminFieldOfficeController::class, 'store'])
                ->name('field-offices.store');
            Route::get('/field-offices/{fieldOffice}', [AdminFieldOfficeController::class, 'show'])
                ->name('field-offices.show');
            Route::get('/field-offices/{fieldOffice}/edit', [AdminFieldOfficeController::class, 'edit'])
                ->name('field-offices.edit');
            Route::put('/field-offices/{fieldOffice}', [AdminFieldOfficeController::class, 'update'])
                ->name('field-offices.update');
            Route::post('/field-offices/{fieldOffice}/toggle', [AdminFieldOfficeController::class, 'toggle'])
                ->name('field-offices.toggle');

            /*
             * Subject matter experts. Reference data in the same sense a field
             * office is — created once, assigned to many runs — and managed by
             * the same roles that own the trainings those assignments appear
             * on, because choosing who delivers a programme is part of building
             * it. There is no destroy: an expert carrying evaluations cannot be
             * deleted (the database refuses), so `toggle` is the retirement.
             */
            Route::get('/smes', [AdminSubjectMatterExpertController::class, 'index'])
                ->name('smes.index');
            Route::get('/smes/create', [AdminSubjectMatterExpertController::class, 'create'])
                ->name('smes.create');
            Route::post('/smes', [AdminSubjectMatterExpertController::class, 'store'])
                ->name('smes.store');
            Route::get('/smes/{expert}', [AdminSubjectMatterExpertController::class, 'show'])
                ->name('smes.show');
            Route::get('/smes/{expert}/edit', [AdminSubjectMatterExpertController::class, 'edit'])
                ->name('smes.edit');
            Route::put('/smes/{expert}', [AdminSubjectMatterExpertController::class, 'update'])
                ->name('smes.update');
            Route::post('/smes/{expert}/toggle', [AdminSubjectMatterExpertController::class, 'toggle'])
                ->name('smes.toggle');
        });

        /*
         * Evaluation results.
         *
         * Region-wide by construction: an average over one office's
         * participants, labelled with the training's name, is a number that
         * would be quoted as the training's rating and would not be one. So
         * the field-office role — the one role scoped to a subset of
         * participants — is not in this group, rather than being shown a
         * filtered version of the same page. Management is: reading how the
         * region's programmes were received is exactly their remit.
         */
        Route::middleware(EnsureUserIsStaff::class.':admin|superadmin|management')->group(function () {
            Route::get('/evaluations', [AdminEvaluationController::class, 'index'])
                ->name('evaluations.index');
            Route::get('/trainings/{training}/evaluations', [AdminEvaluationController::class, 'show'])
                ->name('trainings.evaluations');
        });

        /*
         * The scannable evaluation posters.
         *
         * HRD only, and narrower than the group above on purpose: management
         * reads results, but cutting and withdrawing a code decides what the
         * room is asked, which belongs with whoever owns the training. Narrower
         * too than the scan-links group next door — a station link authorises a
         * door and a field office genuinely staffs doors, whereas the panel a
         * poster asks about is HRD's to configure.
         *
         * The printable sheet sits alongside rather than under /evaluations
         * because it is a property of the run, not of its results, and is
         * wanted before a single response exists.
         */
        Route::middleware(EnsureUserIsStaff::class.':admin|superadmin')->group(function () {
            Route::post('/trainings/{training}/evaluation-codes', [EvaluationCodeController::class, 'store'])
                ->name('evaluation-codes.store');
            Route::get('/trainings/{training}/evaluation-codes/print', [EvaluationCodeController::class, 'print'])
                ->name('evaluation-codes.print');
            Route::post('/evaluation-codes/{evaluationDayCode}/regenerate', [EvaluationCodeController::class, 'regenerate'])
                ->name('evaluation-codes.regenerate');
            Route::delete('/evaluation-codes/{evaluationDayCode}', [EvaluationCodeController::class, 'destroy'])
                ->name('evaluation-codes.destroy');
            Route::get('/evaluation-codes/{evaluationDayCode}.png', [EvaluationCodeController::class, 'image'])
                ->name('evaluation-codes.image');
        });

        /*
         * Review queues. The queue itself is visible to every staff role
         * (scoped by office) because knowing what is outstanding is oversight
         * as much as it is work. Deciding an item is the work, so management
         * is named out of it — a granted cancellation refunds money and frees
         * a seat, which is not something a role that reads reports should be
         * able to do. Only HRD may convert a request into an actual training.
         */
        Route::get('/requests', [AdminRequestQueueController::class, 'index'])->name('requests.index');

        Route::middleware(EnsureUserIsStaff::class.':field-office|collecting-officer|admin|superadmin')
            ->group(function () {
                Route::post('/requests/cancellations/{cancellationRequest}', [AdminRequestQueueController::class, 'reviewCancellation'])
                    ->name('requests.cancellations.review');
                Route::post('/requests/trainings/{trainingRequest}', [AdminRequestQueueController::class, 'reviewTrainingRequest'])
                    ->name('requests.trainings.review');
                Route::post('/requests/outputs/{output}', [AdminRequestQueueController::class, 'reviewOutput'])
                    ->name('requests.outputs.review');
            });

        /*
         * Agency requests are HRD correspondence, not a review queue — the
         * officer writes letters back to an agency on CSC's behalf, so this
         * sits with the same roles that own trainings.
         */
        Route::middleware(EnsureUserIsStaff::class.':admin|superadmin')->group(function () {
            Route::get('/agency-requests', [AdminAgencyRequestController::class, 'index'])
                ->name('agency-requests.index');
            Route::post('/agency-requests/{agencyRequest}/assign', [AdminAgencyRequestController::class, 'assign'])
                ->name('agency-requests.assign');
            Route::post('/agency-requests/{agencyRequest}/notify-ord', [AdminAgencyRequestController::class, 'notifyOrd'])
                ->name('agency-requests.notify-ord');
            Route::post('/agency-requests/{agencyRequest}/requirements', [AdminAgencyRequestController::class, 'sendRequirements'])
                ->name('agency-requests.requirements');
            Route::post('/agency-requests/{agencyRequest}/verify-payment', [AdminAgencyRequestController::class, 'verifyPayment'])
                ->name('agency-requests.verify-payment');
            Route::post('/agency-requests/{agencyRequest}/reject', [AdminAgencyRequestController::class, 'reject'])
                ->name('agency-requests.reject');
        });

        Route::middleware(EnsureUserIsStaff::class.':admin|superadmin')->group(function () {
            Route::post('/requests/trainings/{trainingRequest}/convert', [AdminRequestQueueController::class, 'convertTrainingRequest'])
                ->name('requests.trainings.convert');
        });

        /*
         * Reporting. Every export is field-office scoped inside the controller
         * — see ExportScopingTest, which is the guard on that.
         */
        Route::get('/analytics', AdminAnalyticsController::class)->name('analytics');
        Route::get('/exports/participants', [AdminExportController::class, 'participants'])
            ->name('exports.participants');
        Route::get('/exports/certificates', [AdminExportController::class, 'certificates'])
            ->name('exports.certificates');
        Route::get('/exports/registrations', [AdminExportController::class, 'registrations'])
            ->name('exports.registrations');
        // One participant's whole record, for when someone asks what they have
        // attended. Office-scoped in the controller like the directory itself.
        Route::get('/exports/participants/{user}/history', [AdminExportController::class, 'participantHistory'])
            ->name('exports.participant-history');
        Route::get('/exports/trainings/{training}/roster', [AdminExportController::class, 'roster'])
            ->name('exports.roster');
        // The affected-participants list for a rescheduled run, with the fee
        // state that decides each case. Office-scoped in the controller like
        // every other export here.
        Route::get('/exports/trainings/{training}/affected', [AdminExportController::class, 'affected'])
            ->name('exports.affected');
        Route::get('/exports/payments', [AdminExportController::class, 'payments'])
            ->name('exports.payments');
        // Per-training revenue, with the PRIME-HRM discounts identified.
        // Gated on the collecting-officer designation inside the controller,
        // like the payments export it sits beside.
        Route::get('/exports/trainings/{training}/revenue', [AdminExportController::class, 'revenue'])
            ->name('exports.revenue');
        // The analytics report exports. They share the ReportScope parser with
        // the analytics page, so a download and the screen it came from always
        // cover the same trainings; the revenue one is gated on the
        // collecting-officer designation inside the controller.
        Route::get('/exports/reports/revenue', [AdminExportController::class, 'revenueReport'])
            ->name('exports.reports.revenue');
        Route::get('/exports/reports/breakdown', [AdminExportController::class, 'breakdownReport'])
            ->name('exports.reports.breakdown');

        /*
         * The participants desk, ported from v1's admin/hrd/participants page.
         *
         * Reading the directory is every staff role's — a collecting officer
         * needs to look someone up as much as HRD does. Acting on a record is
         * narrower: correcting a profile, mailing a reset link, and switching
         * an account off belong to HRD and to the field office that owns the
         * record, because a branch office fixing its own participants' details
         * is the ordinary case rather than an escalation. Management reads
         * only. What keeps a field office to "its own" is not this list but
         * the office guard inside the controller, which 404s another office's
         * participant on every action including these.
         */
        Route::middleware(EnsureUserIsStaff::class.':admin|superadmin|field-office')->group(function () {
            Route::get('/participants/{user}/edit', [AdminParticipantController::class, 'edit'])
                ->name('participants.edit');
            Route::put('/participants/{user}', [AdminParticipantController::class, 'update'])
                ->name('participants.update');
            Route::post('/participants/{user}/toggle', [AdminParticipantController::class, 'toggle'])
                ->name('participants.toggle');
            Route::post('/participants/{user}/password-reset', [AdminParticipantController::class, 'sendPasswordReset'])
                ->name('participants.password-reset');
        });

        Route::get('/participants', [AdminParticipantController::class, 'index'])->name('participants.index');
        Route::get('/participants/{user}', [AdminParticipantController::class, 'show'])->name('participants.show');

        /*
         * The certificate register. Looking one up is every staff role's job —
         * the office fields "where is my certificate?" on any phone that rings
         * — so reading and downloading are open, scoped by office. Re-sending
         * puts mail in someone's inbox, so it sits with the same roles that
         * manage participant records.
         */
        Route::get('/certificates', [AdminCertificateController::class, 'index'])
            ->name('certificates.index');
        Route::get('/certificates/{certificate}/download', [AdminCertificateController::class, 'download'])
            ->name('certificates.download');
        Route::get('/certificates/{certificate}', [AdminCertificateController::class, 'show'])
            ->name('certificates.show');

        Route::middleware(EnsureUserIsStaff::class.':admin|superadmin|field-office')->group(function () {
            Route::post('/certificates/{certificate}/resend', [AdminCertificateController::class, 'resend'])
                ->name('certificates.resend');
        });
    });

// The profile form sits outside the completeness gate, or it would redirect to itself.
Route::middleware('auth')->group(function () {
    Route::get('/profile/complete', [ProfileController::class, 'create'])->name('profile.complete');
    Route::post('/profile/complete', [ProfileController::class, 'store'])->name('profile.complete.store');
});

Route::middleware(['auth', EnsureProfileIsComplete::class, EnsureEmailIsVerified::class])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('/trainings', [TrainingController::class, 'index'])->name('trainings.index');
    // Declared before the slug route, which would otherwise swallow "calendar"
    // as a training slug and 404.
    Route::get('/trainings/calendar', [TrainingController::class, 'calendar'])->name('trainings.calendar');
    Route::get('/trainings/{training:slug}', [TrainingController::class, 'show'])->name('trainings.show');
    Route::post('/trainings/{training}/register', [RegistrationController::class, 'store'])
        ->name('registrations.store');

    Route::get('/my/registrations', [RegistrationController::class, 'index'])->name('registrations.index');
    // The training as an .ics the participant can keep. Owner-only, decided in
    // the controller — a registration is nobody else's appointment.
    Route::get('/my/registrations/{registration}/calendar', [RegistrationController::class, 'calendar'])
        ->name('registrations.calendar');
    // Owner-or-staff, decided in the controller — the participant needs to
    // re-read what they attached, and staff need it to review the claim.
    Route::get('/registrations/{registration}/supporting-document', [RegistrationController::class, 'supportingDocument'])
        ->name('registrations.supporting-document');
    // Re-uploading a rejected (or missing) supervisory document. The controller
    // decides ownership and whether the workflow still allows a replacement.
    Route::post('/my/registrations/{registration}/supporting-document', [RegistrationController::class, 'resubmitDocument'])
        ->name('registrations.supporting-document.resubmit');
    Route::delete('/my/registrations/{registration}', [RegistrationController::class, 'destroy'])
        ->name('registrations.destroy');

    Route::get('/my/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('/my/registrations/{registration}/payments', [PaymentController::class, 'store'])
        ->name('payments.store');
    Route::post('/my/payments/{payment}/refund', [PaymentController::class, 'requestRefund'])
        ->name('payments.refund');
    Route::get('/payments/{payment}/proof', [PaymentController::class, 'proof'])->name('payments.proof');

    /*
     * Physical copies of official receipts. Participant-facing: filing a
     * request, attaching the GCash proof, and cancelling while that is still
     * possible. The proof route sits here rather than in the admin group
     * because the participant who filed it can also open their own attachment;
     * the controller makes the owner-or-officer call.
     */
    Route::get('/my/physical-or', [PhysicalOrRequestController::class, 'index'])
        ->name('physical-or.index');
    Route::post('/my/payments/{payment}/physical-or', [PhysicalOrRequestController::class, 'store'])
        ->name('physical-or.store');
    Route::post('/my/physical-or/{physicalOrRequest}/proof', [PhysicalOrRequestController::class, 'uploadProof'])
        ->name('physical-or.proof-upload');
    Route::post('/my/physical-or/{physicalOrRequest}/cancel', [PhysicalOrRequestController::class, 'cancel'])
        ->name('physical-or.cancel');
    Route::get('/physical-or/{physicalOrRequest}/proof', [PhysicalOrRequestController::class, 'proof'])
        ->name('physical-or.proof');

    // Registered here rather than in the admin group because the participant
    // who filed the claim can also open their own attachment; the controller
    // makes the owner-or-officer call.
    Route::get('/refunds/{refundRequest}/proof', [PaymentController::class, 'refundProof'])
        ->name('payments.refund-proof');

    /*
     * Agency requests: an agency formally asking CSC to run a training for its
     * own staff, and the document exchange that follows. Distinct from the
     * training-requests routes below, which are the suggestion box.
     */
    Route::get('/my/agency-requests', [AgencyRequestController::class, 'index'])
        ->name('agency-requests.index');
    Route::post('/my/agency-requests', [AgencyRequestController::class, 'store'])
        ->name('agency-requests.store');
    Route::post('/my/agency-requests/{agencyRequest}/confirmation', [AgencyRequestController::class, 'storeConfirmation'])
        ->name('agency-requests.confirmation');
    Route::post('/my/agency-requests/{agencyRequest}/completion', [AgencyRequestController::class, 'storeCompletion'])
        ->name('agency-requests.completion');
    Route::post('/my/agency-requests/{agencyRequest}/cancel', [AgencyRequestController::class, 'cancel'])
        ->name('agency-requests.cancel');
    // Owner-or-staff, decided in the controller: both sides of the
    // correspondence need to read what the other sent.
    Route::get('/agency-request-documents/{document}', [AgencyRequestController::class, 'download'])
        ->name('agency-requests.documents.download');

    Route::get('/my/training-requests', [TrainingRequestController::class, 'index'])
        ->name('training-requests.index');
    Route::post('/my/training-requests', [TrainingRequestController::class, 'store'])
        ->name('training-requests.store');

    Route::post('/my/registrations/{registration}/outputs', [RegistrationOutputController::class, 'store'])
        ->name('outputs.store');
    Route::get('/outputs/{output}/download', [RegistrationOutputController::class, 'download'])
        ->name('outputs.download');

    /*
     * Evaluating the experts who delivered a training day.
     *
     * Keyed by registration and day number rather than by a token in an email,
     * because the form has to be reachable on the evening of day 2 by somebody
     * who deleted the day 1 message — and because the registration is what
     * proves the person was on the programme. Ownership is checked in the
     * controller; which days are open is SmeEvaluationService's call.
     */
    Route::get('/my/evaluations', [EvaluationController::class, 'index'])->name('evaluations.index');
    Route::get('/my/evaluations/{registration}/days/{day}', [EvaluationController::class, 'show'])
        ->whereNumber('day')
        ->name('evaluations.show');
    Route::post('/my/evaluations/{registration}/days/{day}', [EvaluationController::class, 'store'])
        ->whereNumber('day')
        ->name('evaluations.store');

    /*
     * The scanned way in to the same form.
     *
     * A code on the wall at the end of a session, one per evaluation day. It
     * cannot address the route above directly, because that route names a
     * *registration* — one participant's — while a poster is read by the whole
     * room. So the token names the day, and the controller works out who is
     * holding the phone before redirecting them into their own form.
     *
     * Inside the authenticated group on purpose, and that is the whole trick of
     * the logged-out case: Laravel's own auth middleware stores the intended URL
     * and every sign-in path in this app ends in redirect()->intended(), so a
     * participant who scans while signed out is walked through login — and
     * through profile completion and email verification if those are owed — and
     * arrives back here without ever seeing the URL.
     *
     * Not /scan/{token}: that is the participant's own check-in code, and a
     * camera pointed at the wrong poster should not silently do the other thing.
     * Throttled like /verify/{code}, which is the app's other public-ish lookup
     * by opaque string.
     */
    Route::get('/evaluate/{token}', EvaluationScanController::class)
        ->middleware('throttle:30,1')
        ->name('evaluations.scan');

    Route::get('/my/certificates', [CertificateController::class, 'index'])->name('certificates.index');
    Route::get('/my/certificates/{certificate}/download', [CertificateController::class, 'download'])
        ->name('certificates.download');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read', [NotificationController::class, 'markAllRead'])
        ->name('notifications.read');

    Route::get('/my/qr', [QrCodeController::class, 'show'])->name('qr.show');
    Route::get('/my/qr.png', [QrCodeController::class, 'image'])->name('qr.image');
    Route::post('/my/qr/regenerate', [QrCodeController::class, 'regenerate'])->name('qr.regenerate');

    // Where a scanned code lands; the controller restricts it to staff.
    Route::get('/scan/{token}', [QrCodeController::class, 'scan'])->name('scan');
    Route::post('/scan/{token}/check-in', [QrCodeController::class, 'checkIn'])->name('scan.check-in');
});

/*
 * Everything unmatched is a branded 404 through the SPA shell, so a typo in the
 * bar or a stale bookmark lands on the app's own error page instead of
 * Laravel's stock grey one. Inertia::render answers 200, so the status is
 * corrected on the way out — both for Inertia JSON and for full-page loads.
 */
Route::fallback(function () {
    return Inertia::render('Error', ['status' => 404])
        ->toResponse(request())
        ->setStatusCode(404);
});
