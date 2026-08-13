<?php

namespace App\Support;

/**
 * Paths to brand imagery for anything rendered server-side.
 *
 * The master `csc-logo.png` is 4499×4269 — fine as a web asset the browser
 * scales, but ruinous for PDF and QR rendering, where the whole bitmap is
 * decompressed into memory (~77MB) before being scaled down. Everything drawn
 * on the server uses the print-sized copy instead.
 */
class BrandAssets
{
    public static function mark(): ?string
    {
        foreach (['images/csc-logo-print.png', 'images/csc-logo.png'] as $candidate) {
            $path = public_path($candidate);

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * The same mark with the transparent ground flattened to white.
     *
     * The GD writer endroid uses composites a logo's alpha channel in as black
     * (`imagecopyresampled` on a non-alpha source), which puts a black plate
     * behind the mark punchout. The QR's ground is white, so the mark is
     * pre-flattened here — the browser-rendered `<img>` on certificates keeps
     * the transparent original via `mark()`, the server-drawn QR gets this.
     */
    public static function markOnWhite(): ?string
    {
        foreach (['images/csc-logo-print-white.png', 'images/csc-logo-print.png', 'images/csc-logo.png'] as $candidate) {
            $path = public_path($candidate);

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
