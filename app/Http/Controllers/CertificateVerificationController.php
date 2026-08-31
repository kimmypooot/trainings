<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Support\CertificateService;
use Illuminate\Http\RedirectResponse;
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
    /**
     * The code-entry form.
     *
     * Verification worked from the first release, but only for someone who
     * already held the URL — the QR on a printed certificate encodes it. Anyone
     * reading the code off the page with no scanner, or holding a photocopy, or
     * simply told "check it online", had nowhere to go: nothing on the site
     * linked here and there was no way in without the code already in the
     * address bar. A feature whose whole purpose is to be checked by an
     * outsider needs a front door.
     */
    public function form(): Response
    {
        return Inertia::render('Certificates/VerifyLookup');
    }

    /**
     * Exchange a typed code for the canonical result URL.
     *
     * A redirect rather than rendering the result here, so that what the
     * verifier ends up looking at is /verify/{code} — the address they can
     * bookmark, forward to a colleague, or paste into a report. It is also the
     * address the QR already points at, so both routes in reach the same page
     * and there is only one result view to keep honest.
     */
    public function lookup(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ], [], ['code' => 'verification code']);

        // Whitespace and case are the two things a human retyping a code off a
        // printed page gets wrong, and neither is a real mismatch.
        $code = trim($validated['code']);

        $exists = Certificate::where('verification_code', $code)
            ->whereNotNull('generated_at')
            ->exists();

        if (! $exists) {
            /*
             * Back to the form with a message, not a 404.
             *
             * A direct link with a bad code still 404s — that is a wrong URL and
             * saying so is correct. But someone who has just typed a code into a
             * box has almost certainly made a typo, and answering a typo with an
             * error page throws away what they typed and gives them nothing to
             * correct. The wording avoids accusing the certificate of being
             * forged: far and away the likelier explanation is a misread
             * character.
             */
            return back()
                ->withInput()
                ->withErrors([
                    'code' => 'No certificate matches that code. Check for a mistyped character — codes are case-sensitive.',
                ]);
        }

        return redirect()->route('certificates.verify', ['code' => $code]);
    }

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
                // Already in the address bar; shown so a printed copy of this
                // page carries the code it was checked against.
                'code' => $certificate->verification_code,
            ],
            /*
             * When this check was run — not a property of the certificate.
             *
             * The result page is a thing people print and file: an HR officer
             * confirming a new hire's training, an auditor working through a
             * folder. Without a timestamp the printout says only "this was true
             * at some point", which is the one thing a filed record must not be
             * vague about.
             */
            'verifiedAt' => now()->format('d F Y \a\t g:i A'),
        ]);
    }
}
