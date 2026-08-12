<?php

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
use App\Http\Controllers\Admin\TrainingController as AdminTrainingController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\CertificateVerificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QrCodeController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\RegistrationOutputController;
use App\Http\Controllers\TrainingController;
use App\Http\Controllers\TrainingRequestController;
use App\Http\Middleware\EnsureProfileIsComplete;
use App\Http\Middleware\EnsureUserIsStaff;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Home'))->name('home');

/*
 * Certificate verification is deliberately public and unauthenticated — the
 * point is that anyone holding the printed document can confirm it is genuine.
 * Throttled because it is the one public endpoint that touches issued records.
 */
Route::get('/verify/{code}', [CertificateVerificationController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('certificates.verify');

Route::get('/privacy-policy', fn () => Inertia::render('Legal/PrivacyPolicy'))->name('privacy-policy');
Route::get('/terms-of-service', fn () => Inertia::render('Legal/TermsOfService'))->name('terms-of-service');

Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');

Route::get('/register', [RegisterController::class, 'create'])->name('register');
Route::post('/register', [RegisterController::class, 'store'])->name('register.store');

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

        // Account administration is superadmin-only.
        Route::middleware(EnsureUserIsStaff::class.':superadmin')->group(function () {
            Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
            Route::get('/users/create', [AdminUserController::class, 'create'])->name('users.create');
            Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
            Route::get('/users/{user}/edit', [AdminUserController::class, 'edit'])->name('users.edit');
            Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
            Route::post('/users/{user}/toggle', [AdminUserController::class, 'toggle'])->name('users.toggle');
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
    Route::delete('/my/registrations/{registration}', [RegistrationController::class, 'destroy'])
        ->name('registrations.destroy');

    Route::get('/my/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('/my/registrations/{registration}/payments', [PaymentController::class, 'store'])
        ->name('payments.store');
    Route::post('/my/payments/{payment}/refund', [PaymentController::class, 'requestRefund'])
        ->name('payments.refund');
    Route::get('/payments/{payment}/proof', [PaymentController::class, 'proof'])->name('payments.proof');

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
