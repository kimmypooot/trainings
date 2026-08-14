<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Models\Certificate;
use App\Models\Registration;
use App\Support\CertificateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificateController extends Controller
{
    /**
     * Certificates are released by CSC after completion, so this page shows two
     * states: completed but awaiting release, and released with a download.
     */
    public function index(Request $request): Response
    {
        $completed = Registration::with(['training', 'certificate'])
            ->where('user_id', $request->user()->getKey())
            ->where('status', RegistrationStatus::Completed)
            ->get();

        [$released, $awaiting] = $completed->partition(
            fn (Registration $registration) => (bool) $registration->certificate?->isReleased()
        );

        return Inertia::render('My/Certificates', [
            'awaitingRelease' => $awaiting->map(fn (Registration $registration) => [
                'id' => $registration->id,
                'title' => $registration->training->title,
                'url' => route('trainings.show', $registration->training->slug),
                'completed_at' => $registration->attended_at?->format('d M Y')
                    ?? $registration->training->ends_at->format('d M Y'),
            ])->values()->all(),
            'released' => $released->map(fn (Registration $registration) => [
                'id' => $registration->certificate->id,
                'title' => $registration->training->title,
                'training_url' => route('trainings.show', $registration->training->slug),
                'number' => $registration->certificate->certificate_number,
                'issued_at' => $registration->certificate->generated_at->format('d M Y'),
                'url' => route('certificates.download', $registration->certificate),
                'verify_url' => $registration->certificate->verificationUrl(),
            ])->values()->all(),
        ]);
    }

    /**
     * Stream the stored PDF.
     *
     * Served through here rather than from a public disk so that ownership is
     * checked on every download — a certificate URL must not be something you
     * can guess or forward.
     */
    public function download(Request $request, Certificate $certificate): StreamedResponse
    {
        abort_unless($certificate->user_id === $request->user()->getKey(), 403);
        abort_unless($certificate->isReleased(), 404);

        // The record can outlive its file — seeded data carries a path with no
        // PDF behind it, and a purged storage directory does the same. Without
        // this the participant gets a 500 rather than an honest "not found".
        abort_unless(Storage::disk(CertificateService::DISK)->exists($certificate->file_path), 404);

        CertificateService::recordDownload($certificate);

        return Storage::disk(CertificateService::DISK)->download(
            $certificate->file_path,
            "{$certificate->certificate_number}.pdf"
        );
    }
}
