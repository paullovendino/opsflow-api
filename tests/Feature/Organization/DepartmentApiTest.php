<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Enums\ActivityAction;
use App\Enums\OrgEntityStatus;
use App\Enums\RoleName;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\JobTitle;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\JobTitleSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $projectManager;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, DepartmentSeeder::class, JobTitleSeeder::class]);

        $adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $pmRole = Role::query()->where('name', RoleName::ProjectManager)->firstOrFail();
        $employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();

        $this->admin = User::factory()->create(['role_id' => $adminRole->id]);
        $this->projectManager = User::factory()->create(['role_id' => $pmRole->id]);
        $this->employee = User::factory()->create(['role_id' => $employeeRole->id]);
    }

    public function test_guest_cannot_access_department_endpoints(): void
    {
        $department = Department::query()->firstOrFail();

        $this->getJson('/api/v1/departments')->assertUnauthorized();
        $this->postJson('/api/v1/departments', [])->assertUnauthorized();
        $this->getJson("/api/v1/departments/{$department->id}")->assertUnauthorized();
        $this->putJson("/api/v1/departments/{$department->id}", [])->assertUnauthorized();
        $this->deleteJson("/api/v1/departments/{$department->id}")->assertUnauthorized();
        $this->patchJson("/api/v1/departments/{$department->id}/status", [])->assertUnauthorized();
    }

    public function test_admin_and_project_manager_can_list_departments(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/departments')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Departments retrieved successfully.');

        $this->actingAs($this->projectManager)
            ->getJson('/api/v1/departments')
            ->assertOk();
    }

    public function test_employee_cannot_list_departments(): void
    {
        $this->actingAs($this->employee)
            ->getJson('/api/v1/departments')
            ->assertForbidden();
    }

    public function test_admin_can_create_department(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/departments', [
                'name' => 'Quality Assurance',
                'description' => 'QA and testing',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Quality Assurance')
            ->assertJsonPath('data.description', 'QA and testing')
            ->assertJsonPath('data.status', OrgEntityStatus::Active->value)
            ->assertJsonPath('data.code', null)
            ->assertJsonPath('data.job_titles_count', 0)
            ->assertJsonPath('data.users_count', 0);

        $this->assertDatabaseHas('departments', [
            'name' => 'Quality Assurance',
            'status' => OrgEntityStatus::Active->value,
        ]);

        $this->assertTrue(
            ActivityLog::query()
                ->where('action', ActivityAction::DepartmentCreated->value)
                ->exists(),
        );
    }

    public function test_project_manager_cannot_create_department(): void
    {
        $this->actingAs($this->projectManager)
            ->postJson('/api/v1/departments', [
                'name' => 'Blocked Dept',
            ])
            ->assertForbidden();
    }

    public function test_department_name_must_be_unique_case_insensitive(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/departments', [
                'name' => 'Engineering',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_admin_can_update_department(): void
    {
        $department = Department::query()->create([
            'name' => 'Temporary',
            'description' => 'Temp',
            'status' => OrgEntityStatus::Active,
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/departments/{$department->id}", [
                'name' => 'Temporary Updated',
                'description' => 'Updated desc',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Temporary Updated')
            ->assertJsonPath('data.description', 'Updated desc');
    }

    public function test_admin_can_change_department_status(): void
    {
        $department = Department::query()->create([
            'name' => 'Status Target',
            'status' => OrgEntityStatus::Active,
        ]);

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/v1/departments/{$department->id}/status", [
                'status' => OrgEntityStatus::Inactive->value,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', OrgEntityStatus::Inactive->value);

        $this->assertTrue(
            ActivityLog::query()
                ->where('action', ActivityAction::DepartmentDeactivated->value)
                ->exists(),
        );
    }

    public function test_admin_can_delete_empty_department(): void
    {
        $department = Department::query()->create([
            'name' => 'Deletable',
            'status' => OrgEntityStatus::Active,
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/departments/{$department->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Department deleted successfully.');

        $this->assertSoftDeleted($department);
    }

    public function test_cannot_delete_department_with_job_titles(): void
    {
        $department = Department::query()->whereHas('jobTitles')->firstOrFail();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/departments/{$department->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department']);
    }

    public function test_cannot_delete_department_with_users(): void
    {
        $department = Department::query()->create([
            'name' => 'Has Users',
            'status' => OrgEntityStatus::Active,
        ]);

        User::factory()->create([
            'role_id' => $this->employee->role_id,
            'department_id' => $department->id,
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/departments/{$department->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department']);
    }

    public function test_list_supports_search_and_status_filter(): void
    {
        Department::query()->create([
            'name' => 'Zebra Research',
            'status' => OrgEntityStatus::Inactive,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/departments?q=Zebra&status=inactive');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Zebra Research');
    }

    public function test_list_is_ordered_by_name(): void
    {
        $names = collect(
            $this->actingAs($this->admin)
                ->getJson('/api/v1/departments?per_page=100')
                ->json('data'),
        )->pluck('name')->all();

        $this->assertSame(collect($names)->sort()->values()->all(), $names);
    }
}
