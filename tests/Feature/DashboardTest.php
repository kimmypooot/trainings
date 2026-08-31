<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\Certificate;
use App\Models\Profile;
use App\Models\Training;
use App\Models\User;
use App\Support\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function completedUser(): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);

        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_dashboard_renders_for_a_completed_participant(): void
    {
        $this->actingAs($this->completedUser())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('summary')
                ->where('nextTraining', null)
                ->where('recentActivity', [])
            );
    }

    /**
     * The "needs your attention" block is empty until something is owed.
     *
     * It earns its place on the page by being absent most of the time, so an
     * all-clear participant seeing an empty block would be the bug.
     */
    public function test_nothing_is_owed_when_there_is_nothing_outstanding(): void
    {
        $this->actingAs($this->completedUser())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('attention', []));
    }

    public function test_an_unsettled_fee_is_surfaced_as_something_owed(): void
    {
        $user = $this->completedUser();

        RegistrationService::register($user, Training::factory()->paid()->create());

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $attention = $page->toArray()['props']['attention'];

                $this->assertCount(1, $attention);
                $this->assertSame('payments', $attention[0]['key']);
                $this->assertSame('/my/payments', $attention[0]['href']);
            });
    }

    /**
     * The hero's QR button is gated on approval.
     *
     * A pending registration cannot be checked in, and offering the code anyway
     * only tells the participant at the door.
     */
    public function test_the_check_in_code_is_withheld_until_a_registration_is_approved(): void
    {
        $user = $this->completedUser();
        $training = Training::factory()->paid()->create(['starts_at' => now()->addWeek()]);

        $registration = RegistrationService::register($user, $training);

        $this->assertSame(RegistrationStatus::Pending, $registration->status);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('nextTraining.can_check_in', false)
                // The fee is named as owed rather than left as a bare figure.
                ->where('nextTraining.fee_settled', false)
            );

        $registration->forceFill(['status' => RegistrationStatus::Approved])->save();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('nextTraining.can_check_in', true));
    }

    /** A free training has no fee to settle, so the line says nothing at all. */
    public function test_a_free_training_carries_no_fee_state(): void
    {
        $user = $this->completedUser();

        RegistrationService::register($user, Training::factory()->create(['starts_at' => now()->addWeek()]));

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('nextTraining.payment_amount', null)
                ->where('nextTraining.fee_settled', null)
            );
    }

    public function test_activity_lists_each_event_rather_than_one_row_per_registration(): void
    {
        $user = $this->completedUser();
        $training = Training::factory()->create();

        $registration = RegistrationService::register($user, $training);
        $registration->forceFill([
            'status' => RegistrationStatus::Completed,
            'registered_at' => now()->subDays(30),
            'reviewed_at' => now()->subDays(20),
            'attended_at' => now()->subDays(2),
        ])->save();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $kinds = collect($page->toArray()['props']['recentActivity'])->pluck('kind');

                // One registration, three things that happened to it — the old
                // feed showed only the last.
                $this->assertEqualsCanonicalizing(
                    ['completed', 'approved', 'registered'],
                    $kinds->all()
                );
            });
    }

    public function test_activity_is_ordered_newest_first_and_banded_by_recency(): void
    {
        // Pinned to midday: the "two hours ago" event below is only in the same
        // calendar day as "now" if now is not the small hours, so without this
        // the test fails on any run between midnight and 02:00.
        $this->travelTo(today()->addHours(12));

        $user = $this->completedUser();
        $registration = RegistrationService::register($user, Training::factory()->create());
        $registration->forceFill([
            'registered_at' => now()->subDays(30),
            'reviewed_at' => now()->subHours(2),
        ])->save();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('recentActivity.0.kind', 'approved')
                ->where('recentActivity.0.group', 'Today')
                ->where('recentActivity.1.kind', 'registered')
                ->where('recentActivity.1.group', 'Earlier')
                ->etc()
            );
    }

    public function test_a_released_certificate_appears_in_activity(): void
    {
        $user = $this->completedUser();
        $registration = RegistrationService::register($user, Training::factory()->create());
        $registration->forceFill(['registered_at' => now()->subDays(10)])->save();

        Certificate::factory()->create([
            'user_id' => $user->getKey(),
            'registration_id' => $registration->getKey(),
            'generated_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('recentActivity.0.kind', 'certificate')
                ->where('recentActivity.0.title', 'Certificate issued')
                ->etc()
            );
    }

    public function test_new_users_default_to_the_participant_role(): void
    {
        $this->assertSame(Role::Participant, User::factory()->create()->role);
    }

    public function test_shared_props_expose_role_and_unread_count(): void
    {
        $this->actingAs($this->completedUser())
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.role', 'participant')
                ->where('auth.user.role_label', 'Participant')
                ->where('unreadNotifications', 0)
            );
    }

    public function test_profile_page_renders_with_existing_values(): void
    {
        $user = $this->completedUser();

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Profile/Edit')
                ->where('profile.first_name', $user->profile->first_name)
                ->where('profile.sector', $user->profile->sector)
                ->where('profile.region', $user->profile->region)
                ->where('profile.province', $user->profile->province)
                ->where('profile.city_municipality', $user->profile->city_municipality)
                ->where('profile.updated_at', $user->profile->updated_at->toISOString())
                ->where('user.is_verified', true)
                ->has('options.sectors')
            );
    }

    public function test_the_profile_badge_follows_email_verification(): void
    {
        $verified = $this->completedUser();
        $unverified = User::factory()->unverified()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($unverified)->create();

        $this->actingAs($verified)
            ->get('/profile')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('user.is_verified', true));

        // Unverified accounts cannot reach the profile editor at all — the
        // verification gate parks them on the notice screen instead.
        $this->actingAs($unverified->refresh())
            ->get('/profile')
            ->assertRedirect('/email/verify');
    }

    public function test_profile_can_be_updated_without_resetting_completion(): void
    {
        $user = $this->completedUser();
        $completedAt = $user->profile_completed_at;

        $payload = [
            ...$user->profile->only([
                'first_name', 'middle_name', 'last_name', 'suffix', 'sex', 'civil_status',
                'mobile_number', 'position_title', 'salary_grade', 'sector', 'region', 'province',
                'city_municipality', 'field_office_id', 'position_level', 'employment_status',
                'organization_address',
            ]),
            'date_of_birth' => $user->profile->date_of_birth->format('Y-m-d'),
            'is_pwd' => 'No',
            'organization_name' => 'new agency',
            'consent' => true,
        ];

        $this->actingAs($user)
            ->from('/profile')
            ->put('/profile', $payload)
            ->assertRedirect('/profile')
            ->assertSessionHas('success');

        $user->refresh();

        $this->assertSame('NEW AGENCY', $user->profile->organization_name);
        $this->assertEquals($completedAt, $user->profile_completed_at);
    }
}
