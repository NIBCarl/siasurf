<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dedicated profile photo upload for students and instructors.
 */
class ProfileAvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AvatarService::DISK);
    }

    public function test_student_can_upload_a_profile_photo(): void
    {
        $user = User::factory()->student()->create();

        $response = $this->actingAs($user)->post(route('profile.avatar.update'), [
            'avatar' => UploadedFile::fake()->image('me.jpg', 400, 400),
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertNotNull($user->avatar_path);
        $this->assertStringStartsWith(AvatarService::DIRECTORY.'/', $user->avatar_path);
        $this->assertStringStartsWith('user-'.$user->id.'-', basename($user->avatar_path));
        $this->assertSame('/storage/'.$user->avatar_path, $user->avatar_url);

        Storage::disk(AvatarService::DISK)->assertExists($user->avatar_path);
    }

    public function test_instructor_can_upload_a_profile_photo(): void
    {
        $user = User::factory()->instructor()->create();

        $response = $this->actingAs($user)->post(route('profile.avatar.update'), [
            'avatar' => UploadedFile::fake()->image('instructor.png', 320, 320),
        ]);

        $response->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertNotNull($user->avatar_path);
        Storage::disk(AvatarService::DISK)->assertExists($user->avatar_path);
    }

    public function test_replacing_a_photo_deletes_the_previous_file(): void
    {
        $user = User::factory()->student()->create();

        $this->actingAs($user)->post(route('profile.avatar.update'), [
            'avatar' => UploadedFile::fake()->image('first.jpg', 300, 300),
        ]);

        $firstPath = $user->refresh()->avatar_path;
        $this->assertNotNull($firstPath);

        $this->actingAs($user)->post(route('profile.avatar.update'), [
            'avatar' => UploadedFile::fake()->image('second.jpg', 300, 300),
        ]);

        $secondPath = $user->refresh()->avatar_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk(AvatarService::DISK)->assertMissing($firstPath);
        Storage::disk(AvatarService::DISK)->assertExists($secondPath);
    }

    public function test_profile_photo_must_be_a_jpg_or_png_image(): void
    {
        $user = User::factory()->student()->create();

        $response = $this->actingAs($user)
            ->from(route('profile.edit'))
            ->post(route('profile.avatar.update'), [
                'avatar' => UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf'),
            ]);

        $response->assertSessionHasErrors('avatar');
        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_profile_photo_cannot_exceed_two_megabytes(): void
    {
        $user = User::factory()->student()->create();

        $response = $this->actingAs($user)
            ->from(route('profile.edit'))
            ->post(route('profile.avatar.update'), [
                'avatar' => UploadedFile::fake()->image('huge.jpg')->size(AvatarService::MAX_KILOBYTES + 1),
            ]);

        $response->assertSessionHasErrors('avatar');
        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_user_can_remove_their_profile_photo(): void
    {
        $user = User::factory()->instructor()->create();

        $this->actingAs($user)->post(route('profile.avatar.update'), [
            'avatar' => UploadedFile::fake()->image('me.jpg', 300, 300),
        ]);

        $path = $user->refresh()->avatar_path;
        $this->assertNotNull($path);

        $response = $this->actingAs($user)->delete(route('profile.avatar.destroy'));

        $response->assertSessionHasNoErrors();

        $this->assertNull($user->refresh()->avatar_path);
        $this->assertNull($user->avatar_url);
        Storage::disk(AvatarService::DISK)->assertMissing($path);
    }

    public function test_guests_cannot_upload_a_profile_photo(): void
    {
        $response = $this->post(route('profile.avatar.update'), [
            'avatar' => UploadedFile::fake()->image('me.jpg', 200, 200),
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_deleting_an_account_removes_the_stored_photo(): void
    {
        $user = User::factory()->student()->create();

        $this->actingAs($user)->post(route('profile.avatar.update'), [
            'avatar' => UploadedFile::fake()->image('me.jpg', 300, 300),
        ]);

        $path = $user->refresh()->avatar_path;
        $this->assertNotNull($path);

        $this->actingAs($user)->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

        Storage::disk(AvatarService::DISK)->assertMissing($path);
    }

    public function test_avatar_url_leaves_external_urls_untouched(): void
    {
        $user = User::factory()->make([
            'avatar_path' => 'https://cdn.example.test/avatar.jpg',
        ]);

        $this->assertSame('https://cdn.example.test/avatar.jpg', $user->avatar_url);
    }

    public function test_avatar_url_is_null_without_a_photo(): void
    {
        $user = User::factory()->make(['avatar_path' => null]);

        $this->assertNull($user->avatar_url);
    }
}
