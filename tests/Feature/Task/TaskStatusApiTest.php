<?php

declare(strict_types=1);

namespace Tests\Feature\Task;

use App\Enums\RoleName;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskStatusApiTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private User $employee;

    private User $otherEmployee;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();

        $this->administrator = User::factory()->create([
            'role_id' => $adminRole->id,
            'email' => 'admin@opsflow.test',
        ]);
        $this->employee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'email' => 'employee@opsflow.test',
        ]);
        $this->otherEmployee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'email' => 'other.employee@opsflow.test',
        ]);

        $this->project = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Status Project',
        ]);
        $this->project->members()->attach($this->employee->id, [
            'joined_at' => now(),
        ]);

        $this->task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->administrator->id,
            'title' => 'Status Target',
            'description' => 'Keep me',
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Medium,
            'due_date' => '2026-08-15',
            'assigned_to' => $this->employee->id,
        ]);
    }

    public function test_authenticated_admin_can_update_status_only(): void
    {
        $response = $this->actingAs($this->administrator)
            ->patchJson("/api/v1/tasks/{$this->task->id}/status", [
                'status' => TaskStatus::InProgress->value,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Task status updated successfully.')
            ->assertJsonPath('data.status', TaskStatus::InProgress->value)
            ->assertJsonPath('data.title', 'Status Target')
            ->assertJsonPath('data.description', 'Keep me')
            ->assertJsonPath('data.priority', TaskPriority::Medium->value)
            ->assertJsonPath('data.due_date', '2026-08-15')
            ->assertJsonPath('data.assignee.id', $this->employee->id)
            ->assertJsonPath('data.project.id', $this->project->id);

        $fresh = $this->task->fresh();
        $this->assertSame(TaskStatus::InProgress, $fresh->status);
        $this->assertSame('Status Target', $fresh->title);
        $this->assertSame('Keep me', $fresh->description);
        $this->assertSame(TaskPriority::Medium, $fresh->priority);
        $this->assertSame($this->employee->id, $fresh->assigned_to);
        $this->assertSame($this->project->id, $fresh->project_id);
    }

    public function test_any_task_status_value_is_accepted(): void
    {
        foreach (TaskStatus::cases() as $status) {
            $response = $this->actingAs($this->administrator)
                ->patchJson("/api/v1/tasks/{$this->task->id}/status", [
                    'status' => $status->value,
                ]);

            $response->assertOk()
                ->assertJsonPath('data.status', $status->value);

            $this->assertSame($status, $this->task->fresh()->status);
        }
    }

    public function test_status_update_rejects_invalid_values(): void
    {
        $response = $this->actingAs($this->administrator)
            ->patchJson("/api/v1/tasks/{$this->task->id}/status", [
                'status' => 'not-a-status',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'errors' => [
                    'status',
                ],
            ]);
    }

    public function test_create_and_update_remain_status_free(): void
    {
        $created = $this->actingAs($this->administrator)
            ->postJson('/api/v1/tasks', [
                'project_id' => $this->project->id,
                'title' => 'Status Ignored On Create',
                'priority' => TaskPriority::Low->value,
                'status' => TaskStatus::Completed->value,
            ]);

        $created->assertCreated()
            ->assertJsonPath('data.status', TaskStatus::Todo->value);

        $taskId = $created->json('data.id');

        $updated = $this->actingAs($this->administrator)
            ->putJson("/api/v1/tasks/{$taskId}", [
                'title' => 'Status Ignored On Update',
                'description' => 'Still todo',
                'priority' => TaskPriority::High->value,
                'status' => TaskStatus::Cancelled->value,
            ]);

        $updated->assertOk()
            ->assertJsonPath('data.status', TaskStatus::Todo->value)
            ->assertJsonPath('data.title', 'Status Ignored On Update');

        $this->assertSame(TaskStatus::Todo, Task::query()->findOrFail($taskId)->status);
    }

    public function test_employee_assigned_to_self_can_update_status(): void
    {
        $response = $this->actingAs($this->employee)
            ->patchJson("/api/v1/tasks/{$this->task->id}/status", [
                'status' => TaskStatus::InReview->value,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', TaskStatus::InReview->value);
    }

    public function test_employee_cannot_update_status_when_unassigned(): void
    {
        $this->task->update(['assigned_to' => null]);

        $this->actingAs($this->employee)
            ->patchJson("/api/v1/tasks/{$this->task->id}/status", [
                'status' => TaskStatus::InProgress->value,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized.');
    }

    public function test_employee_cannot_update_status_when_assigned_to_other(): void
    {
        $this->project->members()->attach($this->otherEmployee->id, [
            'joined_at' => now(),
        ]);
        $this->task->update(['assigned_to' => $this->otherEmployee->id]);

        $this->actingAs($this->employee)
            ->patchJson("/api/v1/tasks/{$this->task->id}/status", [
                'status' => TaskStatus::Blocked->value,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_employee_cannot_update_status_for_inaccessible_project(): void
    {
        $otherProject = Project::factory()->create([
            'created_by' => $this->administrator->id,
        ]);
        $inaccessible = Task::factory()->create([
            'project_id' => $otherProject->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->employee->id,
        ]);

        $this->actingAs($this->employee)
            ->patchJson("/api/v1/tasks/{$inaccessible->id}/status", [
                'status' => TaskStatus::Completed->value,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_guest_cannot_update_task_status(): void
    {
        $this->patchJson("/api/v1/tasks/{$this->task->id}/status", [
            'status' => TaskStatus::InProgress->value,
        ])->assertUnauthorized();
    }
}
