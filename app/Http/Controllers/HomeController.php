<?php

namespace App\Http\Controllers;

use App\Enums\Curriculum;
use App\Enums\RegistrationStatus;
use App\Enums\TrainingMode;
use App\Enums\TrainingStatus;
use App\Models\FieldOffice;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Support\PublicCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public landing page, which is also the public training catalogue.
 *
 * These were two pages until the calendar section and /programs turned out to
 * be the same grid of the same cards from the same source, differing only in
 * that one of them could be filtered. Rather than render the filter bar twice,
 * the catalogue moved here and /programs became a permanent redirect to the
 * #upcoming anchor.
 *
 * It is the anonymous half of a pair — TrainingController serves the same
 * calendar to signed-in participants, but answers a different question ("what
 * can I do about this run") and so carries registration state, eligibility and
 * meeting links that must not appear here. Everything shipped from this
 * controller comes from PublicCatalogService::card(), which is the single place
 * that decides what an anonymous visitor is allowed to see.
 */
class HomeController extends Controller
{
    /** A page of cards: four rows of three on a wide screen. */
    private const PER_PAGE = 12;

    public function index(Request $request): Response
    {
        /*
         * Filters are read leniently and never validated into a 422. A bad
         * value on a public URL — a stale link, a hand-edited query string, a
         * crawler guessing — should return an honest empty result, not an error
         * page. An unknown mode or category simply matches nothing.
         */
        $filters = [
            'search' => $request->string('search')->trim()->toString(),
            'mode' => $request->string('mode')->toString(),
            'category' => $request->string('category')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $programs = PublicCatalogService::query()
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($query) => $query
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('training_code', 'like', "%{$search}%")
                    ->orWhere('venue', 'like', "%{$search}%")
            ))
            ->when($filters['mode'], fn ($query, $mode) => $query->where('mode', $mode))
            ->when($filters['category'], fn ($query, $category) => $query->where('category', $category))
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $cards = collect($programs->items())
            ->map(fn (Training $training) => PublicCatalogService::card($training));

        /*
         * Status is filtered after the query rather than inside it.
         *
         * It is not a column: "full" compares a live registration count against
         * capacity, and "opening" / "closing-soon" / "closed" are read off two
         * nullable dates against now(). Expressing that in SQL would mean
         * duplicating registrationState()'s precedence rules in a second
         * language, where they could quietly disagree with the badge the card
         * ends up showing.
         *
         * The cost is that a filtered page can hold fewer than PER_PAGE cards.
         * That is a fair trade at a regional office's catalogue size, and the
         * result count below is drawn from the same filtered list, so the page
         * never claims more than it shows.
         */
        if ($filters['status']) {
            $cards = $cards->where('status', $filters['status'])->values();
        }

        return Inertia::render('Home', [
            'stats' => $this->stats(),
            'openProgramCount' => $this->openProgramCount(),
            'programs' => $cards->all(),
            'filters' => $filters,
            'filterOptions' => [
                'modes' => TrainingMode::options(),
                'categories' => Curriculum::options(),
                // Mirrors PublicCatalogService::registrationState()'s keys. Kept
                // server-side so the page cannot offer a filter for a status the
                // service no longer produces.
                'statuses' => [
                    ['value' => 'open', 'label' => 'Open for registration'],
                    ['value' => 'closing-soon', 'label' => 'Closing soon'],
                    ['value' => 'opening', 'label' => 'Opening later'],
                    ['value' => 'full', 'label' => 'Fully booked'],
                    ['value' => 'closed', 'label' => 'Registration closed'],
                    ['value' => 'ongoing', 'label' => 'In progress'],
                ],
            ],
            'meta' => [
                'current_page' => $programs->currentPage(),
                'last_page' => $programs->lastPage(),
                'total' => $programs->total(),
                // What this page actually shows, after the status filter. The
                // paginator's own count predates that filter and would overstate.
                'showing' => $cards->count(),
            ],
        ]);
    }

    /**
     * How many runs a visitor could sign up for right now — the hero's one
     * piece of live information, under the buttons.
     *
     * Deliberately *not* derived from `programs`. That list is the filtered,
     * paginated catalogue below, so sharing it would let a keystroke in the
     * search box rewrite the hero's headline claim, and page 2 would report a
     * different number from page 1. The hero answers "is there anything open
     * for me at all", which no filter the visitor has typed may change. That is
     * also why it is absent from the `only:` list Home.vue sends when
     * filtering: nothing on the page can make it stale.
     *
     * Counted in PHP rather than in SQL because registrability is not a column
     * — PublicCatalogService::registrationState weighs a live seat count
     * against two nullable dates, in a defined order — and expressing that in a
     * where clause would mean maintaining those precedence rules in a second
     * language, where they could quietly disagree with the badge the catalogue
     * below prints for the same run. The query is already bounded to published,
     * unfinished runs, which at a regional office is a calendar, not a table.
     *
     * Uncached, unlike stats(): this is the one figure on the page a visitor
     * acts on, and an hour-stale "3 programs open" sends them to an empty
     * calendar.
     */
    private function openProgramCount(): int
    {
        return PublicCatalogService::query()
            ->get()
            ->filter(fn (Training $training) => PublicCatalogService::card($training)['is_registrable'])
            ->count();
    }

    /**
     * Landing-page headline figures.
     *
     * Real counts straight from the database, cached for an hour so a public
     * page never runs four queries per hit.
     *
     * Every figure has to earn its place, because this band is the one part of
     * the landing page that makes a claim. Two rules follow from that:
     *
     * 1. A figure that would read as an indictment is withheld, not shown as a
     *    zero. A fresh deployment has delivered nothing and completed nothing,
     *    and "0% completion rate" on the front page of a training portal is a
     *    statement about the office, not about the data being new. Completion
     *    is reported only once there is a real denominator behind it.
     * 2. Nothing is asserted that this database cannot count. The figure here
     *    used to be a hard-coded "17 regional offices" describing the
     *    nationwide CSC organisation — true of the Commission, but not a fact
     *    this single-region deployment has any standing to publish. It is now
     *    the number of field offices actually taking part, which is countable
     *    and is the more useful number for a visitor anyway.
     *
     * An empty array is a valid answer and hides the whole band; see Home.vue.
     *
     * @return array<int, array{figure: string, label: string}>
     */
    private function stats(): array
    {
        return Cache::remember('home.stats', now()->addHour(), function () {
            $stats = [];

            $enrolled = User::whereNotNull('profile_completed_at')->count();
            if ($enrolled > 0) {
                $stats[] = ['figure' => number_format($enrolled), 'label' => 'Personnel enrolled'];
            }

            // "Delivered" = a training that has actually begun, any status that
            // is not still a draft or unceremoniously cancelled.
            $delivered = Training::whereNotIn('status', [TrainingStatus::Draft, TrainingStatus::Cancelled])
                ->where('starts_at', '<=', now())
                ->count();
            if ($delivered > 0) {
                $stats[] = ['figure' => number_format($delivered), 'label' => 'Programs delivered'];
            }

            $approvedOrCompleted = Registration::whereIn('status', [
                RegistrationStatus::Approved,
                RegistrationStatus::Completed,
            ])->count();

            // Rule 1 above: no denominator, no percentage.
            if ($approvedOrCompleted > 0) {
                $completed = Registration::where('status', RegistrationStatus::Completed)->count();
                $stats[] = [
                    'figure' => round($completed / $approvedOrCompleted * 100).'%',
                    'label' => 'Completion rate',
                ];
            }

            $offices = FieldOffice::count();
            if ($offices > 0) {
                $stats[] = [
                    'figure' => number_format($offices),
                    'label' => $offices === 1 ? 'Participating office' : 'Participating offices',
                ];
            }

            return $stats;
        });
    }
}
