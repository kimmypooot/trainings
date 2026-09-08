<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sidebar and routes/web.php must agree about who can reach what.
 *
 * These are two lists of the same fact kept in different languages, and they
 * had drifted in both directions at once. A field office once had
 * /admin/trainings routed to it and no nav row pointing at it; /admin/scanner
 * had no row at all, from anywhere, so a complete offline attendance station
 * was reachable only by whoever had been told the URL. Neither failure shows up
 * as an error — one is a page nobody can find, the other is a menu item that
 * 403s — so neither was noticed.
 *
 * The nav is parsed out of the layout rather than restated here, for the reason
 * the layout keeps it as data in the first place: a copy of the list in a test
 * is a third place to update and the first to be forgotten.
 */
class NavigationReachTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every nav item as (key, href, roles), read from AuthenticatedLayout.
     *
     * @return array<int, array{key: string, href: string, roles: array<int, string>}>
     */
    private function navItems(): array
    {
        $source = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.vue'));

        $staff = ['field-office', 'admin', 'management', 'superadmin'];
        $venue = ['field-office', 'collecting-officer', 'admin', 'superadmin'];
        $all = ['participant', ...$staff, 'collecting-officer'];

        preg_match_all(
            '/key:\s*\'([a-z0-9-]+)\',\s*\n\s*label:[^\n]+\n\s*href:\s*\'([^\']+)\',\s*\n(?:[^}]*?)roles:\s*([^\n]+)/m',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        $items = [];

        /*
         * Expand the named lists, then read every quoted role out of whatever
         * is left.
         *
         * Deliberately not a `match` over which constant appears. That was the
         * first version and it was blind in exactly the way this test exists to
         * prevent: `[...VENUE_ROLES, 'management']` hit the VENUE_ROLES arm,
         * returned the four venue roles and silently dropped the addition — so
         * a nav row offering a page to a role the route refuses parsed as
         * correct. Substituting the constants and then taking every literal
         * cannot miss an addition, because it never decides what the expression
         * "is".
         */
        $expand = fn (string $expression) => str_replace(
            ['ALL_ROLES', 'STAFF_ROLES', 'VENUE_ROLES'],
            [
                "'".implode("','", $all)."'",
                "'".implode("','", $staff)."'",
                "'".implode("','", $venue)."'",
            ],
            $expression,
        );

        foreach ($matches as [$_, $key, $href, $rolesExpression]) {
            preg_match_all("/'([a-z-]+)'/", $expand($rolesExpression), $found);

            $roles = array_values(array_unique(array_filter(
                $found[1],
                fn ($r) => in_array($r, $all, true),
            )));

            $this->assertNotEmpty($roles, "Could not read the roles for nav item “{$key}”.");

            $items[] = ['key' => $key, 'href' => $href, 'roles' => $roles];
        }

        return $items;
    }

    private function userFor(string $role): User
    {
        $user = User::factory()->create([
            'role' => Role::from($role),
            'profile_completed_at' => now(),
            // The one nav item gated on a designation rather than a role.
            'is_collecting_officer' => true,
        ]);

        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    /**
     * Nothing in the sidebar 403s for the role it is shown to.
     *
     * This is the half that catches a nav row added for a page the role cannot
     * open — a menu item that punishes the person who trusts it.
     */
    public function test_every_role_can_open_every_link_its_sidebar_offers(): void
    {
        $items = $this->navItems();

        // A guard on the parser: if the regex stops matching, every assertion
        // below passes vacuously and the test becomes decoration.
        $this->assertGreaterThan(20, count($items), 'The nav parser found suspiciously few items.');

        $checked = 0;

        foreach ($items as $item) {
            foreach ($item['roles'] as $role) {
                $user = $this->userFor($role);

                $status = $this->actingAs($user)->get($item['href'])->getStatusCode();
                $this->flushSession();

                $this->assertNotSame(
                    403,
                    $status,
                    "The sidebar offers “{$item['key']}” ({$item['href']}) to {$role}, but that role is refused it.",
                );

                $checked++;
            }
        }

        $this->assertGreaterThan(40, $checked);
    }

    /**
     * The scanner in particular, because it is what this test was written for.
     *
     * It is the venue's whole attendance workflow and it spent its life with no
     * inbound link anywhere in the application.
     */
    public function test_the_scan_station_is_reachable_from_the_sidebar(): void
    {
        $scanner = collect($this->navItems())->firstWhere('href', '/admin/scanner');

        $this->assertNotNull(
            $scanner,
            'Nothing in the sidebar points at /admin/scanner, so the attendance station is unreachable again.',
        );

        // And exactly the roles the route admits — no more, and no fewer.
        $this->assertEqualsCanonicalizing(
            ['field-office', 'collecting-officer', 'admin', 'superadmin'],
            $scanner['roles'],
        );
    }

    /**
     * A collecting officer has a job, and the sidebar has to contain it.
     *
     * This role was down to two rows — Dashboard and My Profile — while the
     * routes let it work a door, review three request queues and take payments.
     */
    public function test_a_collecting_officer_has_more_than_a_dashboard(): void
    {
        $offered = collect($this->navItems())
            ->filter(fn ($item) => in_array('collecting-officer', $item['roles'], true))
            ->pluck('key');

        $this->assertContains('admin-scanner', $offered);
        $this->assertContains('admin-requests', $offered);
    }
}
