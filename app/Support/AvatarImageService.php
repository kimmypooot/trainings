<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Normalises an uploaded profile photo before it is stored.
 *
 * Avatars are rendered at 32–112 px in a circle, so keeping whatever a phone
 * camera produced — routinely 3–8 MP and several megabytes — costs storage and
 * bandwidth on every page that draws a header, and buys nothing. Every upload
 * is therefore decoded, squared, downscaled, and re-encoded to a single
 * predictable format.
 *
 * Re-encoding has two effects beyond size, both wanted:
 *
 *  - EXIF is dropped. Phone photos carry GPS coordinates and a device serial,
 *    and this is a government system holding personal data — a profile photo
 *    should not quietly publish where it was taken to every staff member who
 *    opens the participant record.
 *  - The stored bytes are ones GD produced, not ones the uploader supplied. A
 *    file that is a valid JPEG *and* something else besides cannot survive a
 *    decode/re-encode round trip.
 *
 * GD rather than Intervention: this is the only image manipulation in the
 * system and GD is already loaded, so a dependency would be carried for one
 * call site.
 */
class AvatarImageService
{
    /**
     * The longest edge of a stored avatar.
     *
     * Twice the largest rendered size (AppAvatar's `lg` is 56 px) so the photo
     * stays sharp on a 2× display, and no further.
     */
    public const SIZE = 512;

    /** High enough that the artefacts are invisible at avatar sizes. */
    public const QUALITY = 85;

    /**
     * The most pixels this will decode, regardless of file size.
     *
     * The 2 MB upload cap does not bound this: a highly compressible PNG can
     * be a few hundred kilobytes and still decode to hundreds of megabytes in
     * memory. 40 MP is far beyond any real photo and well inside PHP's default
     * memory limit once decoded.
     */
    public const MAX_PIXELS = 40_000_000;

    /**
     * Square, downscale, and store the photo. Returns the path on the disk.
     *
     * @throws ValidationException when the file cannot be read as an image
     */
    public static function store(UploadedFile $file, string $directory, string $disk): string
    {
        // The path is passed alongside the bytes only so EXIF can be read from
        // it; everything else works off the bytes.
        return self::process(
            (string) file_get_contents((string) $file->getRealPath()),
            $directory,
            $disk,
            $file->getRealPath() ?: null
        );
    }

    /**
     * Same, for an image already in memory — the photo imported from a linked
     * Google account. No EXIF pass: Google serves images already upright.
     *
     * @throws ValidationException
     */
    public static function storeBytes(string $bytes, string $directory, string $disk): string
    {
        return self::process($bytes, $directory, $disk, null);
    }

    /**
     * @throws ValidationException
     */
    private static function process(string $bytes, string $directory, string $disk, ?string $exifPath): string
    {
        $image = self::decode($bytes, $exifPath);

        try {
            $square = self::squareAndScale($image);
        } finally {
            // The source is large; free it before encoding rather than waiting
            // for the request to end.
            imagedestroy($image);
        }

        try {
            $path = $directory.'/'.Str::random(40).'.jpg';
            Storage::disk($disk)->put($path, self::encode($square));
        } finally {
            imagedestroy($square);
        }

        return $path;
    }

    /**
     * Decode the upload, with the orientation the camera recorded applied.
     *
     * @throws ValidationException
     */
    private static function decode(string $bytes, ?string $exifPath): GdImage
    {
        $size = @getimagesizefromstring($bytes);

        if ($size === false) {
            throw ValidationException::withMessages([
                'photo' => 'That file could not be read as an image. Try a JPG, PNG, or WebP.',
            ]);
        }

        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw ValidationException::withMessages([
                'photo' => 'That image is too large to process. Please use a smaller photo.',
            ]);
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw ValidationException::withMessages([
                'photo' => 'That file could not be read as an image. Try a JPG, PNG, or WebP.',
            ]);
        }

        if ($exifPath === null) {
            return $image;
        }

        return self::applyExifOrientation($image, $exifPath, $size[2]);
    }

    /**
     * Rotate to match the EXIF orientation tag.
     *
     * Phones store the sensor image and a "which way was I held" tag rather
     * than rotating the pixels. GD ignores the tag, so without this a portrait
     * photo lands sideways — and since the tag is stripped on re-encode, there
     * would be nothing left to correct it later.
     */
    private static function applyExifOrientation(GdImage $image, string $path, int $type): GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 0);

        $degrees = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($degrees === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $degrees, 0);

        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    /**
     * Centre-crop to a square, then scale to at most SIZE.
     *
     * Cropping before scaling is what keeps faces from being squashed: the
     * avatar is drawn in a circle, so a non-square photo has to lose its edges
     * one way or another, and taking them off the long side keeps the middle —
     * where the subject almost always is — intact.
     *
     * Small photos are cropped but never enlarged; upscaling would only invent
     * detail and inflate the file.
     */
    private static function squareAndScale(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $edge = min($width, $height);
        $target = min($edge, self::SIZE);

        $canvas = imagecreatetruecolor($target, $target);

        // Transparent PNGs and WebPs are flattened onto white rather than kept:
        // the output is JPEG, and JPEG has no alpha channel, so without this
        // the transparent parts come out black.
        imagefilledrectangle($canvas, 0, 0, $target, $target, imagecolorallocate($canvas, 255, 255, 255));

        imagecopyresampled(
            $canvas,
            $image,
            0, 0,
            intdiv($width - $edge, 2),
            intdiv($height - $edge, 2),
            $target, $target,
            $edge, $edge
        );

        return $canvas;
    }

    /**
     * Encode to JPEG bytes.
     */
    private static function encode(GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, self::QUALITY);

        return (string) ob_get_clean();
    }
}
