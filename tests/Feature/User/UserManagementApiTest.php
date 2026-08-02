<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Enums\DepartmentCode;
use App\Enums\JobTitleCode;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\JobTitle;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\JobTitleSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Role $employeeRole;

    private Role $adminRole;

    private Department $department;

    private JobTitle $jobTitle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, DepartmentSeeder::class, JobTitleSeeder::class]);

        $this->employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();
        $this->adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $this->department = Department::query()->where('code', DepartmentCode::Engineering)->firstOrFail();
        $this->jobTitle = JobTitle::query()->where('code', JobTitleCode::SoftwareEngineer)->firstOrFail();
        $this->actor = User::factory()->create([
            'role_id' => $this->adminRole->id,
        ]);
    }

    public function test_authenticated_user_can_create_user(): void
    {
        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/users', $this->validPayload([
                'email' => 'new.user@opsflow.test',
                'password' => 'password123',
            ]));

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'User created successfully.')
            ->assertJsonPath('data.email', 'new.user@opsflow.test')
            ->assertJsonPath('data.first_name', 'Jane')
            ->assertJsonPath('data.last_name', 'Doe')
            ->assertJsonPath('data.full_name', 'Jane Doe')
            ->assertJsonPath('data.status', UserStatus::Active->value)
            ->assertJsonPath('data.role.name', RoleName::Employee->value)
            ->assertJsonPath('data.department.code', DepartmentCode::Engineering->value)
            ->assertJsonPath('data.job_title.code', JobTitleCode::SoftwareEngineer->value)
            ->assertJsonMissingPath('data.password');

        $this->assertDatabaseHas('users', [
            'email' => 'new.user@opsflow.test',
            'first_name' => 'Jane',
        ]);
    }

    public function test_password_is_hashed_when_creating_user(): void
    {
        $this->actingAs($this->actor)
            ->postJson('/api/v1/users', $this->validPayload([
                'email' => 'hashed@opsflow.test',
                'password' => 'password123',
            ]))
            ->assertCreated();

        $user = User::query()->where('email', 'hashed@opsflow.test')->firstOrFail();

        $this->assertNotSame('password123', $user->password);
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function test_authenticated_user_can_list_users(): void
    {
        User::factory()->count(2)->create([
            'role_id' => $this->employeeRole->id,
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/users');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Users retrieved successfully.')
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.current_page', 1);

        $this->assertGreaterThanOrEqual(3, $response->json('meta.total'));
        $this->assertGreaterThanOrEqual(3, count($response->json('data')));
        $this->assertIsArray($response->json('data.0.role'));
    }

    public function test_authenticated_user_can_show_user(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'department_id' => $this->department->id,
            'job_title_id' => $this->jobTitle->id,
            'first_name' => 'Show',
            'last_name' => 'Case',
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson("/api/v1/users/{$user->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.first_name', 'Show')
            ->assertJsonPath('data.full_name', 'Show Case')
            ->assertJsonPath('data.role.name', RoleName::Employee->value)
            ->assertJsonPath('data.department.code', DepartmentCode::Engineering->value)
            ->assertJsonPath('data.job_title.code', JobTitleCode::SoftwareEngineer->value)
            ->assertJsonMissingPath('data.password');
    }

    public function test_authenticated_user_can_update_user(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'email' => 'before@opsflow.test',
        ]);

        $adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();

        $response = $this->actingAs($this->actor)
            ->putJson("/api/v1/users/{$user->id}", $this->validPayload([
                'first_name' => 'Updated',
                'last_name' => 'Person',
                'email' => 'after@opsflow.test',
                'role_id' => $adminRole->id,
                'status' => UserStatus::Inactive->value,
            ]));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.first_name', 'Updated')
            ->assertJsonPath('data.email', 'after@opsflow.test')
            ->assertJsonPath('data.status', UserStatus::Inactive->value)
            ->assertJsonPath('data.role.name', RoleName::Administrator->value);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'after@opsflow.test',
            'first_name' => 'Updated',
        ]);
    }

    public function test_update_does_not_change_password_when_password_omitted(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'password' => 'password',
        ]);

        $originalHash = $user->password;

        $payload = $this->validPayload([
            'email' => $user->email,
        ]);
        unset($payload['password']);

        $this->actingAs($this->actor)
            ->putJson("/api/v1/users/{$user->id}", $payload)
            ->assertOk();

        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_authenticated_user_can_soft_delete_user(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->employeeRole->id,
        ]);

        $response = $this->actingAs($this->actor)
            ->deleteJson("/api/v1/users/{$user->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'User deleted successfully.');

        $this->assertSoftDeleted($user);
    }

    public function test_authenticated_user_can_update_status_only(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'first_name' => 'Status',
            'last_name' => 'Target',
            'email' => 'status.target@opsflow.test',
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($this->actor)
            ->patchJson("/api/v1/users/{$user->id}/status", [
                'status' => UserStatus::Inactive->value,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', UserStatus::Inactive->value)
            ->assertJsonPath('data.first_name', 'Status')
            ->assertJsonPath('data.email', 'status.target@opsflow.test');

        $fresh = $user->fresh();
        $this->assertSame(UserStatus::Inactive, $fresh->status);
        $this->assertSame('Status', $fresh->first_name);
        $this->assertSame('status.target@opsflow.test', $fresh->email);
    }

    public function test_store_validation_errors_are_returned(): void
    {
        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/users', []);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonStructure([
                'errors' => [
                    'first_name',
                    'last_name',
                    'email',
                    'password',
                    'role_id',
                    'status',
                ],
            ]);
    }

    public function test_email_must_be_unique_on_create(): void
    {
        User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'email' => 'taken@opsflow.test',
        ]);

        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/users', $this->validPayload([
                'email' => 'taken@opsflow.test',
            ]));

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_user_resource_serialization_shape(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'department_id' => $this->department->id,
            'job_title_id' => $this->jobTitle->id,
            'first_name' => 'Resource',
            'middle_name' => 'Test',
            'last_name' => 'User',
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson("/api/v1/users/{$user->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'first_name',
                    'middle_name',
                    'last_name',
                    'full_name',
                    'email',
                    'avatar',
                    'status',
                    'last_login_at',
                    'role' => ['id', 'name', 'description'],
                    'department' => ['id', 'name', 'code', 'description'],
                    'job_title' => ['id', 'name', 'code', 'description'],
                ],
                'errors',
                'meta',
            ])
            ->assertJsonPath('data.full_name', 'Resource Test User');
    }

    public function test_guest_cannot_access_user_endpoints(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();
        $this->postJson('/api/v1/users', [])->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Jane',
            'middle_name' => null,
            'last_name' => 'Doe',
            'email' => 'jane.doe@opsflow.test',
            'password' => 'password123',
            'role_id' => $this->employeeRole->id,
            'department_id' => $this->department->id,
            'job_title_id' => $this->jobTitle->id,
            'status' => UserStatus::Active->value,
            'avatar' => null,
        ], $overrides);
    }
}
