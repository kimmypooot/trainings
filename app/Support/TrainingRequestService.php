<?php

namespace App\Support;

use App\Enums\RequestStatus;
use App\Enums\TrainingStatus;
use App\Models\Training;
use App\Models\TrainingRequest;
use App\Models\User;
use App\Notifications\TrainingRequestReviewed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Agency-requested trainings: submission, review, and conversion into a real
 * training once HRD agrees to run it.
 */
class TrainingRequestService
{
    public static function review(
        TrainingRequest $request,
        RequestStatus $decision,
        User $reviewer,
        ?string $remarks = null
    ): TrainingRequest {
        if ($decision === RequestStatus::Pending) {
            throw ValidationException::withMessages([
                'decision' => 'That is not a valid review decision.',
            ]);
        }

        if ($decision === RequestStatus::Rejected && blank($remarks)) {
            throw ValidationException::withMessages([
                'remarks' => 'Give a reason when declining a training request.',
            ]);
        }

        $request = DB::transaction(function () use ($request, $decision, $reviewer, $remarks) {
            $locked = TrainingRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isPending()) {
                throw ValidationException::withMessages([
                    'decision' => 'This request has already been reviewed.',
                ]);
            }

            $locked->forceFill([
                'status' => $decision,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ])->save();

            ActivityLogger::recordTransition(
                "training-request.{$decision->value}",
                $locked,
                RequestStatus::Pending,
                $decision,
                "Training request “{$locked->title}” {$decision->label()} by {$reviewer->name}.",
                ['remarks' => $remarks],
                $reviewer,
            );

            return $locked;
        });

        $request->loadMissing('requester');
        $request->requester?->notify(new TrainingRequestReviewed($request));

        return $request;
    }

    /**
     * Turn an approved request into a draft training.
     *
     * Created as a draft, never published: the request supplies the intent, but
     * a venue, schedule and capacity still need HRD's hand before participants
     * should be able to see it.
     */
    public static function convert(TrainingRequest $request, User $creator): Training
    {
        return DB::transaction(function () use ($request, $creator) {
            $locked = TrainingRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== RequestStatus::Approved) {
                throw ValidationException::withMessages([
                    'request' => 'Only an approved request can be turned into a training.',
                ]);
            }

            if ($locked->training_id !== null) {
                throw ValidationException::withMessages([
                    'request' => 'This request has already been turned into a training.',
                ]);
            }

            $starts = $locked->preferred_start?->copy()->setTime(8, 0) ?? now()->addMonth()->setTime(8, 0);
            $ends = $locked->preferred_end?->copy()->setTime(17, 0) ?? $starts->copy()->setTime(17, 0);

            $training = Training::create([
                'title' => $locked->title,
                'slug' => self::uniqueSlug($locked->title),
                'description' => $locked->justification,
                'category' => $locked->category,
                'venue' => 'To be announced',
                'starts_at' => $starts,
                'ends_at' => $ends,
                'duration_days' => max(1, $starts->diffInDays($ends) + 1),
                'capacity' => $locked->expected_participants,
                'target_participants' => $locked->expected_participants
                    ? "Approximately {$locked->expected_participants} participants"
                    : null,
                'status' => TrainingStatus::Draft,
                'created_by' => $creator->getKey(),
            ]);

            $locked->forceFill(['training_id' => $training->getKey()])->save();

            ActivityLogger::record(
                'training.created-from-request',
                $training,
                "Training “{$training->title}” created from request #{$locked->getKey()}.",
                ['training_request_id' => $locked->getKey()],
                $creator,
            );

            return $training;
        });
    }

    private static function uniqueSlug(string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $suffix = 2;

        while (Training::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
