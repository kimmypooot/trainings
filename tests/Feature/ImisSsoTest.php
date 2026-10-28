<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\ProfilePhotoController;
use App\Models\FieldOffice;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Staff sign in from the IMIS portal: the account is linked, created and kept
 * in step from IMIS, and cannot use a TIMS password or Google while it is.
 */
class ImisSsoTest extends TestCase
{
    use RefreshDatabase;

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

        // Registered once; the answer is read when the request is made.
        Http::fake(['imis.test/api/*' => fn () => Http::response($this->imisAnswer)]);
    }

    /** What IMIS answers for a person, overridable per test. */
    private function imis(array $user = []): void
    {
        $this->imisAnswer = ['success' => true, 'user' => $user + [
            'id' => 7, 'username' => 'hrstaff', 'fname' => 'Juan', 'lname' => 'Cruz', 'mname' => 'Dela',
            'email' => 'juan@csc.test', 'role' => 'Administrator', 'role_code' => 'admin',
            'office_code' => 'HRD', 'office_type' => 'regional_support_unit', 'photo_url' => null,
        ]];
    }

    private function signIn(string $token = 'abc')
    {
        return $this->post('/sso', ['sso_token' => $token]);
    }

    private function office(string $code = 'lfoi', string $imisCode = 'LFO-I'): FieldOffice
    {
        return FieldOffice::where('code', $code)->first()
            ?? FieldOffice::factory()->create(['code' => $code, 'imis_code' => $imisCode]);
    }

    public function test_the_link_from_imis_shows_the_splash_page(): void
    {
        $this->get('/sso?sso_token=abc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/ImisSso')->where('token', 'abc'));
        $this->assertGuest();
    }

    public function test_a_staff_account_is_created_on_first_sign_in_with_the_imis_role(): void
    {
        $this->imis();

        $this->signIn()->assertRedirect(route('admin.dashboard'));

        $user = User::where('imis_user_id', 7)->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(Role::Admin, $user->role);
        $this->assertSame('Juan D. Cruz', $user->name);
        $this->assertSame('juan@csc.test', $user->email);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->hasPassword());
        Http::assertSent(fn ($r) => $r['token'] === 'abc' && $r['audience'] === 'tims.test');
    }

    public function test_a_field_office_role_gets_the_field_office_mapped_from_the_imis_unit(): void
    {
        $office = $this->office();
        $this->imis(['role_code' => 'field-office', 'office_code' => 'LFO-I', 'office_type' => 'field_office']);

        $this->signIn()->assertRedirect(route('admin.dashboard'));

        $user = User::where('imis_user_id', 7)->firstOrFail();
        $this->assertSame(Role::FieldOffice, $user->role);
        $this->assertSame($office->id, $user->field_office_id);
    }

    public function test_a_field_office_role_without_a_matching_field_office_is_refused(): void
    {
        $this->imis(['role_code' => 'field-office', 'office_code' => 'NOPE', 'office_type' => 'field_office']);

        $this->signIn()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, User::where('imis_user_id', 7)->count());
    }

    public function test_role_and_office_are_rewritten_from_imis_on_every_sign_in(): void
    {
        $office = $this->office();
        $this->imis(['role_code' => 'admin']);
        $this->signIn();
        $this->post('/logout');

        $this->imis(['role_code' => 'field-office', 'office_code' => 'LFO-I', 'office_type' => 'field_office']);
        $this->signIn('def')->assertRedirect(route('admin.dashboard'));

        $user = User::where('imis_user_id', 7)->firstOrFail();
        $this->assertSame(Role::FieldOffice, $user->role);
        $this->assertSame($office->id, $user->field_office_id);
        $this->assertSame(1, User::where('imis_user_id', 7)->count());
    }

    public function test_an_existing_staff_account_is_linked_by_email(): void
    {
        $existing = User::factory()->create(['email' => 'juan@csc.test', 'role' => Role::Management]);
        $this->imis(['role_code' => 'admin']);

        $this->signIn()->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(7, $existing->fresh()->imis_user_id);
        $this->assertSame(Role::Admin, $existing->fresh()->role);
    }

    public function test_a_participant_with_the_same_email_is_never_promoted(): void
    {
        $participant = User::factory()->create(['email' => 'juan@csc.test', 'role' => Role::Participant]);
        $this->imis();

        $this->signIn()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(Role::Participant, $participant->fresh()->role);
        $this->assertNull($participant->fresh()->imis_user_id);
    }

    public function test_no_role_participant_role_or_unknown_role_is_refused(): void
    {
        foreach ([null, 'participant', 'made-up'] as $code) {
            $this->imis(['role_code' => $code]);
            $this->signIn()->assertRedirect(route('login'));
            $this->assertGuest();
        }

        $this->assertSame(0, User::where('imis_user_id', 7)->count());
    }

    public function test_an_invalid_token_or_a_missing_one_is_refused(): void
    {
        $this->imisAnswer = ['success' => false];
        $this->signIn('bad')->assertRedirect(route('login'));
        $this->post('/sso')->assertRedirect(route('login'));
        $this->get('/sso')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, User::where('imis_user_id', 7)->count());
    }

    public function test_a_deactivated_account_is_refused(): void
    {
        User::factory()->create(['imis_user_id' => 7, 'role' => Role::Admin, 'is_active' => false]);
        $this->imis();

        $this->signIn()->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_staff_cannot_use_a_password_but_a_super_admin_and_participants_can(): void
    {
        $staff = User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);
        $this->post('/login', ['email' => $staff->email, 'password' => 'password'])->assertSessionHasErrors('form');
        $this->assertGuest();

        $admin = User::factory()->create(['role' => Role::SuperAdmin, 'profile_completed_at' => now()]);
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('admin.dashboard'));
        $this->post('/logout');

        $participant = User::factory()->create(['role' => Role::Participant, 'profile_completed_at' => now()]);
        $this->post('/login', ['email' => $participant->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($participant);
    }

    public function test_password_sign_in_is_unchanged_while_imis_sign_in_is_off(): void
    {
        config(['services.imis.sso_url' => '']);
        $staff = User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);

        $this->post('/login', ['email' => $staff->email, 'password' => 'password'])->assertRedirect(route('admin.dashboard'));
    }

    public function test_the_imis_photo_is_copied_once_into_tims_storage(): void
    {
        Storage::fake(ProfilePhotoController::DISK);
        $url = 'https://imis.test/storage/profile-photos/a.jpg';
        $image = imagecreatetruecolor(400, 300);
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        Http::fake([
            'imis.test/api/*' => fn () => Http::response($this->imisAnswer),
            $url => Http::response($bytes),
        ]);

        $this->imis(['photo_url' => $url]);
        $this->signIn()->assertRedirect(route('admin.dashboard'));

        // On the first sign-in itself, with no queue worker needed.
        $user = User::where('imis_user_id', 7)->firstOrFail();
        $this->assertNotNull($user->avatar_path);
        Storage::disk(ProfilePhotoController::DISK)->assertExists($user->avatar_path);

        // Already has a photo: not fetched or replaced again.
        $kept = $user->avatar_path;
        $this->post('/logout');
        $this->signIn('def');
        $this->assertSame($kept, $user->fresh()->avatar_path);
    }

    public function test_a_photo_from_any_other_host_is_never_fetched(): void
    {
        $this->imis(['photo_url' => 'https://evil.test/a.jpg']);
        $this->signIn()->assertRedirect(route('admin.dashboard'));

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'evil.test'));
        $this->assertNull(User::where('imis_user_id', 7)->value('avatar_path'));
    }

    public function test_imis_signing_out_ends_the_tims_session_it_started(): void
    {
        $sid = str_repeat('s', 64);
        $this->imis(['sid' => $sid]);
        $this->signIn()->assertRedirect(route('admin.dashboard'));

        $row = DB::table('imis_sessions')->sole();
        $this->assertSame(hash('sha256', $sid), $row->sid_hash);
        Session::getHandler()->write($row->session_id, 'payload');

        $this->postJson('/sso/logout', ['sid' => $sid])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(0, DB::table('imis_sessions')->count());
        $this->assertSame('', Session::getHandler()->read($row->session_id));
    }

    public function test_an_unknown_sid_signs_nobody_out_and_is_not_an_error(): void
    {
        $this->imis(['sid' => str_repeat('s', 64)]);
        $this->signIn();

        $this->postJson('/sso/logout', ['sid' => str_repeat('x', 64)])->assertOk();
        $this->postJson('/sso/logout', ['sid' => 'short'])->assertOk();
        $this->postJson('/sso/logout')->assertOk();

        $this->assertSame(1, DB::table('imis_sessions')->count());
    }

    public function test_the_logout_call_works_during_maintenance(): void
    {
        SiteSetting::current()->forceFill(['maintenance_mode' => true])->save();

        $this->postJson('/sso/logout', ['sid' => str_repeat('x', 64)])->assertOk();
    }

    public function test_no_sid_from_imis_records_nothing(): void
    {
        $this->imis();
        $this->signIn()->assertRedirect(route('admin.dashboard'));

        $this->assertSame(0, DB::table('imis_sessions')->count());
    }
}
