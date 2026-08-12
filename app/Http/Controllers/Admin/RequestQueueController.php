<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\CancellationRequest;
use App\Models\RegistrationOutput;
use App\Models\TrainingRequest;
use App\Support\CancellationRequestService;
use App\Support\TrainingRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The three staff review queues: withdrawals, agency training requests, and
 * submitted outputs.
 *
 * Kept in one controller because they are one screen with three tabs, and each
 * decision is a couple of lines — three controllers would be three copies of
 * the same validation.
 */
class RequestQueueController extends Controller
{
    public function index(Request $request): Response
    {
        $officeId = $request->user()->scopedFieldOfficeId();

        // Field-office staff see only their own participants' requests, the
        // same rule the roster and participant directory already apply.
        $scope = fn ($query) => $query->when($officeId !== null, fn ($inner) => $inner->whereHas(
            'registration.user.profile',
            fn ($profile) => $profile->where('field_office_id', $officeId)
        ));

        $cancellations = CancellationRequest::with(['registration.user', 'registration.training'])
            ->tap($scope)
            ->latest()
            ->limit(100)
            ->get();

        $outputs = RegistrationOutput::with(['registration.user', 'registration.training'])
            ->tap($scope)
            ->latest()
            ->limit(100)
            ->get();

        $trainingRequests = TrainingRequest::with(['requester.profile', 'training'])
            ->when($officeId !== null, fn ($query) => $query->whereHas(
                'requester.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            ))
            ->latest()
            ->limit(100)
            ->get();

        return Inertia::render('Admin/Requests/Index', [
            'cancellations' => $cancellations->map(fn (CancellationRequest $item) => [
                'id' => $item->id,
                'participant' => $item->registration->user->name,
                'training' => $item->registration->training->title,
                'reason' => $item->reason,
                'status' => $item->status->value,
                'status_label' => $item->status->label(),
                'review_remarks' => $item->review_remarks,
                'submitted_at' => $item->created_at->format('d M Y'),
            ])->all(),
            'trainingRequests' => $trainingRequests->map(fn (TrainingRequest $item) => [
                'id' => $item->id,
                'requester' => $item->requester?->name,
                'title' => $item->title,
                'category' => $item->category,
                'justification' => $item->justification,
                'expected_participants' => $item->expected_participants,
                'preferred_start' => $item->preferred_start?->format('d M Y'),
                'status' => $item->status->value,
                'status_label' => $item->status->label(),
                'review_remarks' => $item->review_remarks,
                'submitted_at' => $item->created_at->format('d M Y'),
                'converted' => $item->training_id !== null,
            ])->all(),
            'outputs' => $outputs->map(fn (RegistrationOutput $item) => [
                'id' => $item->id,
                'participant' => $item->registration->user->name,
                'training' => $item->registration->training->title,
                'title' => $item->title,
                'description' => $item->description,
                'filename' => $item->original_filename,
                'size' => $item->readableSize(),
                'status' => $item->status->value,
                'status_label' => $item->status->label(),
                'review_remarks' => $item->review_remarks,
                'submitted_at' => $item->created_at->format('d M Y'),
                'download_url' => route('outputs.download', $item),
            ])->all(),
            'scopedTo' => $request->user()->fieldOffice?->name,
        ]);
    }

    public function reviewCancellation(Request $request, CancellationRequest $cancellationRequest): RedirectResponse
    {
        $validated = $this->decision($request);

        CancellationRequestService::review(
            $cancellationRequest,
            RequestStatus::from($validated['decision']),
            $request->user(),
            $validated['remarks'] ?? null
        );

        return back()->with('success', 'Withdrawal request reviewed.');
    }

    public function reviewTrainingRequest(Request $request, TrainingRequest $trainingRequest): RedirectResponse
    {
        $validated = $this->decision($request);

        TrainingRequestService::review(
            $trainingRequest,
            RequestStatus::from($validated['decision']),
            $request->user(),
            $validated['remarks'] ?? null
        );

        return back()->with('success', 'Training request reviewed.');
    }

    /**
     * Turn an approved request into a draft training.
     */
    public function convertTrainingRequest(Request $request, TrainingRequest $trainingRequest): RedirectResponse
    {
        $training = TrainingRequestService::convert($trainingRequest, $request->user());

        return redirect()
            ->route('admin.trainings.edit', $training)
            ->with('success', 'Draft training created — fill in the venue and schedule before publishing.');
    }

    public function reviewOutput(Request $request, RegistrationOutput $output): RedirectResponse
    {
        $validated = $this->decision($request);

        $output->forceFill([
            'status' => RequestStatus::from($validated['decision']),
            'reviewed_by' => $request->user()->getKey(),
            'reviewed_at' => now(),
            'review_remarks' => $validated['remarks'] ?? null,
        ])->save();

        return back()->with('success', 'Output reviewed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function decision(Request $request): array
    {
        return $request->validate([
            'decision' => ['required', Rule::in([RequestStatus::Approved->value, RequestStatus::Rejected->value])],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
