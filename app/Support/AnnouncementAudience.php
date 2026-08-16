<?php

namespace App\Support;

use App\Enums\RegistrationStatus;
use App\Models\Profile;
use App\Models\Registration;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves who an announcement goes to.
 *
 * One query builder, used by the preview, the count, and the send. That is the
 * whole point of the class: in v1 the "recipients (47)" figure came from
 * `count-recipients.php` and the actual send came from `send-bulk-emails.php`,
 * each with its own copy of the WHERE clause. They drifted, and the number on
 * screen stopped matching the number of emails that went out — which is a bad
 * thing to discover after pressing send.
 *
 * @phpstan-type Filters array{training_id?: int|null, statuses?: array<int, string>, sectors?: array<int, string>, regions?: array<int, string>}
 */
class AnnouncementAudience
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public static function query(array $filters, ?int $fieldOfficeId = null): Builder
    {
        $statuses = array_filter((array) ($filters['statuses'] ?? []));
        $sectors = array_filter((array) ($filters['sectors'] ?? []));
        $regions = array_filter((array) ($filters['regions'] ?? []));

        return Registration::query()
            ->with(['user.profile', 'training', 'payments'])
            ->when(
                filled($filters['training_id'] ?? null),
                fn (Builder $query) => $query->where('training_id', $filters['training_id'])
            )
            ->when(
                $statuses !== [],
                fn (Builder $query) => $query->whereIn('status', $statuses)
            )
            // Sector and region live on the profile, so both narrow through the
            // same relation rather than adding a join per filter.
            ->when(
                $sectors !== [] || $regions !== [] || $fieldOfficeId !== null,
                fn (Builder $query) => $query->whereHas(
                    'user.profile',
                    function (Builder $profile) use ($sectors, $regions, $fieldOfficeId) {
                        $profile
                            ->when($sectors !== [], fn ($inner) => $inner->whereIn('sector', $sectors))
                            ->when($regions !== [], fn ($inner) => $inner->whereIn('region', $regions))
                            // Office scoping is not a filter the sender chooses;
                            // it is applied on top of whatever they picked, so a
                            // field office can never reach another's people.
                            ->when(
                                $fieldOfficeId !== null,
                                fn ($inner) => $inner->where('field_office_id', $fieldOfficeId)
                            );
                    }
                )
            )
            // A participant with no email address cannot be a recipient, and
            // counting them would inflate the figure the sender is shown.
            ->whereHas('user', fn (Builder $user) => $user->whereNotNull('email'));
    }

    /**
     * How many people this actually reaches.
     *
     * Distinct on the user: someone registered for two of the selected
     * trainings is one recipient, not two — and the send de-duplicates the same
     * way, so the number shown is the number of emails sent.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function count(array $filters, ?int $fieldOfficeId = null): int
    {
        return self::query($filters, $fieldOfficeId)->distinct('user_id')->count('user_id');
    }

    /**
     * A short sample for the preview, already rendered.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{name: string, email: string, subject: string, body: string}>
     */
    public static function preview(
        array $filters,
        string $subject,
        string $body,
        ?int $fieldOfficeId = null,
        int $limit = 3
    ): array {
        return self::query($filters, $fieldOfficeId)
            ->limit($limit)
            ->get()
            ->map(fn (Registration $registration) => [
                'name' => $registration->user->name,
                'email' => $registration->user->email,
                'subject' => EmailTemplateRenderer::render($subject, $registration),
                'body' => EmailTemplateRenderer::render($body, $registration),
            ])
            ->all();
    }

    /**
     * The filter vocabularies, drawn from the data rather than a fixed list —
     * offering a sector nobody is in produces an empty send.
     *
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    public static function filterOptions(?int $fieldOfficeId = null): array
    {
        $distinct = fn (string $column) => Profile::query()
            ->when($fieldOfficeId !== null, fn ($query) => $query->where('field_office_id', $fieldOfficeId))
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(fn (string $value) => ['value' => $value, 'label' => $value])
            ->all();

        return [
            'statuses' => array_map(
                fn (RegistrationStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                [
                    RegistrationStatus::Approved,
                    RegistrationStatus::Pending,
                    RegistrationStatus::Waitlisted,
                    RegistrationStatus::Completed,
                ],
            ),
            'sectors' => $distinct('sector'),
            'regions' => $distinct('region'),
        ];
    }
}
