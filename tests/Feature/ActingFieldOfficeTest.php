<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\FieldOffice;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * A field-office user who heads a second office by designation in IMIS (a
 * concurrent Director) works as one office at a time: the sign-in remembers
 * the offices, the header switches between them, and every scoped view
 * follows the active one.
 */
class ActingFieldOfficeTest extends TestCase
{
    use RefreshDatabase;

    private FieldOffice $home;

    private FieldOffice $satellite;

    private FieldOffice $other;

    /** @var array<string, mixed> */
    private array $imisAnswer = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.imis.sso_url' => 'https://imis.test/api/v1/sso/validate',
            'services.imis.audience' => 'tims.test',
            'services.imis.asset_origin' => 'https://imis.test',
        ]);
        Http::fake(['imis.test/api/*' => fn () => Http::response($this->imisAnswer)]);

        // The offices the migration seeds; IMIS names them by these codes.
        $this->home = tap(FieldOffice::where('code', 'bfo')->firstOrFail())->update(['imis_code' => 'BFO']);
        $this->satellite = tap(FieldOffice::where('code', 'wlso')->firstOrFail())->update(['imis_code' => 'WLSO']);
        $this->other = tap(FieldOffice::where('code', 'nsfo')->firstOrFail())->update(['imis_code' => 'NSFO']);
    }

    /** @param  list<array<string, string>>  $headed */
    private function imis(array $headed): void
    {
        $this->imisAnswer = ['success' => true, 'user' => [
            'id' => 7, 'username' => 'director', 'fname' => 'Michael', 'lname' => 'Cruz', 'mname' => 'Dela',
            'email' => 'director@csc.test', 'role' => 'Field Office', 'role_code' => 'field-office',
            'office_code' => 'BFO', 'office_type' => 'field_office', 'photo_url' => null,
            'headed_offices' => $headed,
        ]];
    }

    private function participantIn(FieldOffice $office, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'profile_completed_at' => now()]);
        Profile::factory()->for($user)->create(['field_office_id' => $office->id]);

        return $user->refresh();
    }

    private function director(): User
    {
        return User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => $this->home->id,
            'profile_completed_at' => now(),
        ]);
    }

    /** @return array<string, array{allowed: list<int>, active: int}> */
    private function working(int $active): array
    {
        return ['acting_field_office' => ['allowed' => [$this->home->id, $this->satellite->id], 'active' => $active]];
    }

    public function test_signing_in_remembers_the_offices_imis_says_the_person_heads(): void
    {
        $this->imis([['code' => 'WLSO', 'name' => 'CSC Satellite Office - Western Leyte', 'type' => 'satellite_office']]);

        $this->post('/sso', ['sso_token' => 'abc'])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('acting_field_office', ['allowed' => [$this->home->id, $this->satellite->id], 'active' => null]);
    }

    public function test_nothing_opens_until_an_office_is_chosen(): void
    {
        $director = $this->director();
        $this->participantIn($this->home, 'biliran@example.com');
        $unchosen = ['acting_field_office' => ['allowed' => [$this->home->id, $this->satellite->id], 'active' => null]];

        foreach (['/admin', '/admin/participants', '/admin/trainings', '/admin/analytics'] as $url) {
            $this->actingAs($director)->withSession($unchosen)->get($url)->assertRedirect(route('admin.choose-office'));
        }

        $this->actingAs($director)->withSession($unchosen)->get('/admin/choose-office')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/ChooseOffice')
                ->where('active', null)
                ->where('homeId', $this->home->id)
                ->has('offices', 2));
    }

    public function test_choosing_an_office_returns_the_person_to_where_they_were_headed(): void
    {
        $director = $this->director();
        $unchosen = ['acting_field_office' => ['allowed' => [$this->home->id, $this->satellite->id], 'active' => null]];

        $this->actingAs($director)->withSession($unchosen)->get('/admin/participants')->assertRedirect(route('admin.choose-office'));

        $this->post('/admin/acting-office', ['field_office_id' => $this->satellite->id])
            ->assertRedirect(url('/admin/participants'))
            ->assertSessionHas('acting_field_office.active', $this->satellite->id);

        $this->get('/admin/participants')->assertOk();
    }

    public function test_someone_with_one_office_is_never_sent_to_the_chooser(): void
    {
        $this->actingAs($this->director())->get('/admin')->assertOk();
        $this->actingAs($this->director())->get('/admin/choose-office')->assertRedirect(route('admin.dashboard'));
    }

    public function test_someone_with_one_office_is_not_given_a_switcher(): void
    {
        $this->imis([]);

        $this->post('/sso', ['sso_token' => 'abc'])->assertSessionMissing('acting_field_office');

        $this->actingAs($this->director())
            ->get('/admin')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.acting_office', null));
    }

    public function test_an_office_with_no_tims_counterpart_is_ignored(): void
    {
        $this->satellite->forceFill(['imis_code' => null])->save();
        $this->imis([['code' => 'WLSO', 'name' => 'CSC Satellite Office - Western Leyte', 'type' => 'satellite_office']]);

        $this->post('/sso', ['sso_token' => 'abc'])->assertSessionMissing('acting_field_office');
    }

    public function test_the_header_lists_the_offices_and_the_active_one(): void
    {
        $this->actingAs($this->director())
            ->withSession($this->working($this->satellite->id))
            ->get('/admin')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.acting_office.active', $this->satellite->id)
                ->has('auth.user.acting_office.offices', 2)
                ->where('auth.user.acting_office.offices.0.id', $this->home->id)
            );
    }

    public function test_every_scoped_view_follows_the_office_being_worked_as(): void
    {
        $biliran = $this->participantIn($this->home, 'biliran@example.com');
        $leyte = $this->participantIn($this->satellite, 'leyte@example.com');
        $this->participantIn($this->other, 'samar@example.com');
        $director = $this->director();

        $this->actingAs($director)->withSession($this->working($this->home->id))
            ->get('/admin/participants')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('participants.data', 1)
                ->where('participants.data.0.email', $biliran->email));

        $this->actingAs($director)->withSession($this->working($this->satellite->id))
            ->get('/admin/participants')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('participants.data', 1)
                ->where('participants.data.0.email', $leyte->email)
                ->where('scopedTo', 'CSC Satellite Office - Western Leyte'));

        // Working as one office, the other office's people are not found.
        $this->actingAs($director)->withSession($this->working($this->satellite->id))
            ->get("/admin/participants/{$biliran->id}")->assertNotFound();
    }

    public function test_switching_changes_the_office_and_is_logged(): void
    {
        $director = $this->director();

        $this->actingAs($director)->withSession($this->working($this->home->id))
            ->post('/admin/acting-office', ['field_office_id' => $this->satellite->id])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('acting_field_office.active', $this->satellite->id);

        $this->assertDatabaseHas('activity_logs', ['action' => 'field_office.switched', 'causer_id' => $director->id]);
    }

    public function test_an_office_the_person_does_not_head_is_refused(): void
    {
        $this->actingAs($this->director())->withSession($this->working($this->home->id))
            ->post('/admin/acting-office', ['field_office_id' => $this->other->id])
            ->assertForbidden();
    }

    public function test_someone_with_a_single_office_cannot_switch(): void
    {
        $this->actingAs($this->director())
            ->post('/admin/acting-office', ['field_office_id' => $this->satellite->id])
            ->assertForbidden();
    }
}
