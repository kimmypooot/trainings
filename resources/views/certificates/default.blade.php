{{--
    The issued certificate.

    Rendered once at release and stored, so this template can change without
    altering documents already in participants' hands. Written for dompdf,
    which supports neither flexbox nor grid — hence the absolute positioning,
    which is the layout tool it actually honours.

    Ported from v1's CertificateEngine::renderA4() (the "Completion"
    category) — portrait A4, "This" / red title / "is given to" / navy name
    (underlined by an independent fixed-width rule, not one sized to the
    text) / "for completing" / navy training title / a multi-line
    venue-or-online clause / "Given this Nth day of Month Year." / a centred
    signature block / a QR code anchored bottom-left with the certificate
    number printed beneath it — now over the actual certificate_bg.jpg
    supplied for this deployment, in the fonts CertificateEngine registers
    for this category (Calibri for body text, Roboto Bold for the title,
    Mirante Bold for the name and training title).

    The background already carries the CSC seal (top) and the "Lingkod
    Bayani" mark (bottom) inside its own ribbon banners, so — unlike the
    version of this template before this background existed — nothing here
    draws a frame, a seal, or the issuing office's name as text: the artwork
    is doing that job now. `resources/images/certificates/certificate_bg.jpg`
    is this deployment's own upload, not a generic CSC asset, so a future
    redeploy for a different office swaps that file rather than editing this
    one — the same reasoning `config('office.*')` follows elsewhere, applied
    to a picture instead of a string.

    ── On the positioning below ──────────────────────────────────────────
    Every element here is placed at an absolute (top, left, width) traced
    directly out of renderA4()'s own cursor math — not a CSS margin flow
    approximating it, which is what this template did before this pass and
    is exactly why it drifted from CertificateEngine's actual spacing: a
    browser's line-height and a TCPDF Cell's height are not the same
    quantity, and small per-element differences compound going down the
    page. Positioning every element independently, from the same
    millimetre coordinates TCPDF used, means the drift cannot compound —
    each element is right (or wrong) on its own, not relative to the one
    above it.

    Conversion is uniform: 794px / 210mm ≈ 3.7809524 px/mm, applied to both
    axes (the page is 794×1123 for 210×297mm, which is the same ratio to
    four decimal places). Every top/left/width value in this file's CSS is
    that constant times a millimetre coordinate traced from v1's source —
    see the comment on each rule for the trace.

    A training title long enough to wrap onto a second line does push
    everything below it down, matching v1's own MultiCell() — measured and
    applied as $titleWrapOffset (see CertificateService::wrapLines()), since
    nothing here has TCPDF's cursor to do that on its own. The one position
    still accepted as fixed rather than measured is the signature block's y
    (see the note on .el-signature-name for why its own floor-vs-ceiling
    formula absorbs a wrapped line without needing to move) — for the same
    reason a short, single-purpose certificate is what this application
    actually issues, and that is the case the rest of this layout is traced
    against.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $certificate->certificate_number }}</title>
    <style>
        @page { margin: 0; }

        /*
            Registered against the TTFs committed in resources/fonts, the same
            way Poppins is elsewhere in the app — dompdf reads neither the
            browser's webfonts nor a remote URL (enable_remote is off), so
            each face has to be a local file. Paths are absolute and
            forward-slashed: they sit inside dompdf's chroot (the app root)
            and the Windows separator does not survive its URL parser.

            Only the four faces CertificateEngine::renderA4() actually uses
            for this category are registered — Calibri Regular/Italic for
            body text, Roboto Bold for the title, Mirante Bold for the name
            and training title. Certification/Appearance are not issued by
            this deployment (see CLAUDE.md's note on certificate categories),
            so their extra faces (Calibri Bold, Roboto Regular/Italic) are not
            copied in until one of those is actually built.
        */
        @font-face {
            font-family: 'Calibri';
            font-weight: 400;
            font-style: normal;
            src: url("{{ str_replace(DIRECTORY_SEPARATOR, '/', resource_path('fonts/Calibri.ttf')) }}") format('truetype');
        }

        @font-face {
            font-family: 'Calibri';
            font-weight: 400;
            font-style: italic;
            src: url("{{ str_replace(DIRECTORY_SEPARATOR, '/', resource_path('fonts/Calibri-Italic.ttf')) }}") format('truetype');
        }

        @font-face {
            font-family: 'Roboto';
            font-weight: 700;
            font-style: normal;
            src: url("{{ str_replace(DIRECTORY_SEPARATOR, '/', resource_path('fonts/Roboto-Bold.ttf')) }}") format('truetype');
        }

        @font-face {
            font-family: 'Mirante';
            font-weight: 400;
            font-style: normal;
            src: url("{{ str_replace(DIRECTORY_SEPARATOR, '/', resource_path('fonts/Mirante-Bold.ttf')) }}") format('truetype');
        }

        body {
            margin: 0;
            font-family: 'Calibri', 'DejaVu Sans', sans-serif;
            color: #1f2937;
            /* A4 portrait at 96dpi — matches v1's renderA4(), which is
               'P' (portrait) throughout; only the merged/landscape formats
               (a different certificate category this deployment does not
               issue) are landscape in v1. */
            width: 794px;
            height: 1123px;
            position: relative;
            background-image: url("{{ str_replace(DIRECTORY_SEPARATOR, '/', resource_path('images/certificates/certificate_bg.jpg')) }}");
            /* The supplied artwork is already A4-portrait proportioned
               (4419×6250 ≈ the same 0.7071 ratio as 210×297mm), so a plain
               100%/100% fill reaches every edge with no crop and no
               distortion — cover would do the same here, but only because
               the ratios already match. */
            background-size: 100% 100%;
            background-repeat: no-repeat;
        }

        /*
            Every text element shares this base — position is the only thing
            that varies per element, set individually below.

            Two things here exist specifically because dompdf is not a
            browser, found by comparing its actual PDF output against these
            rules rather than trusting a browser preview of the same HTML
            (which renders this file with a browser engine's CSS support,
            not dompdf's — the two disagree often enough that this is the
            only preview worth trusting):

            `line-height: 1` is load-bearing. Left at its default ("normal"),
            the line box dompdf reserves for a custom font is whatever that
            font's own metrics claim — and Mirante's claimed metrics are
            large enough that its default line box ran well past the
            element's own `top`, overlapping whatever was positioned right
            below it (the name's underline sat across the name's own text,
            "for completing" crowded the name, the venue line crowded the
            training title). Pinning it to 1 makes the box height track the
            font-size this template actually sets, not a per-font guess.

            `width` (not `right`) is the other one. `left: 0; right: 0;` —
            ordinary CSS for "full width, no explicit number" — measured
            correctly in a browser and did not in dompdf, whose box model
            does not reliably compute a width from `left`+`right` alone; the
            title rendered far wider than 32px Roboto with 4px tracking
            should and ran off the right edge of the page. Every full-width
            element below states its width explicitly instead.
        */
        p { margin: 0; position: absolute; text-align: center; line-height: 1; }

        /* Font-size in px throughout is v1's own pt size × 4/3 (96dpi). */

        /* SetXY(0, 59); Cell(210, 6, 'This', ...): full page width, y=59mm. */
        .el-this { top: 223px; left: 0; width: 794px; font-size: 19px; }

        /* SetXY(0, 68); Cell(210, 10, $titleText, ...): y=68mm. v1 titles
           this category in red (self::$red), not navy — navy is reserved
           for the participant's name and the training title. */
        .el-title {
            top: 257px;
            left: 0;
            width: 794px;
            margin: 0;
            font-family: 'Roboto', 'DejaVu Sans', sans-serif;
            font-weight: 700;
            font-size: 32px;
            /*
                No letter-spacing: v1's Cell() call has no such concept —
                TCPDF has no letter-spacing property at all — so this was
                purely a stylistic addition on top of the port, not something
                being adapted from CertificateEngine. It is also what was
                pushing this title past the page's right edge: dompdf
                combines a custom @font-face with letter-spacing far more
                aggressively than a browser does, confirmed by removing it
                here and nowhere else changing.
            */
            text-transform: uppercase;
            color: #ec1c2d;
        }

        /* Ln(4) after the title (y 78→82); Cell(210, 6, $preamble, ...). */
        .el-preamble { top: 310px; left: 0; width: 794px; font-size: 19px; }

        /* Ln(6) after the preamble (y 88→94); SetX(20); Cell(170, 10,
           $paxName, ...): centred in v1's own 170mm box, not the full page
           — the box v1's auto-shrink loop measures GetStringWidth()
           against. The training title below no longer shares this width —
           see its own comment. */
        .el-name {
            top: 355px;
            left: 76px;
            width: 643px;
            font-family: 'Mirante', 'DejaVu Sans', sans-serif;
            color: #2a338f;
            /* v1's Cell() (unlike the training title's MultiCell()) never
               wraps a name — a name too wide for its box simply runs past
               it. white-space: nowrap keeps that the same failure mode here
               rather than a new one (wrapping into the underline below it),
               since nothing measures the name the way titleWrapOffset
               measures the training title. */
            white-space: nowrap;
        }

        /*
            Ln(2) after the name (y 104→106); Line(50, 106, 160, 106): an
            independent line from a fixed x=50mm to x=160mm — a constant
            110mm span regardless of how long or short the name is, not an
            underline sized to the text.
        */
        .el-name-rule {
            position: absolute;
            top: 408px;
            left: 189px;
            width: 416px;
            border-top: 1px solid #2a338f;
        }

        /* Ln(4) after the rule (y 106→110); Cell(210, 6, $actionText, ...). */
        .el-action { top: 416px; left: 0; width: 794px; font-size: 19px; }

        /* Ln(1) after the action text (y 116→117); MultiCell(170, 9,
           $title, ..., x=20) in v1 — 170mm, the same box the name above
           uses. Widened here to 780px (~206mm-equivalent — a 7px/~2mm
           gutter either side, the narrowest this has been asked to go) so
           a long title has more room before it needs to wrap at all, and
           less unused margin either side of it once it does — see
           CertificateService::TRAINING_TITLE_BOX_WIDTH.
           $trainingTitleLines is already wrapped to fit this width — see
           CertificateService::wrapLines() — and joined with <br> in the
           markup below, not left as one string for dompdf to wrap on its
           own (see the note on the base `p` rule for why that overflowed). */
        .el-training {
            top: 442px;
            left: 7px;
            width: 780px;
            font-family: 'Mirante', 'DejaVu Sans', sans-serif;
            color: #2a338f;
        }

        /*
            Ln(3) after the training title (y 126→129); SetX(30);
            MultiCell(150, 6, ...): a 150mm box starting at x=30mm, not the
            full page — narrower than the name/training box above it, which
            is why the venue sentence wraps sooner than either of those
            would. Two lines for the offline branch ("held on {date} at
            the" / "{venue}."), a Ln(2) apart (137−131); three for the
            online branch, a Ln(1) apart (129/136/143) — traced separately
            below since the two branches consume different heights.

            The traced 488px left a visibly bigger gap above this line than
            the one between "for completing" and the training title above
            it — both exist because `line-height: 1` (see the note on the
            base `p` rule) makes each box's true height a font-size, not
            v1's own Cell/Ln heights, so a gap traced from those no longer
            reads as the same gap once rendered. The visual gap that
            actually matters is not the same as the top-to-top distance,
            though: the training title's own box (32px) is taller than
            "for completing"'s (19px), so it needs more clearance below it
            to leave the *same* visible whitespace, not less — an equal
            top-to-top distance (tried at 26px) ran the two lines into each
            other outright, and 478px still touched. 492px is what actually
            clears it, confirmed against the rendered PDF each time, not
            computed from a formula.

            `top` is not set here: a training title long enough to wrap onto
            a second line pushes every one of these down by
            $titleWrapOffset (0 when it doesn't), applied as an inline style
            in the markup below rather than a fixed number in this
            stylesheet — see CertificateService::wrapLines(). This is
            this template's equivalent of v1's own cursor moving further
            down the page after a wrapped MultiCell().
        */
        .el-venue-1 { left: 113px; width: 567px; font-size: 19px; }
        .el-venue-2 { left: 113px; width: 567px; font-size: 19px; }
        .el-venue-3 { left: 113px; width: 567px; font-size: 19px; }

        /*
            Ln(6) after the venue block; writeHTML(...). The offline branch
            ends its last MultiCell at y=143mm (→149mm after the Ln); the
            online branch's third line ends at y=149mm (→155mm) — six
            millimetres lower, because it is one line and one Ln(1) longer.
            `top` is inline for the same reason as the venue lines above.
        */
        .el-given { left: 0; width: 794px; font-size: 19px; }
        .el-given sup { font-size: 13px; }

        /*
            Centred, not off to one side — v1 sets the signing officer's name
            and title dead-centre across the page, independently of the QR,
            at `min(max(200, contentEndY + 25), 236)` millimetres: a floor of
            200mm, a ceiling of 236mm, and content length decides where in
            between. This template has no cursor to ask "how far down did the
            content actually run" the way TCPDF does, so it needs one fixed
            number — and the floor, not the ceiling, is what a short,
            single-purpose certificate like this one's converges to: with no
            credit-hours line and a training title that is one line for
            anything under ~46 characters, v1's own content ends well short
            of the ~175mm needed to push past 200mm at all.
        */
        /* SetXY(0, 191); Cell(210, 10, ..., 'C'): full page width, y=191mm
           (sig1NameY(200) − 9). Moved down 40px net from the traced
           position (60px down, then back up 20px) — v1's own value left
           more clearance above the QR than this deployment wants, and the
           first move down overshot it slightly. Still well clear of the QR
           (93px above its own top at this position, checked against the
           rendered PDF). */
        .el-signature-name {
            top: 762px;
            left: 0;
            width: 794px;
            font-family: 'Mirante', 'DejaVu Sans', sans-serif;
            font-size: 25px;
            color: #2a338f;
        }

        /* Ln(-1) then Cell(210, 6, [title line], ...): y=200mm — v1 prints
           the title line immediately under the name, with essentially no
           gap between the two cells. Moved down the same net 40px as the
           name above, to stay flush beneath it. */
        .el-signature-title {
            top: 796px;
            left: 0;
            width: 794px;
            font-size: 23px;
            color: #2a338f;
        }

        /*
            Bottom-left, independent of the signature block — v1's QR sits at
            a fixed (x=20mm, y=235mm), sized 35mm, with its "Cert No:" caption
            immediately below (y=270mm, flush against the QR's own bottom
            edge) inside a slightly wider box (x=10mm, 55mm wide) whose centre
            lines up with the QR's own centre — which is what centring this
            wider box does here, rather than centring the caption under the
            QR image alone. The "Lingkod Bayani" mark at the foot of the
            supplied background sits bottom-*centre* (roughly 42–62% of the
            page width) against this block's bottom-*left* position, so the
            two occupy different horizontal territory despite overlapping in
            y — checked against the actual artwork, not assumed.
        */
        .verification {
            position: absolute;
            top: 889px;
            left: 38px;
            width: 208px;
            text-align: center;
        }

        .qr { width: 132px; height: 132px; }

        .cert-no {
            position: static;
            margin: 0;
            font-size: 11px;
            color: #4b5563;
        }
    </style>
