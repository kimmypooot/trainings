<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\CancellationRequest;
use App\Models\RegistrationOutput;
use App\Models\TrainingRequest;
use App\Support\CancellationRequestService;
use App\Support\RegistrationOutputService;
use App\Support\TrainingRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    /**
     * How many rows each queue shows.
     *
     * A bound rather than a paginator: three independent paginators on one
     * three-tab screen is a worse answer than a cap, provided the cap can only
     * hide decided items — which is what the pending-first ordering below
     * guarantees, and what the summary() counts make visible.
     */
    private const LIMIT = 100;

    /**
     * How a request in each of the three queues reaches a field office.
     *
     * Two shapes: a withdrawal and an output hang off a registration, while a
     * training request is filed by the participant directly. Named once here
     * because the listing and the decision must narrow by exactly the same
     * path — they used not to, and that was the bug: `index()` scoped all three
     * queues while the three POSTs that act on them scoped none, so a field
     * office could approve another office's withdrawal (freeing a seat and
     * starting a refund) by posting its id.
     */
    private const OFFICE_PATHS = [
        CancellationRequest::class => 'registration.user.profile',
        RegistrationOutput::class => 'registration.user.profile',
        TrainingRequest::class => 'requester.profile',
    ];

    /**
     * Re-resolve a route-bound queue item against the actor's field office.
     *
     * The same move `ManagesRosterDecisions::scopedRegistration` makes, and for
     * the same reason: route-model binding knows nothing about scoping, so an
     * action that trusted the bound model would act on whatever id was posted.
     *
     * 404 rather than 403, matching the roster and the participant directory —
     * whether another office's request exists is not this actor's to learn.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  TModel  $item
     * @return TModel
     */
    private function scoped(Request $request, $item)
    {
        $officeId = $request->user()->scopedFieldOfficeId();

        if ($officeId === null) {
            return $item;
        }

        $path = self::OFFICE_PATHS[$item::class];

        return $item::query()
            ->whereKey($item->getKey())
            ->whereHas($path, fn ($profile) => $profile->where('field_office_id', $officeId))
            ->firstOr(fn () => abort(404));
    }

    public function index(Request $request): Response
    {
        $officeId = $request->user()->scopedFieldOfficeId();

        // Field-office staff see only their own participants' requests, the
        // same rule the roster and participant directory already apply — and
        // the same rule scoped() applies to each decision below.
        $scope = fn ($query) => $query->when($officeId !== null, fn ($inner) => $inner->whereHas(
            self::OFFICE_PATHS[CancellationRequest::class],
            fn ($profile) => $profile->where('field_office_id', $officeId)
        ));

        $scopeTrainingRequests = fn ($query) => $query->when($officeId !== null, fn ($inner) => $inner->whereHas(
            self::OFFICE_PATHS[TrainingRequest::class],
            fn ($profile) => $profile->where('field_office_id', $officeId)
        ));

        /*
         * Pending first, then newest.
         *
         * The cap is what makes the ordering matter. These lists are capped at
         * 100 rather than paginated — three independent paginators on one
         * three-tab screen is a worse answer than a bound — and with a plain
         * `latest()` the hundredth decided item pushed the oldest *pending* one
         * off the end. That item then existed only in the sidebar badge, which
         * counts the database: a number telling a staff member there is work,
         * beside a list that does not contain it and no way to reach it.
         *
         * Ordering by status first means the cap can only ever hide items that
         * have already been decided, which are the ones nobody is looking for.
         */
        $pendingFirst = fn ($query) => $query
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [RequestStatus::Pending->value])
            ->latest();

        $cancellations = CancellationRequest::with(['registration.user', 'registration.training'])
            ->tap($scope)
            ->tap($pendingFirst)
            ->limit(self::LIMIT)
            ->get();

        $outputs = RegistrationOutput::with(['registration.user', 'registration.training'])
            ->tap($scope)
            ->tap($pendingFirst)
            ->limit(self::LIMIT)
            ->get();

        $trainingRequests = TrainingRequest::with(['requester.profile', 'training'])
            ->tap($scopeTrainingRequests)
            ->tap($pendingFirst)
            ->limit(self::LIMIT)
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
            /*
             * Counted in the database, not from the arrays above.
             *
             * The page used to derive its tab badges by filtering the truncated
             * list, so once a queue passed the cap the tab and the sidebar
             * disagreed — and the sidebar was the one telling the truth. Both
             * now read the same number.
             *
             * `total` rides along so the page can say how much it is not
             * showing. A list silently ending at 100 is the failure this whole
             * change is about.
             */
            'queues' => [
                'cancellations' => $this->summary(CancellationRequest::query()->tap($scope), $cancellations),
                'trainings' => $this->summary(TrainingRequest::query()->tap($scopeTrainingRequests), $trainingRequests),
                'outputs' => $this->summary(RegistrationOutput::query()->tap($scope), $outputs),
            ],
            'scopedTo' => $request->user()->fieldOffice?->name,
        ]);
    }

    /**
     * How many are pending, how many exist, and how many made it onto the page.
     *
     * @param  Collection<int, mixed>  $shown
     * @return array{pending: int, total: int, shown: int}
     */
    private function summary($query, $shown): array
    {
        $total = (clone $query)->count();

        return [
            'pending' => (clone $query)->where('status', RequestStatus::Pending)->count(),
            'total' => $total,
            'shown' => $shown->count(),
        ];
    }

    public function reviewCancellation(Request $request, CancellationRequest $cancellationRequest): RedirectResponse
    {
        $validated = $this->decision($request);

        CancellationRequestService::review(
            $this->scoped($request, $cancellationRequest),
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
            $this->scoped($request, $trainingRequest),
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

        RegistrationOutputService::review(
            $this->scoped($request, $output),
            RequestStatus::from($validated['decision']),
            $request->user(),
            $validated['remarks'] ?? null
        );

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
