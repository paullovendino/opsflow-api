<?php

declare(strict_types=1);

namespace Tests\Feature\Task;

use App\Enums\RoleName;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskAssignmentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $this->actor = User::factory()->create([
            'role_id' => $adminRole->id,
            'first_name' => 'Ada',
            'last_name' => 'Admin',
            'email' => 'ada.admin@opsflow.test',
        ]);
        $this->project = Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'OpsFlow Launch',
        ]);
        $this->task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'title' => 'Assignable Task',
            'status' => TaskStatus::Todo,
            'assigned_to' => null,
        ]);
    }

    public function test_authenticated_user_can_assign_project_owner(): void
    {
        $response = $this->actingAs($this->actor)
            ->patchJson("/api/v1/tasks/{$this->task->id}/assignment", [
                'assigned_to' => $this->actor->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Task assignment updated successfully.')
            ->assertJsonPath('data.assignee.id', $this->actor->id)
            ->assertJsonPath('data.assignee.email', 'ada.admin@opsflow.test')
            ->assertJsonPath('data.status', TaskStatus::Todo->value)
            ->assertJsonPath('data.creator.id', $this->actor->id)
            ->assertJsonPath('data.project.id', $this->project->id);

        $this->assertDatabaseHas('tasks', [
            'id' => $this->task->id,
            'assigned_to' => $this->actor->id,
            'status' => TaskStatus::Todo->value,
            'created_by' => $this->actor->id,
            'project_id' => $this->project->id,
        ]);
    }

    public function test_authenticated_user_can_assign_project_member(): void
    {
        $member = User::factory()->create([
            'first_name' => 'Sam',
            'last_name' => 'Lee',
            'email' => 'sam@example.com',
        ]);
        $this->project->members()->attach($member->id, [
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($this->actor)
            ->patchJson("/api/v1/tasks/{$this->task->id}/assignment", [
                'assigned_to' => $member->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.assignee.id', $member->id)
            ->assertJsonPath('data.assignee.full_name', 'Sam Lee')
            ->assertJsonPath('data.assignee.email', 'sam@example.com');

        $this->assertDatabaseHas('tasks', [
            'id' => $this->task->id,
            'assigned_to' => $member->id,
        ]);
    }

    public function test_authenticated_user_can_clear_assignment(): void
    {
        $member = User::factory()->create();
        $this->project->members()->attach($member->id, [
            'joined_at' => now(),
        ]);
        $this->task->update(['assigned_to' => $member->id]);

        $response = $this->actingAs($this->actor)
            ->patchJson("/api/v1/tasks/{$this->task->id}/assignment", [
                'assigned_to' => null,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.assignee', null);

        $this->assertDatabaseHas('tasks', [
            'id' => $this->task->id,
            'assigned_to' => null,
        ]);
    }

    public function test_assignment_replaces_previous_assignee(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->project->members()->attach([$first->id, $second->id], [
            'joined_at' => now(),
        ]);
        $this->task->update(['assigned_to' => $first->id]);

        $response = $this->actingAs($this->actor)
            ->patchJson("/api/v1/tasks/{$this->task->id}/assignment", [
                'assigned_to' => $second->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.assignee.id', $second->id);

        $this->assertDatabaseHas('tasks', [
            'id' => $this->task->id,
            'assigned_to' => $second->id,
        ]);
    }

    public function test_assignment_rejects_non_owner_non_member(): void
    {
        $outsider = User::factory()->create();

        $response = $this->actingAs($this->actor)
            ->patchJson("/api/v1/tasks/{$this->task->id}/assignment", [
                'assigned_to' => $outsider->id,
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'errors' => [
                    'assigned_to',
                ],
            ]);

        $this->assertNull($this->task->fresh()->assigned_to);
    }

    public function test_assignment_rejects_inactive_user(): void
    {
        $inactive = User::factory()->create([
            'status' => UserStatus::Inactive,
        ]);
        $this->project->members()->attach($inactive->id, [
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($this->actor)
            ->patchJson("/api/v1/tasks/{$this->task->id}/assignment", [
                'assigned_to' => $inactive->id,
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'errors' => [
                    'assigned_to',
                ],
            ]);
    }

    public function test_assignment_rejects_soft_deleted_user(): void
    {
        $deleted = User::factory()->create();
        $this->project->members()->attach($deleted->id, [
            'joined_at' => now(),
        ]);
        $deletedId = $deleted->id;
        $deleted->delete();

        $response = $this->actingAs($this->actor)
            ->patchJson("/api/v1/tasks/{$this->task->id}/assignment", [
                'assigned_to' => $deletedId,
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'errors' => [
                    'assigned_to',
                ],
            ]);
    }

    public function test_assignment_requires_assigned_to_key(): void
    {
        $response = $this->actingAs($this->actor)
            ->patchJson("/api/v1/tasks/{$this->task->id}/assignment", []);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'errors' => [
                    'assigned_to',
                ],
            ]);
    }

    public function test_assignment_does_not_change_status_creator_or_project(): void
    {
        $member = User::factory()->create();
        $this->project->members()->attach($member->id, [
            'joined_at' => now(),
        ]);
        $this->task->update([
            'status' => TaskStatus::InProgress,
            'title' => 'Keep Title',
        ]);

        $response = $this->actingAs($this->actor)
            ->patchJson("/api/v1/tasks/{$this->task->id}/assignment", [
                'assigned_to' => $member->id,
                'status' => TaskStatus::Completed->value,
                'title' => 'Should Not Change',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.assignee.id', $member->id)
            ->assertJsonPath('data.status', TaskStatus::InProgress->value)
            ->assertJsonPath('data.title', 'Keep Title')
            ->assertJsonPath('data.creator.id', $this->actor->id)
            ->assertJsonPath('data.project.id', $this->project->id);

        $fresh = $this->task->fresh();
        $this->assertSame(TaskStatus::InProgress, $fresh->status);
        $this->assertSame('Keep Title', $fresh->title);
        $this->assertSame($this->actor->id, $fresh->created_by);
        $this->assertSame($this->project->id, $fresh->project_id);
    }

    public function test_guest_cannot_update_assignment(): void
    {
        $this->patchJson("/api/v1/tasks/{$this->task->id}/assignment", [
            'assigned_to' => $this->actor->id,
        ])->assertUnauthorized();
    }
}
