<?php

namespace App\Support;

use App\Models\Training;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The header search box, in one place.
 *
 * Staff spent every lookup navigating to the right list *before* they could
 * type a name into it. This is the same two questions — "which participant?"
 * and "which run?" — answered from wherever they happen to be standing.
 *
 * Two rules hold this together:
 *
 * Participants come through ParticipantFilter::base(), not a fresh query. That
 * class already carries the field-office restriction ExportScopingTest and
 * FieldOfficeScopingTest guard, and a search box is precisely the surface where
 * a second, hand-rolled participant query would quietly drift more permissive
 * than the directory it claims to shortcut. Reusing base() means this cannot
 * disagree with the list, ever, including the failing-closed behaviour when a
 * field-office account has no office assigned.
 *
 * Trainings are deliberately *not* scoped. A run belongs to the region rather
 * than to a branch — the admin trainings listing is region-wide for every staff
 * role, and narrowing it here would invent a rule the rest of the app does not
 * have.
 */
class GlobalSearch
{
    /**
     * Below this, a query matches most of the database and helps nobody.
     */
    public const MIN_TERM_LENGTH = 2;

    /**
     * Per section. The box is a jump-to, not a report — anyone needing the
     * whole answer is sent to the list, which is what `more` is for.
     */
    public const LIMIT = 5;

    /**
     * @return array{participants: array<int, array<string, mixed>>, trainings: array<int, array<string, mixed>>, more: array{participants: ?string, trainings: ?string}}
     */
    public static function results(User $actor, string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return self::empty();
        }

        return [
            'participants' => self::participants($actor, $term),
            'trainings' => self::trainings($term),
            // The "see all" links carry the term into the real listing, so the
            // box never becomes a dead end at five results.
            'more' => [
                'participants' => route('admin.participants.index', ['search' => $term]),
                'trainings' => route('admin.trainings.index', ['search' => $term]),
            ],
        ];
    }

    /**
     * @return array{participants: array<int, array<string, mixed>>, trainings: array<int, array<string, mixed>>, more: array{participants: ?string, trainings: ?string}}
     */
    public static function empty(): array
    {
        return [
            'participants' => [],
            'trainings' => [],
            'more' => ['participants' => null, 'trainings' => null],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function participants(User $actor, string $term): array
    {
        $base = ParticipantFilter::base($actor->scopedFieldOfficeId());

        return ParticipantFilter::apply($base, ['search' => $term])
            ->with('profile.fieldOffice')
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'label' => $user->profile?->directoryName() ?? $user->name,
                // The email is what disambiguates two people with one name,
                // which is the whole reason a result list needs a second line.
                'meta' => collect([$user->email, $user->profile?->organization_name])
                    ->filter()
                    ->implode(' · '),
                'url' => route('admin.participants.show', $user),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function trainings(string $term): array
    {
        return Training::query()
            ->where(fn (Builder $query) => $query
                ->where('title', 'like', "%{$term}%")
                ->orWhere('venue', 'like', "%{$term}%")
            )
            // Newest first: a title people are searching for is nearly always
            // the run happening now, not the one three years back.
            ->orderByDesc('starts_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Training $training) => [
                'id' => $training->id,
                'label' => $training->title,
                'meta' => collect([
                    $training->starts_at?->format('d M Y'),
                    $training->venue,
                ])->filter()->implode(' · '),
                // The roster, not the edit form: every staff role can open a
                // roster, and editing is HRD's alone. A result that 403s for
                // half the people who can see it is worse than no result.
                'url' => route('admin.trainings.roster', $training),
            ])
            ->all();
    }
}
