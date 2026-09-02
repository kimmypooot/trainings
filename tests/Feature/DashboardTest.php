<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\Certificate;
use App\Models\Profile;
use App\Models\Training;
use App\Models\User;
use App\Support\AgencyRequestService;
use App\Support\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    /**
     * An outstanding item says which training it is about.
     *
     * The count on its own ("a training fee needs settling") restated the
     * errand without advancing it — the participant still had to open the
     * payments screen to find out which fee. The row now carries the training,
     * the amount and when it starts.
     */
    public function test_an_unsettled_fee_is_surfaced_with_the_training_it_belongs_to(): void
    {
        $user = $this->completedUser();
        $startsAt = now()->addWeeks(2);
        $endsAt = now()->addWeeks(2)->addDays(2);

        $training = Training::factory()->paid()->create([
            'title' => 'Public Service Ethics',
            'payment_amount' => 1500,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        RegistrationService::register($user, $training);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($startsAt, $endsAt) {
                $attention = $page->toArray()['props']['attention'];

                $this->assertCount(1, $attention);
                $this->assertSame('payments', $attention[0]['queue']);
                $this->assertSame(route('payments.index'), $attention[0]['href']);
                $this->assertSame('Public Service Ethics', $attention[0]['subject']);
                $this->assertSame('1500.00', (string) $attention[0]['amount']);

                // The dates are what tell one run of a repeated programme from
                // another, so they travel on every row — pre-formatted, because
                // the page pairs them through dateRange.ts.
                $this->assertSame($startsAt->format('d M Y'), $attention[0]['starts_at']);
                $this->assertSame($endsAt->format('d M Y'), $attention[0]['ends_at']);

                // Nothing to add: the training has not begun, and the dates
                // above already say when it does.
                $this->assertNull($attention[0]['detail']);
            });
    }

    /**
     * A fee owed on a training already under way says so.
     *
     * The dates alone do not: the reader would have to know today's date and
     * make the comparison themselves, which is the work the row exists to save.
     */
    public function test_a_fee_owed_on_a_started_training_is_marked_as_under_way(): void
    {
        $user = $this->completedUser();
        $training = Training::factory()->paid()->create([
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addDay(),
        ]);

        // Registered while it was still open, then the run began — which is
        // the only way to arrive at this state, since registration closes once
        // a training has started.
        RegistrationService::register($user, $training);
        $training->forceFill([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ])->save();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $attention = $page->toArray()['props']['attention'];

                $this->assertSame('Already under way', $attention[0]['detail']);
            });
    }

    /**
     * The block and the sidebar badge count the same things.
     *
     * They were two statements of one rule and are now one, so this is the
     * guard on that staying true: a badge showing work the block does not list
     * is work the participant cannot find, and a number they learn to ignore.
     */
    public function test_the_attention_block_and_the_sidebar_badge_agree(): void
    {
        $user = $this->completedUser();

        RegistrationService::register($user, Training::factory()->paid()->create());
        RegistrationService::register($user, Training::factory()->paid()->create());

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $props = $page->toArray()['props'];

                $this->assertSame(2, $props['pendingActions']['payments']);
                $this->assertCount(2, $props['attention']);
            });
    }

    /**
     * An agency request says which stage it is stuck at, and on what.
     *
     * Worth its own test beyond the copy it asserts: the row is chosen by
     * comparing the model's cast `status` against the enum in PHP, and static
     * analysis reads that attribute as a plain string and calls the comparison
     * dead. It is not — the cast makes it an enum at runtime — and this is what
     * says so, so the baselined warning stays a warning about the stubs rather
     * than cover for a branch that never runs.
     */
    public function test_an_agency_request_names_the_training_and_the_stage(): void
    {
        Storage::fake('local');

        $agency = $this->completedUser();
        $staff = User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);
        $pdf = fn (string $name) => UploadedFile::fake()->create($name, 64, 'application/pdf');

        $request = AgencyRequestService::submit($agency, [
            'agency_name' => 'Municipality of Palo',
            'training_title' => 'Records Management',
            'proposed_start' => now()->addMonth()->toDateString(),
            'proposed_end' => now()->addMonth()->addDays(2)->toDateString(),
            'proposed_venue' => 'Municipal Hall, Palo',
            'expected_participants' => 30,
        ], $pdf('letter.pdf'));

        AgencyRequestService::assign($request, $staff);
        AgencyRequestService::sendRequirements(
            $request->fresh(),
            $staff,
            'Please return the signed confirmation form.',
            $pdf('response.pdf'),
            $pdf('form.pdf'),
        );

        $this->actingAs($agency)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($request) {
                $attention = $page->toArray()['props']['attention'];

                $this->assertCount(1, $attention);
                $this->assertSame('agency-requests', $attention[0]['queue']);
                $this->assertSame(
                    'Requirements sent — your confirmation is needed',
                    $attention[0]['label'],
                );
                $this->assertSame('Records Management', $attention[0]['subject']);
                $this->assertSame($request->request_code, $attention[0]['detail']);

                // No schedule has been agreed at this stage, so the row shows
                // the dates the agency proposed rather than nothing.
                $this->assertSame(now()->addMonth()->format('d M Y'), $attention[0]['starts_at']);
                $this->assertSame(now()->addMonth()->addDays(2)->format('d M Y'), $attention[0]['ends_at']);
            });
    }

    /**
     * A long queue summarises rather than filling the page.
     *
     * Detail is the point of the block, but four near-identical rows push the
     * rest of the dashboard off the screen to say one thing.
     */
    public function test_a_long_queue_is_capped_with_an_overflow_row(): void
    {
        $user = $this->completedUser();

        foreach (range(1, 5) as $ignored) {
            RegistrationService::register($user, Training::factory()->paid()->create());
        }

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $attention = $page->toArray()['props']['attention'];

                // Three detailed rows, then one standing for the other two.
                $this->assertCount(4, $attention);
                $this->assertSame('payments-more', $attention[3]['key']);
                $this->assertSame('2 more payment matters', $attention[3]['label']);
                $this->assertSame(route('payments.index'), $attention[3]['href']);
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

    /**
     * A future-dated event does not claim to have happened this week.
     *
     * Carbon 3 diffs are signed, so a timestamp ahead of now is negative and
     * slips past any `< 7` window however far out it is. The symptom was a row
     * reading "2 weeks from now" at the top of a feed of things that had
     * happened. Seeded demo data carries such rows, and a clock skew or a
     * backdated import can produce them in earnest.
     */
    public function test_a_future_dated_event_is_not_banded_as_this_week(): void
    {
        $user = $this->completedUser();
        $registration = RegistrationService::register($user, Training::factory()->create());
        $registration->forceFill(['registered_at' => now()->addWeeks(2)])->save();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('recentActivity.0.kind', 'registered')
                ->where('recentActivity.0.group', 'Earlier')
                ->where('recentActivity.0.at_label', now()->addWeeks(2)->format('d M Y'))
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
