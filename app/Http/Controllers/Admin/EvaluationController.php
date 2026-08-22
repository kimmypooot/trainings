<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Training;
use App\Support\SmeEvaluationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What participants said about the experts, read by the office.
 *
 * Two screens: every run that collects evaluations with its response rate, and
 * one run broken down by expert and by day. Both are region-wide — the routes
 * withhold them from the field-office role rather than showing it a partial
 * average, because a mean computed over one office's participants and labelled
 * as the training's rating is a number that will end up in a report meaning
 * something it does not mean.
 */
class EvaluationController extends Controller
{
    public function index(): Response
    {
        $trainings = Training::query()
            ->has('subjectMatterExperts')
            // Nothing to report on a run that has not begun; the list is a
            // reading screen, not a schedule.
            ->where('starts_at', '<=', now())
            ->withCount([
                'subjectMatterExperts',
                'dayEvaluations',
                'registrations as expected_count' => fn ($query) => $query
                    ->whereIn('status', RegistrationStatus::occupying()),
            ])
            ->orderByDesc('starts_at')
            ->limit(100)
            ->get();

        return Inertia::render('Admin/Evaluations/Index', [
            'trainings' => $trainings->map(function (Training $training) {
                // Denominator: one evaluation per slot-holder per training day.
                $possible = $training->expected_count * max(1, $training->duration_days ?? 1);

                return [
                    'id' => $training->id,
                    'title' => $training->title,
                    'training_code' => $training->training_code,
                    'starts_at' => $training->starts_at->format('d M Y'),
                    'duration_days' => max(1, $training->duration_days ?? 1),
                    'status_label' => $training->status->label(),
                    'experts' => $training->subject_matter_experts_count,
                    'submissions' => $training->day_evaluations_count,
                    'possible' => $possible,
                    'response_rate' => $possible > 0
                        ? round($training->day_evaluations_count / $possible * 100)
                        : null,
                    'url' => route('admin.trainings.evaluations', $training),
                ];
            })->all(),
        ]);
    }

    /** One run's results: averages per expert per day, and the written answers. */
    public function show(Request $request, Training $training): Response
    {
        $training->load('subjectMatterExperts');

        /*
         * Management can read this page but not the SME directory, which is
         * HRD's to maintain. So the link to an expert's own record is offered
         * only to the roles that can actually open it — a link that 403s is
         * worse than no link.
         */
        $mayOpenExperts = in_array($request->user()->role, [Role::Admin, Role::SuperAdmin], true);

        return Inertia::render('Admin/Evaluations/Show', [
            'training' => [
                'id' => $training->id,
                'title' => $training->title,
                'training_code' => $training->training_code,
                'starts_at' => $training->starts_at->format('d M Y'),
                'venue' => $training->venue,
                'duration_days' => max(1, $training->duration_days ?? 1),
                'status_label' => $training->status->label(),
                'roster_url' => route('admin.trainings.roster', $training),
            ],
            'assignments' => $training->subjectMatterExperts->map(fn ($expert) => [
                'id' => $expert->id,
                'name' => $expert->name,
                'display_name' => $expert->displayName(),
                'topic' => $expert->pivot->topic,
                // Pivot columns come back as raw JSON — there is no cast on an
                // anonymous pivot — and the page renders "Days 1, 3", not a
                // string that happens to look like an array.
                'days' => is_string($expert->pivot->days)
                    ? json_decode($expert->pivot->days, true)
                    : $expert->pivot->days,
                'url' => $mayOpenExperts ? route('admin.smes.show', $expert) : null,
            ])->all(),
            'results' => SmeEvaluationService::resultsFor($training),
            'comments' => SmeEvaluationService::commentsFor($training),
            ...SmeEvaluationService::formDefinition(),
        ]);
    }
}
