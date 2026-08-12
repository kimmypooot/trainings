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
        $pending = Registration::where('training_id', $training->getKey())
            ->where('status', RegistrationStatus::Completed)
            ->whereDoesntHave('certificate', fn ($query) => $query->whereNotNull('generated_at'))
            ->count();

        if ($pending === 0) {
            return back()->withErrors([
                'certificate' => 'No completed registrations are waiting for a certificate.',
            ]);
        }

        ReleaseCertificates::dispatch(
            $training,
            $request->user(),
            $request->user()->scopedFieldOfficeId(),
        );

        return back()->with('success', "Queued {$pending} certificate(s) for release.");
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
