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
use App\Services\Dashboard\DashboardService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();

        $this->administrator = User::factory()->create([
            'role_id' => $adminRole->id,
            'email' => 'admin.dashboard@opsflow.test',
        ]);
        $this->employee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'email' => 'employee.dashboard@opsflow.test',
        ]);
    }

    public function test_guest_cannot_access_dashboard(): void
    {
        $this->getJson('/api/v1/dashboard')
            ->assertUnauthorized();
    }

    public function test_authenticated_user_receives_dashboard_envelope_and_shape(): void
    {
        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Dashboard retrieved successfully.')
            ->assertJsonPath('errors', null)
            ->assertJsonPath('meta', null)
            ->assertJsonStructure([
                'data' => [
                    'projects' => [
                        'total',
                        'by_status' => [
                            'planning',
                            'active',
                            'on_hold',
                            'completed',
                            'archived',
                        ],
                    ],
                    'tasks' => [
                        'total',
                        'by_status' => [
                            'todo',
                            'in_progress',
                            'in_review',
                            'blocked',
                            'completed',
                            'cancelled',
                        ],
                        'by_priority' => [
                            'low',
                            'medium',
                            'high',
                            'urgent',
                        ],
                        'overdue',
                        'assigned_to_me',
                    ],
                    'recent',
                ],
            ]);

        $this->assertSame(0, $response->json('data.projects.total'));
        $this->assertSame(0, $response->json('data.tasks.total'));
        $this->assertSame([], $response->json('data.recent'));
    }

    public function test_project_and_task_statistics_are_aggregated_with_zero_fill(): void
    {
        $active = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'status' => ProjectStatus::Active,
            'name' => 'Active Project',
        ]);
        Project::factory()->create([
            'created_by' => $this->administrator->id,
            'status' => ProjectStatus::Planning,
            'name' => 'Planning Project',
        ]);
        Project::factory()->create([
            'created_by' => $this->administrator->id,
            'status' => ProjectStatus::Active,
            'name' => 'Soft Deleted Project',
            'deleted_at' => now(),
        ]);

        Task::factory()->create([
            'project_id' => $active->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Low,
            'title' => 'Todo Low',
        ]);
        Task::factory()->create([
            'project_id' => $active->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::InProgress,
            'priority' => TaskPriority::Urgent,
            'title' => 'In Progress Urgent',
        ]);
        Task::factory()->create([
            'project_id' => $active->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Medium,
            'title' => 'Soft Deleted Task',
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.projects.total', 2)
            ->assertJsonPath('data.projects.by_status.planning', 1)
            ->assertJsonPath('data.projects.by_status.active', 1)
            ->assertJsonPath('data.projects.by_status.on_hold', 0)
            ->assertJsonPath('data.projects.by_status.completed', 0)
            ->assertJsonPath('data.projects.by_status.archived', 0)
            ->assertJsonPath('data.tasks.total', 2)
            ->assertJsonPath('data.tasks.by_status.todo', 1)
            ->assertJsonPath('data.tasks.by_status.in_progress', 1)
            ->assertJsonPath('data.tasks.by_status.in_review', 0)
            ->assertJsonPath('data.tasks.by_priority.low', 1)
            ->assertJsonPath('data.tasks.by_priority.medium', 0)
            ->assertJsonPath('data.tasks.by_priority.urgent', 1);
    }

    public function test_overdue_and_assigned_to_me_calculations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00'));

        $project = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'status' => ProjectStatus::Active,
        ]);

        Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->administrator->id,
            'status' => TaskStatus::InProgress,
            'due_date' => '2026-08-01',
            'title' => 'Overdue Assigned',
        ]);
        Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->employee->id,
            'status' => TaskStatus::Todo,
            'due_date' => '2026-08-03',
            'title' => 'Overdue Other Assignee',
        ]);
        Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->administrator->id,
            'status' => TaskStatus::Completed,
            'due_date' => '2026-08-01',
            'title' => 'Completed Past Due',
        ]);
        Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->administrator->id,
            'status' => TaskStatus::Cancelled,
            'due_date' => '2026-08-01',
            'title' => 'Cancelled Past Due',
        ]);
        Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->administrator->id,
            'status' => TaskStatus::Todo,
            'due_date' => null,
            'title' => 'No Due Date',
        ]);
        Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->administrator->id,
            'status' => TaskStatus::Todo,
            'due_date' => '2026-08-10',
            'title' => 'Future Due',
        ]);

        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.tasks.overdue', 2)
            ->assertJsonPath('data.tasks.assigned_to_me', 5);

        Carbon::setTestNow();
    }

    public function test_recent_work_items_are_merged_sorted_and_limited(): void
    {
        $olderProject = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Older Project',
            'updated_at' => Carbon::parse('2026-08-01 10:00:00'),
        ]);
        $newerProject = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Newer Project',
            'updated_at' => Carbon::parse('2026-08-03 10:00:00'),
        ]);

        $olderTask = Task::factory()->create([
            'project_id' => $newerProject->id,
            'created_by' => $this->administrator->id,
            'title' => 'Older Task',
            'status' => TaskStatus::Todo,
            'updated_at' => Carbon::parse('2026-08-02 10:00:00'),
        ]);
        $newestTask = Task::factory()->create([
            'project_id' => $newerProject->id,
            'created_by' => $this->administrator->id,
            'title' => 'Newest Task',
            'status' => TaskStatus::InProgress,
            'updated_at' => Carbon::parse('2026-08-04 10:00:00'),
        ]);

        // Touch to ensure timestamps stick past factory defaults.
        $olderProject->forceFill(['updated_at' => Carbon::parse('2026-08-01 10:00:00')])->saveQuietly();
        $newerProject->forceFill(['updated_at' => Carbon::parse('2026-08-03 10:00:00')])->saveQuietly();
        $olderTask->forceFill(['updated_at' => Carbon::parse('2026-08-02 10:00:00')])->saveQuietly();
        $newestTask->forceFill(['updated_at' => Carbon::parse('2026-08-04 10:00:00')])->saveQuietly();

        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard?recent_limit=3');

        $response->assertOk();

        $recent = $response->json('data.recent');
        $this->assertCount(3, $recent);
        $this->assertSame('task', $recent[0]['type']);
        $this->assertSame($newestTask->id, $recent[0]['id']);
        $this->assertSame('Newest Task', $recent[0]['title']);
        $this->assertSame($newerProject->id, $recent[0]['project_id']);
        $this->assertSame('project', $recent[1]['type']);
        $this->assertSame($newerProject->id, $recent[1]['id']);
        $this->assertSame('Newer Project', $recent[1]['name']);
        $this->assertSame('task', $recent[2]['type']);
        $this->assertSame($olderTask->id, $recent[2]['id']);
        $this->assertArrayNotHasKey('name', $recent[0]);
        $this->assertArrayNotHasKey('title', $recent[1]);
        $this->assertArrayNotHasKey('project_id', $recent[1]);
    }

    public function test_recent_limit_defaults_and_clamps(): void
    {
        $project = Project::factory()->create([
            'created_by' => $this->administrator->id,
        ]);

        foreach (range(1, 12) as $index) {
            $task = Task::factory()->create([
                'project_id' => $project->id,
                'created_by' => $this->administrator->id,
                'title' => "Task {$index}",
            ]);
            $task->forceFill([
                'updated_at' => Carbon::parse('2026-08-04 10:00:00')->addSeconds($index),
            ])->saveQuietly();
        }

        $default = $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard');
        $default->assertOk();
        $this->assertCount(DashboardService::DEFAULT_RECENT_LIMIT, $default->json('data.recent'));

        $clamped = $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard?recent_limit=100');
        $clamped->assertOk();
        $this->assertLessThanOrEqual(DashboardService::MAX_RECENT_LIMIT, count($clamped->json('data.recent')));
        $this->assertCount(13, $clamped->json('data.recent')); // 12 tasks + 1 project

        $minClamped = $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard?recent_limit=0');
        $minClamped->assertOk();
        $this->assertCount(1, $minClamped->json('data.recent'));
    }

    public function test_invalid_recent_limit_returns_validation_error(): void
    {
        $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard?recent_limit=abc')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['recent_limit']);
    }
}
