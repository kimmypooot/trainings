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
}
