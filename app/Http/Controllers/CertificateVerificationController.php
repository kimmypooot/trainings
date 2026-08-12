<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Support\CertificateService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public check an employer runs after scanning the QR on a certificate.
 *
 * Deliberately outside the auth group: the whole point is that anyone holding
 * the document can confirm it without an account. It discloses only what is
 * already printed on the certificate they are looking at — never the
 * participant's email, office or any other profile data.
 */
class CertificateVerificationController extends Controller
{
    public function show(Request $request, string $code): Response
    {
        $certificate = Certificate::with(['user', 'training'])
            ->where('verification_code', $code)
            ->whereNotNull('generated_at')
            ->first();

        if (! $certificate) {
            throw new NotFoundHttpException('No certificate matches that code.');
        }

        CertificateService::recordVerification($certificate, $request->ip(), $request->userAgent());

        return Inertia::render('Certificates/Verify', [
            'certificate' => [
                'number' => $certificate->certificate_number,
                'participant' => $certificate->user->name,
                'training' => $certificate->training->title,
                'venue' => $certificate->training->venue,
                'starts_at' => $certificate->training->starts_at->format('d F Y'),
                'ends_at' => $certificate->training->ends_at->format('d F Y'),
                'duration_days' => $certificate->training->duration_days,
                'issued_at' => $certificate->generated_at->format('d F Y'),
            ],
        ]);
    }
}
