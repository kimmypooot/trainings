<?php

namespace App\Http\Controllers;

use App\Enums\EvaluationRating;
use App\Models\Registration;
use App\Models\SmeEvaluation;
use App\Models\SubjectMatterExpert;
use App\Support\SmeEvaluationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The participant's side of the SME evaluation: what they still owe, and the
 * form itself.
 *
 * Ownership is checked here on every action rather than by a scope on the
 * query, because the day form is reached by registration id and "someone
 * else's registration" is the one attack this workflow has. The rules about
 * *which* day may be filled in live in SmeEvaluationService, so the list and
 * the form can never disagree about what is open.
 */
class EvaluationController extends Controller
{
    public function index(Request $request): Response
    {
        $registrations = Registration::with(['training.subjectMatterExperts', 'dayEvaluations', 'attendances'])
            ->where('user_id', $request->user()->getKey())
            // A training that has not started cannot have a day to evaluate,
            // and listing it would fill the page with rows that only say "not
            // yet" — the participant's Registrations screen is where a future
            // booking belongs.
            ->whereHas('training', fn ($query) => $query->where('starts_at', '<=', now()))
            ->orderByDesc('registered_at')
            ->get();

        $trainings = $registrations
            ->map(function (Registration $registration) {
                $days = SmeEvaluationService::daysFor($registration);

                return [
                    'registration_id' => $registration->getKey(),
                    'title' => $registration->training->title,
                    'training_code' => $registration->training->training_code,
                    'venue' => $registration->training->venue,
                    'status_label' => $registration->status->label(),
                    'days' => array_map(fn (array $day) => [
                        'day' => $day['day'],
                        'date' => $day['date']->format('D, d M Y'),
                        'experts' => $day['experts']
                            ->map(fn (SubjectMatterExpert $expert) => $expert->displayName())
                            ->all(),
                        'submitted' => $day['evaluation'] !== null,
                        'submitted_at' => $day['evaluation']?->submitted_at->format('d M Y, g:i A'),
                        'open' => $day['open'],
                        'reason' => $day['reason'],
                        'url' => route('evaluations.show', [
                            'registration' => $registration->getKey(),
                            'day' => $day['day'],
                        ]),
                    ], $days),
                ];
            })
            // A run with nothing to evaluate on any day — no experts assigned,
            // or every day still in the future — is noise on this page.
            ->filter(fn (array $training) => collect($training['days'])
                ->contains(fn (array $day) => $day['open'] || $day['submitted']))
            ->values()
            ->all();

        return Inertia::render('My/Evaluations', [
            'trainings' => $trainings,
            'pending' => SmeEvaluationService::pendingFor($request->user())->count(),
        ]);
    }

    /** The evaluation form for one training day. */
    public function show(Request $request, Registration $registration, int $day): Response|RedirectResponse
    {
        $this->authorizeOwner($request, $registration);

        $context = collect(SmeEvaluationService::daysFor($registration))->firstWhere('day', $day);

        // A day that is not open is not an error worth an exception page — the
        // participant most likely followed a link from an email sent before the
        // session, so say why and put them back on the list.
        if ($context === null || (! $context['open'] && $context['evaluation'] === null)) {
            return redirect()
                ->route('evaluations.index')
                ->with('error', $context['reason'] ?? 'That day is not part of this training.');
        }

        $existing = $context['evaluation']?->loadMissing('smeEvaluations');

        return Inertia::render('Evaluations/Form', [
            'training' => [
                'title' => $registration->training->title,
                'training_code' => $registration->training->training_code,
                'venue' => $registration->training->venue,
            ],
            'day' => [
                'number' => $context['day'],
                'date' => $context['date']->format('l, d F Y'),
                'total' => count($registration->training->trainingDays()),
            ],
            'experts' => $context['experts']->map(fn (SubjectMatterExpert $expert) => [
                'id' => $expert->getKey(),
                'name' => $expert->name,
                'display_name' => $expert->displayName(),
                'position' => $expert->position,
                'organization' => $expert->organization,
                'topic' => $expert->pivot->topic,
            ])->all(),
            // Prefilled when amending: the participant is correcting what they
            // said, not writing it again from a blank page.
            'existing' => $existing === null ? null : [
                'learned' => $existing->learned,
                'liked_most' => $existing->liked_most,
                'needs_improvement' => $existing->needs_improvement,
                'suggestions' => $existing->suggestions,
                'submitted_at' => $existing->submitted_at->format('d M Y, g:i A'),
                'ratings' => $existing->smeEvaluations
                    ->mapWithKeys(fn (SmeEvaluation $rating) => [
                        $rating->subject_matter_expert_id => [
                            ...collect(array_keys(SmeEvaluation::CRITERIA))
                                ->mapWithKeys(fn (string $column) => [$column => $rating->{$column}->value])
                                ->all(),
                            'comments' => $rating->comments,
                        ],
                    ])
                    ->all(),
            ],
            'submitUrl' => route('evaluations.store', [
                'registration' => $registration->getKey(),
                'day' => $day,
            ]),
            ...SmeEvaluationService::formDefinition(),
        ]);
    }

    public function store(Request $request, Registration $registration, int $day): RedirectResponse
    {
        $this->authorizeOwner($request, $registration);

        $ratingRules = collect(array_keys(SmeEvaluation::CRITERIA))
            ->mapWithKeys(fn (string $column) => [
                "ratings.*.{$column}" => ['required', Rule::enum(EvaluationRating::class)],
            ])
            ->all();

        $data = $request->validate([
            // The narrative half of the form is optional throughout — the
            // Commission's own form asks for it without requiring it, and
            // demanding four paragraphs is how you teach a room to click
            // through the ratings without reading them.
            'learned' => ['nullable', 'string', 'max:2000'],
            'liked_most' => ['nullable', 'string', 'max:2000'],
            'needs_improvement' => ['nullable', 'string', 'max:2000'],
            'suggestions' => ['nullable', 'string', 'max:2000'],
            'ratings' => ['required', 'array', 'min:1'],
            'ratings.*.comments' => ['nullable', 'string', 'max:2000'],
            ...$ratingRules,
        ]);

        // Which experts the ids in that array are allowed to be is the
        // service's call, not a validation rule — it is the same question the
        // form asked when it decided what to render.
        SmeEvaluationService::submit(
            $registration,
            $day,
            $data,
            $data['ratings'],
        );

        return redirect()
            ->route('evaluations.index')
            ->with('success', "Thank you — your evaluation for day {$day} has been recorded.");
    }

    /**
     * Only the participant who holds the registration may see or file its
     * evaluations. Staff read the results through the admin screens, which
     * aggregate; nobody edits somebody else's answers.
     */
    private function authorizeOwner(Request $request, Registration $registration): void
    {
        abort_unless($registration->user_id === $request->user()->getKey(), 403);
    }
}
