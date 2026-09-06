<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\Admin\RequestQueueController;
use App\Models\Profile;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\UndoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The participant guide, and the facts it is only useful if it gets right.
 *
 * A help page is the one screen whose errors are invisible in normal use:
 * nothing breaks, no test fails, and the only symptom is a participant doing
 * what it told them and being refused. So the assertions here are about the
 * numbers rather than the prose — that the limits the guide prints are the
 * limits the validators enforce, and that the page stays reachable to exactly
 * the people who need it.
 */
class HelpGuideTest extends TestCase
{
    use RefreshDatabase;

    private function participant(bool $completeProfile = true): User
    {
        $user = User::factory()->create([
            'profile_completed_at' => $completeProfile ? now() : null,
        ]);

        if ($completeProfile) {
            Profile::factory()->for($user)->create();
        }

        return $user->refresh();
    }

    /**
     * The file rules each guide row describes, as (controller, the rule line).
     *
     * Matched against the controller source rather than by calling the
     * endpoints: the point is that the *number in the guide* and the *number in
     * the rule* agree, and a request-level test would prove only that some
     * limit exists.
     *
     * Each case names the guide row it is about, not just a size. The first
     * cut matched on the size alone — "some row says 5 MB" — and two rows do,
     * so changing one of them to 50 MB left the assertion passing. A guard that
     * cannot see the mistake it exists to catch is worse than none, because it
     * is also a claim that the mistake was checked for.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function uploadRules(): array
    {
        return [
            'payment proof' => ['Proof of payment', 'Http/Controllers/PaymentController.php', '5 MB', 'max:5120'],
            'supporting document' => ['Supporting document for a supervisory course', 'Http/Controllers/RegistrationController.php', '5 MB', 'max:5120'],
            'training output' => ['Training output', 'Http/Controllers/RegistrationOutputController.php', '10 MB', 'max:10240'],
            'agency letter' => ['Agency request letter', 'Http/Controllers/AgencyRequestController.php', '10 MB', 'max:10240'],
            'profile photo' => ['Profile photo', 'Http/Controllers/ProfilePhotoController.php', '2 MB', 'max:2048'],
        ];
    }

    #[DataProvider('uploadRules')]
    public function test_each_limit_the_guide_prints_is_the_limit_that_is_enforced(
        string $document,
        string $controller,
        string $printed,
        string $rule,
    ): void {
        $source = file_get_contents(app_path($controller));

        $this->assertStringContainsString(
            $rule,
            $source,
            "The guide tells participants {$document} may be {$printed}, but {$controller} no longer contains {$rule}.",
        );

        // And that this row of the guide is the one printing it — matched on
        // the document, so a wrong figure on one row cannot hide behind a right
        // figure on another.
        $this->actingAs($this->participant())
            ->get('/help')
            ->assertOk()
            ->assertInertia(function ($page) use ($document, $printed) {
                $row = collect($page->toArray()['props']['uploadLimits'])
                    ->firstWhere('what', $document);

                $this->assertNotNull($row, "The guide no longer has a row for {$document}.");
                $this->assertSame(
                    $printed,
                    $row['size'],
                    "The guide tells participants {$document} may be {$row['size']}, but the rule allows {$printed}.",
                );

                return $page;
            });
    }

    public function test_the_stated_password_length_is_the_one_the_policy_applies(): void
    {
        $user = $this->participant();

        $this->actingAs($user)
            ->get('/help')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('passwordMinimum', AppServiceProvider::PASSWORD_MINIMUM));

        // The floor is quoted, not invented: a shorter password is refused.
        $this->flushSession();

        $short = str_repeat('a1', (int) floor((AppServiceProvider::PASSWORD_MINIMUM - 1) / 2));

        $this->actingAs($user)
            ->from('/profile')
            ->post('/change-password', [
                'current_password' => 'password',
                'password' => $short,
                'password_confirmation' => $short,
            ])
            ->assertSessionHasErrors('password');
    }

    /**
     * The gate is the whole reason this route sits where it does.
     *
     * EnsureProfileIsComplete redirects the participant area to the profile
     * form. If the guide were inside that gate, the person who cannot get past
     * the form — which is the single most likely reason to open a guide at all
     * — would be redirected away from the page explaining it.
     */
    public function test_a_participant_with_an_incomplete_profile_can_still_read_the_guide(): void
    {
        $user = $this->participant(completeProfile: false);

        // The gate is real: the rest of the area does bounce this account.
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/profile/complete');
        $this->flushSession();

        $this->actingAs($user)->get('/help')->assertOk();
    }

    public function test_the_guide_needs_an_account(): void
    {
        $this->get('/help')->assertRedirect('/login');
    }

    /**
     * Written for participants, refused to nobody. A staff member following a
     * link into it should read it, not meet a 403 — the sidebar decides who is
     * *offered* the guide, the route decides who is *allowed* it, and those are
     * different questions.
     */
    public function test_staff_are_not_locked_out_of_it(): void
    {
        $staff = User::factory()->create([
            'role' => Role::Admin,
            'profile_completed_at' => now(),
        ]);

        $this->actingAs($staff)->get('/help')->assertOk();
    }

    /**
     * The staff guide is for staff, and for all of them.
     *
     * Not narrowed by role: the page shows each reader the sections that apply
     * to them, and narrowing the route as well would be the same role list kept
     * in two places, disagreeing the first time one moved.
     */
    public function test_the_staff_guide_is_open_to_every_staff_role_and_closed_to_participants(): void
    {
        foreach ([Role::FieldOffice, Role::CollectingOfficer, Role::Admin, Role::Management, Role::SuperAdmin] as $role) {
            $staff = User::factory()->create(['role' => $role, 'profile_completed_at' => now()]);

            $this->actingAs($staff)->get('/admin/help')->assertOk();
            $this->flushSession();
        }

        $this->actingAs($this->participant())->get('/admin/help')->assertForbidden();
    }

    /**
     * The figures it quotes are the ones the code applies.
     *
     * Each of these appears in the guide's prose as a number a reader will act
     * on — how long they have to undo, how many rows a queue shows, how many
     * exports a minute they get. A guide quoting a figure the code no longer
     * uses is confidently wrong on the page somebody opened because they were
     * unsure, so the page is handed them rather than told them.
     */
    public function test_the_staff_guide_quotes_the_real_limits(): void
    {
        $staff = User::factory()->create(['role' => Role::SuperAdmin, 'profile_completed_at' => now()]);

        $this->actingAs($staff)
            ->get('/admin/help')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Help/Admin')
                    ->where('undoWindow', UndoService::WINDOW_SECONDS)
                    ->where('queueCap', RequestQueueController::LIMIT)
                    ->where('exportLimit', AppServiceProvider::EXPORTS_PER_MINUTE)
            );
    }

    /**
     * No invented contact details: the office block is the shared prop, so a
     * deployment for another regional office names that office.
     */
    public function test_it_carries_the_configured_office_rather_than_a_hardcoded_one(): void
    {
        config(['office.name' => 'CSC Regional Office II', 'office.email' => 'ro02.hrd@csc.gov.ph']);

        $this->actingAs($this->participant())
            ->get('/help')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->where('office.name', 'CSC Regional Office II')
                    ->where('office.email', 'ro02.hrd@csc.gov.ph')
            );
    }
}
