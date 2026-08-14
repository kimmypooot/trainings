<?php

namespace App\Support;

use App\Models\Training;
use App\Models\User;

/**
 * Who may take a Supervisory Development Course, and what they must show.
 *
 * CSC's rule: an SDC is for people who actually supervise staff. Salary grade
 * is the proxy — below SG 11 nobody does, from SG 18 up it is taken as given,
 * and the band in between is exactly where the title does not settle it. Those
 * participants have to attach a designation, memorandum, or office order that
 * shows staff reporting to them.
 *
 * v1 decided whether a training was an SDC by searching its *name* for
 * "supervisory development course" and then for "track 1", "track1", "track 2"
 * and four more spellings. That silently stopped applying the moment anyone
 * titled a run differently. v2 already carries `trainings.is_supervisory` as an
 * explicit flag set on the training form, so the rule keys off that instead —
 * same policy, but it fails visibly rather than silently.
 */
class SupervisoryEligibility
{
    /** Below this, the participant does not supervise anyone. */
    public const MINIMUM_GRADE = 11;

    /** From this grade up, supervisory function is assumed and needs no proof. */
    public const ASSUMED_GRADE = 18;

    /**
     * The participant's salary grade as a number, or null when it cannot be
     * read as one.
     *
     * Profiles store grades as "SG 15", with "Not Applicable" a valid choice
     * for job-order and contract-of-service staff. Those genuinely have no
     * grade, so they come back null rather than zero — zero would read as
     * "below the floor" and wrongly block them.
     */
    public static function gradeFor(User $user): ?int
    {
        $raw = $user->profile?->salary_grade;

        if (blank($raw) || ! preg_match('/(\d+)/', $raw, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Whether this rule applies at all. A training that is not supervisory
     * imposes nothing on anyone.
     */
    public static function applies(Training $training): bool
    {
        return $training->is_supervisory;
    }

    /**
     * A participant below the floor cannot take the course.
     *
     * An unreadable grade does *not* block. v1 behaved the same way, and it is
     * the right call here: the alternative turns a data-entry gap in a profile
     * into a locked door, with no way for the participant to see why. HRD
     * reviews every registration anyway, so an ineligible one is still caught
     * by a person — this check only spares the obvious cases.
     */
    public static function isBarred(Training $training, User $user): bool
    {
        if (! self::applies($training)) {
            return false;
        }

        $grade = self::gradeFor($user);

        return $grade !== null && $grade < self::MINIMUM_GRADE;
    }

    /**
     * Whether proof of supervisory function must be attached.
     */
    public static function requiresSupportingDocument(Training $training, User $user): bool
    {
        if (! self::applies($training)) {
            return false;
        }

        $grade = self::gradeFor($user);

        return $grade !== null
            && $grade >= self::MINIMUM_GRADE
            && $grade < self::ASSUMED_GRADE;
    }

    /** What the participant is told when they are turned away. */
    public static function barredMessage(): string
    {
        return 'A Supervisory Development Course requires Salary Grade '
            .self::MINIMUM_GRADE.' or higher.';
    }

    /** What the upload field explains on the registration form. */
    public static function documentHint(): string
    {
        return 'Upload a Request for Designation, Memorandum, or Office Order showing that staff report to you.';
    }
}
