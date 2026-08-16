<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\ProfilePhotoController;
use App\Models\User;
use App\Support\AvatarImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    private function participant(array $overrides = []): User
    {
        return User::factory()->create([
            'profile_completed_at' => now(),
            'email_verified_at' => now(),
            ...$overrides,
        ]);
    }

    public function test_an_account_without_a_photo_falls_back_to_initials(): void
    {
        $this->assertNull($this->participant()->avatarUrl());
    }

    /**
     * A stored photo is always served through the authorising route, never as
     * a third-party URL.
     */
    public function test_a_stored_photo_resolves_to_the_stream_route(): void
    {
        $user = $this->participant(['avatar_path' => 'avatars/mine.jpg']);

        $this->assertStringContainsString('/profile/photo/'.$user->getKey(), $user->avatarUrl());
    }

    public function test_a_participant_can_upload_a_photo(): void
    {
        Storage::fake(ProfilePhotoController::DISK);

        $user = $this->participant();

        $this->actingAs($user)
            ->post('/profile/photo', ['photo' => UploadedFile::fake()->image('me.jpg', 400, 400)])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertNotNull($user->avatar_path);
        Storage::disk(ProfilePhotoController::DISK)->assertExists($user->avatar_path);
    }

    /**
     * The stored bytes are GD's, not the uploader's — see AvatarImageService.
     */
    public function test_an_oversized_photo_is_downscaled_to_a_square(): void
    {
        Storage::fake(ProfilePhotoController::DISK);

        $user = $this->participant();

        $this->actingAs($user)
            ->post('/profile/photo', ['photo' => UploadedFile::fake()->image('huge.jpg', 2400, 1600)])
            ->assertSessionHasNoErrors();

        $stored = Storage::disk(ProfilePhotoController::DISK)->get($user->refresh()->avatar_path);
        [$width, $height, $type] = getimagesizefromstring($stored);

        $this->assertSame(AvatarImageService::SIZE, $width);
        $this->assertSame(AvatarImageService::SIZE, $height);
        $this->assertSame(IMAGETYPE_JPEG, $type);
    }

    public function test_a_photo_smaller_than_the_target_is_squared_but_not_enlarged(): void
    {
        Storage::fake(ProfilePhotoController::DISK);

        $user = $this->participant();

        $this->actingAs($user)
            ->post('/profile/photo', ['photo' => UploadedFile::fake()->image('small.png', 300, 200)]);

        [$width, $height] = getimagesizefromstring(
            Storage::disk(ProfilePhotoController::DISK)->get($user->refresh()->avatar_path)
        );

        // Cropped to the short edge, never upscaled past it.
        $this->assertSame(200, $width);
        $this->assertSame(200, $height);
    }

    public function test_a_png_is_re_encoded_as_jpeg(): void
    {
        Storage::fake(ProfilePhotoController::DISK);

        $user = $this->participant();

        $this->actingAs($user)
            ->post('/profile/photo', ['photo' => UploadedFile::fake()->image('me.png', 400, 400)]);

        $path = $user->refresh()->avatar_path;

        $this->assertStringEndsWith('.jpg', $path);
        $this->assertSame(
            IMAGETYPE_JPEG,
            getimagesizefromstring(Storage::disk(ProfilePhotoController::DISK)->get($path))[2]
        );
    }

    /**
     * Replacing a photo does not leave the old file behind.
     */
    public function test_replacing_a_photo_discards_the_previous_file(): void
    {
        Storage::fake(ProfilePhotoController::DISK);

        $user = $this->participant();

        $this->actingAs($user)
            ->post('/profile/photo', ['photo' => UploadedFile::fake()->image('first.jpg', 400, 400)]);

        $first = $user->refresh()->avatar_path;

        $this->actingAs($user)
            ->post('/profile/photo', ['photo' => UploadedFile::fake()->image('second.jpg', 400, 400)]);

        $second = $user->refresh()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk(ProfilePhotoController::DISK)->assertMissing($first);
        Storage::disk(ProfilePhotoController::DISK)->assertExists($second);
    }

    public function test_removing_the_photo_deletes_the_file_and_falls_back_to_initials(): void
    {
        Storage::fake(ProfilePhotoController::DISK);

        $user = $this->participant();

        $this->actingAs($user)
            ->post('/profile/photo', ['photo' => UploadedFile::fake()->image('me.jpg', 400, 400)]);

        $path = $user->refresh()->avatar_path;

        $this->actingAs($user)->delete('/profile/photo')->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertNull($user->avatar_path);
        $this->assertNull($user->avatarUrl());
        Storage::disk(ProfilePhotoController::DISK)->assertMissing($path);
    }

    public function test_a_dangerous_file_type_is_refused(): void
    {
        Storage::fake(ProfilePhotoController::DISK);

        $user = $this->participant();

        $this->actingAs($user)
            ->post('/profile/photo', ['photo' => UploadedFile::fake()->create('payload.svg', 10, 'image/svg+xml')])
            ->assertSessionHasErrors('photo');

        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_the_photo_is_streamed_to_its_owner_and_to_staff_but_not_to_other_participants(): void
    {
        Storage::fake(ProfilePhotoController::DISK);

        $user = $this->participant();

        $this->actingAs($user)
            ->post('/profile/photo', ['photo' => UploadedFile::fake()->image('me.jpg', 400, 400)]);

        $url = "/profile/photo/{$user->getKey()}";

        $this->actingAs($user)->get($url)->assertOk();
        $this->actingAs($this->participant(['role' => Role::Admin]))->get($url)->assertOk();
        $this->actingAs($this->participant())->get($url)->assertForbidden();
    }

    public function test_guests_cannot_reach_a_photo(): void
    {
        $user = $this->participant(['avatar_path' => 'avatars/whatever.jpg']);

        $this->get("/profile/photo/{$user->getKey()}")->assertRedirect('/login');
    }
}
