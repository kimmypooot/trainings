<?php

namespace App\Enums;

/**
 * The 5-point agreement scale participants rate a subject matter expert on.
 *
 * Taken verbatim from the Commission's own evaluation form ("5 = Strongly
 * Agree" … "1 = Strongly Disagree"), including the neutral point's wording,
 * because the numbers get reported upward and have to mean the same thing they
 * meant on paper.
 *
 * Int-backed, so a rating is stored as the number it is and an average is a
 * plain SQL AVG() rather than a mapping exercise.
 */
enum EvaluationRating: int
{
    case StronglyDisagree = 1;
    case Disagree = 2;
    case Neutral = 3;
    case Agree = 4;
    case StronglyAgree = 5;

    public function label(): string
    {
        return match ($this) {
            self::StronglyDisagree => 'Strongly Disagree',
            self::Disagree => 'Disagree',
            self::Neutral => 'Neither Agree nor Disagree',
            self::Agree => 'Agree',
            self::StronglyAgree => 'Strongly Agree',
        };
    }

    /**
     * Highest first: a rating scale is read from the strongest agreement down,
     * the way the paper form prints it.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $rating) => ['value' => $rating->value, 'label' => $rating->label()],
            array_reverse(self::cases())
        );
    }
}
