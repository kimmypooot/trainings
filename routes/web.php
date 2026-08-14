<?php

use App\Http\Controllers\Admin\ActivityLogController as AdminActivityLogController;
use App\Http\Controllers\Admin\AgencyRequestController as AdminAgencyRequestController;
use App\Http\Controllers\Admin\AnalyticsController as AdminAnalyticsController;
use App\Http\Controllers\Admin\AttendanceController as AdminAttendanceController;
use App\Http\Controllers\Admin\CertificateController as AdminCertificateController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\EmailController as AdminEmailController;
use App\Http\Controllers\Admin\ExportController as AdminExportController;
use App\Http\Controllers\Admin\FieldOfficeController as AdminFieldOfficeController;
use App\Http\Controllers\Admin\ParticipantController as AdminParticipantController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\RequestQueueController as AdminRequestQueueController;
use App\Http\Controllers\Admin\ScanLinkController as AdminScanLinkController;
use App\Http\Controllers\Admin\ScannerController;
use App\Http\Controllers\Admin\TrainingController as AdminTrainingController;
use App\Http\Controllers\Admin\UndoController as AdminUndoController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AgencyRequestController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\CertificateVerificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QrCodeController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\RegistrationOutputController;
use App\Http\Controllers\ScanLinkController;
use App\Http\Controllers\TrainingController;
use App\Http\Controllers\TrainingRequestController;
use App\Http\Middleware\EnsureProfileIsComplete;
use App\Http\Middleware\EnsureUserIsStaff;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [HomeController::class, 'index'])->name('home');

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
    $urls = array_map(fn (string $path) => url($path), [
        '/',
        '/login',
        '/register',
        '/forgot-password',
        '/privacy-policy',
        '/terms-of-service',
    ]);

    return response(view('sitemap', ['urls' => $urls]))
        ->header('Content-Type', 'application/xml');
})->name('sitemap');

/*
 * Certificate verification is deliberately public and unauthenticated — the
 * point is that anyone holding the printed document can confirm it is genuine.
 * Throttled because it is the one public endpoint that touches issued records.
 */
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

Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');

Route::get('/register', [RegisterController::class, 'create'])->name('register');
Route::post('/register', [RegisterController::class, 'store'])->name('register.store');

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

Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');

/*
 * Staff area. Participant-facing pages stay under the profile gate; staff have
 * their own shell and are not asked to complete a participant profile.
 */
