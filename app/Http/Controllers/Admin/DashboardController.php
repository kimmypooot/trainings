<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Enums\TrainingStatus;
use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $officeId = $request->user()->scopedFieldOfficeId();

        $upcoming = Training::visible()
            ->upcoming()
            ->withCount([
                'registrations as active_count' => fn ($query) => $query->whereIn('status', RegistrationStatus::occupying()),
            ])
            ->orderBy('starts_at')
            ->limit(5)
            ->get();

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'published' => Training::where('status', TrainingStatus::Published)->count(),
                'drafts' => Training::where('status', TrainingStatus::Draft)->count(),
                // People counts are scoped; the training catalogue is regional
                // and stays the same for everyone.
                'participants' => User::where('role', Role::Participant)
                    ->when($officeId !== null, fn ($query) => $query->whereHas(
                        'profile',
                        fn ($profile) => $profile->where('field_office_id', $officeId)
                    ))
                    ->count(),
                'registrations' => Registration::whereIn('status', RegistrationStatus::occupying())
                    ->when($officeId !== null, fn ($query) => $query->whereHas(
                        'user.profile',
                        fn ($profile) => $profile->where('field_office_id', $officeId)
                    ))
                    ->count(),
            ],
            'scopedTo' => $request->user()->fieldOffice?->name,
            'upcoming' => $upcoming->map(fn (Training $training) => [
                'id' => $training->id,
                'title' => $training->title,
                'venue' => $training->venue,
                'starts_at' => $training->starts_at->format('d M Y, g:i A'),
                'when' => $training->starts_at->diffForHumans(),
                'registered' => $training->active_count,
                'capacity' => $training->capacity,
                // Surfaced so a nearly-full or empty session gets noticed early.
                'nearly_full' => $training->capacity !== null
                    && $training->active_count >= (int) ceil($training->capacity * 0.9),
                'roster_url' => route('admin.trainings.roster', $training),
            ])->all(),
            'awaitingCompletion' => Training::visible()
                ->where('ends_at', '<', now())
                ->whereHas('registrations', fn ($query) => $query->where(
                    'status', RegistrationStatus::Approved
                ))
                ->orderByDesc('ends_at')
                ->limit(5)
                ->get()
                ->map(fn (Training $training) => [
                    'id' => $training->id,
                    'title' => $training->title,
                    'ended' => $training->ends_at->format('d M Y'),
                    'roster_url' => route('admin.trainings.roster', $training),
                ])->all(),
        ]);
    }
}
