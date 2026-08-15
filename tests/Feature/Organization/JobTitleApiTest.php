<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Enums\ActivityAction;
use App\Enums\DepartmentCode;
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

class JobTitleApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $projectManager;

    private User $employee;

    private Department $engineering;

    private Department $operations;

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

        $this->engineering = Department::query()->where('code', DepartmentCode::Engineering->value)->firstOrFail();
        $this->operations = Department::query()->where('code', DepartmentCode::Operations->value)->firstOrFail();
    }

    public function test_guest_cannot_access_job_title_endpoints(): void
    {
        $jobTitle = JobTitle::query()->firstOrFail();

        $this->getJson('/api/v1/job-titles')->assertUnauthorized();
        $this->postJson('/api/v1/job-titles', [])->assertUnauthorized();
        $this->getJson("/api/v1/job-titles/{$jobTitle->id}")->assertUnauthorized();
        $this->putJson("/api/v1/job-titles/{$jobTitle->id}", [])->assertUnauthorized();
        $this->deleteJson("/api/v1/job-titles/{$jobTitle->id}")->assertUnauthorized();
        $this->patchJson("/api/v1/job-titles/{$jobTitle->id}/status", [])->assertUnauthorized();
    }

    public function test_admin_and_project_manager_can_list_job_titles(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/job-titles')
            ->assertOk()
            ->assertJsonPath('message', 'Job titles retrieved successfully.');

        $this->actingAs($this->projectManager)
            ->getJson('/api/v1/job-titles')
            ->assertOk();
    }

    public function test_employee_cannot_list_job_titles(): void
    {
        $this->actingAs($this->employee)
            ->getJson('/api/v1/job-titles')
            ->assertForbidden();
    }

    public function test_admin_can_create_job_title(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/job-titles', [
                'name' => 'QA Engineer',
                'description' => 'Quality assurance',
                'department_id' => $this->engineering->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'QA Engineer')
            ->assertJsonPath('data.department_id', $this->engineering->id)
            ->assertJsonPath('data.status', OrgEntityStatus::Active->value)
            ->assertJsonPath('data.code', null)
            ->assertJsonPath('data.department.id', $this->engineering->id)
            ->assertJsonPath('data.users_count', 0);

        $this->assertTrue(
            ActivityLog::query()
                ->where('action', ActivityAction::JobTitleCreated->value)
                ->exists(),
        );
    }

    public function test_project_manager_cannot_create_job_title(): void
    {
        $this->actingAs($this->projectManager)
            ->postJson('/api/v1/job-titles', [
                'name' => 'Blocked Title',
                'department_id' => $this->engineering->id,
            ])
            ->assertForbidden();
    }

    public function test_job_title_name_unique_per_department_case_insensitive(): void
    {
        JobTitle::query()->create([
            'department_id' => $this->engineering->id,
            'name' => 'Lead Engineer',
            'status' => OrgEntityStatus::Active,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/job-titles', [
                'name' => 'lead engineer',
                'department_id' => $this->engineering->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/job-titles', [
                'name' => 'Lead Engineer',
                'department_id' => $this->operations->id,
            ])
            ->assertCreated();
    }

    public function test_admin_can_update_job_title(): void
    {
        $jobTitle = JobTitle::query()->create([
            'department_id' => $this->engineering->id,
            'name' => 'Temp Title',
            'status' => OrgEntityStatus::Active,
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/job-titles/{$jobTitle->id}", [
                'name' => 'Temp Title Updated',
                'description' => 'Updated',
                'department_id' => $this->engineering->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Temp Title Updated');
    }

    public function test_cannot_change_department_when_users_assigned(): void
    {
        $jobTitle = JobTitle::query()->create([
            'department_id' => $this->engineering->id,
            'name' => 'Assigned Title',
            'status' => OrgEntityStatus::Active,
        ]);

        User::factory()->create([
            'role_id' => $this->employee->role_id,
            'department_id' => $this->engineering->id,
            'job_title_id' => $jobTitle->id,
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/job-titles/{$jobTitle->id}", [
                'name' => 'Assigned Title',
                'department_id' => $this->operations->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id']);
    }

    public function test_changing_department_records_department_changed_activity(): void
    {
        $jobTitle = JobTitle::query()->create([
            'department_id' => $this->engineering->id,
            'name' => 'Movable Title',
            'status' => OrgEntityStatus::Active,
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/job-titles/{$jobTitle->id}", [
                'name' => 'Movable Title',
                'department_id' => $this->operations->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.department_id', $this->operations->id);

        $this->assertTrue(
            ActivityLog::query()
                ->where('action', ActivityAction::JobTitleDepartmentChanged->value)
                ->exists(),
        );
    }

    public function test_admin_can_change_job_title_status(): void
    {
        $jobTitle = JobTitle::query()->create([
            'department_id' => $this->engineering->id,
            'name' => 'Status Title',
            'status' => OrgEntityStatus::Active,
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/job-titles/{$jobTitle->id}/status", [
                'status' => OrgEntityStatus::Inactive->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', OrgEntityStatus::Inactive->value);
    }

    public function test_admin_can_delete_unused_job_title(): void
    {
        $jobTitle = JobTitle::query()->create([
            'department_id' => $this->engineering->id,
            'name' => 'Deletable Title',
            'status' => OrgEntityStatus::Active,
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/job-titles/{$jobTitle->id}")
            ->assertOk();

        $this->assertSoftDeleted($jobTitle);
    }

    public function test_cannot_delete_job_title_with_users(): void
    {
        $jobTitle = JobTitle::query()->create([
            'department_id' => $this->engineering->id,
            'name' => 'In Use Title',
            'status' => OrgEntityStatus::Active,
        ]);

        User::factory()->create([
            'role_id' => $this->employee->role_id,
            'department_id' => $this->engineering->id,
            'job_title_id' => $jobTitle->id,
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/job-titles/{$jobTitle->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['job_title']);
    }

    public function test_nested_department_job_titles_route_filters_by_department(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/departments/{$this->engineering->id}/job-titles");

        $response->assertOk();

        $departmentIds = collect($response->json('data'))->pluck('department_id')->unique()->values()->all();

        $this->assertSame([$this->engineering->id], $departmentIds);
    }

    public function test_list_supports_search_status_and_department_filter(): void
    {
        JobTitle::query()->create([
            'department_id' => $this->operations->id,
            'name' => 'Zebra Operator',
            'status' => OrgEntityStatus::Inactive,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/job-titles?q=Zebra&status=inactive&department_id='.$this->operations->id);

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Zebra Operator');
    }
}
