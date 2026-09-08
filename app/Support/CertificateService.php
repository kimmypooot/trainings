<?php

namespace App\Support;

use App\Enums\RegistrationStatus;
use App\Models\Certificate;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\CertificateReleased;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
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
 *
 * `regenerate()` is the deliberate exception to that, not a contradiction of
 * it: staff sometimes need to correct a certificate that already went out — a
 * typo'd name fixed on the profile after release, a template fix that should
 * reach documents already issued. It is a manual, per-certificate, logged
 * action, never an automatic one, and the certificate number and verification
 * code never change, so the QR on a certificate printed before the fix keeps
 * verifying against the same record.
 */
class CertificateService
{
    /** Certificates live on a private disk and are served through an authorising controller. */
    public const DISK = 'local';

    /**
     * The box the training title wraps within — wider than v1's own 170mm
     * (which the participant's name box, `.el-name` in the template, still
     * uses), so a long title has more room before it wraps at all and less
     * empty margin either side of it once it does. Shared with wrapLines()
     * so the measurement and the box it is measured against cannot drift
     * apart — if this changes, the template's `.el-training` `left`/`width`
     * have to change with it.
     */
    private const TRAINING_TITLE_BOX_WIDTH = 780.0;

    /**
     * How much taller than its own font-size a line of Mirante actually
     * renders at `line-height: 1` — not the 1.0 that number implies.
     *
     * Traced against v1 this would be one MultiCell(…, 9, …) row (9mm, a
     * fixed value independent of font size, since TCPDF's row height is
     * whatever the call states). This is not that: it is a multiplier on
     * the *actual* titleSize in play, calibrated against the real rendered
     * PDF rather than v1's number, because `line-height: 1` on this font
     * turned out not to mean "one font-size tall" — see the note on the
     * base `p` rule. Every gap this template traced against a Mirante
     * element needed noticeably more clearance than the element's own
     * font-size accounted for (confirmed each time by regenerating the
     * actual PDF, not estimated), and a title that wraps onto a third line
     * at a shrunk font-size needs that same disproportionate clearance per
     * extra line, not v1's flat 9mm.
     */
    private const WRAPPED_LINE_HEIGHT_MULTIPLIER = 1.8;

    /** The training-title face, measured against for wrapping — see wrapLines(). */
    private const MIRANTE_FONT = __DIR__.'/../../resources/fonts/Mirante-Bold.ttf';

