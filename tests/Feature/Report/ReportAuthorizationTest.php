<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Enums\ProjectStatus;
use App\Enums\RoleName;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Policies\ReportPolicy;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private User $projectManager;

    private User $employee;

    private User $otherEmployee;

    private Project $accessibleProject;

    private Project $inaccessibleProject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $pmRole = Role::query()->where('name', RoleName::ProjectManager)->firstOrFail();
        $employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();

        $this->administrator = User::factory()->create([
            'role_id' => $adminRole->id,
            'email' => 'admin.report.authz@opsflow.test',
        ]);
        $this->projectManager = User::factory()->create([
            'role_id' => $pmRole->id,
            'email' => 'pm.report.authz@opsflow.test',
        ]);
        $this->employee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'email' => 'employee.report.authz@opsflow.test',
        ]);
        $this->otherEmployee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'email' => 'other.report.authz@opsflow.test',
        ]);

        $this->accessibleProject = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'status' => ProjectStatus::Active,
            'name' => 'Accessible Report Project',
        ]);
        $this->accessibleProject->members()->attach($this->employee->id, [
            'joined_at' => now(),
        ]);

        $this->inaccessibleProject = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'status' => ProjectStatus::Planning,
            'name' => 'Inaccessible Report Project',
        ]);

        Task::factory()->create([
            'project_id' => $this->accessibleProject->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->employee->id,
            'status' => TaskStatus::Todo,
        ]);
        Task::factory()->create([
            'project_id' => $this->inaccessibleProject->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->otherEmployee->id,
            'status' => TaskStatus::InProgress,
        ]);
    }

    public function test_report_policy_matrix(): void
    {
        $policy = new ReportPolicy;

        $this->assertTrue($policy->viewAnyProjectReports($this->administrator));
        $this->assertTrue($policy->viewAnyProjectReports($this->projectManager));
        $this->assertTrue($policy->viewAnyProjectReports($this->employee));

        $this->assertTrue($policy->viewProjectReport($this->administrator, $this->inaccessibleProject));
        $this->assertTrue($policy->viewProjectReport($this->employee, $this->accessibleProject));
        $this->assertFalse($policy->viewProjectReport($this->employee, $this->inaccessibleProject));

        $this->assertTrue($policy->viewAnyEmployeeReports($this->administrator));
        $this->assertTrue($policy->viewAnyEmployeeReports($this->projectManager));
        $this->assertFalse($policy->viewAnyEmployeeReports($this->employee));

        $this->assertTrue($policy->viewEmployeeReport($this->administrator, $this->otherEmployee));
        $this->assertTrue($policy->viewEmployeeReport($this->employee, $this->employee));
        $this->assertFalse($policy->viewEmployeeReport($this->employee, $this->otherEmployee));
    }

    public function test_administrator_and_project_manager_see_organization_wide_project_reports(): void
    {
        foreach ([$this->administrator, $this->projectManager] as $actor) {
            $response = $this->actingAs($actor)
                ->getJson('/api/v1/reports/projects');

            $response->assertOk();
            $ids = collect($response->json('data'))->pluck('project.id')->all();
            $this->assertContains($this->accessibleProject->id, $ids);
            $this->assertContains($this->inaccessibleProject->id, $ids);
        }
    }

    public function test_employee_sees_only_accessible_project_reports(): void
    {
        $list = $this->actingAs($this->employee)
            ->getJson('/api/v1/reports/projects');

        $list->assertOk();
        $ids = collect($list->json('data'))->pluck('project.id')->all();
        $this->assertContains($this->accessibleProject->id, $ids);
        $this->assertNotContains($this->inaccessibleProject->id, $ids);

        $this->actingAs($this->employee)
            ->getJson("/api/v1/reports/projects/{$this->accessibleProject->id}")
            ->assertOk();

        $this->actingAs($this->employee)
            ->getJson("/api/v1/reports/projects/{$this->inaccessibleProject->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_employee_employee_report_restrictions(): void
    {
        $this->actingAs($this->employee)
            ->getJson('/api/v1/reports/employees')
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->employee)
            ->getJson("/api/v1/reports/employees/{$this->employee->id}")
            ->assertOk()
            ->assertJsonPath('data.user.id', $this->employee->id);

        $this->actingAs($this->employee)
            ->getJson("/api/v1/reports/employees/{$this->otherEmployee->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_guest_is_unauthorized_on_all_report_routes(): void
    {
        $this->getJson('/api/v1/reports/projects')->assertUnauthorized();
        $this->getJson("/api/v1/reports/projects/{$this->accessibleProject->id}")->assertUnauthorized();
        $this->getJson('/api/v1/reports/employees')->assertUnauthorized();
        $this->getJson("/api/v1/reports/employees/{$this->employee->id}")->assertUnauthorized();
    }
}
