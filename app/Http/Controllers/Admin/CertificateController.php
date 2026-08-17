<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Jobs\ReleaseCertificates;
use App\Models\Certificate;
use App\Models\CertificateVerification;
use App\Models\Registration;
use App\Models\Training;
use App\Notifications\CertificateReleased;
use App\Support\CertificateService;
use App\Support\QrCodeBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HRD's side of certificate issuance, ported from v1's
 * `admin/hrd/certificates.php`.
 */
class CertificateController extends Controller
{
    /**
     * Every certificate issued, ported from v1's `certificates.php`.
     *
     * v1 made you pick a training before it would show you anything, which is
     * the wrong way round for the question the office actually gets asked —
     * someone rings up quoting a certificate number, or a name, and nobody
     * knows which run it came from. So this is a flat directory with the
     * training as a filter rather than a prerequisite.
     */
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'training' => $request->string('training')->toString(),
            'emailed' => $request->string('emailed')->toString(),
            'year' => $request->string('year')->toString(),
        ];

        $officeId = $request->user()->scopedFieldOfficeId();

        $certificates = $this->scoped($officeId)
            ->with(['user', 'training'])
            ->when($filters['search'], fn ($query, $search) => $query->where(fn ($inner) => $inner
                ->where('certificate_number', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($user) => $user
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                )
                ->orWhereHas('training', fn ($training) => $training->where('title', 'like', "%{$search}%"))
            ))
            ->when($filters['training'], fn ($query, $id) => $query->where('training_id', $id))
            ->when($filters['emailed'] === '1', fn ($query) => $query->whereNotNull('email_sent_at'))
            ->when($filters['emailed'] === '0', fn ($query) => $query->whereNull('email_sent_at'))
            ->when($filters['year'], fn ($query, $year) => $query->whereYear('generated_at', $year))
            ->latest('generated_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Certificates/Index', [
            'certificates' => $certificates->through(fn (Certificate $certificate) => [
                'id' => $certificate->id,
                'number' => $certificate->certificate_number,
                'participant' => $certificate->user->name,
                'email' => $certificate->user->email,
                'training' => $certificate->training->title,
                'issued_at' => $certificate->generated_at?->format('d M Y'),
                'email_sent_at' => $certificate->email_sent_at?->format('d M Y'),
                'verifications' => $certificate->verification_count,
                'downloads' => $certificate->download_count,
                'last_verified_at' => $certificate->last_verified_at?->diffForHumans(),
                // The public URL printed on the document, so staff can check
                // what a caller is looking at without leaving the page.
                'verify_url' => $certificate->verificationUrl(),
                'download_url' => route('admin.certificates.download', $certificate),
            ]),
            'filters' => $filters,
            'stats' => $this->stats($officeId),
            'trainings' => $this->issuingTrainings($officeId),
            // The years that have actually issued something, so the filter never
            // offers an empty one.
            'years' => $this->scoped($officeId)
                ->selectRaw('YEAR(generated_at) as year')
                ->distinct()
                ->orderByDesc('year')
                ->pluck('year')
                ->map(fn ($year) => ['value' => (string) $year, 'label' => (string) $year])
                ->all(),
            'can' => ['resend' => $this->mayResend($request)],
            'scopedTo' => $request->user()->fieldOffice?->name,
            // The export honours the same filters the register is showing, and
            // the field-office scope, so what staff download is what they see.
            //
            // Empty-string rather than falsy: the "Not yet emailed" option's
            // value is "0", which a bare array_filter drops — so filtering the
            // register to un-emailed certificates and pressing Export handed
            // back every certificate instead, which is the one case where a
            // silently wider download actually matters.
            'exportUrl' => route(
                'admin.exports.certificates',
                array_filter($filters, fn (string $value) => $value !== ''),
            ),
        ]);
    }

    /**
     * One certificate, with the record of who has looked it up — v1's
     * `qrcodes.php` and its verification-history dialog.
     *
     * The counts on the register answer "has anyone checked this?"; this
     * answers the question that follows, which is the one that matters when an
     * employer disputes a document: who checked, from where, and when. The data
     * has been written since release (CertificateService::recordVerification)
     * with nothing reading it back until now.
     */
    public function show(Request $request, Certificate $certificate): Response
    {
        $this->authorizeCertificate($request, $certificate);

        $certificate->load(['user', 'training']);

        return Inertia::render('Admin/Certificates/Show', [
            'certificate' => [
                'id' => $certificate->id,
                'number' => $certificate->certificate_number,
                'participant' => $certificate->user->name,
                'email' => $certificate->user->email,
                'training' => $certificate->training->title,
                'issued_at' => $certificate->generated_at?->format('d M Y, g:i A'),
                'email_sent_at' => $certificate->email_sent_at?->format('d M Y, g:i A'),
                'verifications' => $certificate->verification_count,
                'downloads' => $certificate->download_count,
                'last_verified_at' => $certificate->last_verified_at?->format('d M Y, g:i A'),
                'last_downloaded_at' => $certificate->last_downloaded_at?->format('d M Y, g:i A'),
                'verify_url' => $certificate->verificationUrl(),
                'download_url' => route('admin.certificates.download', $certificate),
                // The same code printed on the document. Rendered server-side
                // as a data URI so the page stays self-contained — no request
                // to an external QR service with a certificate code in the URL.
                'qr' => QrCodeBuilder::dataUri($certificate->verificationUrl(), size: 320),
                'is_released' => $certificate->isReleased(),
            ],
            // Newest first, and capped: this is a "who has been asking lately"
            // panel, not an export. A certificate that has been checked
            // thousands of times should not drag the page down with it.
            'verifications' => $certificate->verifications()
                // Insertion order breaks the tie: `verified_at` has one-second
                // resolution, and a certificate doing the rounds at an agency
                // gets checked several times within the same second.
                ->orderByDesc('verified_at')
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (CertificateVerification $hit) => [
                    'id' => $hit->id,
                    'verified_at' => $hit->verified_at?->format('d M Y, g:i A'),
                    'ip_address' => $hit->ip_address,
                    'user_agent' => $hit->user_agent,
                ])
                ->all(),
            'can' => ['resend' => $this->mayResend($request)],
        ]);
    }

    /**
     * Hand a staff member the issued PDF.
     *
     * The office gets asked to re-send or reprint constantly, and until now the
     * only copy staff could reach was the participant's own download.
     */
    public function download(Request $request, Certificate $certificate): StreamedResponse
    {
        $this->authorizeCertificate($request, $certificate);

        abort_unless($certificate->isReleased(), 404);

        // The record can outlive its file — seeded data carries a path with no
        // PDF behind it, and a purged storage directory does the same.
        abort_unless(Storage::disk(CertificateService::DISK)->exists($certificate->file_path), 404);

        return Storage::disk(CertificateService::DISK)->download(
            $certificate->file_path,
            "{$certificate->certificate_number}.pdf"
        );
    }

    /**
     * Send the certificate email again, ported from v1's
     * `send-certificate-email.php`.
     *
     * The commonest reason is the plainest: the first one went to a mailbox the
     * participant no longer reads, or was never delivered at all. Nothing is
     * re-issued — the same stored PDF is linked, so the document in circulation
     * does not change.
     */
    public function resend(Request $request, Certificate $certificate): RedirectResponse
    {
        $this->authorizeCertificate($request, $certificate);

        abort_unless($certificate->isReleased(), 404);

        $certificate->loadMissing('user');
        $certificate->user->notify(new CertificateReleased($certificate));
        $certificate->forceFill(['email_sent_at' => now()])->save();

        return back()->with(
            'success',
            "Certificate {$certificate->certificate_number} re-sent to {$certificate->user->email}."
        );
    }

    /**
     * Certificates this staff member may see.
     *
     * Field-office staff are limited to their own office's participants, the
     * same invariant the roster and the participant directory carry.
     */
    private function scoped(?int $officeId): Builder
    {
        return Certificate::query()
            // A row with no file is a half-finished release, not a certificate
            // anybody can be shown.
            ->whereNotNull('generated_at')
            ->when($officeId !== null, fn (Builder $query) => $query->whereHas(
                'user.profile',
                fn (Builder $profile) => $profile->where('field_office_id', $officeId)
            ));
    }

    /**
     * @return array<string, int>
     */
    private function stats(?int $officeId): array
    {
        return [
            'total' => $this->scoped($officeId)->count(),
            'this_year' => $this->scoped($officeId)->whereYear('generated_at', now()->year)->count(),
            'not_emailed' => $this->scoped($officeId)->whereNull('email_sent_at')->count(),
            'verifications' => (int) $this->scoped($officeId)->sum('verification_count'),
        ];
    }

    /**
     * Only trainings that have actually issued something — a filter listing
     * every run would be mostly dead options.
     *
     * @return array<int, array{value: int, label: string}>
     */
    private function issuingTrainings(?int $officeId): array
    {
        return Training::whereIn('id', $this->scoped($officeId)->select('training_id'))
            ->orderByDesc('starts_at')
            ->get()
            ->map(fn (Training $training) => [
                'value' => $training->id,
                'label' => $training->title.' — '.$training->starts_at->format('M Y'),
            ])
            ->all();
    }

    private function authorizeCertificate(Request $request, Certificate $certificate): void
    {
        $officeId = $request->user()->scopedFieldOfficeId();

        abort_if(
            $officeId !== null
                && $certificate->user->profile?->field_office_id !== $officeId,
            404
        );
    }

    private function mayResend(Request $request): bool
    {
        return in_array(
            $request->user()->role,
            [Role::Admin, Role::SuperAdmin, Role::FieldOffice],
            true
        );
    }

    /**
     * Issue certificates for a whole training in one go.
     */
    public function releaseTraining(Request $request, Training $training): RedirectResponse
    {
        $awaiting = Registration::where('training_id', $training->getKey())
            ->where('status', RegistrationStatus::Completed)
            // A participant who said at registration that they do not need a
            // certificate is left out of the batch. Printing one anyway is
            // wasted paper, and worse, it puts a document into circulation
            // that nobody asked for. Individual release still works for them
            // if they change their mind.
            ->where('needs_certificate', true)
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
