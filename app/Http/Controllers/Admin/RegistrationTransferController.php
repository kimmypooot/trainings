<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Training;
use App\Support\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Move a selection of a roster to another training.
 *
 * Ported from v1's `transfer-participants.php`. The usual cause is a run being
 * rescheduled or split, where the alternative is cancelling and re-registering
 * everyone — losing the original registration dates, the attendance recorded
 * against them, and any payment attached.
 *
 * The destinations it validates against come from Support\TransferTargets, the
 * same list the roster dialog and the affected screen offer.
 * RegistrationTransferTest is the guard.
 */
class RegistrationTransferController extends Controller
{
    /**
     * Move a selection of the roster to another training.
     *
     * Ported from v1's `transfer-participants.php`. The usual cause is a run
     * being rescheduled or split, where the alternative is cancelling and
     * re-registering everyone — losing the original registration dates, the
     * attendance recorded against them, and any payment attached.
     */
    public function __invoke(Request $request, Training $training): RedirectResponse
    {
        $validated = $request->validate([
            'target_training_id' => [
                'required',
                'integer',
                // Never this one: a transfer onto the training you are already
                // looking at is a misclick, not an intent.
                Rule::notIn([$training->getKey()]),
                Rule::exists('trainings', 'id'),
            ],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $result = RegistrationService::transfer(
            $validated['ids'],
            Training::findOrFail($validated['target_training_id']),
            $request->user(),
            $validated['reason'],
            $request->user()->scopedFieldOfficeId(),
        );

        if ($result['moved'] === 0) {
            return back()->withErrors([
                'transfer' => 'Nobody could be moved. '.implode('; ', $result['skipped']),
            ]);
        }

        // Reported rather than swallowed: "moved 12" when 3 were skipped is how
        // a participant quietly stays on a training that no longer runs.
        $message = "{$result['moved']} participant(s) moved.";

        if ($result['skipped'] !== []) {
            $message .= ' Skipped: '.implode('; ', $result['skipped']).'.';
        }

        return back()->with('success', $message);
    }
}
