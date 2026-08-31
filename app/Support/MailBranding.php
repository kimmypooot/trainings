<?php

namespace App\Support;

/**
 * Where the seal in an email masthead comes from.
 *
 * The problem this solves: an <img src="https://…"> in an email is fetched by
 * the *recipient's* mail client, not by us. On any deployment whose APP_URL is
 * not reachable from the public internet — every local install, every intranet
 * staging box — url('/images/csc-logo-256.png') resolves to an address Gmail's
 * image proxy cannot load, and the masthead arrives as a broken image or as
 * nothing at all. That is exactly how the logo went missing.
 *
 * So the default is not a URL at all: the seal travels *with* the message as an
 * inline attachment, referenced by content id, and EmbedMailLogo attaches the
 * file as the message goes out. Nothing has to be publicly reachable for the
 * masthead to render, on localhost or in production.
 *
 * MAIL_LOGO_URL still wins when it is set, for a deployment that would rather
 * serve the mark from a CDN than add ~38KB to every message.
 */
class MailBranding
{
    /**
     * The content id the masthead references and EmbedMailLogo attaches under.
     *
     * The two have to agree exactly; a mismatch is a broken image, so the
     * string lives here and neither side writes it out by hand.
     */
    public const LOGO_CID = 'csc-seal';

    /**
     * The seal at 256px.
     *
     * Around five times its rendered size, which is what a retina inbox wants,
     * and the smallest of the three variants in public/images at ~38KB.
     */
    private const LOGO_FILE = 'images/csc-logo-256.png';

    /**
     * What to put in the masthead's src attribute.
     */
    public static function logoSrc(): string
    {
        $configured = config('office.logo_url');

        return $configured ?: 'cid:'.self::LOGO_CID;
    }

    /**
     * The file to attach, or null when there is nothing to attach.
     *
     * Null both when a remote URL is configured (the message does not reference
     * a cid at all) and when the asset is genuinely missing — a message with a
     * text wordmark and no seal is a far better outcome than a send that throws.
     */
    public static function logoPath(): ?string
    {
        if (config('office.logo_url')) {
            return null;
        }

        $path = public_path(self::LOGO_FILE);

        return is_file($path) ? $path : null;
    }
}
