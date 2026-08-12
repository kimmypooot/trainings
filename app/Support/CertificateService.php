<?php

namespace App\Support;

use App\Enums\RegistrationStatus;
use App\Models\Certificate;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\CertificateReleased;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Issuing and re-issuing training certificates.
 *
 * The PDF is rendered once at release and stored, rather than generated on each
 * download: the document a participant shows an employer must be byte-identical
 * to the one CSC issued, and a template edit six months from now must not
 * silently change certificates already in circulation.
 */
class CertificateService
{
    /** Certificates live on a private disk and are served through an authorising controller. */
    public const DISK = 'local';

    /**
     * Issue a certificate for a completed registration.
     *
     * Idempotent on the registration: calling it twice returns the existing
     * certificate rather than minting a second number for the same person.
     */
    public static function release(Registration $registration, User $releasedBy): Certificate
    {
        $registration->loadMissing(['user', 'training']);

        if ($registration->status !== RegistrationStatus::Completed) {
            throw ValidationException::withMessages([
                'certificate' => 'Only a completed registration can be issued a certificate.',
            ]);
        }

        $certificate = DB::transaction(function () use ($registration, $releasedBy) {
            $existing = Certificate::where('registration_id', $registration->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing?->isReleased()) {
                return $existing;
            }

            return $existing ?? Certificate::create([
                'registration_id' => $registration->getKey(),
                'user_id' => $registration->user_id,
                'training_id' => $registration->training_id,
                'certificate_number' => self::nextNumber($registration),
                // Random, not sequential: this is the public lookup key, and a
                // guessable one would expose every certificate ever issued.
                'verification_code' => Str::random(32),
                'generated_by' => $releasedBy->getKey(),
            ]);
        });

        if ($certificate->isReleased()) {
            return $certificate;
        }

        $certificate->forceFill([
            'file_path' => self::render($certificate),
            'generated_at' => now(),
            'generated_by' => $releasedBy->getKey(),
        ])->save();

        $registration->user->notify(new CertificateReleased($certificate));

        $certificate->forceFill(['email_sent_at' => now()])->save();

        return $certificate;
    }

    /**
     * Render the PDF and return its path on the private disk.
     */
    private static function render(Certificate $certificate): string
    {
        $certificate->loadMissing(['user.profile', 'training']);

        $pdf = Pdf::loadView('certificates.default', [
            'certificate' => $certificate,
            'participant' => $certificate->user,
            'training' => $certificate->training,
            // Small and logo-free: at this size the CSC mark would eat enough
            // modules to stop the code scanning off a printed page.
            'qr' => QrCodeBuilder::dataUri($certificate->verificationUrl(), size: 300, withLogo: false),
        ])->setPaper('a4', 'landscape');

        $path = "certificates/{$certificate->verification_code}.pdf";

        Storage::disk(self::DISK)->put($path, $pdf->output());

        return $path;
    }

    /**
     * The printed, human-readable number.
     *
     * Sequential per year in the style of v1's `certificate_number`, so CSC can
     * quote "certificate 42 of 2026" in correspondence.
     */
    private static function nextNumber(Registration $registration): string
    {
        $year = $registration->training->starts_at->format('Y');
        $sequence = Certificate::whereHas(
            'training',
            fn ($query) => $query->whereYear('starts_at', $year)
        )->count() + 1;

        do {
            $number = sprintf('CSC8-%s-%06d', $year, $sequence);
            $sequence++;
        } while (Certificate::where('certificate_number', $number)->exists());

        return $number;
    }

    /**
     * Record a public verification hit.
     */
    public static function recordVerification(Certificate $certificate, ?string $ip, ?string $agent): void
    {
        $certificate->verifications()->create([
            'verified_at' => now(),
            'ip_address' => $ip,
            'user_agent' => $agent,
        ]);

        $certificate->increment('verification_count');
        $certificate->forceFill(['last_verified_at' => now()])->save();
    }

    public static function recordDownload(Certificate $certificate): void
    {
        $certificate->increment('download_count');
        $certificate->forceFill(['last_downloaded_at' => now()])->save();
    }
}
