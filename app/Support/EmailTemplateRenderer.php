<?php

namespace App\Support;

use App\Models\Registration;

/**
 * Fills the placeholders in an announcement, ported from v1's
 * `replaceVariables()`.
 *
 * The vocabulary is declared in one place — variables() — and both the
 * substitution and the "available variables" list on the compose screen read
 * from it. In v1 those were two separate hand-maintained lists, so the UI
 * offered placeholders that the sender did not replace, and they went out to
 * participants as literal `{amount_due}`.
 *
 * Unknown placeholders are left untouched rather than blanked. A visible
 * `{venue_details}` in a draft is a typo the sender can see and fix; silently
 * emptying it produces a sentence with a hole in it that nobody notices until
 * it has been sent.
 */
class EmailTemplateRenderer
{
    /**
     * Every placeholder, with the line shown beside it on the compose screen.
     *
     * @return array<string, string>
     */
    public static function variables(): array
    {
        return [
            'participant_name' => "The participant's full name",
            'first_name' => 'First name only, for a warmer opening',
            'training_title' => 'Title of the training',
            'training_code' => 'Training reference code',
            'training_dates' => 'Start and end dates as a phrase',
            'start_date' => 'Start date',
            'end_date' => 'End date',
            'venue' => 'Venue',
            'registration_status' => "The participant's registration status",
            'payment_status' => 'Paid, awaiting verification, or unpaid',
            'amount_due' => 'Training fee, formatted',
        ];
    }

    /**
     * @return array<int, array{token: string, description: string}>
     */
    public static function variableOptions(): array
    {
        return array_map(
            fn (string $description, string $name) => [
                'token' => '{'.$name.'}',
                'description' => $description,
            ],
            self::variables(),
            array_keys(self::variables()),
        );
    }

    /**
     * Render one registration's copy of a message.
     */
    public static function render(string $text, Registration $registration): string
    {
        $registration->loadMissing(['user.profile', 'training', 'payments']);

        $replacements = [];

        foreach (self::valuesFor($registration) as $name => $value) {
            $replacements['{'.$name.'}'] = $value;
        }

        return strtr($text, $replacements);
    }

    /**
     * @return array<string, string>
     */
    private static function valuesFor(Registration $registration): array
    {
        $training = $registration->training;
        $profile = $registration->user?->profile;

        return [
            'participant_name' => $registration->user?->name ?? '',
            // Falls back to the account name's first word when there is no
            // profile — better a rough first name than an empty greeting.
            'first_name' => $profile?->first_name
                ?? strtok((string) $registration->user?->name, ' ')
                ?: '',
            'training_title' => $training?->title ?? '',
            'training_code' => $training?->training_code ?? '',
            'training_dates' => self::dateRange($registration),
            'start_date' => $training?->starts_at?->format('F d, Y') ?? '',
            'end_date' => $training?->ends_at?->format('F d, Y') ?? '',
            'venue' => $training?->venue ?? '',
            'registration_status' => $registration->status->label(),
            'payment_status' => self::paymentStatus($registration),
            'amount_due' => $training?->payment_required
                ? number_format((float) $training->payment_amount, 2)
                : '0.00',
        ];
    }

    /**
     * "12 March 2026" for a one-day run, "12–14 March 2026" for a longer one.
     *
     * The formatting itself is Training::dateRange() — participants must read
     * the same dates in their confirmation email as staff read on the report.
     * This wrapper only handles the case the model cannot: a registration
     * whose training has gone.
     */
    private static function dateRange(Registration $registration): string
    {
        return $registration->training?->dateRange() ?? '';
    }

    private static function paymentStatus(Registration $registration): string
    {
        if (! $registration->training?->payment_required) {
            return 'No fee';
        }

        $latest = $registration->payments->sortByDesc('created_at')->first();

        return $latest?->status->label() ?? 'Unpaid';
    }
}
