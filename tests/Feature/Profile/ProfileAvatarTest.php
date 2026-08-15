<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Enums\RoleName;
use App\Models\File;
use App\Models\Role;
use App\Models\User;
use App\Services\Profile\ProfileService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        Storage::fake(File::DISK_LOCAL);

        $this->seed(RolesSeeder::class);
        $employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();

        $this->employee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'email' => 'eli.avatar@opsflow.test',
        ]);
        $this->otherEmployee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'email' => 'other.avatar@opsflow.test',
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
            ['John Paul Profile.jpg', 'jpg'],
            ['portrait.png', 'png'],
            ['face.webp', 'webp'],
        ] as [$name, $ext]) {
            $file = UploadedFile::fake()->image($name);

            $response = $this->actingAs($this->employee)
                ->post('/api/v1/profile', [
                    'avatar' => $file,
                    '_method' => 'PUT',
                ]);

            $response->assertOk()
                ->assertJsonPath('success', true);

            $this->employee->refresh()->load('avatarFile');
            $avatar = $this->employee->avatarFile;
            $this->assertInstanceOf(File::class, $avatar);
            $this->assertSame($name, $avatar->file_name);
            $this->assertSame(File::COLLECTION_AVATAR, $avatar->collection);
            $this->assertSame(ProfileService::AVATAR_DISK, $avatar->disk);
            $this->assertSame($ext, $avatar->extension);
            $this->assertSame('user', $avatar->attachable_type);
            $this->assertSame($this->employee->id, $avatar->attachable_id);
            $this->assertStringStartsWith('avatars/'.$this->employee->id.'/', $avatar->file_path);
            $this->assertStringEndsWith('.'.$ext, $avatar->file_path);
            $this->assertDoesNotMatchRegularExpression('#/avatar\.'.$ext.'$#', $avatar->file_path);
            Storage::disk(ProfileService::AVATAR_DISK)->assertExists($avatar->file_path);
            $this->assertSame([], Storage::disk(File::DISK_LOCAL)->allFiles('tmp/uploads'));

            $avatarUrl = $response->json('data.user.avatar');
            $this->assertIsString($avatarUrl);
            $this->assertStringContainsString('/storage/'.$avatar->file_path, $avatarUrl);
            $this->assertStringContainsString('v=', $avatarUrl);
        }
    }

    public function test_avatar_file_polymorphic_relationship(): void
    {
        $file = File::query()->create([
            'file_name' => 'portrait.jpg',
            'file_path' => 'avatars/'.$this->employee->id.'/abc123.jpg',
            'disk' => ProfileService::AVATAR_DISK,
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size' => 128,
            'collection' => File::COLLECTION_AVATAR,
            'attachable_type' => 'user',
            'attachable_id' => $this->employee->id,
        ]);

        Storage::disk(ProfileService::AVATAR_DISK)->put($file->file_path, 'bytes');

        $this->employee->load('avatarFile');
        $this->assertTrue($this->employee->avatarFile?->is($file));
        $this->assertTrue($file->attachable->is($this->employee));
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

        $this->employee->refresh()->load('avatarFile');
        $old = $this->employee->avatarFile;
        $this->assertInstanceOf(File::class, $old);
        $oldPath = $old->file_path;
        $oldId = $old->id;
        Storage::disk(ProfileService::AVATAR_DISK)->assertExists($oldPath);

        $second = UploadedFile::fake()->image('second.png');
        $this->actingAs($this->employee)
            ->post('/api/v1/profile', [
                'avatar' => $second,
                '_method' => 'PUT',
            ])
            ->assertOk();

        $this->employee->refresh()->load('avatarFile');
        $new = $this->employee->avatarFile;
        $this->assertInstanceOf(File::class, $new);
        $this->assertNotSame($oldId, $new->id);
        $this->assertSame('second.png', $new->file_name);
        $this->assertStringEndsWith('.png', $new->file_path);
        Storage::disk(ProfileService::AVATAR_DISK)->assertExists($new->file_path);
        Storage::disk(ProfileService::AVATAR_DISK)->assertMissing($oldPath);
        $this->assertDatabaseMissing('files', ['id' => $oldId]);
    }

    public function test_failed_replacement_preserves_old_avatar(): void
    {
        $this->actingAs($this->employee)
            ->post('/api/v1/profile', [
                'avatar' => UploadedFile::fake()->image('keep.jpg'),
                '_method' => 'PUT',
            ])
            ->assertOk();

        $this->employee->refresh()->load('avatarFile');
        $old = $this->employee->avatarFile;
        $this->assertInstanceOf(File::class, $old);
        $oldPath = $old->file_path;
        $oldId = $old->id;

        $this->actingAs($this->employee)
            ->post('/api/v1/profile', [
                'avatar' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
                '_method' => 'PUT',
            ])
            ->assertUnprocessable();

        $this->employee->refresh()->load('avatarFile');
        $this->assertSame($oldId, $this->employee->avatarFile?->id);
        Storage::disk(ProfileService::AVATAR_DISK)->assertExists($oldPath);
        $this->assertSame(1, File::query()->avatars()->where('attachable_id', $this->employee->id)->count());
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

        $this->employee->refresh()->load('avatarFile');
        $this->assertNull($this->employee->avatarFile);
        $this->assertSame(0, File::query()->count());
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

        $this->employee->refresh()->load('avatarFile');
        $path = $this->employee->avatarFile?->file_path;
        $this->assertNotNull($path);
        Storage::disk(ProfileService::AVATAR_DISK)->assertExists($path);

        $response = $this->actingAs($this->employee)
            ->deleteJson('/api/v1/profile/avatar');

        $response->assertOk()
            ->assertJsonPath('data.user.avatar', null)
            ->assertJsonPath('message', 'Avatar removed successfully.');

        $this->employee->refresh()->load('avatarFile');
        $this->assertNull($this->employee->avatarFile);
        Storage::disk(ProfileService::AVATAR_DISK)->assertMissing($path);
        $this->assertSame(0, File::query()->avatars()->where('attachable_id', $this->employee->id)->count());

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

        $this->otherEmployee->refresh()->load('avatarFile');
        $otherPath = $this->otherEmployee->avatarFile?->file_path;
        $this->assertNotNull($otherPath);
        Storage::disk(ProfileService::AVATAR_DISK)->assertExists($otherPath);

        $this->actingAs($this->employee)
            ->post('/api/v1/profile', [
                'avatar' => UploadedFile::fake()->image('mine.png'),
                '_method' => 'PUT',
            ])
            ->assertOk();

        $this->otherEmployee->refresh()->load('avatarFile');
        $this->employee->refresh()->load('avatarFile');
        $this->assertSame($otherPath, $this->otherEmployee->avatarFile?->file_path);
        $this->assertNotNull($this->employee->avatarFile);
        $this->assertStringEndsWith('.png', (string) $this->employee->avatarFile?->file_path);

        $this->actingAs($this->employee)
            ->deleteJson('/api/v1/profile/avatar')
            ->assertOk();

        $this->otherEmployee->refresh()->load('avatarFile');
        $this->assertSame($otherPath, $this->otherEmployee->avatarFile?->file_path);
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

        $this->employee->refresh()->load('avatarFile');
        $path = $this->employee->avatarFile?->file_path;
        $this->assertNotNull($path);

        $response = $this->actingAs($this->employee)
            ->getJson('/api/v1/auth/me');

        $response->assertOk();
        $avatar = $response->json('data.avatar');
        $this->assertIsString($avatar);
        $this->assertStringContainsString('/storage/'.$path, $avatar);
        $this->assertStringContainsString('v=', $avatar);
    }

    public function test_legacy_avatar_paths_are_backfilled_into_files(): void
    {
        $path = 'avatars/'.$this->employee->id.'/avatar.jpg';
        Storage::disk(ProfileService::AVATAR_DISK)->put($path, 'fake-image-bytes');

        Schema::table('users', function ($table): void {
            if (! Schema::hasColumn('users', 'avatar')) {
                $table->string('avatar')->nullable();
            }
        });

        DB::table('users')->where('id', $this->employee->id)->update(['avatar' => $path]);
        File::query()->where('attachable_id', $this->employee->id)->delete();

        $migration = require database_path('migrations/2026_08_15_180002_migrate_user_avatars_to_files.php');
        $migration->up();

        $this->assertFalse(Schema::hasColumn('users', 'avatar'));
        $this->employee->refresh()->load('avatarFile');
        $this->assertInstanceOf(File::class, $this->employee->avatarFile);
        $this->assertSame($path, $this->employee->avatarFile?->file_path);
        Storage::disk(ProfileService::AVATAR_DISK)->assertExists($path);
    }
}
