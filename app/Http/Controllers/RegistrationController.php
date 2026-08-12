<?php

namespace App\Http\Controllers;

use App\Models\Registration;
use App\Models\Training;
use App\Support\CancellationRequestService;
use App\Support\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationController extends Controller
{
    /**
     * The participant's own registrations.
     */
    public function index(Request $request): Response
    {
        $registrations = Registration::with(['training', 'cancellationRequests'])
            ->where('user_id', $request->user()->getKey())
            ->join('trainings', 'trainings.id', '=', 'registrations.training_id')
            ->orderByDesc('trainings.starts_at')
            ->select('registrations.*')
            ->get();

        return Inertia::render('My/Registrations', [
            'registrations' => $registrations->map(fn (Registration $registration) => [
                'id' => $registration->id,
                'status' => $registration->status->value,
                'registered_at' => $registration->registered_at->format('d M Y'),
                'can_withdraw' => $registration->status->isCancellable()
                    && ! $registration->hasPendingCancellation(),
                'withdrawal_pending' => $registration->hasPendingCancellation(),
                'training' => [
                    'title' => $registration->training->title,
                    'venue' => $registration->training->venue,
                    'starts_at' => $registration->training->starts_at->format('d M Y, g:i A'),
                    'is_past' => $registration->training->starts_at->isPast(),
                    'url' => route('trainings.show', $registration->training->slug),
                ],
            ])->all(),
        ]);
    }

    /**
     * Register for a training.
     */
    public function store(Request $request, Training $training): RedirectResponse
    {
        RegistrationService::register($request->user(), $training);

        return back()->with(
            'success',
            "Your registration for {$training->title} has been submitted and is awaiting approval by CSC."
        );
    }

    /**
     * Ask to withdraw from a training.
     *
     * The slot is held until CSC decides — catering and materials are ordered
     * against a confirmed head count, so a withdrawal is a request, not a
     * unilateral act.
     */
    public function destroy(Request $request, Registration $registration): RedirectResponse
    {
        abort_unless($registration->user_id === $request->user()->getKey(), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        CancellationRequestService::open($registration, $validated['reason']);

        return back()->with(
            'success',
            'Your withdrawal request has been submitted. Your slot is held until CSC reviews it.'
        );
    }
}
