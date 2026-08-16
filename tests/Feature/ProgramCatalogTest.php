<?php

namespace Tests\Feature;

use App\Models\Training;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The public catalogue at /programs.
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

        $this->get('/programs')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Programs/Index')
                ->has('programs', 1)
                ->where('programs.0.title', 'Open Program')
            );
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
        Training::factory()->create([
            'starts_at' => now()->addDays(30),
            'meeting_link' => 'https://meet.example.test/secret-room',
            'facilitator_name' => 'MARIA SANTOS',
            'facilitator_contact' => '09171234567',
        ]);

        $response = $this->get('/programs')->assertOk();

        $response->assertDontSee('secret-room', false);
        $response->assertDontSee('09171234567', false);

        $card = $response->viewData('page')['props']['programs'][0];

        $this->assertArrayNotHasKey('meeting_link', $card);
        $this->assertArrayNotHasKey('facilitator_name', $card);
        $this->assertArrayNotHasKey('facilitator_contact', $card);
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

        $titles = collect($this->get('/programs')->viewData('page')['props']['programs'])
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
            $titles = collect($this->get('/programs?search='.urlencode($term))->viewData('page')['props']['programs'])
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
            $this->get('/programs?status='.$status)->viewData('page')['props']['programs']
        )->pluck('title')->all();

        $this->assertSame(['Joinable'], $titlesFor('open'));
        $this->assertSame(['Not Open Yet'], $titlesFor('opening'));
        $this->assertSame(['Booked Out'], $titlesFor('full'));
    }

    /**
     * A public URL is guessable, hand-editable, and crawled. A nonsense filter
     * must return an honest empty result, never a 422 or a 500.
     */
    public function test_unknown_filter_values_return_an_empty_result_not_an_error(): void
    {
        Training::factory()->create(['starts_at' => now()->addDays(30)]);

        $this->get('/programs?mode=teleportation&category=wizardry&status=invented')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('programs', 0));
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
        $props = $this->get('/programs?status=open')->viewData('page')['props'];

        $this->assertCount(1, $props['programs']);
        $this->assertSame(1, $props['meta']['showing']);
    }

    public function test_the_catalogue_paginates(): void
    {
        Training::factory()->count(15)->create(['starts_at' => now()->addDays(30)]);

        $this->get('/programs')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('programs', 12)
                ->where('meta.last_page', 2)
                ->where('meta.total', 15)
            );

        $this->get('/programs?page=2')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('programs', 3));
    }

    /**
     * The landing page and /programs read the same source, so a program must
     * never be described one way on one and another way on the other.
     */
    public function test_the_landing_page_and_the_catalogue_agree(): void
    {
        Training::factory()->full()->create([
            'title' => 'Booked Out',
            'starts_at' => now()->addDays(40),
        ]);

        $onHome = collect($this->get('/')->viewData('page')['props']['upcomingTrainings'])->firstWhere('title', 'Booked Out');
        $onCatalogue = collect($this->get('/programs')->viewData('page')['props']['programs'])->firstWhere('title', 'Booked Out');

        $this->assertSame($onHome, $onCatalogue);
    }

    /** The catalogue is a public page and belongs in the sitemap. */
    public function test_the_catalogue_is_listed_in_the_sitemap(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee(url('/programs'), false);
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
            ->get('/programs')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Programs/Index'));
    }
}
