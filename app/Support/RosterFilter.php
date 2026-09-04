<?php

namespace App\Support;

use App\Enums\RegistrationStatus;
use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Narrowing and ordering a training's roster.
 *
 * This logic used to live in `Roster.vue` as a `filtered` computed over the
 * whole participant list, which is why the whole participant list had to be in
 * the browser. Moving it here is what lets the page ship one page of rows
 * instead of all of them.
 *
 * It filters an already-loaded collection rather than building a query, and
 * that is deliberate. The roster is loaded in full regardless, because three of
 * its counts — evaluations outstanding above all — come from
 * `SmeEvaluationService::progressFor()`, which reads the attendance and
 * evaluation relations and cannot be expressed as SQL. Since the rows are in
 * memory anyway, filtering them here costs nothing extra and keeps this a
 * faithful port of the predicates the page used to apply. The expensive part
 * was never loading the rows; it was serialising six hundred of them into a
 * page payload and rendering each one twice.
 *
 * Every predicate below is the same rule the browser applied, moved. If one of
 * them has to change, it changes once.
 */
class RosterFilter
{
    /** Rows per page. */
    public const PER_PAGE = 25;

    /**
     * The columns the table offers to sort by.
     *
     * The two dotted keys are the names the table header has always used for
     * them, kept verbatim so the header markup did not have to be renamed on
     * the way past — they are wire values here, not property paths.
     */
    private const SORTABLE = [
        'name', 'organization', 'field_office', 'status',
        'supervisory_document.status_label', 'evaluation.submitted',
    ];

    /**
     * The filters as the request states them, normalised.
     *
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request): array
    {
        $sort = $request->string('sort')->toString();

        return [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString() ?: 'all',
            'document' => $request->string('document')->toString() ?: 'all',
            'evaluation' => $request->string('evaluation')->toString() ?: 'all',
            'not_checked_in' => $request->boolean('not_checked_in'),
            // An unknown sort key is dropped rather than passed through: it
            // arrives from a query string, and `usort` on a column that does
            // not exist would silently order by nothing.
            'sort' => in_array($sort, self::SORTABLE, true) ? $sort : null,
            'direction' => $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * @param  Collection<int, Registration>  $registrations
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Registration>
     */
    public static function apply(
        Collection $registrations,
        array $filters,
        ?int $todayDay,
    ): Collection {
        $needle = mb_strtolower(trim((string) $filters['search']));

        $rows = $registrations->filter(function (Registration $registration) use ($filters, $needle, $todayDay) {
            if ($needle !== '' && ! self::matchesSearch($registration, $needle)) {
                return false;
            }

            if ($filters['status'] !== 'all' && $registration->status->value !== $filters['status']) {
                return false;
            }

            if ($filters['document'] !== 'all'
                && $registration->supervisory_document_status?->value !== $filters['document']) {
                return false;
            }

            if ($filters['not_checked_in'] && ! self::notCheckedInOn($registration, $todayDay)) {
                return false;
            }

            if ($filters['evaluation'] !== 'all'
                && self::evaluationState($registration) !== $filters['evaluation']) {
                return false;
            }

            return true;
        });

        return $filters['sort'] === null
            ? $rows->values()
            : self::sort($rows, $filters['sort'], $filters['direction']);
    }

    /**
     * Name, email and organisation — the three things somebody standing at a
     * door actually types.
     */
    private static function matchesSearch(Registration $registration, string $needle): bool
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            $registration->user->name,
            $registration->user->email,
            $registration->user->profile?->organization_name,
        ])));

        return str_contains($haystack, $needle);
    }

    /**
     * Present on the roster today and not yet through the door.
     *
     * Says nothing on a day the training is not running, which is why the page
     * hides the filter entirely then — matching the browser's `todayDay !== null`
     * guard rather than quietly matching everybody.
     */
    public static function notCheckedInOn(Registration $registration, ?int $todayDay): bool
    {
        // Approved and completed only — the same pair `isMarkable()` uses on
        // the page, and deliberately *not* `occupiesSlot()`, which also counts
        // pending. Somebody whose registration has not been approved yet is not
        // a no-show; putting them on the chase list would have the door staff
        // hunting for people who were never told to come.
        if ($todayDay === null || ! in_array($registration->status, [
            RegistrationStatus::Approved,
            RegistrationStatus::Completed,
        ], true)) {
            return false;
        }

        return $registration->attendances
            ->firstWhere('training_day', $todayDay)?->time_in === null;
    }

    /**
     * `not-required`, `submitted` or `not-submitted`, exactly as the evaluations
     * tab's chips read them.
     */
    public static function evaluationState(Registration $registration): string
    {
        $progress = SmeEvaluationService::progressFor($registration);

        if ($progress['expected'] === 0) {
            return 'not-required';
        }

        return $progress['outstanding'] === [] ? 'submitted' : 'not-submitted';
    }

    /**
     * @param  Collection<int, Registration>  $rows
     * @return Collection<int, Registration>
     */
    private static function sort(Collection $rows, string $key, string $direction): Collection
    {
        $value = fn (Registration $registration) => match ($key) {
            'name' => (string) $registration->user->name,
            'organization' => (string) $registration->user->profile?->organization_name,
            'field_office' => (string) $registration->user->profile?->fieldOffice?->name,
            'status' => $registration->status->value,
            'supervisory_document.status_label' => (string) $registration->supervisory_document_status?->label(),
            'evaluation.submitted' => (string) SmeEvaluationService::progressFor($registration)['submitted'],
            /*
             * Unreachable while `fromRequest()` is the only way in — it drops
             * anything not on SORTABLE. The arm is here because the type system
             * cannot see that guarantee across the call, and the alternative is
             * a match that throws: a sort key is query-string input, and the
             * failure mode for an unrecognised one should be an unsorted list,
             * not a 500 on the roster.
             */
            default => '',
        };

        // Case-insensitive, so "dela Cruz" and "DELA CRUZ" sort together —
        // names are stored upper-cased for most accounts and mixed for the
        // rest, and a byte-order sort interleaves them incomprehensibly.
        //
        // SORT_NATURAL is also what keeps the one numeric column honest: the
        // values arrive as strings, and a string sort would put 10 before 2.
        $sorted = $rows->sortBy(fn (Registration $r) => mb_strtolower($value($r)), SORT_NATURAL);

        return ($direction === 'desc' ? $sorted->reverse() : $sorted)->values();
    }
}
