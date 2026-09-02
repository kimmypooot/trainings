<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EvaluationDayCode;
use App\Models\Training;
use App\Support\QrCodeBuilder;
use App\Support\SmeEvaluationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The posters that go on the wall at the end of a session.
 *
 * Deliberately narrow: this issues, rotates and withdraws the codes, and prints
 * them. It decides nothing about who may answer what — SmeEvaluationService owns
 * that, and is asked again at scan time, because these sheets get printed days
 * before they are used and the panel can change underneath them.
 *
 * Codes are only ever cut for days that actually collect a form. On a run
 * delivered by one expert throughout, that is one poster for the last day, not
 * one per day: printing four would put three signs on a wall inviting people to
 * a form that will turn them away.
 */
class EvaluationCodeController extends Controller
{
    /**
     * Cut codes for the days the admin picked.
     *
     * Idempotent, so a day that already has a code is left with the code it
     * has — `EvaluationDayCode::issue()`'s firstOrCreate. That is the difference
     * between this being safe to press twice and being a trap: replacing a code
     * whose poster is already on a wall is `regenerate()`, a different button
     * with a warning on it.
     *
     * Omitting `days` entirely means every evaluation day, which is both the
     * sensible default and what the endpoint did before it took a selection.
     */
    public function store(Request $request, Training $training): RedirectResponse
    {
        $eligible = $training->evaluationDays();

        if ($eligible === []) {
            return back()->with(
                'error',
                'This training has no subject matter experts assigned yet, so there is nothing to evaluate.'
            );
        }

        /*
         * Validated against the run rather than merely typed.
         *
         * A day that collects no form is refused here, not quietly dropped: the
         * only way to ask for one is a screen built before the panel changed,
         * and an error that says so sends the admin to reload — where silently
         * ignoring the request would leave them staring at a day they asked for
         * and did not get, with nothing on screen admitting why.
         */
        $validated = $request->validate([
            'days' => ['nullable', 'array'],
            'days.*' => ['integer', Rule::in($eligible)],
        ], [
            'days.*.in' => 'The training days changed while this panel was open. Reload the roster and try again.',
        ]);

        $days = $validated['days'] ?? $eligible;

        $before = $training->evaluationDayCodes()->count();

        foreach ($days as $day) {
            EvaluationDayCode::issue($training, (int) $day, $request->user());
        }

        $cut = $training->evaluationDayCodes()->count() - $before;

        return back()->with('success', $cut === 0
            ? 'Every day you picked already has a code.'
            : ($cut === 1 ? 'One evaluation code is ready to print.' : "{$cut} evaluation codes are ready to print."));
    }

    /**
     * Replace a code, killing every printed copy of the old one.
     *
     * The warning belongs on the button rather than here, but the consequence is
     * worth stating: this is the remedy for a poster that leaked or a sign that
     * went up on the wrong door, and it works by making the old sheet useless.
     */
    public function regenerate(Request $request, EvaluationDayCode $evaluationDayCode): RedirectResponse
    {
        $evaluationDayCode->regenerate($request->user());

        return back()->with(
            'success',
            "Day {$evaluationDayCode->day_number} has a new code. Any sheet already printed no longer works."
        );
    }

    /** Withdraw a code without losing what it recorded. */
    public function destroy(EvaluationDayCode $evaluationDayCode): RedirectResponse
    {
        $evaluationDayCode->revoke();

        return back()->with(
            'success',
            "Day {$evaluationDayCode->day_number}'s code has been withdrawn and no longer opens the form."
        );
    }

    /**
     * The code as a PNG, for saving or dropping into a slide.
     *
     * Served as an image rather than shipped in the roster's Inertia payload:
     * a data URI per day would add hundreds of kilobytes to a page that is
     * already one of the heaviest in the app, on every single load, for a
     * thumbnail most visitors never look at.
     */
    public function image(EvaluationDayCode $evaluationDayCode): HttpResponse
    {
        $png = base64_decode(explode(',', QrCodeBuilder::dataUri($evaluationDayCode->url()), 2)[1]);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="evaluation-day-'.$evaluationDayCode->day_number.'.png"',
            // Private: the URL inside is not a secret, but it is not something
            // to leave in a shared proxy cache either.
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * One sheet per evaluation day, sized for a wall.
     *
     * Its own page rather than a print stylesheet over the roster panel: a sign
     * that has to be read from across a function room shares no layout with a
     * management table, and the codes are embedded as data URIs here — a sheet
     * that half-prints because an image request timed out is worse than a slow
     * page.
     */
    public function print(Training $training): Response
    {
        $codes = $training->evaluationDayCodes()->get()->keyBy('day_number');

        $sheets = collect(SmeEvaluationService::codeBoard($training))
            ->filter(fn (array $day) => $day['collects'] && isset($codes[$day['day']]))
            ->map(function (array $day) use ($codes, $training) {
                $code = $codes[$day['day']];

                return [
                    'day' => $day['day'],
                    'date' => $day['date']->format('l, d F Y'),
                    'revoked' => ! $code->isActive(),
                    'url' => $code->url(),
                    'qr' => QrCodeBuilder::dataUri($code->url(), 900),
                    'experts' => $day['experts']->map(fn ($expert) => $expert->displayName())->all(),
                    'training' => $training->title,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Admin/Evaluations/Codes', [
            'training' => [
                'id' => $training->id,
                'title' => $training->title,
                'code' => $training->training_code,
                'dates' => $training->dateRange(),
                'venue' => $training->venue,
            ],
            'sheets' => $sheets,
            'rosterUrl' => route('admin.trainings.roster', $training),
        ]);
    }
}
