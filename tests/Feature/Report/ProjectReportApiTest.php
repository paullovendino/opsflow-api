<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Enums\ProjectStatus;
use App\Enums\RoleName;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProjectReportApiTest extends TestCase
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
            'email' => 'admin.project.reports@opsflow.test',
        ]);
        $this->employee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'email' => 'employee.project.reports@opsflow.test',
        ]);

        $this->project = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'status' => ProjectStatus::Active,
            'name' => 'Reportable Project',
        ]);
        $this->project->members()->attach($this->employee->id, [
            'joined_at' => now(),
        ]);
    }

    public function test_guest_cannot_access_project_reports(): void
    {
        $this->getJson('/api/v1/reports/projects')->assertUnauthorized();
        $this->getJson("/api/v1/reports/projects/{$this->project->id}")->assertUnauthorized();
    }

    public function test_project_report_list_returns_paginated_summaries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00'));

        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Low,
            'assigned_to' => null,
            'due_date' => '2026-08-01',
            'created_at' => '2026-08-02 10:00:00',
        ]);
        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Completed,
            'priority' => TaskPriority::High,
            'assigned_to' => $this->employee->id,
            'created_at' => '2026-08-03 10:00:00',
        ]);

        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/reports/projects');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Project reports retrieved successfully.')
            ->assertJsonPath('errors', null)
            ->assertJsonStructure([
                'data' => [
                    [
                        'project' => ['id', 'name', 'status', 'start_date', 'due_date', 'created_at'],
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
                            'by_priority' => ['low', 'medium', 'high', 'urgent'],
                            'overdue',
                            'unassigned',
                        ],
                        'members_count',
                    ],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
            ]);

        $item = collect($response->json('data'))->firstWhere('project.id', $this->project->id);
        $this->assertNotNull($item);
        $this->assertSame(2, $item['tasks']['total']);
        $this->assertSame(1, $item['tasks']['by_status']['todo']);
        $this->assertSame(1, $item['tasks']['by_status']['completed']);
        $this->assertSame(0, $item['tasks']['by_status']['in_progress']);
        $this->assertSame(1, $item['tasks']['by_priority']['low']);
        $this->assertSame(1, $item['tasks']['by_priority']['high']);
        $this->assertSame(1, $item['tasks']['overdue']);
        $this->assertSame(1, $item['tasks']['unassigned']);
        $this->assertSame(1, $item['members_count']);

        Carbon::setTestNow();
    }

    public function test_project_report_detail_and_date_filtering(): void
    {
        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->administrator->id,
            'title' => 'In Range',
            'created_at' => '2026-08-02 10:00:00',
        ]);
        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->administrator->id,
            'title' => 'Out Of Range',
            'created_at' => '2026-07-01 10:00:00',
        ]);

        $response = $this->actingAs($this->administrator)
            ->getJson("/api/v1/reports/projects/{$this->project->id}?from_date=2026-08-01&to_date=2026-08-31");

        $response->assertOk()
            ->assertJsonPath('message', 'Project report retrieved successfully.')
            ->assertJsonPath('data.project.id', $this->project->id)
            ->assertJsonPath('data.tasks.total', 1);
    }

    public function test_invalid_date_range_returns_validation_error(): void
    {
        $this->actingAs($this->administrator)
            ->getJson("/api/v1/reports/projects/{$this->project->id}?from_date=2026-08-10&to_date=2026-08-01")
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['to_date']);
    }

    public function test_soft_deleted_projects_and_tasks_are_excluded(): void
    {
        $deletedProject = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Deleted Project',
            'deleted_at' => now(),
        ]);

        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->administrator->id,
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/reports/projects');

        $ids = collect($response->json('data'))->pluck('project.id')->all();
        $this->assertContains($this->project->id, $ids);
        $this->assertNotContains($deletedProject->id, $ids);

        $item = collect($response->json('data'))->firstWhere('project.id', $this->project->id);
        $this->assertSame(0, $item['tasks']['total']);
    }

    public function test_search_and_status_filters_and_per_page_clamp(): void
    {
        Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Other Alpha',
            'status' => ProjectStatus::Planning,
        ]);

        $filtered = $this->actingAs($this->administrator)
            ->getJson('/api/v1/reports/projects?search=Reportable&status=active');

        $filtered->assertOk();
        $this->assertCount(1, $filtered->json('data'));
        $this->assertSame($this->project->id, $filtered->json('data.0.project.id'));

        $clamped = $this->actingAs($this->administrator)
            ->getJson('/api/v1/reports/projects?per_page=500');

        $clamped->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }
}
