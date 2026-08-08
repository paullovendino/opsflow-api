<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Enums\ProjectStatus;
use App\Enums\RoleName;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EmployeeReportApiTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private User $employee;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();

        $this->administrator = User::factory()->create([
            'role_id' => $adminRole->id,
            'email' => 'admin.employee.reports@opsflow.test',
        ]);
        $this->employee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'email' => 'jane.employee.reports@opsflow.test',
            'first_name' => 'Jane',
            'last_name' => 'Reporter',
            'status' => UserStatus::Active,
        ]);

        $this->project = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'status' => ProjectStatus::Active,
            'name' => 'Workload Project',
        ]);
    }

    public function test_guest_cannot_access_employee_reports(): void
    {
        $this->getJson('/api/v1/reports/employees')->assertUnauthorized();
        $this->getJson("/api/v1/reports/employees/{$this->employee->id}")->assertUnauthorized();
    }

    public function test_employee_report_list_and_detail_shapes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00'));

        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->employee->id,
            'status' => TaskStatus::InProgress,
            'priority' => TaskPriority::Urgent,
            'due_date' => '2026-08-01',
            'created_at' => '2026-08-02 10:00:00',
        ]);
        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->employee->id,
            'status' => TaskStatus::Completed,
            'priority' => TaskPriority::Medium,
            'created_at' => '2026-08-03 10:00:00',
        ]);

        $list = $this->actingAs($this->administrator)
            ->getJson('/api/v1/reports/employees?search=Jane');

        $list->assertOk()
            ->assertJsonPath('message', 'Employee reports retrieved successfully.')
            ->assertJsonStructure([
                'data' => [
                    [
                        'user' => [
                            'id',
                            'first_name',
                            'middle_name',
                            'last_name',
                            'full_name',
                            'email',
                            'status',
                        ],
                        'tasks' => [
                            'total',
                            'by_status',
                            'by_priority',
                            'overdue',
                        ],
                    ],
                ],
                'meta' => ['current_page', 'per_page', 'total'],
            ]);

        $item = collect($list->json('data'))->firstWhere('user.id', $this->employee->id);
        $this->assertNotNull($item);
        $this->assertSame(2, $item['tasks']['total']);
        $this->assertSame(1, $item['tasks']['overdue']);
        $this->assertArrayNotHasKey('by_project', $item['tasks']);

        $detail = $this->actingAs($this->administrator)
            ->getJson("/api/v1/reports/employees/{$this->employee->id}");

        $detail->assertOk()
            ->assertJsonPath('message', 'Employee report retrieved successfully.')
            ->assertJsonPath('data.user.id', $this->employee->id)
            ->assertJsonPath('data.tasks.total', 2)
            ->assertJsonPath('data.tasks.by_status.in_progress', 1)
            ->assertJsonPath('data.tasks.by_status.completed', 1)
            ->assertJsonPath('data.tasks.by_priority.urgent', 1);

        $byProject = $detail->json('data.tasks.by_project');
        $this->assertIsArray($byProject);
        $this->assertCount(1, $byProject);
        $this->assertSame($this->project->id, $byProject[0]['project_id']);
        $this->assertSame('Workload Project', $byProject[0]['name']);
        $this->assertSame(2, $byProject[0]['total']);

        Carbon::setTestNow();
    }

    public function test_employee_report_respects_date_range_and_soft_deleted_project_tasks(): void
    {
        $deletedProject = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Gone Project',
            'deleted_at' => now(),
        ]);

        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->employee->id,
            'created_at' => '2026-08-05 10:00:00',
        ]);
        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->employee->id,
            'created_at' => '2026-07-01 10:00:00',
        ]);
        Task::factory()->create([
            'project_id' => $deletedProject->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->employee->id,
            'created_at' => '2026-08-05 11:00:00',
        ]);

        $response = $this->actingAs($this->administrator)
            ->getJson("/api/v1/reports/employees/{$this->employee->id}?from_date=2026-08-01&to_date=2026-08-31");

        $response->assertOk()
            ->assertJsonPath('data.tasks.total', 1);
    }

    public function test_invalid_employee_report_query_returns_validation_error(): void
    {
        $this->actingAs($this->administrator)
            ->getJson('/api/v1/reports/employees?from_date=2026-08-10&to_date=2026-08-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to_date']);
    }

    public function test_employee_report_list_can_be_empty(): void
    {
        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/reports/employees?search=NoSuchEmployee');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }
}
