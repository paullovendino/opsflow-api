<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\ProjectStatus;
use App\Enums\RoleName;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Policies\DashboardPolicy;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardAuthorizationTest extends TestCase
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
            'email' => 'admin.dash.authz@opsflow.test',
        ]);
        $this->projectManager = User::factory()->create([
            'role_id' => $pmRole->id,
            'email' => 'pm.dash.authz@opsflow.test',
        ]);
        $this->employee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'email' => 'employee.dash.authz@opsflow.test',
        ]);
        $this->otherEmployee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'email' => 'other.dash.authz@opsflow.test',
        ]);

        $this->accessibleProject = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'status' => ProjectStatus::Active,
            'name' => 'Accessible Project',
        ]);
        $this->accessibleProject->members()->attach($this->employee->id, [
            'joined_at' => now(),
        ]);

        $this->inaccessibleProject = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'status' => ProjectStatus::Planning,
            'name' => 'Inaccessible Project',
        ]);

        Task::factory()->create([
            'project_id' => $this->accessibleProject->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->employee->id,
            'status' => TaskStatus::InProgress,
            'priority' => TaskPriority::High,
            'due_date' => Carbon::yesterday()->toDateString(),
            'title' => 'Accessible Task',
        ]);

        Task::factory()->create([
            'project_id' => $this->inaccessibleProject->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->otherEmployee->id,
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Low,
            'due_date' => Carbon::yesterday()->toDateString(),
            'title' => 'Inaccessible Task',
        ]);
    }

    public function test_dashboard_policy_allows_all_authenticated_roles(): void
    {
        $policy = new DashboardPolicy;

        $this->assertTrue($policy->view($this->administrator));
        $this->assertTrue($policy->view($this->projectManager));
        $this->assertTrue($policy->view($this->employee));
    }

    public function test_administrator_sees_organization_wide_dashboard(): void
    {
        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.projects.total', 2)
            ->assertJsonPath('data.tasks.total', 2)
            ->assertJsonPath('data.tasks.overdue', 2)
            ->assertJsonPath('data.tasks.assigned_to_me', 0);

        $titles = collect($response->json('data.recent'))
            ->where('type', 'task')
            ->pluck('title')
            ->all();

        $this->assertContains('Accessible Task', $titles);
        $this->assertContains('Inaccessible Task', $titles);
    }

    public function test_project_manager_sees_organization_wide_dashboard(): void
    {
        $response = $this->actingAs($this->projectManager)
            ->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.projects.total', 2)
            ->assertJsonPath('data.tasks.total', 2)
            ->assertJsonPath('data.tasks.overdue', 2);
    }

    public function test_employee_sees_only_owned_or_member_project_data(): void
    {
        $owned = Project::factory()->create([
            'created_by' => $this->employee->id,
            'status' => ProjectStatus::OnHold,
            'name' => 'Owned By Employee',
        ]);
        Task::factory()->create([
            'project_id' => $owned->id,
            'created_by' => $this->employee->id,
            'assigned_to' => $this->employee->id,
            'status' => TaskStatus::Blocked,
            'priority' => TaskPriority::Medium,
            'title' => 'Owned Task',
        ]);

        $response = $this->actingAs($this->employee)
            ->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.projects.total', 2)
            ->assertJsonPath('data.projects.by_status.active', 1)
            ->assertJsonPath('data.projects.by_status.on_hold', 1)
            ->assertJsonPath('data.projects.by_status.planning', 0)
            ->assertJsonPath('data.tasks.total', 2)
            ->assertJsonPath('data.tasks.by_status.in_progress', 1)
            ->assertJsonPath('data.tasks.by_status.blocked', 1)
            ->assertJsonPath('data.tasks.by_status.todo', 0)
            ->assertJsonPath('data.tasks.overdue', 1)
            ->assertJsonPath('data.tasks.assigned_to_me', 2);

        $recentTypes = collect($response->json('data.recent'));
        $projectNames = $recentTypes->where('type', 'project')->pluck('name')->all();
        $taskTitles = $recentTypes->where('type', 'task')->pluck('title')->all();

        $this->assertContains('Accessible Project', $projectNames);
        $this->assertContains('Owned By Employee', $projectNames);
        $this->assertNotContains('Inaccessible Project', $projectNames);
        $this->assertContains('Accessible Task', $taskTitles);
        $this->assertContains('Owned Task', $taskTitles);
        $this->assertNotContains('Inaccessible Task', $taskTitles);
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->getJson('/api/v1/dashboard')
            ->assertUnauthorized();
    }
}
