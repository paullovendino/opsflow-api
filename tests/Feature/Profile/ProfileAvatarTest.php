<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use App\Services\Profile\ProfileService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileAvatarTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $otherEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ProfileService::AVATAR_DISK);

        $this->seed(RolesSeeder::class);
        $employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();

        $this->employee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'email' => 'eli.avatar@opsflow.test',
            'avatar' => null,
        ]);
        $this->otherEmployee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'email' => 'other.avatar@opsflow.test',
            'avatar' => null,
        ]);
    }

    public function test_guest_cannot_upload_or_remove_avatar(): void
    {
        $this->withHeader('Accept', 'application/json')
            ->post('/api/v1/profile', [
                'avatar' => UploadedFile::fake()->image('avatar.jpg'),
                '_method' => 'PUT',
            ])
            ->assertUnauthorized();

        $this->deleteJson('/api/v1/profile/avatar')->assertUnauthorized();
    }

    public function test_user_can_upload_jpeg_png_and_webp_avatars(): void
    {
        foreach ([
            ['avatar.jpg', 'image/jpeg', 'jpg'],
            ['avatar.png', 'image/png', 'png'],
            ['avatar.webp', 'image/webp', 'webp'],
        ] as [$name, $mime, $ext]) {
            $file = UploadedFile::fake()->image($name);

            $response = $this->actingAs($this->employee)
                ->post('/api/v1/profile', [
                    'avatar' => $file,
                    '_method' => 'PUT',
                ]);

            $response->assertOk()
                ->assertJsonPath('success', true);

            $expectedPath = 'avatars/'.$this->employee->id.'/avatar.'.$ext;
            $this->employee->refresh();
            $this->assertSame($expectedPath, $this->employee->avatar);
            Storage::disk(ProfileService::AVATAR_DISK)->assertExists($expectedPath);

            $avatarUrl = $response->json('data.user.avatar');
            $this->assertIsString($avatarUrl);
            $this->assertStringContainsString('/storage/'.$expectedPath, $avatarUrl);
            $this->assertStringContainsString('v=', $avatarUrl);
        }
    }

    public function test_upload_replaces_previous_avatar_safely(): void
    {
        $first = UploadedFile::fake()->image('first.jpg');
        $this->actingAs($this->employee)
            ->post('/api/v1/profile', [
                'avatar' => $first,
                '_method' => 'PUT',
            ])
            ->assertOk();

        $oldPath = 'avatars/'.$this->employee->id.'/avatar.jpg';
        Storage::disk(ProfileService::AVATAR_DISK)->assertExists($oldPath);

        $second = UploadedFile::fake()->image('second.png');
        $this->actingAs($this->employee)
            ->post('/api/v1/profile', [
                'avatar' => $second,
                '_method' => 'PUT',
            ])
            ->assertOk();

        $newPath = 'avatars/'.$this->employee->id.'/avatar.png';
        $this->employee->refresh();
        $this->assertSame($newPath, $this->employee->avatar);
        Storage::disk(ProfileService::AVATAR_DISK)->assertExists($newPath);
        Storage::disk(ProfileService::AVATAR_DISK)->assertMissing($oldPath);
    }

    public function test_non_image_and_oversized_files_are_rejected(): void
    {
        $this->actingAs($this->employee)
            ->post('/api/v1/profile', [
                'avatar' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
                '_method' => 'PUT',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['avatar']);

        $this->actingAs($this->employee)
            ->post('/api/v1/profile', [
                'avatar' => UploadedFile::fake()->image('huge.jpg')->size(3000),
                '_method' => 'PUT',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['avatar']);

        $this->employee->refresh();
        $this->assertNull($this->employee->avatar);
    }

    public function test_unsupported_image_type_is_rejected(): void
    {
        $this->actingAs($this->employee)
            ->post('/api/v1/profile', [
                'avatar' => UploadedFile::fake()->image('avatar.gif'),
                '_method' => 'PUT',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['avatar']);
    }

    public function test_user_can_remove_avatar_and_removal_is_idempotent(): void
    {
        $this->actingAs($this->employee)
            ->post('/api/v1/profile', [
                'avatar' => UploadedFile::fake()->image('avatar.jpg'),
                '_method' => 'PUT',
            ])
            ->assertOk();

        $path = 'avatars/'.$this->employee->id.'/avatar.jpg';
        Storage::disk(ProfileService::AVATAR_DISK)->assertExists($path);

        $response = $this->actingAs($this->employee)
            ->deleteJson('/api/v1/profile/avatar');

        $response->assertOk()
            ->assertJsonPath('data.user.avatar', null)
            ->assertJsonPath('message', 'Avatar removed successfully.');

        $this->employee->refresh();
        $this->assertNull($this->employee->avatar);
        Storage::disk(ProfileService::AVATAR_DISK)->assertMissing($path);

        $this->actingAs($this->employee)
            ->deleteJson('/api/v1/profile/avatar')
            ->assertOk()
            ->assertJsonPath('data.user.avatar', null);
    }

    public function test_avatar_operations_only_affect_authenticated_user(): void
    {
        $this->actingAs($this->otherEmployee)
            ->post('/api/v1/profile', [
                'avatar' => UploadedFile::fake()->image('other.jpg'),
                '_method' => 'PUT',
            ])
            ->assertOk();

        $otherPath = 'avatars/'.$this->otherEmployee->id.'/avatar.jpg';
        Storage::disk(ProfileService::AVATAR_DISK)->assertExists($otherPath);

        $this->actingAs($this->employee)
            ->post('/api/v1/profile', [
                'avatar' => UploadedFile::fake()->image('mine.png'),
                '_method' => 'PUT',
            ])
            ->assertOk();

        $this->otherEmployee->refresh();
        $this->employee->refresh();
        $this->assertSame($otherPath, $this->otherEmployee->avatar);
        $this->assertSame('avatars/'.$this->employee->id.'/avatar.png', $this->employee->avatar);

        $this->actingAs($this->employee)
            ->deleteJson('/api/v1/profile/avatar')
            ->assertOk();

        $this->otherEmployee->refresh();
        $this->assertSame($otherPath, $this->otherEmployee->avatar);
        Storage::disk(ProfileService::AVATAR_DISK)->assertExists($otherPath);
    }

    public function test_me_returns_public_avatar_url(): void
    {
        $this->actingAs($this->employee)
            ->post('/api/v1/profile', [
                'avatar' => UploadedFile::fake()->image('avatar.jpg'),
                '_method' => 'PUT',
            ])
            ->assertOk();

        $response = $this->actingAs($this->employee)
            ->getJson('/api/v1/auth/me');

        $response->assertOk();
        $avatar = $response->json('data.avatar');
        $this->assertIsString($avatar);
        $this->assertStringContainsString('/storage/avatars/'.$this->employee->id.'/avatar.jpg', $avatar);
    }
}
