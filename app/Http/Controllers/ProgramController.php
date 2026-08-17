<?php

namespace App\Http\Controllers;

use App\Enums\Curriculum;
use App\Enums\TrainingMode;
use App\Models\Training;
use App\Support\PublicCatalogService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public training catalogue at /programs.
 *
 * The landing page shows the first six programs; this is where the rest live,
 * with search and facets. It is the anonymous half of a pair — TrainingController
 * serves the same calendar to signed-in participants, but answers a different
 * question ("what can I do about this run") and so carries registration state,
 * eligibility and meeting links that must not appear here.
 *
 * No authentication, so nothing personal is read or written. Everything shipped
 * comes from PublicCatalogService::card(), which is the single place that
 * decides what an anonymous visitor is allowed to see.
 */
class ProgramController extends Controller
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

        return Inertia::render('Programs/Index', [
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
}
