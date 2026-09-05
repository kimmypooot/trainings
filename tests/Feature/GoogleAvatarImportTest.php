<?php

namespace Tests\Feature;

use App\Http\Controllers\ProfilePhotoController;
use App\Jobs\ImportGoogleAvatar;
use App\Models\User;
use App\Support\AvatarImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Copying the photo off a newly connected Google account.
 *
 * The point of importing rather than linking is that no page ever renders a
 * googleusercontent.com URL — see App\Jobs\ImportGoogleAvatar.
 */
class GoogleAvatarImportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://lh3.googleusercontent.com/a/photo';

    private function participant(array $overrides = []): User
    {
        return User::factory()->create([
            'profile_completed_at' => now(),
            'email_verified_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * A real JPEG, so the resizer has something to decode. Built in memory
     * rather than through UploadedFile::fake(), whose temp file is collected
     * as soon as the object goes out of scope.
     */
    private function fakeImageBytes(int $width = 800, int $height = 600): string
    {
        $image = imagecreatetruecolor($width, $height);

        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }

    public function test_the_photo_is_stored_locally_and_resized(): void
    {
        Storage::fake(ProfilePhotoController::DISK);
        Http::fake([self::URL => Http::response($this->fakeImageBytes())]);

        $user = $this->participant();

        (new ImportGoogleAvatar($user->getKey(), self::URL))->handle();

        $user->refresh();

        $this->assertNotNull($user->avatar_path);
        Storage::disk(ProfilePhotoController::DISK)->assertExists($user->avatar_path);

        [$width, $height] = getimagesizefromstring(
            Storage::disk(ProfilePhotoController::DISK)->get($user->avatar_path)
        );

        // Squared and capped, exactly like an upload.
        $this->assertSame($height, $width);
        $this->assertLessThanOrEqual(AvatarImageService::SIZE, $width);

        // And the rendered URL is ours, not Google's.
        $this->assertStringNotContainsString('googleusercontent', (string) $user->avatarUrl());
    }

    public function test_an_existing_photo_is_never_overwritten(): void
    {
        Storage::fake(ProfilePhotoController::DISK);
        Http::fake([self::URL => Http::response($this->fakeImageBytes())]);

        $user = $this->participant(['avatar_path' => 'avatars/mine.jpg']);

        (new ImportGoogleAvatar($user->getKey(), self::URL))->handle();

        $this->assertSame('avatars/mine.jpg', $user->refresh()->avatar_path);
        Http::assertNothingSent();
    }

    public function test_a_non_google_url_is_refused(): void
    {
        Storage::fake(ProfilePhotoController::DISK);
        Http::fake();

        $user = $this->participant();

        (new ImportGoogleAvatar($user->getKey(), 'https://evil.example.com/payload.jpg'))->handle();

        $this->assertNull($user->refresh()->avatar_path);
        Http::assertNothingSent();
    }

    /** An internal address is the request-forgery case the host pin exists for. */
    public function test_an_internal_url_is_refused(): void
    {
        Storage::fake(ProfilePhotoController::DISK);
        Http::fake();

        $user = $this->participant();

        (new ImportGoogleAvatar($user->getKey(), 'http://127.0.0.1/admin'))->handle();

        $this->assertNull($user->refresh()->avatar_path);
        Http::assertNothingSent();
    }

    public function test_a_lookalike_host_is_refused(): void
    {
        Storage::fake(ProfilePhotoController::DISK);
        Http::fake();

        $user = $this->participant();

        (new ImportGoogleAvatar($user->getKey(), 'https://googleusercontent.com.evil.test/x.jpg'))->handle();

        $this->assertNull($user->refresh()->avatar_path);
        Http::assertNothingSent();
    }

    public function test_a_failed_fetch_leaves_the_account_on_initials(): void
    {
        Storage::fake(ProfilePhotoController::DISK);
        Http::fake([self::URL => Http::response('', 404)]);

        $user = $this->participant();

        (new ImportGoogleAvatar($user->getKey(), self::URL))->handle();

        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_a_response_that_is_not_an_image_is_discarded(): void
    {
        Storage::fake(ProfilePhotoController::DISK);
        Http::fake([self::URL => Http::response('<html>not an image</html>')]);

        $user = $this->participant();

        (new ImportGoogleAvatar($user->getKey(), self::URL))->handle();

        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_a_deleted_account_is_not_an_error(): void
    {
        Storage::fake(ProfilePhotoController::DISK);
        Http::fake([self::URL => Http::response($this->fakeImageBytes())]);

        (new ImportGoogleAvatar(999999, self::URL))->handle();

        Http::assertNothingSent();
    }

    /*
     * ── The manual backstop ───────────────────────────────────────────────
     *
     * The import is dispatched once and never retried, so a job that is queued
     * and then never run leaves the account with initials and no way back —
     * disconnecting Google, which would re-import, is refused for an account
     * Google is the only way into. tims:import-google-avatar is that way back.
     */

    public function test_the_command_re_queues_the_import(): void
    {
        Queue::fake();

        $user = $this->participant([
            'google_id' => 'google-123',
            'google_avatar_url' => self::URL,
        ]);

        $this->artisan('tims:import-google-avatar', ['user' => $user->email])
            ->assertSuccessful();

        Queue::assertPushed(
            ImportGoogleAvatar::class,
            fn (ImportGoogleAvatar $job) => $job->userId === $user->getKey() && $job->url === self::URL,
        );
    }

    public function test_the_command_takes_a_numeric_id_too(): void
    {
        Queue::fake();

        $user = $this->participant([
            'google_id' => 'google-123',
            'google_avatar_url' => self::URL,
        ]);

        $this->artisan('tims:import-google-avatar', ['user' => (string) $user->getKey()])
            ->assertSuccessful();

        Queue::assertPushed(ImportGoogleAvatar::class);
    }

    public function test_the_command_refuses_an_account_with_no_google_photo(): void
    {
        Queue::fake();

        $user = $this->participant();

        $this->artisan('tims:import-google-avatar', ['user' => $user->email])
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_the_command_leaves_a_photo_the_participant_already_has(): void
    {
        Queue::fake();

        $user = $this->participant([
            'google_id' => 'google-123',
            'google_avatar_url' => self::URL,
            'avatar_path' => 'avatars/mine.jpg',
        ]);

        $this->artisan('tims:import-google-avatar', ['user' => $user->email])
            ->assertFailed();

        Queue::assertNothingPushed();
        $this->assertSame('avatars/mine.jpg', $user->refresh()->avatar_path);
    }

    public function test_force_clears_the_slot_so_the_import_can_fill_it(): void
    {
        Queue::fake();

        $user = $this->participant([
            'google_id' => 'google-123',
            'google_avatar_url' => self::URL,
            'avatar_path' => 'avatars/mine.jpg',
        ]);

        $this->artisan('tims:import-google-avatar', ['user' => $user->email, '--force' => true])
            ->assertSuccessful();

        // The job refuses to overwrite, so --force has to clear the slot for
        // it rather than just asking louder.
        $this->assertNull($user->refresh()->avatar_path);
        Queue::assertPushed(ImportGoogleAvatar::class);
    }

    public function test_an_unknown_account_is_refused(): void
    {
        Queue::fake();

        $this->artisan('tims:import-google-avatar', ['user' => 'nobody@example.com'])
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    /*
     * The participant's own button.
     *
     * The command above exists because a participant could not recover a lost
     * import on their own. These cover the half that means they no longer have
     * to ask the office.
     */

    public function test_a_participant_can_sync_the_photo_from_google(): void
    {
        Storage::fake(ProfilePhotoController::DISK);
        Http::fake([self::URL => Http::response($this->fakeImageBytes())]);

        $user = $this->participant([
            'google_id' => 'g-1',
            'google_avatar_url' => self::URL,
        ]);

        $this->actingAs($user)->post('/profile/photo/google')
            ->assertRedirect()
            ->assertSessionHas('success');

        $path = $user->refresh()->avatar_path;

        $this->assertNotNull($path);
        Storage::disk(ProfilePhotoController::DISK)->assertExists($path);
    }

    /**
     * Unlike the queued import, this one is the participant asking — so it
     * replaces rather than refusing, and the old file does not linger.
     */
    public function test_the_sync_replaces_an_existing_photo_and_drops_the_old_file(): void
    {
        Storage::fake(ProfilePhotoController::DISK);
        Http::fake([self::URL => Http::response($this->fakeImageBytes())]);

        $old = AvatarImageService::storeBytes(
            $this->fakeImageBytes(),
            'avatars',
            ProfilePhotoController::DISK
        );

        $user = $this->participant([
            'google_id' => 'g-1',
            'google_avatar_url' => self::URL,
            'avatar_path' => $old,
        ]);

        $this->actingAs($user)->post('/profile/photo/google')->assertSessionHas('success');

        $this->assertNotSame($old, $user->refresh()->avatar_path);
        Storage::disk(ProfilePhotoController::DISK)->assertMissing($old);
    }

    /**
     * The whole reason this is synchronous and swaps last: a dead Google host
     * must leave the participant exactly as they were, never on initials.
     */
    public function test_a_failed_sync_leaves_the_current_photo_untouched(): void
    {
        Storage::fake(ProfilePhotoController::DISK);
        Http::fake([self::URL => Http::response('', 500)]);

        $existing = AvatarImageService::storeBytes(
            $this->fakeImageBytes(),
            'avatars',
            ProfilePhotoController::DISK
        );

        $user = $this->participant([
            'google_id' => 'g-1',
            'google_avatar_url' => self::URL,
            'avatar_path' => $existing,
        ]);

        $this->actingAs($user)->post('/profile/photo/google')
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($existing, $user->refresh()->avatar_path);
        Storage::disk(ProfilePhotoController::DISK)->assertExists($existing);
    }

    public function test_the_sync_refuses_an_account_with_no_google_connection(): void
    {
        Http::fake();

        $user = $this->participant();

        $this->actingAs($user)->post('/profile/photo/google')->assertSessionHas('error');

        $this->assertNull($user->refresh()->avatar_path);
        Http::assertNothingSent();
    }

    public function test_the_sync_refuses_a_google_account_carrying_no_photo(): void
    {
        Http::fake();

        $user = $this->participant(['google_id' => 'g-1', 'google_avatar_url' => null]);

        $this->actingAs($user)->post('/profile/photo/google')->assertSessionHas('error');

        Http::assertNothingSent();
    }

    /**
     * The stored URL is not user input, but it is the one value this endpoint
     * hands to the server's own HTTP client — so the allow-list has to hold
     * here as well as in the job, which is the reason both read it from
     * GoogleAvatarFetcher rather than each carrying a copy.
     */
    public function test_the_sync_refuses_a_url_that_is_not_googles(): void
    {
        Storage::fake(ProfilePhotoController::DISK);
        Http::fake();

        $user = $this->participant([
            'google_id' => 'g-1',
            'google_avatar_url' => 'https://169.254.169.254/latest/meta-data/',
        ]);

        $this->actingAs($user)->post('/profile/photo/google')->assertSessionHas('error');

        $this->assertNull($user->refresh()->avatar_path);
        Http::assertNothingSent();
    }

    public function test_the_sync_needs_a_signed_in_account(): void
    {
        Http::fake();

        $this->post('/profile/photo/google')->assertRedirect('/login');

        Http::assertNothingSent();
    }

    /**
     * The card offers the button from this prop, so it has to mean "there is
     * something to fetch" rather than merely "Google is connected".
     */
    public function test_the_profile_offers_the_button_only_when_there_is_a_photo_to_fetch(): void
    {
        $connectedWithPhoto = $this->participant([
            'google_id' => 'g-1',
            'google_avatar_url' => self::URL,
        ]);

        $this->actingAs($connectedWithPhoto)->get('/profile')
            ->assertInertia(fn ($page) => $page->where('user.google_photo_available', true));

        $this->flushSession();

        $connectedWithout = $this->participant([
            'google_id' => 'g-2',
            'google_avatar_url' => null,
        ]);

        $this->actingAs($connectedWithout)->get('/profile')
            ->assertInertia(fn ($page) => $page->where('user.google_photo_available', false));
    }
}
