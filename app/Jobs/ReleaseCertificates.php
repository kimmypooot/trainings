<?php

namespace App\Jobs;

use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Support\CertificateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Issue certificates for everyone who completed a training.
 *
 * Queued because rendering a few hundred PDFs is far too slow for a request,
 * and each is released independently so one bad row cannot cost the whole
 * batch — a participant with a malformed name should not block their cohort.
 */
class ReleaseCertificates implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Training $training,
        private readonly User $releasedBy,
        private readonly ?int $fieldOfficeId = null,
    ) {}

    public function handle(): void
    {
        Registration::with(['user', 'training'])
            ->where('training_id', $this->training->getKey())
            ->where('status', RegistrationStatus::Completed)
            ->when($this->fieldOfficeId !== null, fn ($query) => $query->whereHas(
                'user.profile',
                fn ($profile) => $profile->where('field_office_id', $this->fieldOfficeId)
            ))
            ->chunkById(25, function ($registrations) {
                foreach ($registrations as $registration) {
                    try {
                        CertificateService::release($registration, $this->releasedBy);
                    } catch (\Throwable $e) {
                        Log::error('Could not release a certificate.', [
                            'registration_id' => $registration->getKey(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}
