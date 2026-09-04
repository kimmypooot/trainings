<?php

namespace Tests\Feature;

use App\Models\SubjectMatterExpert;
use App\Models\Training;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The public catalogue, which lives on the landing page.
 *
 * It used to be a page of its own at /programs. That page and the landing
 * page's calendar section rendered identical cards from the same source and
 * differed only in that one could be filtered, so the filters moved to '/' and
 * /programs became a permanent redirect.
 *
 * Its whole reason for existing is to be reachable without an account, so the
 * first thing asserted is that no middleware crept in front of it. The rest
 * guards what an anonymous payload may carry — the boundary that matters here,
 * since the same programs are also served to signed-in participants with far
 * more attached.
 */
class ProgramCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_catalogue_is_public(): void
    {
        Training::factory()->create(['title' => 'Open Program', 'starts_at' => now()->addDays(30)]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Home')
                ->has('programs', 1)
                ->where('programs.0.title', 'Open Program')
            );
    }

    /**
     * The old catalogue URL was in the sitemap and in real bookmarks, so it
     * keeps working. Permanent, because the content genuinely moved and did not
     * merely go missing.
     */
    public function test_the_old_catalogue_url_redirects_to_the_calendar(): void
    {
        $this->get('/programs')
            ->assertStatus(301)
            ->assertRedirect('/#upcoming');
    }

    /**
     * Nothing earned by signing in may reach an anonymous payload.
     *
     * An Inertia payload is readable JSON in the response body, so a field
     * shipped here is published whatever the template renders. The meeting link
     * is the sharp one — it is the thing a paid, approved participant gets and
     * nobody else.
     */
    public function test_the_payload_withholds_what_signing_in_earns(): void
    {
        $training = Training::factory()->create([
            'starts_at' => now()->addDays(30),
            'meeting_link' => 'https://meet.example.test/secret-room',
            'signatory_name' => 'MARIA SANTOS',
        ]);

        // An expert's contact details are internal, and the catalogue card is
        // the most public surface in the app — so the number is planted here
        // and asserted absent rather than merely assumed to be unreferenced.
        $expert = SubjectMatterExpert::factory()->create([
            'name' => 'MARIA SANTOS',
            'contact_number' => '09171234567',
            'email' => 'sme.private@csc.gov.ph',
        ]);
        $training->subjectMatterExperts()->attach($expert);

        $response = $this->get('/')->assertOk();

        $response->assertDontSee('secret-room', false);
        $response->assertDontSee('09171234567', false);
        $response->assertDontSee('sme.private@csc.gov.ph', false);

        $card = $response->viewData('page')['props']['programs'][0];

        $this->assertArrayNotHasKey('meeting_link', $card);
        $this->assertArrayNotHasKey('signatory_name', $card);
        $this->assertArrayNotHasKey('subject_matter_experts', $card);
    }

    public function test_drafts_and_finished_runs_are_absent(): void
    {
        Training::factory()->create(['title' => 'Listed', 'starts_at' => now()->addDays(30)]);
        Training::factory()->draft()->create(['title' => 'Draft']);
        Training::factory()->create([
            'title' => 'Finished',
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->subDays(4),
        ]);

        $titles = collect($this->get('/')->viewData('page')['props']['programs'])
            ->pluck('title')
            ->all();

        $this->assertSame(['Listed'], $titles);
    }

    public function test_search_matches_title_code_and_venue(): void
    {
        Training::factory()->create([
            'title' => 'Records Management Seminar',
            'training_code' => 'TRN-2026-0001',
            'venue' => 'Palo, Leyte',
            'starts_at' => now()->addDays(30),
        ]);
        Training::factory()->create([
            'title' => 'Ethics Workshop',
            'training_code' => 'TRN-2026-0002',
            'venue' => 'Ormoc City',
            'starts_at' => now()->addDays(31),
        ]);

        foreach (['Records', 'TRN-2026-0001', 'Palo'] as $term) {
            $titles = collect($this->get('/?search='.urlencode($term))->viewData('page')['props']['programs'])
                ->pluck('title')
                ->all();

            $this->assertSame(['Records Management Seminar'], $titles, "searching for [{$term}]");
        }
    }

    public function test_status_filter_narrows_to_one_lifecycle_stage(): void
    {
        Training::factory()->create([
            'title' => 'Joinable',
            'starts_at' => now()->addDays(30),
            'registration_closes_at' => now()->addDays(20),
        ]);
        Training::factory()->create([
            'title' => 'Not Open Yet',
            'starts_at' => now()->addDays(60),
            'registration_opens_at' => now()->addDays(20),
        ]);
        Training::factory()->full()->create([
            'title' => 'Booked Out',
            'starts_at' => now()->addDays(40),
        ]);

        $titlesFor = fn (string $status) => collect(
            $this->get('/?status='.$status)->viewData('page')['props']['programs']
        )->pluck('title')->all();

        $this->assertSame(['Joinable'], $titlesFor('open'));
        $this->assertSame(['Not Open Yet'], $titlesFor('opening'));
        $this->assertSame(['Booked Out'], $titlesFor('full'));
    }

    /**
     * A public URL is guessable, hand-editable, and crawled. A nonsense filter
     * must return an honest empty result, never a 422 or a 500 — and on the
     * landing page it must not take the rest of the page down with it.
     */
    public function test_unknown_filter_values_return_an_empty_result_not_an_error(): void
    {
        Training::factory()->create(['starts_at' => now()->addDays(30)]);

        $this->get('/?mode=teleportation&category=wizardry&status=invented')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('programs', 0)
                // The hero and the stats block are not part of the catalogue and
                // must survive a filter that matches nothing. The count is not
                // asserted: HomeController now publishes only the figures it can
                // stand behind, so the size is a property of how much history the
                // deployment has, not of whether this filter broke the page.
                ->has('stats')
            );
    }

    /**
     * The hero's "open for registration" line counts only what can be joined.
     *
     * Everything the catalogue below shows and says "no" about — full, not yet
     * open, already running — would make that line's claim false. It is the one
     * figure on the landing page a visitor acts on, so counting a booked-out run
     * in it sends someone to a calendar with nothing on it for them.
     */
    public function test_the_hero_counts_only_runs_a_visitor_can_join(): void
    {
        Training::factory()->create([
            'title' => 'Joinable',
            'starts_at' => now()->addDays(30),
            'registration_closes_at' => now()->addDays(20),
        ]);
        Training::factory()->create([
            'title' => 'Not Open Yet',
            'starts_at' => now()->addDays(60),
            'registration_opens_at' => now()->addDays(20),
        ]);
        Training::factory()->full()->create([
            'title' => 'Booked Out',
            'starts_at' => now()->addDays(40),
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('openProgramCount', 1));
    }

    /**
     * The hero's count is not derived from the catalogue below it.
     *
     * The two answer different questions — "is there anything open for me at
     * all" against "show me the ones matching what I typed" — so a filter that
     * empties the grid must leave the hero saying exactly what it said before,
     * and page 2 must not report a different number from page 1. Sharing the
     * list would make a keystroke rewrite the page's headline offer, which is
     * also why `openProgramCount` is not in Home.vue's `only:` list.
     */
    public function test_the_hero_count_ignores_the_catalogue_filters(): void
    {
        Training::factory()->create([
            'title' => 'Joinable',
            'starts_at' => now()->addDays(30),
            'registration_closes_at' => now()->addDays(20),
        ]);

        $props = $this->get('/?search=nothing-matches-this')->viewData('page')['props'];

        $this->assertCount(0, $props['programs']);
        $this->assertSame(1, $props['openProgramCount']);
    }

    /**
     * The count is not capped at what any one screen shows.
     *
     * The line reports how much is open, not how much fits — an office with a
     * full quarter published must not be described by the size of a page.
     */
    public function test_the_hero_count_is_not_capped_by_pagination(): void
    {
        Training::factory()->count(15)->create([
            'starts_at' => now()->addDays(30),
            'registration_closes_at' => now()->addDays(20),
        ]);

        $props = $this->get('/')->viewData('page')['props'];

        // A page holds twelve; the hero still reports all fifteen.
        $this->assertCount(12, $props['programs']);
        $this->assertSame(15, $props['openProgramCount']);
    }

    /**
     * Nothing open is an ordinary state for a regional calendar — between
     * quarters, or after a batch closes. Home.vue withholds the line entirely on
     * a zero rather than printing "0 programs open for registration" under a
     * button asking for an account, so the zero has to be what the controller
     * actually sends.
     */
    public function test_the_hero_count_is_zero_when_nothing_is_open(): void
    {
        Training::factory()->full()->create(['starts_at' => now()->addDays(40)]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('openProgramCount', 0));
    }

    public function test_the_result_count_reports_what_the_page_shows(): void
    {
        Training::factory()->create([
            'title' => 'Joinable',
            'starts_at' => now()->addDays(30),
            'registration_closes_at' => now()->addDays(20),
        ]);
        Training::factory()->full()->create(['starts_at' => now()->addDays(40)]);

        // The paginator matched two rows; the status filter leaves one on screen,
        // and the figure the page prints must be the latter.
        $props = $this->get('/?status=open')->viewData('page')['props'];

        $this->assertCount(1, $props['programs']);
        $this->assertSame(1, $props['meta']['showing']);
    }

    public function test_the_catalogue_paginates(): void
    {
        Training::factory()->count(15)->create(['starts_at' => now()->addDays(30)]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('programs', 12)
                ->where('meta.last_page', 2)
                ->where('meta.total', 15)
            );

        $this->get('/?page=2')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('programs', 3));
    }

    /**
     * The catalogue is reachable at '/', so the sitemap must not also advertise
     * the old URL — that would send a crawler to a 301 for a page it already
     * has.
     */
    public function test_the_sitemap_lists_the_landing_page_and_not_the_old_url(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee(url('/').'<', false)
            ->assertDontSee(url('/programs'), false);
    }

    /**
     * Being public, the page must behave the same for a signed-in visitor —
     * including one whose profile is incomplete, whom EnsureProfileIsComplete
     * would bounce off any gated route.
     */
    public function test_a_signed_in_visitor_with_an_incomplete_profile_still_sees_it(): void
    {
        Training::factory()->create(['starts_at' => now()->addDays(30)]);

        $user = User::factory()->create(['profile_completed_at' => null]);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Home')
                ->has('programs', 1)
            );
    }
}