    /**
     * Issue a certificate for a completed registration.
     *
     * Idempotent on the registration: calling it twice returns the existing
     * certificate rather than minting a second number for the same person.
     */
    public static function release(Registration $registration, User $releasedBy): Certificate
    {
        $registration->loadMissing(['user', 'training', 'payments']);

        if ($registration->status !== RegistrationStatus::Completed) {
            throw ValidationException::withMessages([
                'certificate' => 'Only a completed registration can be issued a certificate.',
            ]);
        }

        /*
         * A promissory note buys a seat, not a certificate. The office lets the
         * participant attend on the strength of the note, but the document that
         * proves they attended is withheld until the fee is actually settled —
         * it is the only leverage left once the training is over, and a
         * certificate cannot be recalled after it has been handed out.
         */
        if (! $registration->hasClearedFee()) {
            throw ValidationException::withMessages([
                'certificate' => sprintf(
                    '%s attended on a promissory note. The certificate is held until the fee is paid and verified.',
                    $registration->user->name
                ),
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

        // A certificate is the one artefact here that circulates outside CSC
        // and cannot be recalled, so its issuance is worth a trail entry even
        // though nothing about it ever changes afterwards.
        ActivityLogger::record(
            'certificate.released',
            $certificate,
            "Certificate {$certificate->certificate_number} issued to {$registration->user->name}.",
            [
                'training_id' => $registration->training_id,
                'registration_id' => $registration->getKey(),
            ],
            $releasedBy,
        );

        $registration->user->notify(new CertificateReleased($certificate));

        $certificate->forceFill(['email_sent_at' => now()])->save();

        return $certificate;
    }

    /**
     * Re-render an already-issued certificate's PDF from its current data.
     *
     * The number and verification code are untouched — this replaces the
     * file behind them, not the document's identity. `render()` derives the
     * storage path from `verification_code`, which does not change here, so
     * the new PDF lands at the same path and simply overwrites the old one;
     * nothing referencing this certificate (the download link, the QR code,
     * the public verify page) needs to change.
     *
     * Not called from anywhere automatically — see the class docblock.
     */
    public static function regenerate(Certificate $certificate, User $actor): Certificate
    {
        if (! $certificate->isReleased()) {
            throw ValidationException::withMessages([
                'certificate' => 'Only an already-issued certificate can be regenerated.',
            ]);
        }

        $certificate->loadMissing(['user', 'training']);

        $certificate->forceFill([
            'file_path' => self::render($certificate),
        ])->save();

        ActivityLogger::record(
            'certificate.regenerated',
            $certificate,
            "Certificate {$certificate->certificate_number} PDF regenerated for {$certificate->user->name}.",
            [
                'training_id' => $certificate->training_id,
                'registration_id' => $certificate->registration_id,
            ],
            $actor,
        );

        return $certificate;
    }

    /**
     * Render the PDF and return its path on the private disk.
     */
    private static function render(Certificate $certificate): string
    {
        $certificate->loadMissing(['user.profile', 'training']);

        $issuedOn = $certificate->generated_at ?? now();

        /*
         * The training's own `signatory_name` wins when a run sets one — that
         * is what lets a training actually presided over by someone else say
         * so. Otherwise this falls back to the office-wide default from
         * OfficeSetting, and only then to the generic "Authorized Signatory"
         * the template always had.
         *
         * The title line is shown only alongside the *office default* name,
         * never alongside a per-training override: `office.default_signatory_title`
         * is known to pair with `office.default_signatory_name`, but a
         * training that names a different signatory (a guest facilitator,
         * say) has told us a name, not a title, and printing the office
         * default's title under a different person's name would misstate who
         * they are.
         */
        $usingDefaultSignatory = $certificate->training->signatory_name === null
            && config('office.default_signatory_name');

        $signatoryName = $certificate->training->signatory_name
            ?: (config('office.default_signatory_name') ?: 'Authorized Signatory');

        $signatoryTitle = $usingDefaultSignatory ? config('office.default_signatory_title') : null;

        $pdf = Pdf::loadView('certificates.default', [
            'certificate' => $certificate,
            'participant' => $certificate->user,
            'training' => $certificate->training,
            // Small and logo-free: at this size the CSC mark would eat enough
            // modules to stop the code scanning off a printed page.
            'qr' => QrCodeBuilder::dataUri($certificate->verificationUrl(), size: 300, withLogo: false),
            // "Given this 15th day of June 2026." — ported from v1's wording.
            // Split into pieces here rather than computed in the template:
            // ordinal-suffix arithmetic is a fact about the date, not a view
            // concern, and a template has no business doing date math dompdf
            // then has to render into HTML anyway.
            'givenDay' => (int) $issuedOn->format('j'),
            'givenSuffix' => self::ordinalSuffix((int) $issuedOn->format('j')),
            'givenMonthYear' => $issuedOn->format('F Y'),
            // v1's own date string for the venue sentence — never "held from
            // X to Y", which this template invented for a multi-day run
            // before this pass. A single smart range reads correctly whether
            // the run is one day or several: see displayDateRange().
            // A null ends_at means a single-day run — Training's own
            // convention (see its docblock) — so starts_at stands in as the
            // end for that purpose here too.
            'displayDate' => self::displayDateRange(
                $certificate->training->starts_at,
                $certificate->training->ends_at ?? $certificate->training->starts_at,
            ),
            'signatoryName' => $signatoryName,
            'signatoryTitle' => $signatoryTitle,
            // A long name or training title is common enough (agency names,
            // multi-part course titles) that fixed point sizes either clip or
            // wrap unpredictably in dompdf, which cannot be asked mid-render
            // to measure its own text the way TCPDF's GetStringWidth loop in
            // v1 does. This is that loop's spirit without the capability:
            // point size is a function of character count, computed once,
            // ahead of render.
            //
            // Base and floor are v1's own point sizes for this category
            // (26pt→18pt for the name, 24pt→20pt for the training title),
            // converted to px at 96dpi (×4/3) — not the arbitrary pixel
            // values an earlier pass of this template picked by eye, which
            // is why the rendered PDF looked smaller than intended: nothing
            // was actually wrong with $nameSize/$titleSize being applied,
            // the numbers going in were just too small.
            'nameSize' => $nameSize = self::shrinkToFit($certificate->user->name, base: 35, min: 24, threshold: 28),
            'titleSize' => $titleSize = self::shrinkToFit($certificate->training->title, base: 32, min: 27, threshold: 46),
            // Wrapped here, once, rather than left as one string inside a
            // width-constrained box for dompdf to wrap on its own — see
            // wrapLines() for why that measured wider than it should and
            // overflowed the box.
            'trainingTitleLines' => $trainingTitleLines = self::wrapLines(
                $certificate->training->title,
                self::MIRANTE_FONT,
                $titleSize,
                self::TRAINING_TITLE_BOX_WIDTH,
            ),
            // How far to push every element below the training title down,
            // for the same reason v1's own MultiCell() does: a title that
            // wraps onto a second line needs the rest of the layout to make
            // room for it, not print through it. shrinkToFit() above keeps
            // most titles to one line, but a long one still wraps — and
            // unlike v1, nothing here has a cursor that naturally reflows
            // when that happens, so the offset has to be measured and
            // applied explicitly.
            'titleWrapOffset' => (count($trainingTitleLines) - 1)
                * (int) round($titleSize * self::WRAPPED_LINE_HEIGHT_MULTIPLIER),
        ])->setPaper('a4', 'portrait');

        $path = "certificates/{$certificate->verification_code}.pdf";

        Storage::disk(self::DISK)->put($path, $pdf->output());

        return $path;
    }

    /**
     * "1st", "2nd", "3rd", "4th"… for the printed issue date.
     */
    public static function ordinalSuffix(int $day): string
    {
        if ($day % 100 >= 11 && $day % 100 <= 13) {
            return 'th';
        }

        return match ($day % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }

    /**
     * "3 August 2026", or "3–5 August 2026", or "30 July–2 August 2026" —
     * ported from v1's `buildDisplayDate()`. v1 never branches its venue
     * sentence into "held from X to Y"; a multi-day run instead gets one
     * smart date string that a single "held on {date} at the…" line already
     * reads correctly, same-day or not.
     */
    public static function displayDateRange(CarbonInterface $start, CarbonInterface $end): string
    {
        if ($start->isSameDay($end)) {
            return $start->format('j F Y');
        }

        if ($start->isSameMonth($end)) {
            return $start->format('j').'–'.$end->format('j F Y');
        }

        return $start->format('j F').'–'.$end->format('j F Y');
    }

    /**
     * A point size that shrinks as text grows past `threshold` characters,
     * down to `min` — one point for every four characters over, which keeps a
     * long agency name or course title from clipping or wrapping onto a third
     * line inside the fixed box the template gives it.
     */
    private static function shrinkToFit(string $text, int $base, int $min, int $threshold): int
    {
        $over = mb_strlen($text) - $threshold;

        if ($over <= 0) {
            return $base;
        }

        return max($min, $base - intdiv($over, 4));
    }

    /**
     * $text, greedily word-wrapped to fit within $maxWidthPx, set in
     * $fontFile at $fontSizePx — the same question v1's own `getNumLines()`
     * (a TCPDF method, backed by its own font-metrics engine) answers before
     * deciding whether the training title needs to wrap at all. Measured
     * here through GD's `imagettfbbox()`, which is also FreeType-backed and
     * reads the same font file, rather than guessed at.
     *
     * The lines are rendered verbatim in the template — `<br>`-joined,
     * never left as one long string inside a width-constrained box for
     * dompdf to wrap on its own. That was tried first and dompdf's own
     * line-breaking for this custom font measured text noticeably wider
     * than it actually is, wrapping later than it should and overflowing
     * the box (the same class of measurement gap that made letter-spacing
     * push the title off the page — see that note above). Wrapping the text
     * here, once, with the one measurement already known to be correct
     * (it's what decides how far to push the content below), means dompdf
     * only ever has to *place* already-line-broken text, never *break* it.
     *
     * A plain greedy word-wrap — the same algorithm a browser's own line
     * breaking uses for ordinary text without hyphenation.
     *
     * @return array<int, string>
     */
    private static function wrapLines(string $text, string $fontFile, float $fontSizePx, float $maxWidthPx): array
    {
        $words = preg_split('/\s+/', trim($text));
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : "{$current} {$word}";
            $box = imagettfbbox($fontSizePx, 0, $fontFile, $candidate);
            $width = abs($box[2] - $box[0]);

            if ($width > $maxWidthPx && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [$text] : $lines;
    }

    /**
     * The printed, human-readable number.
     *
     * Sequential per year in the style of v1's `certificate_number`, so CSC can
     * quote "certificate 42 of 2026" in correspondence.
     *
     * The prefix is `office.certificate_prefix` rather than a literal string —
     * this codebase is deployed per office, and a deployment that wants a
     * region-coded prefix (CSC8 for Region VIII, say) sets it explicitly. It
     * defaults to CERT.
     */
    private static function nextNumber(Registration $registration): string
    {
        $year = $registration->training->starts_at->format('Y');
        $prefix = config('office.certificate_prefix');
        $sequence = Certificate::whereHas(
            'training',
            fn ($query) => $query->whereYear('starts_at', $year)
        )->count() + 1;

        do {
            $number = sprintf('%s-%s-%05d', $prefix, $year, $sequence);
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
