{{--
    The issued certificate.

    Rendered once at release and stored, so this template can change without
    altering documents already in participants' hands. Written for dompdf,
    which supports neither flexbox nor grid — hence the tables and absolute
    positioning, which are the layout tools it actually honours.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $certificate->certificate_number }}</title>
    <style>
        @page { margin: 0; }

        body {
            margin: 0;
            font-family: 'DejaVu Serif', Georgia, serif;
            color: #1f2937;
            /* A4 landscape at 96dpi. */
            width: 1123px;
            height: 794px;
            position: relative;
        }

        .frame {
            position: absolute;
            top: 26px; right: 26px; bottom: 26px; left: 26px;
            border: 3px solid #2a338f;
        }

        .frame-inner {
            position: absolute;
            top: 8px; right: 8px; bottom: 8px; left: 8px;
            border: 1px solid #ec1c2d;
        }

        .content { position: absolute; top: 66px; left: 90px; right: 90px; text-align: center; }

        .seal { height: 78px; }

        .issuer {
            margin: 12px 0 0;
            font-size: 13px;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #2a338f;
        }

        .office { margin: 3px 0 0; font-size: 12px; color: #4b5563; }

        .title {
            margin: 26px 0 0;
            font-size: 40px;
            letter-spacing: 7px;
            text-transform: uppercase;
            color: #1e2668;
        }

        .rule { width: 150px; height: 2px; margin: 14px auto 0; background: #ec1c2d; }

        .preamble { margin: 26px 0 0; font-size: 13px; color: #4b5563; }

        .name {
            margin: 12px 0 0;
            font-size: 34px;
            color: #1e2668;
            border-bottom: 1px solid #d1d5db;
            display: inline-block;
            padding: 0 40px 6px;
        }

        .training { margin: 22px 0 0; font-size: 19px; font-weight: bold; color: #1f2937; }

        .detail { margin: 8px 0 0; font-size: 13px; color: #4b5563; }

        .footer { position: absolute; bottom: 62px; left: 90px; right: 90px; }

        .footer td { vertical-align: bottom; font-size: 11px; color: #4b5563; }

        .signature-line {
            border-top: 1px solid #6b7280;
            padding-top: 5px;
            width: 230px;
            font-size: 12px;
            color: #1f2937;
        }

        .qr { width: 96px; height: 96px; }

        .verify { font-size: 9px; color: #6b7280; margin: 3px 0 0; }

        .serial {
            position: absolute;
            bottom: 34px;
            left: 90px;
            font-size: 10px;
            letter-spacing: 1px;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <div class="frame"><div class="frame-inner"></div></div>

    <div class="content">
        @if ($mark = \App\Support\BrandAssets::mark())
            <img class="seal" src="{{ $mark }}" alt="">
        @endif

        <p class="issuer">Civil Service Commission</p>
        <p class="office">Regional Office VIII &middot; Eastern Visayas</p>

        <h1 class="title">Certificate of Completion</h1>
        <div class="rule"></div>

        <p class="preamble">This is to certify that</p>
        <p class="name">{{ $participant->name }}</p>

        <p class="training">{{ $training->title }}</p>

        <p class="detail">
            has satisfactorily completed the above training
            @if ($training->duration_days > 1)
                conducted over {{ $training->duration_days }} days
            @endif
            @if ($training->starts_at->isSameDay($training->ends_at))
                on {{ $training->starts_at->format('d F Y') }}
            @else
                from {{ $training->starts_at->format('d F Y') }} to {{ $training->ends_at->format('d F Y') }}
            @endif
            at {{ $training->venue }}.
        </p>
    </div>

    <table class="footer" width="100%">
        <tr>
            <td width="40%">
                <div class="signature-line">
                    {{ $training->facilitator_name ?: 'Authorized Signatory' }}<br>
                    <span style="font-size: 10px; color: #6b7280;">Civil Service Commission RO VIII</span>
                </div>
            </td>
            <td width="30%" style="text-align: center;">
                Issued {{ $certificate->generated_at?->format('d F Y') ?? now()->format('d F Y') }}
            </td>
            <td width="30%" style="text-align: right;">
                <img class="qr" src="{{ $qr }}" alt="Verification QR code">
                <p class="verify">Scan to verify authenticity</p>
            </td>
        </tr>
    </table>

    <p class="serial">Certificate No. {{ $certificate->certificate_number }}</p>
</body>
</html>