Route::middleware(['auth', EnsureUserIsStaff::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', AdminDashboardController::class)->name('dashboard');

        // Creating and editing trainings is HRD work; field offices and
        // management get the roster but not the pen.
        Route::middleware(EnsureUserIsStaff::class.':admin|superadmin')->group(function () {
            Route::get('/trainings/create', [AdminTrainingController::class, 'create'])->name('trainings.create');
            Route::post('/trainings', [AdminTrainingController::class, 'store'])->name('trainings.store');
            Route::get('/trainings/{training}/edit', [AdminTrainingController::class, 'edit'])
                ->name('trainings.edit');
            Route::put('/trainings/{training}', [AdminTrainingController::class, 'update'])
                ->name('trainings.update');
            Route::post('/registrations/{registration}/review', [AdminTrainingController::class, 'review'])
                ->name('registrations.review');
            Route::post('/registrations/{registration}/complete', [AdminTrainingController::class, 'complete'])
                ->name('registrations.complete');

            // One decision applied to a roster selection.
            Route::post('/trainings/{training}/registrations/bulk', [AdminTrainingController::class, 'bulk'])
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
        Route::get('/trainings/{training}/roster', [AdminTrainingController::class, 'roster'])
            ->name('trainings.roster');

        // Taking attendance is venue work, so every staff role can do it — a
        // field office running its own session should not need HRD on the phone.
        Route::post('/registrations/{registration}/attendance', [AdminAttendanceController::class, 'store'])
            ->name('attendance.store');
        Route::post('/registrations/{registration}/attendance/check-out', [AdminAttendanceController::class, 'checkOut'])
            ->name('attendance.check-out');

        /*
         * The venue scanning station, same reasoning as the two routes above:
         * scanning is door work, so every staff role gets it, scoped by office.
         *
         * The roster download hands a whole training's participants to a device
         * that will then work without a network, which is why it stays inside
         * this authenticated group and is never reachable as a public page.
         */
        Route::get('/scanner', [ScannerController::class, 'index'])->name('scanner');
        Route::get('/scanner/trainings/{training}/roster', [ScannerController::class, 'roster'])
            ->name('scanner.roster');
        Route::post('/scanner/sync', [ScannerController::class, 'sync'])->name('scanner.sync');

        /*
         * Issuing a station to someone without an account. Kept in the same
         * every-staff-role group as scanning itself, because deciding who works
         * a door is the same job as working it — and a link can never grant
         * more than its issuer already holds, so no role gains reach by having
         * this. Revocation is here rather than superadmin-only for the same
         * reason it matters at all: a phone goes missing mid-session and the
         * person at the venue has to be able to kill the link themselves.
         */
        Route::post('/trainings/{training}/scan-links', [AdminScanLinkController::class, 'store'])
            ->name('scan-links.store');
        Route::delete('/scan-links/{scanLink}', [AdminScanLinkController::class, 'destroy'])
            ->name('scan-links.destroy');

        // Account administration is superadmin-only.
        Route::middleware(EnsureUserIsStaff::class.':superadmin')->group(function () {
            Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
            Route::get('/users/create', [AdminUserController::class, 'create'])->name('users.create');
            Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
            Route::get('/users/{user}/edit', [AdminUserController::class, 'edit'])->name('users.edit');
            Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
            Route::post('/users/{user}/toggle', [AdminUserController::class, 'toggle'])->name('users.toggle');

            // The audit trail records what every other role did, so it sits
            // behind the one role that is not itself reviewed through it.
            // Read-only by design — no delete, no export.
            Route::get('/activity', [AdminActivityLogController::class, 'index'])->name('activity');
        });

        // Money is the collecting officer's remit, shared with HRD leadership.
        Route::middleware(EnsureUserIsStaff::class.':collecting-officer|admin|superadmin')->group(function () {
            Route::get('/payments', [AdminPaymentController::class, 'index'])->name('payments.index');
            Route::post('/payments/{payment}/review', [AdminPaymentController::class, 'review'])
                ->name('payments.review');
            Route::post('/refunds/{refundRequest}/review', [AdminPaymentController::class, 'reviewRefund'])
                ->name('refunds.review');
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
            Route::get('/field-offices/{fieldOffice}/edit', [AdminFieldOfficeController::class, 'edit'])
                ->name('field-offices.edit');
            Route::put('/field-offices/{fieldOffice}', [AdminFieldOfficeController::class, 'update'])
                ->name('field-offices.update');
            Route::post('/field-offices/{fieldOffice}/toggle', [AdminFieldOfficeController::class, 'toggle'])
                ->name('field-offices.toggle');
        });

        // Review queues. Visible to every staff role (scoped by office), but
        // only HRD may convert a request into an actual training.
        Route::get('/requests', [AdminRequestQueueController::class, 'index'])->name('requests.index');
        Route::post('/requests/cancellations/{cancellationRequest}', [AdminRequestQueueController::class, 'reviewCancellation'])
            ->name('requests.cancellations.review');
        Route::post('/requests/trainings/{trainingRequest}', [AdminRequestQueueController::class, 'reviewTrainingRequest'])
            ->name('requests.trainings.review');
        Route::post('/requests/outputs/{output}', [AdminRequestQueueController::class, 'reviewOutput'])
            ->name('requests.outputs.review');

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
        Route::get('/exports/registrations', [AdminExportController::class, 'registrations'])
            ->name('exports.registrations');
        Route::get('/exports/trainings/{training}/roster', [AdminExportController::class, 'roster'])
            ->name('exports.roster');
        Route::get('/exports/payments', [AdminExportController::class, 'payments'])
            ->name('exports.payments');

        Route::get('/participants', [AdminParticipantController::class, 'index'])->name('participants.index');
        Route::get('/participants/{user}', [AdminParticipantController::class, 'show'])->name('participants.show');
    });

// The profile form sits outside the completeness gate, or it would redirect to itself.
Route::middleware('auth')->group(function () {
    Route::get('/profile/complete', [ProfileController::class, 'create'])->name('profile.complete');
    Route::post('/profile/complete', [ProfileController::class, 'store'])->name('profile.complete.store');
});

Route::middleware(['auth', EnsureProfileIsComplete::class])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('/trainings', [TrainingController::class, 'index'])->name('trainings.index');
    Route::get('/trainings/{training:slug}', [TrainingController::class, 'show'])->name('trainings.show');
    Route::post('/trainings/{training}/register', [RegistrationController::class, 'store'])
        ->name('registrations.store');

    Route::get('/my/registrations', [RegistrationController::class, 'index'])->name('registrations.index');
    // Owner-or-staff, decided in the controller — the participant needs to
    // re-read what they attached, and staff need it to review the claim.
    Route::get('/registrations/{registration}/supporting-document', [RegistrationController::class, 'supportingDocument'])
        ->name('registrations.supporting-document');
    Route::delete('/my/registrations/{registration}', [RegistrationController::class, 'destroy'])
        ->name('registrations.destroy');

    Route::get('/my/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('/my/registrations/{registration}/payments', [PaymentController::class, 'store'])
        ->name('payments.store');
    Route::post('/my/payments/{payment}/refund', [PaymentController::class, 'requestRefund'])
        ->name('payments.refund');
    Route::get('/payments/{payment}/proof', [PaymentController::class, 'proof'])->name('payments.proof');
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
