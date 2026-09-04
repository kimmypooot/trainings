<?php

namespace App\Support;

use App\Models\ScanLink;
use App\Models\Training;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Issuing and killing the credentials that open a venue door.
 *
 * A thin service over `ScanLink::issue()`, and it exists for the part the model
 * cannot do: writing the audit trail. Both ends of a station's life were
 * unrecorded — the controller's own comment claimed the row was "the only
 * record of who authorised a door and when it was last used", which was true
 * and was the problem, because a soft-deleted row is a state, not a history. It
 * could not answer *when* a link was cut, by whom, or who revoked it; and after
 * an attendance dispute those are the three questions asked.
 *
 * Lives here rather than in the controller because that is where this codebase
 * calls ActivityLogger from: the services are the choke point every state
 * change already passes through, and they know why something changed.
 */
class ScanLinkService
{
    /**
     * Cut a station for one training and hand back its one and only code.
     *
     * @return array{0: ScanLink, 1: string}
     */
    public static function issue(
        Training $training,
        User $issuer,
        ?string $label = null,
        ?CarbonImmutable $expiresAt = null,
        bool $isTest = false
    ): array {
        [$link, $code] = ScanLink::issue($training, $issuer, $label, $expiresAt, $isTest);

        ActivityLogger::record(
            'scan-link.issued',
            $link,
            sprintf(
                'Scanning station%s issued for “%s”, expiring %s.',
                $label ? " “{$label}”" : '',
                $training->title,
                $link->expires_at->format('d M Y'),
            ),
            [
                'training_id' => $training->getKey(),
                // Which office the link can actually see, which is the issuer's
                // and not the training's. Worth recording because it is the
                // whole security argument for the public door, and it is
                // decided here rather than at the door.
                'scoped_to_field_office_id' => $issuer->scopedFieldOfficeId(),
                'is_test' => $isTest,
                'expires_at' => $link->expires_at->toIso8601String(),
            ],
            $issuer,
        );

        return [$link, $code];
    }

    /**
     * Revoke a station, immediately and for every grant already in the wild.
     *
     * Soft rather than a delete, as before: the row is the record of who
     * authorised a door and when it was last used.
     */
    public static function revoke(ScanLink $link, User $actor): ScanLink
    {
        // Revoking an already-revoked link is not an error — two people can
        // reach for the same kill switch when a phone goes missing — but it
        // must not move the timestamp, or the trail would say the door closed
        // later than it did.
        if ($link->revoked_at !== null) {
            return $link;
        }

        $link->forceFill(['revoked_at' => CarbonImmutable::now()])->save();

        $link->loadMissing('training');

        ActivityLogger::record(
            'scan-link.revoked',
            $link,
            sprintf(
                'Scanning station%s for “%s” revoked.',
                $link->label ? " “{$link->label}”" : '',
                $link->training->title,
            ),
            [
                'training_id' => $link->training_id,
                'issued_by' => $link->issued_by,
                'last_used_at' => $link->last_used_at?->toIso8601String(),
            ],
            $actor,
        );

        return $link;
    }

    /**
     * Whether this staff member may kill this particular station.
     *
     * Revocation stays broad on purpose — the controller's reasoning holds: a
     * phone goes missing mid-session and the person standing at the venue has
     * to be able to kill the link themselves, without finding a superadmin.
     * What it was missing was any bound at all on *whose* link, so any staff
     * account could revoke any station in the region, including one another
     * office was actively working a door with.
     *
     * The bound is the same predicate the rest of the app scopes by: an office
     * -scoped user reaches their own office's stations, and everyone else —
     * HRD, superadmin — reaches all of them, exactly as they see every office's
     * participants. A station issued by an unscoped user has no office behind
     * it, so a field office cannot revoke it; that is the correct answer rather
     * than an awkward one, since such a link can read the whole region and is
     * not a branch office's to cut.
     */
    public static function mayRevoke(ScanLink $link, User $actor): bool
    {
        $officeId = $actor->scopedFieldOfficeId();

        if ($officeId === null) {
            return true;
        }

        return $link->issued_by === $actor->getKey()
            || $link->issuer->scopedFieldOfficeId() === $officeId;
    }
}
