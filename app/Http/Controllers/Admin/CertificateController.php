<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ReleaseCertificates;
use App\Models\Registration;
use App\Models\Training;
use App\Support\CertificateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * HRD's side of certificate issuance, ported from v1's
 * `admin/hrd/certificates.php`.
 */
class CertificateController extends Controller
{
    /**
     * Issue certificates for a whole training in one go.
     */
    public function releaseTraining(Request $request, Training $training): RedirectResponse
    {
        $awaiting = Registration::where('training_id', $training->getKey())
            ->where('status', RegistrationStatus::Completed)
            ->whereDoesntHave('certificate', fn ($query) => $query->whereNotNull('generated_at'));

        // Counted separately so the flash message can say what the batch will
        // not cover. Reporting "queued 40" and quietly issuing 37 is how an
        // unpaid fee goes unnoticed until the participant asks where their
        // certificate is.
        $pending = (clone $awaiting)->feeCleared()->count();
        $held = (clone $awaiting)->count() - $pending;

        if ($pending === 0) {
            return back()->withErrors([
                'certificate' => $held > 0
                    ? "No certificates can be issued yet — {$held} completed participant(s) are still on an unpaid promissory note."
                    : 'No completed registrations are waiting for a certificate.',
            ]);
        }

        ReleaseCertificates::dispatch(
            $training,
            $request->user(),
            $request->user()->scopedFieldOfficeId(),
        );

        return back()->with('success', $held === 0
            ? "Queued {$pending} certificate(s) for release."
            : "Queued {$pending} certificate(s) for release. {$held} held — the fee is still outstanding.");
    }

    /**
     * Issue a single certificate from the roster.
     */
    public function release(Request $request, Registration $registration): RedirectResponse
    {
        $certificate = CertificateService::release($registration, $request->user());

        return back()->with(
            'success',
            "Certificate {$certificate->certificate_number} issued to {$registration->user->name}."
        );
    }
}
