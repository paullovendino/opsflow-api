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
use Tests\TestCase;

class UserAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;

    private Role $projectManagerRole;

    private Role $employeeRole;

    private Department $department;

    private JobTitle $jobTitle;

    private User $administrator;

    private User $projectManager;

    private User $employee;

    private User $otherEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, DepartmentSeeder::class, JobTitleSeeder::class]);

        $this->adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $this->projectManagerRole = Role::query()->where('name', RoleName::ProjectManager)->firstOrFail();
        $this->employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();
        $this->department = Department::query()->where('code', DepartmentCode::Engineering)->firstOrFail();
        $this->jobTitle = JobTitle::query()->where('code', JobTitleCode::SoftwareEngineer)->firstOrFail();

        $this->administrator = User::factory()->create([
            'role_id' => $this->adminRole->id,
            'email' => 'admin@opsflow.test',
        ]);
        $this->projectManager = User::factory()->create([
            'role_id' => $this->projectManagerRole->id,
            'email' => 'pm@opsflow.test',
        ]);
        $this->employee = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'email' => 'employee@opsflow.test',
        ]);
        $this->otherEmployee = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'email' => 'other.employee@opsflow.test',
        ]);
    }

    public function test_administrator_has_full_user_management_access(): void
    {
        $this->actingAs($this->administrator)
            ->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($this->administrator)
            ->getJson("/api/v1/users/{$this->otherEmployee->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->otherEmployee->id);

        $created = $this->actingAs($this->administrator)
            ->postJson('/api/v1/users', $this->validPayload([
                'email' => 'created.by.admin@opsflow.test',
            ]));
        $created->assertCreated()
            ->assertJsonPath('data.email', 'created.by.admin@opsflow.test');

        $targetId = $created->json('data.id');

        $this->actingAs($this->administrator)
            ->putJson("/api/v1/users/{$targetId}", $this->validPayload([
                'first_name' => 'Updated',
                'email' => 'updated.by.admin@opsflow.test',
            ]))
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Updated');

        $this->actingAs($this->administrator)
            ->patchJson("/api/v1/users/{$targetId}/status", [
                'status' => UserStatus::Inactive->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', UserStatus::Inactive->value);

        $this->actingAs($this->administrator)
            ->deleteJson("/api/v1/users/{$targetId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('users', ['id' => $targetId]);
    }

    public function test_project_manager_can_list_and_view_but_not_mutate(): void
    {
        $this->actingAs($this->projectManager)
            ->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($this->projectManager)
            ->getJson("/api/v1/users/{$this->otherEmployee->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->otherEmployee->id);

        $this->actingAs($this->projectManager)
            ->postJson('/api/v1/users', $this->validPayload([
                'email' => 'pm.cannot.create@opsflow.test',
            ]))
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized.');

        $this->actingAs($this->projectManager)
            ->putJson("/api/v1/users/{$this->otherEmployee->id}", $this->validPayload([
                'email' => $this->otherEmployee->email,
            ]))
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->projectManager)
            ->deleteJson("/api/v1/users/{$this->otherEmployee->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->projectManager)
            ->patchJson("/api/v1/users/{$this->otherEmployee->id}/status", [
                'status' => UserStatus::Inactive->value,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_employee_can_view_own_profile(): void
    {
        $this->actingAs($this->employee)
            ->getJson("/api/v1/users/{$this->employee->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $this->employee->id)
            ->assertJsonPath('data.email', 'employee@opsflow.test');
    }

    public function test_employee_cannot_list_users(): void
    {
        $this->actingAs($this->employee)
            ->getJson('/api/v1/users')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized.')
            ->assertJsonPath('errors', null);
    }

    public function test_employee_cannot_view_other_users(): void
    {
        $this->actingAs($this->employee)
            ->getJson("/api/v1/users/{$this->otherEmployee->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_employee_cannot_update_users(): void
    {
        $this->actingAs($this->employee)
            ->putJson("/api/v1/users/{$this->otherEmployee->id}", $this->validPayload([
                'email' => $this->otherEmployee->email,
            ]))
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->employee)
            ->putJson("/api/v1/users/{$this->employee->id}", $this->validPayload([
                'email' => $this->employee->email,
            ]))
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_employee_cannot_delete_users(): void
    {
        $this->actingAs($this->employee)
            ->deleteJson("/api/v1/users/{$this->otherEmployee->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->employee)
            ->deleteJson("/api/v1/users/{$this->employee->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_employee_cannot_change_user_status(): void
    {
        $this->actingAs($this->employee)
            ->patchJson("/api/v1/users/{$this->otherEmployee->id}/status", [
                'status' => UserStatus::Inactive->value,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->employee)
            ->patchJson("/api/v1/users/{$this->employee->id}/status", [
                'status' => UserStatus::Inactive->value,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_employee_cannot_create_users(): void
    {
        $this->actingAs($this->employee)
            ->postJson('/api/v1/users', $this->validPayload([
                'email' => 'employee.cannot.create@opsflow.test',
            ]))
            ->assertForbidden()
            ->assertJsonPath('success', false);
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
