<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Models\Certificate;
use App\Models\Registration;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user()->loadMissing('profile');

        $registrations = Registration::with('training')
            ->where('user_id', $user->getKey())
            ->get();

        // A pending registration is still an upcoming commitment, so it counts
        // as "next" — the badge tells the participant it is awaiting approval.
        $next = $registrations
            ->filter(fn (Registration $r) => $r->status->occupiesSlot() && $r->training->starts_at->isFuture())
            ->sortBy(fn (Registration $r) => $r->training->starts_at)
            ->first();

        return Inertia::render('Dashboard', [
            'summary' => [
                'pending' => $registrations->where('status', RegistrationStatus::Pending)->count(),
                'registered' => $registrations->where('status', RegistrationStatus::Approved)->count(),
                'completed' => $registrations->where('status', RegistrationStatus::Completed)->count(),
                // Only released certificates count — a row without a generated
                // PDF is not something the participant can do anything with.
                'certificates' => Certificate::where('user_id', $user->getKey())
                    ->whereNotNull('generated_at')
                    ->count(),
            ],
            'nextTraining' => $next ? [
                'title' => $next->training->title,
                'schedule' => $next->training->starts_at->diffForHumans(),
                'date' => $next->training->starts_at->format('d M Y, g:i A'),
                'venue' => $next->training->venue,
                'status' => $next->status->value,
                'url' => route('trainings.show', $next->training->slug),
            ] : null,
            'recentActivity' => $registrations
                ->sortByDesc('updated_at')
                ->take(5)
                ->map(fn (Registration $r) => [
                    'id' => $r->id,
                    'status' => $r->status->value,
                    'title' => $r->training->title,
                    'happened_at' => $r->updated_at->format('d M Y'),
                ])
                ->values()
                ->all(),
            'profile' => [
                'first_name' => $user->profile?->first_name,
                'organization' => $user->profile?->organization_name,
                'position' => $user->profile?->position_title,
            ],
        ]);
    }
}
