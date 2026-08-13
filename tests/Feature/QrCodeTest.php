<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Profile;
use App\Models\User;
use App\Support\ParticipantQrCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class QrCodeTest extends TestCase
{
    use RefreshDatabase;

    private function participant(array $attributes = []): User
    {
        $user = User::factory()->create(['profile_completed_at' => now(), ...$attributes]);
        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    public function test_qr_page_renders_an_image_and_mints_a_token(): void
    {
        $user = $this->participant();

        $this->assertNull($user->qr_token);

        $this->actingAs($user)
            ->get('/my/qr')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('My/QrCode')
                ->where('participant.name', $user->name)
                ->where('qr', fn (string $qr) => str_starts_with($qr, 'data:image/png;base64,'))
            );

        $this->assertNotNull($user->refresh()->qr_token);
        $this->assertSame(32, strlen($user->qr_token));
    }

    public function test_token_is_stable_across_visits(): void
    {
        $user = $this->participant();

        $this->actingAs($user)->get('/my/qr')->assertOk();
        $first = $user->refresh()->qr_token;

        $this->actingAs($user)->get('/my/qr')->assertOk();

        $this->assertSame($first, $user->refresh()->qr_token);
    }

    public function test_regenerating_replaces_the_token(): void
    {
        $user = $this->participant();
        $this->actingAs($user)->get('/my/qr');
        $original = $user->refresh()->qr_token;

        $this->actingAs($user)
            ->from('/my/qr')
            ->post('/my/qr/regenerate')
            ->assertRedirect('/my/qr')
            ->assertSessionHas('success');

        $this->assertNotSame($original, $user->refresh()->qr_token);
    }

    public function test_scan_is_forbidden_to_participants(): void
    {
        $owner = $this->participant();
        $this->actingAs($owner)->get('/my/qr');
        $token = $owner->refresh()->qr_token;

        $snooper = $this->participant(['email' => 'snoop@example.com']);

        $this->actingAs($snooper)->get("/scan/{$token}")->assertForbidden();
    }

    public function test_scan_resolves_a_participant_for_staff(): void
    {
        $owner = $this->participant();
        $this->actingAs($owner)->get('/my/qr');
        $token = $owner->refresh()->qr_token;

        $staff = $this->participant(['email' => 'staff@csc.gov.ph', 'role' => Role::Admin]);

        $this->actingAs($staff)
            ->get("/scan/{$token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Staff/ScanResult')
                ->where('participant.email', $owner->email)
            );
    }

    public function test_unknown_token_is_not_found(): void
    {
        $staff = $this->participant(['email' => 'staff@csc.gov.ph', 'role' => Role::Admin]);

        $this->actingAs($staff)->get('/scan/nope')->assertNotFound();
    }

    public function test_scan_requires_authentication(): void
    {
        $this->get('/scan/anything')->assertRedirect('/login');
    }

    public function test_qr_logo_plate_is_white_never_black(): void
    {
        if (! function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is required to inspect the rendered code');
        }

        $user = $this->participant();

        $png = base64_decode(substr(ParticipantQrCode::dataUri($user), strpos(ParticipantQrCode::dataUri($user), ',') + 1));
        $img = imagecreatefromstring($png);
        $cx = intdiv(imagesx($img), 2);
        $cy = intdiv(imagesy($img), 2);

        // The mark sits on a punched-out plate in the centre. The endroid GD
        // writer composites a transparent logo ground in as black, so the plate
        // must be white-flattened before it ever reaches the writer. Sample the
        // inner plate (the ~128px mark region) and require no black pixels.
        $black = 0;
        for ($dy = -50; $dy <= 50; $dy += 10) {
            for ($dx = -50; $dx <= 50; $dx += 10) {
                $rgb = imagecolorsforindex($img, imagecolorat($img, $cx + $dx, $cy + $dy));
                if ($rgb['red'] < 10 && $rgb['green'] < 10 && $rgb['blue'] < 10) {
                    $black++;
                }
            }
        }

        $this->assertSame(0, $black, 'QR logo plate should be white, found black pixels in the punchout.');
    }
}
