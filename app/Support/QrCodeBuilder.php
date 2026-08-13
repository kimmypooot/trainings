<?php

namespace App\Support;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

/**
 * The one place a QR code is drawn.
 *
 * Participant codes and certificate verification codes have the same
 * constraints — they get printed, photocopied and scanned in bad light — so
 * they share the settings rather than each carrying their own copy.
 */
class QrCodeBuilder
{
    /** Logo stays under ~22% of the width; beyond that even level H stops scanning reliably. */
    private const LOGO_RATIO = 0.213;

    /**
     * Render a URL as a PNG data URI.
     *
     * @param  bool  $withLogo  The CSC mark is punched into the middle. Dropped
     *                          for small codes, where it would eat the payload.
     */
    public static function dataUri(string $data, int $size = 600, bool $withLogo = true): string
    {
        // White-flattened mark: the GD writer turns a transparent logo ground
        // into a black plate behind the punchout, which would swallow the code.
        // The ground here is white, so it kicks the print copy out of the way.
        $logo = BrandAssets::markOnWhite();
        $useLogo = $withLogo && $logo !== null;

        return (new Builder(
            writer: new PngWriter,
            data: $data,
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: $size,
            margin: 16,
            // Stated explicitly rather than relying on the library defaults: the
            // fourth argument is alpha, and 0 means fully opaque in GD terms. An
            // opaque white ground guarantees the quiet zone scans and that the
            // code never picks up whatever is behind it.
            foregroundColor: new Color(0, 0, 0, 0),
            backgroundColor: new Color(255, 255, 255, 0),
            logoPath: $useLogo ? $logo : '',
            logoResizeToWidth: (int) round($size * self::LOGO_RATIO),
            // Clears the modules behind the mark so it sits on a clean plate
            // instead of over the pattern.
            logoPunchoutBackground: true,
        ))->build()->getDataUri();
    }
}
