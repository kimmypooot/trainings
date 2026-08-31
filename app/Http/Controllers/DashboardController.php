<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Models\Certificate;
use App\Models\Registration;
use App\Models\User;
use App\Support\PendingActionCounter;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user()->loadMissing('profile');

        // payments rides along because the hero card asks hasSettledFee() of
        // whichever registration turns out to be next, and there is no telling
        // which one that is until the sort below has run.
        $registrations = Registration::with(['training', 'payments'])
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
                'ends_at' => $next->training->ends_at?->format('d M Y, g:i A'),
                'venue' => $next->training->venue,
                'mode_label' => $next->training->mode->label(),
                'payment_amount' => $next->training->payment_required
                    ? $next->training->payment_amount
                    : null,
                // A fee with no word on whether it is settled is worse than no
                // fee line at all: the participant cannot tell whether the
                // figure is a receipt or a bill. Null when nothing is owed.
                'fee_settled' => $next->training->payment_required
                    ? $next->hasSettledFee()
                    : null,
                'status' => $next->status->value,
                // The QR code only opens a door once the registration has been
                // approved. Offering it on a pending one sends the participant
                // to a scanner that will turn them away.
                'can_check_in' => $next->status === RegistrationStatus::Approved,
                'url' => route('trainings.show', $next->training->slug),
                'calendar_url' => route('registrations.calendar', $next),
            ] : null,
            'attention' => $this->attention($user),
            'recentActivity' => $this->activityFeed($user, $registrations),
            'profile' => [
                'first_name' => $user->profile?->first_name,
                'organization' => $user->profile?->organization_name,
                'position' => $user->profile?->position_title,
            ],
        ]);
    }

    /**
     * What the participant still owes, if anything.
     *
     * Deliberately reuses PendingActionCounter rather than re-deriving the
     * rules. The sidebar badge and this block must never disagree about
     * whether there is work waiting, and the counter is already the one place
     * that knows what "pending" means for each queue — including the two kinds
     * of pending payment, and the evaluation count that decays on its own.
     *
     * A zero count is dropped, so an all-clear renders nothing at all: this
     * block exists to be empty most of the time.
     *
     * @return array<int, array<string, string>>
     */
    private function attention(User $user): array
    {
        // Ordered by how much the participant is holding up: an unpaid fee can
        // cost them their slot, an unfilled evaluation costs them nothing yet.
        $queues = [
            'payments' => [
                'href' => '/my/payments',
                'icon' => 'card',
                'one' => 'A training fee needs settling',
                'many' => 'training fees need settling',
            ],
            'agency-requests' => [
                'href' => '/my/agency-requests',
                'icon' => 'building',
                'one' => 'An agency request needs your response',
                'many' => 'agency requests need your response',
            ],
            'physical-or' => [
                'href' => '/my/physical-or',
                'icon' => 'document',
                'one' => 'A receipt request is waiting on your proof of payment',
                'many' => 'receipt requests are waiting on your proof of payment',
            ],
            'evaluations' => [
                'href' => '/my/evaluations',
                'icon' => 'clipboard',
                'one' => 'A training day is waiting to be evaluated',
                'many' => 'training days are waiting to be evaluated',
            ],
        ];

        $counts = PendingActionCounter::for($user);
        $items = [];

        foreach ($queues as $key => $queue) {
            $count = $counts[$key] ?? 0;

            if ($count < 1) {
                continue;
            }

            $items[] = [
                'key' => $key,
                'href' => $queue['href'],
                'icon' => $queue['icon'],
                'label' => $count === 1 ? $queue['one'] : "{$count} {$queue['many']}",
            ];
        }

        return $items;
    }

    /**
     * What has actually happened, most recent first.
     *
     * The old feed listed each registration once and showed its current status,
     * which meant a registration approved in March and completed in June
     * appeared as a single June row reading "Completed" — the approval was
     * simply gone. A registration is not an event; it is a thing that events
     * happen to. So each stored timestamp becomes its own entry, and the list
     * reads as a history rather than a snapshot wearing a date.
     *
     * @param  Collection<int, Registration>  $registrations
     * @return array<int, array<string, mixed>>
     */
    private function activityFeed(User $user, Collection $registrations): array
    {
        $events = [];

        foreach ($registrations as $registration) {
            $training = $registration->training;
            $url = route('trainings.show', $training->slug);

            $events[] = $this->event('registered', 'Registered', $training->title, $registration->registered_at, $url, $registration->id);

            // reviewed_at carries whichever decision was last made; for a
            // registration that has since been completed, that decision was the
            // approval which let it get there.
            if ($registration->reviewed_at) {
                $decision = match ($registration->status) {
                    RegistrationStatus::Waitlisted => ['waitlisted', 'Placed on the waitlist'],
                    RegistrationStatus::Rejected => ['rejected', 'Not approved'],
                    default => ['approved', 'Registration approved'],
                };

                $events[] = $this->event($decision[0], $decision[1], $training->title, $registration->reviewed_at, $url, $registration->id);
            }

            if ($registration->status === RegistrationStatus::Completed) {
                $events[] = $this->event(
                    'completed',
                    'Training completed',
                    $training->title,
                    $registration->attended_at ?? $registration->reviewed_at,
                    $url,
                    $registration->id,
                );
            }

            if ($registration->cancelled_at) {
                $events[] = $this->event('withdrawn', 'Withdrew', $training->title, $registration->cancelled_at, $url, $registration->id);
            }
        }

        // Certificates are the part of "activity" the old feed never showed at
        // all, though it is the entry a participant is most likely looking for.
        $certificates = Certificate::with('registration.training')
            ->where('user_id', $user->getKey())
            ->whereNotNull('generated_at')
            ->get();

        foreach ($certificates as $certificate) {
            $events[] = $this->event(
                'certificate',
                'Certificate issued',
                $certificate->registration?->training?->title ?? 'Training',
                $certificate->generated_at,
                route('certificates.index'),
                "cert-{$certificate->id}",
            );
        }

        return collect($events)
            ->filter(fn (array $event) => $event['sort'] !== null)
            ->sortByDesc('sort')
            ->take(6)
            ->map(fn (array $event) => Arr::except($event, ['sort']))
            ->values()
            ->all();
    }

    /**
     * One entry in the feed.
     *
     * Dates follow the house rule: relative inside the last week, absolute
     * beyond it, and always the other form in the tooltip — "3 days ago" is
     * easier to place, but only while the span is short enough to feel.
     */
    private function event(
        string $kind,
        string $title,
        string $subject,
        ?CarbonInterface $at,
        string $url,
        int|string|null $owner = null,
    ): array {
        return [
            // Keyed on the row the event came off rather than on its subject:
            // two events of the same kind against the same training title would
            // otherwise collide, and a duplicate key silently drops a tile.
            'id' => "{$kind}-{$owner}-{$at?->timestamp}",
            'kind' => $kind,
            'title' => $title,
            'subject' => $subject,
            'url' => $url,
            'sort' => $at?->timestamp,
            'at' => $at?->toIso8601String(),
            'at_label' => $at === null
                ? null
                : ($at->diffInDays(now()) < 7 ? $at->diffForHumans() : $at->format('d M Y')),
            'at_exact' => $at?->format('d M Y, g:i A'),
            'group' => match (true) {
                $at === null => 'Earlier',
                $at->isToday() => 'Today',
                $at->isYesterday() => 'Yesterday',
                $at->diffInDays(now()) < 7 => 'This week',
                default => 'Earlier',
            },
        ];
    }
}
