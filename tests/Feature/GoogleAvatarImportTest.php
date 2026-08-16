<?php

namespace Tests\Feature;

use App\Http\Controllers\ProfilePhotoController;
use App\Jobs\ImportGoogleAvatar;
use App\Models\User;
use App\Support\AvatarImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
}
