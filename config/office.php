<?php

/*
 * Who this deployment belongs to.
 *
 * The public footer used to hard-code the Central Office address on IBP Road,
 * Quezon City, while every other identity string in the app — the JSON-LD in
 * app.blade.php, the og:site_name, the certificate template, AppFooter — said
 * Regional Office VIII. A participant in Eastern Visayas was being handed a
 * Metro Manila address and a Metro Manila trunkline.
 *
 * These live in config rather than in the Vue template because they are a
 * deployment fact, not a design one: the same codebase serving a different
 * regional office should not need a front-end edit to stop lying about where
 * it is. Anything left null is simply not rendered, which is the honest
 * failure mode — no telephone number beats the wrong telephone number.
 */

return [
    'name' => env('OFFICE_NAME', 'Civil Service Commission Regional Office VIII'),
    'short_name' => env('OFFICE_SHORT_NAME', 'CSC RO VIII'),
    'region' => env('OFFICE_REGION', 'Eastern Visayas'),

    'address' => env('OFFICE_ADDRESS', 'Government Center, Candahug, Palo, Leyte'),

    /*
     * Deliberately null by default. The old number was verifiably the wrong
     * office's, and guessing a replacement would repeat the mistake in a way
     * that is harder to notice. Set OFFICE_PHONE in .env for a deployment and
     * the footer row appears; leave it unset and the footer simply omits it.
     */
    'phone' => env('OFFICE_PHONE'),

    /*
     * The Human Resource Division operates TIMS, so its mailbox is the one a
     * participant should reach for — not a generic address for the office as a
     * whole, which routes a training question through a mail room before it
     * gets to anyone who can answer it.
     *
     * This is the contact shown in the site footer, the accessibility
     * statement, and every email we send, so it has to be a mailbox somebody
     * actually reads.
     */
    'email' => env('OFFICE_EMAIL', 'ro08.hrd@csc.gov.ph'),

    /*
     * Where the masthead logo in outgoing email is fetched from.
     *
     * Email images are pulled by the recipient's mail client — or, in Gmail's
     * case, by Google's image proxy — so a linked one has to sit somewhere the
     * public internet can reach. url('/images/...') used to be the default and
     * was wrong on every host that is not yet public: it resolved to an address
     * no recipient could load, and the seal arrived as a broken image.
     *
     * So this is no longer needed for the logo to appear. Left unset, the seal
     * travels with the message as an inline attachment (App\Support\MailBranding
     * and App\Listeners\EmbedMailLogo), which works on localhost and in
     * production alike. Set it to an absolute, publicly reachable URL only if a
     * deployment would rather serve the mark from a CDN than add ~38KB to every
     * message; the attachment is then skipped.
     */
    'logo_url' => env('MAIL_LOGO_URL'),
];