</head>
<body>
    <p class="el-this">This</p>
    <p class="el-title">Certificate of Completion</p>

    <p class="el-preamble">is given to</p>
    <p class="el-name" style="font-size: {{ $nameSize }}px;">{{ $participant->name }}</p>
    <div class="el-name-rule"></div>

    <p class="el-action">for completing</p>
    <p class="el-training" style="font-size: {{ $titleSize }}px;">
        {!! implode('<br>', array_map('e', $trainingTitleLines)) !!}
    </p>

    @if ($training->mode === \App\Enums\TrainingMode::Online)
        <p class="el-venue-1" style="top: {{ 492 + $titleWrapOffset }}px;">conducted online by the</p>
        <p class="el-venue-2" style="top: {{ 518 + $titleWrapOffset }}px;">{{ config('office.name') }} on</p>
        <p class="el-venue-3" style="top: {{ 545 + $titleWrapOffset }}px;">{{ $displayDate }}.</p>
        <p class="el-given" style="top: {{ 590 + $titleWrapOffset }}px;">
            Given this {{ $givenDay }}<sup>{{ $givenSuffix }}</sup> day of {{ $givenMonthYear }}.
        </p>
    @else
        <p class="el-venue-1" style="top: {{ 492 + $titleWrapOffset }}px;">held on {{ $displayDate }} at the</p>
        <p class="el-venue-2" style="top: {{ 522 + $titleWrapOffset }}px;">{{ $training->venue }}.</p>
        <p class="el-given" style="top: {{ 567 + $titleWrapOffset }}px;">
            Given this {{ $givenDay }}<sup>{{ $givenSuffix }}</sup> day of {{ $givenMonthYear }}.
        </p>
    @endif

    <p class="el-signature-name">{{ $signatoryName }}</p>
    @if ($signatoryTitle)
        <p class="el-signature-title">{{ $signatoryTitle }}</p>
    @endif

    <div class="verification">
        <img class="qr" src="{{ $qr }}" alt="Verification QR code">
        <p class="cert-no">Cert No: {{ $certificate->certificate_number }}</p>
    </div>
</body>
</html>
