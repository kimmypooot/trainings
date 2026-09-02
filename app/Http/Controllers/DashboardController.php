<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Models\Certificate;
use App\Models\Registration;
use App\Models\User;
use App\Support\ParticipantAttention;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** Entries the feed shows. */
    private const FEED_ENTRIES = 6;

    /**
     * Rows read per source to fill those entries.
     *
     * Each registration contributes between one and four events, so the floor
     * for correctness is FEED_ENTRIES rows; the rest is headroom so the merge
     * with certificates never runs short.
     */
    private const FEED_ROWS = 20;

    public function __invoke(Request $request): Response
    {
        $user = $request->user()->loadMissing('profile');

        /*
         * Counted in the database rather than over a hydrated collection.
         *
         * This page used to load every registration the participant had ever
         * made — with its training and its payments — and count the resulting
         * collection three times. That is a working set that grows for the
         * life of the account to answer three questions SQL answers in one
         * row each. Counted the same way RegistrationController::index counts
         * them, so the tiles here and the chips there can never drift.
         *
         * toBase(), so the grouped rows come back as plain values: an Eloquent
         * result would cast `status` to the enum, and an enum cannot be an
         * array key.
         */
        $byStatus = Registration::where('user_id', $user->getKey())
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        // payments rides along because the hero card asks hasSettledFee() of
        // whichever registration turns out to be next.
        //
        // A pending registration is still an upcoming commitment, so it counts
        // as "next" — the badge tells the participant it is awaiting approval.
        // Columns are qualified throughout: `trainings` carries a `status` of
        // its own, so a bare one here is an ambiguous-column error.
        $next = Registration::with(['training', 'payments'])
            ->join('trainings', 'trainings.id', '=', 'registrations.training_id')
            ->where('registrations.user_id', $user->getKey())
            ->whereIn('registrations.status', RegistrationStatus::occupying())
            ->where('trainings.starts_at', '>', now())
            ->orderBy('trainings.starts_at')
            ->select('registrations.*')
            ->first();

        // Only released certificates count — a row without a generated PDF is
        // not something the participant can do anything with.
        $certificatesReleased = Certificate::where('user_id', $user->getKey())
            ->whereNotNull('generated_at')
            ->count();

        return Inertia::render('Dashboard', [
            'summary' => [
                'pending' => $byStatus[RegistrationStatus::Pending->value] ?? 0,
                'registered' => $byStatus[RegistrationStatus::Approved->value] ?? 0,
                'completed' => $byStatus[RegistrationStatus::Completed->value] ?? 0,
                'certificates' => $certificatesReleased,
            ],
            'nextTraining' => $next ? [
                'title' => $next->training->title,
                // The countdown ships as an instant, not as the sentence it
                // renders to. "Starts in 2 days" formatted here is true at the
                // moment of render and slowly stops being true on a dashboard
                // someone leaves open — and unlike the greeting, this is the
                // one line on the page a participant plans around. The page
                // recomputes it from this, and puts it in a <time datetime>.
                'starts_at' => $next->training->starts_at->toIso8601String(),
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
            'recentActivity' => $this->activityFeed($user),
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
     * The rows come from ParticipantAttention, which is also what the sidebar
     * badge counts, so the two can no longer disagree about what is waiting.
     * This method is presentation only: which icon a queue wears, and how many
     * of its rows are worth printing here.
     *
     * Each row names its training, because the count on its own did not. "2
     * training fees need settling" is the whole errand restated — the reader
     * still has to open the payments screen to learn which two, which is the
     * trip this block exists to save. Now it says which trainings, how much,
     * and when they start.
     *
     * A queue with nothing in it contributes nothing, so an all-clear renders
     * the block away entirely: it exists to be absent most of the time.
     *
     * @return array<int, array<string, mixed>>
     */
    private function attention(User $user): array
    {
        /*
         * Rows printed per queue before it summarises the rest.
         *
         * Detail is the point of this block, but a participant halfway through
         * a five-day run can owe five evaluations, and five near-identical rows
         * push the rest of the dashboard off the screen to say one thing. Three
         * is enough to show what the queue is about; the overflow row carries
         * the count, which is all the fourth row would have added.
         */
        $perQueue = 3;

        // Ordered by how much the participant is holding up: an unpaid fee can
        // cost them their slot, an unfilled evaluation costs them nothing yet.
        $icons = [
            'payments' => 'card',
            'agency-requests' => 'building',
            'physical-or' => 'document',
            'evaluations' => 'clipboard',
        ];

        $more = [
            'payments' => ['payment matter', 'payment matters'],
            'agency-requests' => ['agency request', 'agency requests'],
            'physical-or' => ['receipt request', 'receipt requests'],
            'evaluations' => ['training day', 'training days'],
        ];

        // Where an overflow row goes. Not the first item's href — an evaluation
        // row links to its own form, and a row standing for four of them must
        // not land on one and imply it is the one.
        $queueHref = [
            'payments' => route('payments.index'),
            'agency-requests' => route('agency-requests.index'),
            'physical-or' => route('physical-or.index'),
            'evaluations' => route('evaluations.index'),
        ];

        $rows = [];

        foreach (ParticipantAttention::for($user) as $queue => $items) {
            if ($items === []) {
                continue;
            }

            foreach (array_slice($items, 0, $perQueue) as $item) {
                $rows[] = $item + ['icon' => $icons[$queue] ?? 'clock'];
            }

            $overflow = count($items) - $perQueue;

            if ($overflow > 0) {
                $rows[] = [
                    'key' => "{$queue}-more",
                    'queue' => $queue,
                    'icon' => $icons[$queue] ?? 'clock',
                    'label' => "{$overflow} more ".($overflow === 1 ? $more[$queue][0] : $more[$queue][1]),
                    'subject' => null,
                    'amount' => null,
                    'detail' => null,
                    // Stands for several trainings, so it names none of their
                    // dates. The keys are still present because the page reads
                    // them off every row.
                    'starts_at' => null,
                    'ends_at' => null,
                    'href' => $queueHref[$queue],
                ];
            }
        }

        return $rows;
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
     * @return array<int, array<string, mixed>>
     */
    private function activityFeed(User $user): array
    {
        /*
         * Bounded, and exactly rather than approximately.
         *
         * Every event a registration can contribute is at or before that
         * registration's most recent timestamp, so ordering by "latest thing
         * that happened to this row" and taking the first N cannot drop an
         * event that belongs in the top six — the seventh-newest registration
         * cannot own an event newer than the sixth's newest. N is well clear of
         * six so the certificates merged in below never squeeze a registration
         * event out of a shortened list.
         */
        $registrations = Registration::with('training')
            ->where('user_id', $user->getKey())
            ->orderByRaw('GREATEST(COALESCE(cancelled_at, 0), COALESCE(attended_at, 0), COALESCE(reviewed_at, 0), COALESCE(registered_at, 0)) DESC')
            ->limit(self::FEED_ROWS)
            ->get();

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
            ->orderByDesc('generated_at')
            ->limit(self::FEED_ROWS)
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
            ->take(self::FEED_ENTRIES)
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
     *
     * The window is measured on the absolute gap, which is not fussiness.
     * Carbon 3's diff methods return a *signed* value, so a timestamp in the
     * future is negative and passes any `< 7` test however far out it is: a
     * row dated next month sorted to the top of the feed, landed under "This
     * week", and read "2 weeks from now" — a history of something that has not
     * happened. Seeded demo data has such rows today, and real data can get
     * them from a clock skew or a backdated import, so the reading has to hold
     * either way.
     */
    private function event(
        string $kind,
        string $title,
        string $subject,
        ?CarbonInterface $at,
        string $url,
        int|string|null $owner = null,
    ): array {
        $withinTheWeek = $at !== null && abs($at->diffInDays(now())) < 7;

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
                : ($withinTheWeek ? $at->diffForHumans() : $at->format('d M Y')),
            'at_exact' => $at?->format('d M Y, g:i A'),
            'group' => match (true) {
                $at === null => 'Earlier',
                $at->isToday() => 'Today',
                $at->isYesterday() => 'Yesterday',
                $withinTheWeek => 'This week',
                default => 'Earlier',
            },
        ];
    }
}
