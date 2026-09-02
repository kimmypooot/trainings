<?php

namespace App\Http\Controllers;

use App\Enums\EvaluationScanOutcome;
use App\Models\EvaluationDayCode;
use App\Support\SmeEvaluationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door a scanned evaluation code opens.
 *
 * Deliberately thin, and deliberately a *redirect* rather than a second copy of
 * the form. Everything that decides who may answer what already exists on
 * /my/evaluations/{registration}/days/{day} — ownership, the open/closed rules,
 * the validation, the amendment path — and a scanning entrance that rendered its
 * own form would be a second place for all of it to be got wrong, tested less
 * than the first. So this resolves a token to a participant and a day, then
 * hands over.
 *
 * What it renders itself is only the endings: the four ways a scan can fail to
 * reach a form. Those are worth a page rather than a flash message, because the
 * person reading them is standing in a room holding a phone, and "nothing
 * happened" is the outcome that guarantees a call to the office.
 */
class EvaluationScanController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        $code = EvaluationDayCode::with('training')->where('token', $token)->first();

        /*
         * A token that names nothing and a token that has been withdrawn are
         * answered with the same page and the same status.
         *
         * There is nothing sensitive behind a code — it is an address, not a
         * credential — so this is not protecting a secret. It is refusing to
         * confirm which of somebody's guesses named a real training day, and
         * that only works if the two cases are indistinguishable in *both* the
         * body and the status line. Returning a 404 for one and a 200 for the
         * other would leave the oracle open while looking like it was closed.
         */
        if ($code === null || ! $code->isActive()) {
            // Counted even so, when the code is real: a spike of scans against
            // a code the office withdrew is how they learn an old poster is
            // still on a wall somewhere.
            $code?->markScanned();

            return $this->page($request, [
                'outcome' => EvaluationScanOutcome::Revoked,
                'reason' => null,
                'code' => $code,
            ], Response::HTTP_NOT_FOUND);
        }

        // Counted before the outcome is known: see EvaluationDayCode::markScanned().
        $code->markScanned();

        $resolution = SmeEvaluationService::resolveScan($request->user(), $code);

        if ($resolution['outcome']->opensForm()) {
            return redirect()->route('evaluations.show', [
                'registration' => $resolution['registration'],
                'day' => $resolution['day'],
            ]);
        }

        return $this->page($request, [
            'outcome' => $resolution['outcome'],
            // The service's sentence, verbatim. These are already written for a
            // participant to read; rewriting them here would give the app two
            // vocabularies for one rule.
            'reason' => $resolution['reason'],
            'code' => $code,
        ]);
    }

    /**
     * One of the endings.
     *
     * @param  array{outcome: EvaluationScanOutcome, reason: ?string, code: ?EvaluationDayCode}  $result
     */
    private function page(Request $request, array $result, int $status = Response::HTTP_OK): Response
    {
        $code = $result['code'];

        return Inertia::render('Evaluations/ScanOutcome', [
            'outcome' => $result['outcome']->value,
            'title' => $result['outcome']->title(),
            'reason' => $result['reason'],
            // Absent for a token that resolved to nothing — there is no training
            // to name, and inventing a heading would be worse than omitting one.
            'training' => $code === null ? null : [
                'title' => $code->training->title,
                'dates' => $code->training->dateRange(),
            ],
            'day' => $code?->day_number,
            'catalogueUrl' => route('trainings.index'),
            'evaluationsUrl' => route('evaluations.index'),
        ])->toResponse($request)->setStatusCode($status);
    }
}
