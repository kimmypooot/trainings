<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Profile;
use App\Models\User;
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
                ->has('options.sectors')
            );
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
