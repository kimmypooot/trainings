<?php

namespace App\Http\Controllers;

use App\Models\TrainingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Participants and agency coordinators asking CSC to run a training.
 */
class TrainingRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $requests = TrainingRequest::with('training')
            ->where('requested_by', $request->user()->getKey())
            ->latest()
            ->get();

        return Inertia::render('My/TrainingRequests', [
            'requests' => $requests->map(fn (TrainingRequest $item) => [
                'id' => $item->id,
                'title' => $item->title,
                'category' => $item->category,
                'justification' => $item->justification,
                'expected_participants' => $item->expected_participants,
                'preferred_start' => $item->preferred_start?->format('d M Y'),
                'preferred_end' => $item->preferred_end?->format('d M Y'),
                'status' => $item->status->value,
                'status_label' => $item->status->label(),
                'review_remarks' => $item->review_remarks,
                'submitted_at' => $item->created_at->format('d M Y'),
                // Present once HRD has turned the request into a real training.
                'training_url' => $item->training
                    ? route('trainings.show', $item->training->slug)
                    : null,
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'justification' => ['required', 'string', 'min:20', 'max:5000'],
            'category' => ['nullable', 'string', 'max:100'],
            'expected_participants' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'preferred_start' => ['nullable', 'date', 'after:today'],
            'preferred_end' => ['nullable', 'date', 'after_or_equal:preferred_start'],
        ]);

        TrainingRequest::create([
            ...$validated,
            'requested_by' => $request->user()->getKey(),
        ]);

        return back()->with('success', 'Your training request has been submitted for review.');
    }
}
