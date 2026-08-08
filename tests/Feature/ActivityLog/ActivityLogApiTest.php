<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Enums\ActivityAction;
use App\Enums\RoleName;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogApiTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private User $projectManager;

    private User $employee;

    private User $otherEmployee;

    private Project $accessibleProject;

    private Project $inaccessibleProject;

    private Task $accessibleTask;

    private Task $inaccessibleTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $pmRole = Role::query()->where('name', RoleName::ProjectManager)->firstOrFail();
        $employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();

        $this->administrator = User::factory()->create([
            'role_id' => $adminRole->id,
            'first_name' => 'Ada',
            'last_name' => 'Admin',
            'email' => 'admin@opsflow.test',
        ]);
        $this->projectManager = User::factory()->create([
            'role_id' => $pmRole->id,
            'first_name' => 'Pat',
            'last_name' => 'Manager',
            'email' => 'pm@opsflow.test',
        ]);
        $this->employee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'first_name' => 'Eli',
            'last_name' => 'Employee',
            'email' => 'employee@opsflow.test',
        ]);
        $this->otherEmployee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'first_name' => 'Ora',
            'last_name' => 'Other',
            'email' => 'other.employee@opsflow.test',
        ]);

        $this->accessibleProject = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Accessible Project',
        ]);
        $this->accessibleProject->members()->attach($this->employee->id, [
            'joined_at' => now(),
        ]);

        $this->inaccessibleProject = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Hidden Project',
        ]);

        $this->accessibleTask = Task::factory()->create([
            'project_id' => $this->accessibleProject->id,
            'created_by' => $this->administrator->id,
            'title' => 'Accessible Task',
        ]);
        $this->inaccessibleTask = Task::factory()->create([
            'project_id' => $this->inaccessibleProject->id,
            'created_by' => $this->administrator->id,
            'title' => 'Hidden Task',
        ]);

        ActivityLog::factory()->create([
            'actor_id' => $this->administrator->id,
            'action' => ActivityAction::ProjectCreated,
            'subject_type' => $this->accessibleProject->getMorphClass(),
            'subject_id' => $this->accessibleProject->id,
            'description' => 'Created project Accessible Project.',
            'properties' => ['status' => 'planning'],
            'created_at' => now()->subHours(3),
        ]);
        ActivityLog::factory()->create([
            'actor_id' => $this->projectManager->id,
            'action' => ActivityAction::TaskCreated,
            'subject_type' => $this->accessibleTask->getMorphClass(),
            'subject_id' => $this->accessibleTask->id,
            'description' => 'Created task Accessible Task.',
            'properties' => ['project_id' => $this->accessibleProject->id],
            'created_at' => now()->subHours(2),
        ]);
        ActivityLog::factory()->create([
            'actor_id' => $this->administrator->id,
            'action' => ActivityAction::ProjectCreated,
            'subject_type' => $this->inaccessibleProject->getMorphClass(),
            'subject_id' => $this->inaccessibleProject->id,
            'description' => 'Created project Hidden Project.',
            'properties' => ['status' => 'planning'],
            'created_at' => now()->subHour(),
        ]);
        ActivityLog::factory()->create([
            'actor_id' => $this->administrator->id,
            'action' => ActivityAction::UserCreated,
            'subject_type' => $this->employee->getMorphClass(),
            'subject_id' => $this->employee->id,
            'description' => 'Created user Eli Employee.',
            'properties' => ['email' => $this->employee->email],
            'created_at' => now()->subMinutes(30),
        ]);
        ActivityLog::factory()->create([
            'actor_id' => $this->administrator->id,
            'action' => ActivityAction::UserCreated,
            'subject_type' => $this->otherEmployee->getMorphClass(),
            'subject_id' => $this->otherEmployee->id,
            'description' => 'Created user Ora Other.',
            'properties' => ['email' => $this->otherEmployee->email],
            'created_at' => now()->subMinutes(10),
        ]);
    }

    public function test_administrator_can_list_global_activity(): void
    {
        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/activity-logs');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Activity logs retrieved successfully.')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('data.0.action', ActivityAction::UserCreated->value)
            ->assertJsonPath('data.0.actor.full_name', 'Ada Admin')
            ->assertJsonPath('data.0.subject.type', 'user')
            ->assertJsonMissingPath('data.0.properties.actor_snapshot');
    }

    public function test_project_manager_can_list_global_activity(): void
    {
        $this->actingAs($this->projectManager)
            ->getJson('/api/v1/activity-logs')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 5);
    }

    public function test_employee_cannot_list_global_activity(): void
    {
        $this->actingAs($this->employee)
            ->getJson('/api/v1/activity-logs')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_guest_cannot_list_activity(): void
    {
        $this->getJson('/api/v1/activity-logs')->assertUnauthorized();
    }

    public function test_activity_list_is_paginated(): void
    {
        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/activity-logs?per_page=2&page=2');

        $response->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 5);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_activity_list_can_filter_by_action_and_subject(): void
    {
        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/activity-logs?'.http_build_query([
                'action' => ActivityAction::ProjectCreated->value,
                'subject_type' => 'project',
                'subject_id' => $this->accessibleProject->id,
            ]));

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', ActivityAction::ProjectCreated->value)
            ->assertJsonPath('data.0.subject_id', $this->accessibleProject->id)
            ->assertJsonPath('data.0.subject.name', 'Accessible Project');
    }

    public function test_activity_list_can_filter_by_actor(): void
    {
        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/activity-logs?actor_id='.$this->projectManager->id);

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.actor.id', $this->projectManager->id)
            ->assertJsonPath('data.0.action', ActivityAction::TaskCreated->value);
    }

    public function test_nested_project_timeline_returns_only_that_subject(): void
    {
        $response = $this->actingAs($this->administrator)
            ->getJson("/api/v1/projects/{$this->accessibleProject->id}/activity-logs");

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subject_type', 'project')
            ->assertJsonPath('data.0.subject_id', $this->accessibleProject->id);
    }

    public function test_nested_task_timeline_returns_only_that_subject(): void
    {
        $response = $this->actingAs($this->employee)
            ->getJson("/api/v1/tasks/{$this->accessibleTask->id}/activity-logs");

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subject_type', 'task')
            ->assertJsonPath('data.0.subject.title', 'Accessible Task');
    }

    public function test_assignment_activity_enriches_missing_assignee_names_for_legacy_logs(): void
    {
        $previousAssignee = User::factory()->create([
            'first_name' => 'Renz',
            'last_name' => 'Patalay',
            'email' => 'renz@opsflow.test',
        ]);
        $nextAssignee = User::factory()->create([
            'first_name' => 'Mark',
            'middle_name' => 'Middle',
            'last_name' => 'Dela Cruz',
            'email' => 'mark@opsflow.test',
        ]);

        ActivityLog::factory()->create([
            'actor_id' => $this->administrator->id,
            'action' => ActivityAction::TaskAssigned,
            'subject_type' => $this->accessibleTask->getMorphClass(),
            'subject_id' => $this->accessibleTask->id,
            'description' => 'Assigned task Accessible Task to Mark Middle Dela Cruz.',
            'properties' => [
                'before' => ['assigned_to' => $previousAssignee->id],
                'after' => ['assigned_to' => $nextAssignee->id],
                'project_id' => $this->accessibleProject->id,
            ],
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->administrator)
            ->getJson("/api/v1/tasks/{$this->accessibleTask->id}/activity-logs");

        $response->assertOk()
            ->assertJsonPath('data.0.action', ActivityAction::TaskAssigned->value)
            ->assertJsonPath('data.0.properties.before.assigned_to', $previousAssignee->id)
            ->assertJsonPath('data.0.properties.before.assigned_to_name', 'Renz Patalay')
            ->assertJsonPath('data.0.properties.after.assigned_to', $nextAssignee->id)
            ->assertJsonPath('data.0.properties.after.assigned_to_name', 'Mark Middle Dela Cruz');
    }

    public function test_employee_can_view_own_user_timeline_only(): void
    {
        $this->actingAs($this->employee)
            ->getJson("/api/v1/users/{$this->employee->id}/activity-logs")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subject_id', $this->employee->id);

        $this->actingAs($this->employee)
            ->getJson("/api/v1/users/{$this->otherEmployee->id}/activity-logs")
            ->assertForbidden();
    }

    public function test_employee_cannot_view_inaccessible_project_or_task_activity(): void
    {
        $this->actingAs($this->employee)
            ->getJson("/api/v1/projects/{$this->inaccessibleProject->id}/activity-logs")
            ->assertForbidden();

        $this->actingAs($this->employee)
            ->getJson("/api/v1/tasks/{$this->inaccessibleTask->id}/activity-logs")
            ->assertForbidden();
    }

    public function test_empty_timeline_returns_empty_data(): void
    {
        $emptyProject = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Quiet Project',
        ]);

        $response = $this->actingAs($this->administrator)
            ->getJson("/api/v1/projects/{$emptyProject->id}/activity-logs");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_activity_logs_have_no_mutation_endpoints(): void
    {
        $log = ActivityLog::query()->firstOrFail();

        $this->actingAs($this->administrator)
            ->postJson('/api/v1/activity-logs', [])
            ->assertMethodNotAllowed();

        $this->actingAs($this->administrator)
            ->deleteJson("/api/v1/activity-logs/{$log->id}")
            ->assertNotFound();
    }
}
