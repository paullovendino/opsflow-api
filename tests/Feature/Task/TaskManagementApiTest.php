<?php

declare(strict_types=1);

namespace Tests\Feature\Task;

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
use Tests\TestCase;

class TaskManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Project $project;

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
    }

    public function test_authenticated_user_can_create_task(): void
    {
        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/tasks', $this->validPayload([
                'title' => 'Draft API contract',
            ]));

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Task created successfully.')
            ->assertJsonPath('data.title', 'Draft API contract')
            ->assertJsonPath('data.description', 'Document task endpoints')
            ->assertJsonPath('data.status', TaskStatus::Todo->value)
            ->assertJsonPath('data.priority', TaskPriority::High->value)
            ->assertJsonPath('data.due_date', '2026-08-15')
            ->assertJsonPath('data.project.id', $this->project->id)
            ->assertJsonPath('data.project.name', 'OpsFlow Launch')
            ->assertJsonPath('data.creator.id', $this->actor->id)
            ->assertJsonPath('data.creator.email', 'ada.admin@opsflow.test')
            ->assertJsonPath('data.assignee', null)
            ->assertJsonMissingPath('data.created_by')
            ->assertJsonMissingPath('data.assigned_to')
            ->assertJsonMissingPath('data.project_id');

        $this->assertDatabaseHas('tasks', [
            'title' => 'Draft API contract',
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'status' => TaskStatus::Todo->value,
            'priority' => TaskPriority::High->value,
            'assigned_to' => null,
        ]);
    }

    public function test_create_assigns_authenticated_user_as_creator_and_ignores_client_created_by(): void
    {
        $other = User::factory()->create();

        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/tasks', $this->validPayload([
                'title' => 'Created By Actor',
                'created_by' => $other->id,
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.creator.id', $this->actor->id);

        $this->assertDatabaseHas('tasks', [
            'title' => 'Created By Actor',
            'created_by' => $this->actor->id,
        ]);
        $this->assertDatabaseMissing('tasks', [
            'title' => 'Created By Actor',
            'created_by' => $other->id,
        ]);
    }

    public function test_create_defaults_status_to_todo_even_if_status_supplied(): void
    {
        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/tasks', $this->validPayload([
                'title' => 'Status Ignored On Create',
                'status' => TaskStatus::Completed->value,
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.status', TaskStatus::Todo->value);

        $this->assertDatabaseHas('tasks', [
            'title' => 'Status Ignored On Create',
            'status' => TaskStatus::Todo->value,
        ]);
    }

    public function test_create_defaults_priority_to_medium_when_omitted(): void
    {
        $payload = $this->validPayload([
            'title' => 'Default Priority',
        ]);
        unset($payload['priority']);

        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/tasks', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.priority', TaskPriority::Medium->value);

        $this->assertDatabaseHas('tasks', [
            'title' => 'Default Priority',
            'priority' => TaskPriority::Medium->value,
        ]);
    }

    public function test_create_can_assign_project_owner(): void
    {
        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/tasks', $this->validPayload([
                'title' => 'Owner Assigned',
                'assigned_to' => $this->actor->id,
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.assignee.id', $this->actor->id);

        $this->assertDatabaseHas('tasks', [
            'title' => 'Owner Assigned',
            'assigned_to' => $this->actor->id,
        ]);
    }

    public function test_create_can_assign_project_member(): void
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
            ->postJson('/api/v1/tasks', $this->validPayload([
                'title' => 'Member Assigned',
                'assigned_to' => $member->id,
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.assignee.id', $member->id)
            ->assertJsonPath('data.assignee.full_name', 'Sam Lee')
            ->assertJsonPath('data.assignee.email', 'sam@example.com');
    }

    public function test_create_rejects_assignee_who_is_not_owner_or_member(): void
    {
        $outsider = User::factory()->create();

        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/tasks', $this->validPayload([
                'title' => 'Invalid Assignee',
                'assigned_to' => $outsider->id,
            ]));

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'errors' => [
                    'assigned_to',
                ],
            ]);
    }

    public function test_create_rejects_inactive_assignee(): void
    {
        $inactive = User::factory()->create([
            'status' => UserStatus::Inactive,
        ]);
        $this->project->members()->attach($inactive->id, [
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/tasks', $this->validPayload([
                'title' => 'Inactive Assignee',
                'assigned_to' => $inactive->id,
            ]));

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'errors' => [
                    'assigned_to',
                ],
            ]);
    }

    public function test_store_validation_errors_are_returned(): void
    {
        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/tasks', []);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonStructure([
                'errors' => [
                    'project_id',
                    'title',
                ],
            ]);
    }

    public function test_authenticated_user_can_list_tasks(): void
    {
        Task::factory()->count(2)->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Tasks retrieved successfully.')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonStructure([
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                    'from',
                    'to',
                ],
            ]);

        $this->assertCount(2, $response->json('data'));
        $this->assertIsArray($response->json('data.0.project'));
        $this->assertIsArray($response->json('data.0.creator'));
        $this->assertArrayHasKey('assignee', $response->json('data.0'));
    }

    public function test_list_excludes_soft_deleted_tasks(): void
    {
        $visible = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'title' => 'Visible Task',
        ]);
        $deleted = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'title' => 'Deleted Task',
        ]);
        $deleted->delete();

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($visible->id, $response->json('data.0.id'));
    }

    public function test_authenticated_user_can_show_task(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'title' => 'Visible Task',
            'status' => TaskStatus::InProgress,
            'priority' => TaskPriority::Urgent,
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson("/api/v1/tasks/{$task->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Task retrieved successfully.')
            ->assertJsonPath('data.id', $task->id)
            ->assertJsonPath('data.title', 'Visible Task')
            ->assertJsonPath('data.status', TaskStatus::InProgress->value)
            ->assertJsonPath('data.priority', TaskPriority::Urgent->value)
            ->assertJsonPath('data.project.id', $this->project->id)
            ->assertJsonPath('data.creator.id', $this->actor->id);
    }

    public function test_authenticated_user_can_update_task_fields_but_not_status_assignment_or_project(): void
    {
        $other = User::factory()->create();
        $otherProject = Project::factory()->create([
            'created_by' => $this->actor->id,
        ]);
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'title' => 'Before',
            'description' => 'Old description',
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Low,
            'due_date' => '2026-01-01',
            'assigned_to' => null,
        ]);

        $response = $this->actingAs($this->actor)
            ->putJson("/api/v1/tasks/{$task->id}", [
                'title' => 'After',
                'description' => 'New description',
                'priority' => TaskPriority::High->value,
                'due_date' => '2026-09-01',
                'status' => TaskStatus::Completed->value,
                'assigned_to' => $other->id,
                'project_id' => $otherProject->id,
                'created_by' => $other->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Task updated successfully.')
            ->assertJsonPath('data.title', 'After')
            ->assertJsonPath('data.description', 'New description')
            ->assertJsonPath('data.priority', TaskPriority::High->value)
            ->assertJsonPath('data.due_date', '2026-09-01')
            ->assertJsonPath('data.status', TaskStatus::Todo->value)
            ->assertJsonPath('data.project.id', $this->project->id)
            ->assertJsonPath('data.creator.id', $this->actor->id)
            ->assertJsonPath('data.assignee', null);

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Todo, $fresh->status);
        $this->assertSame($this->project->id, $fresh->project_id);
        $this->assertSame($this->actor->id, $fresh->created_by);
        $this->assertNull($fresh->assigned_to);
    }

    public function test_update_validation_errors_are_returned(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
        ]);

        $response = $this->actingAs($this->actor)
            ->putJson("/api/v1/tasks/{$task->id}", []);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'errors' => [
                    'title',
                    'priority',
                ],
            ]);
    }

    public function test_authenticated_user_can_soft_delete_task(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
        ]);

        $response = $this->actingAs($this->actor)
            ->deleteJson("/api/v1/tasks/{$task->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Task deleted successfully.');

        $this->assertSoftDeleted($task);
    }

    public function test_guest_cannot_access_task_endpoints(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
        ]);

        $this->getJson('/api/v1/tasks')->assertUnauthorized();
        $this->postJson('/api/v1/tasks', $this->validPayload())->assertUnauthorized();
        $this->getJson("/api/v1/tasks/{$task->id}")->assertUnauthorized();
        $this->putJson("/api/v1/tasks/{$task->id}", [
            'title' => 'Nope',
            'priority' => TaskPriority::Medium->value,
        ])->assertUnauthorized();
        $this->deleteJson("/api/v1/tasks/{$task->id}")->assertUnauthorized();
    }

    public function test_task_resource_shape(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'title' => 'Resource Shape',
            'description' => null,
            'status' => TaskStatus::Blocked,
            'priority' => TaskPriority::Urgent,
            'due_date' => null,
            'assigned_to' => null,
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson("/api/v1/tasks/{$task->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'title',
                    'description',
                    'status',
                    'priority',
                    'due_date',
                    'is_overdue',
                    'project' => [
                        'id',
                        'name',
                    ],
                    'assignee',
                    'creator' => [
                        'id',
                        'first_name',
                        'middle_name',
                        'last_name',
                        'full_name',
                        'email',
                    ],
                    'created_at',
                    'updated_at',
                ],
                'errors',
                'meta',
            ])
            ->assertJsonPath('data.status', TaskStatus::Blocked->value)
            ->assertJsonPath('data.assignee', null)
            ->assertJsonMissingPath('data.created_by')
            ->assertJsonMissingPath('data.project_id')
            ->assertJsonMissingPath('data.assigned_to');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'project_id' => $this->project->id,
            'title' => 'Draft API contract',
            'description' => 'Document task endpoints',
            'priority' => TaskPriority::High->value,
            'due_date' => '2026-08-15',
        ], $overrides);
    }
}
