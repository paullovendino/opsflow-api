<?php

declare(strict_types=1);

namespace Tests\Feature\Task;

use App\Enums\RoleName;
use App\Enums\TaskPriority;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Policies\TaskPolicy;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;

    private Role $projectManagerRole;

    private Role $employeeRole;

    private User $administrator;

    private User $projectManager;

    private User $employee;

    private User $otherEmployee;

    private Project $unrelatedProject;

    private Task $unrelatedTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $this->adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $this->projectManagerRole = Role::query()->where('name', RoleName::ProjectManager)->firstOrFail();
        $this->employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();

        $this->administrator = User::factory()->create([
            'role_id' => $this->adminRole->id,
            'email' => 'admin@opsflow.test',
        ]);
        $this->projectManager = User::factory()->create([
            'role_id' => $this->projectManagerRole->id,
            'email' => 'pm@opsflow.test',
        ]);
        $this->employee = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'email' => 'employee@opsflow.test',
        ]);
        $this->otherEmployee = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'email' => 'other.employee@opsflow.test',
        ]);

        $this->unrelatedProject = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Unrelated Project',
        ]);
        $this->unrelatedTask = Task::factory()->create([
            'project_id' => $this->unrelatedProject->id,
            'created_by' => $this->administrator->id,
            'title' => 'Unrelated Task',
        ]);
    }

    public function test_administrator_has_full_task_management_access(): void
    {
        $this->actingAs($this->administrator)
            ->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($this->administrator)
            ->getJson("/api/v1/tasks/{$this->unrelatedTask->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->unrelatedTask->id);

        $created = $this->actingAs($this->administrator)
            ->postJson('/api/v1/tasks', $this->validPayload([
                'title' => 'Admin Created Task',
            ]));
        $created->assertCreated()
            ->assertJsonPath('data.title', 'Admin Created Task');

        $taskId = $created->json('data.id');

        $this->actingAs($this->administrator)
            ->putJson("/api/v1/tasks/{$taskId}", $this->validUpdatePayload([
                'title' => 'Admin Updated Task',
            ]))
            ->assertOk()
            ->assertJsonPath('data.title', 'Admin Updated Task');

        $this->unrelatedProject->members()->attach($this->otherEmployee->id, [
            'joined_at' => now(),
        ]);

        $this->actingAs($this->administrator)
            ->patchJson("/api/v1/tasks/{$taskId}/assignment", [
                'assigned_to' => $this->otherEmployee->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $this->otherEmployee->id);

        $this->actingAs($this->administrator)
            ->deleteJson("/api/v1/tasks/{$taskId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('tasks', ['id' => $taskId]);
    }

    public function test_project_manager_has_full_task_management_access(): void
    {
        $this->actingAs($this->projectManager)
            ->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($this->projectManager)
            ->getJson("/api/v1/tasks/{$this->unrelatedTask->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->unrelatedTask->id);

        $created = $this->actingAs($this->projectManager)
            ->postJson('/api/v1/tasks', $this->validPayload([
                'title' => 'PM Created Task',
            ]));
        $created->assertCreated()
            ->assertJsonPath('data.creator.id', $this->projectManager->id);

        $taskId = $created->json('data.id');

        $this->actingAs($this->projectManager)
            ->putJson("/api/v1/tasks/{$taskId}", $this->validUpdatePayload([
                'title' => 'PM Updated Task',
            ]))
            ->assertOk()
            ->assertJsonPath('data.title', 'PM Updated Task');

        $this->unrelatedProject->members()->attach($this->employee->id, [
            'joined_at' => now(),
        ]);

        $this->actingAs($this->projectManager)
            ->patchJson("/api/v1/tasks/{$taskId}/assignment", [
                'assigned_to' => $this->employee->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $this->employee->id);

        $this->actingAs($this->projectManager)
            ->deleteJson("/api/v1/tasks/{$taskId}")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_employee_can_list_and_view_tasks_in_owned_projects(): void
    {
        $ownedProject = Project::factory()->create([
            'created_by' => $this->employee->id,
            'name' => 'Owned By Employee',
        ]);
        $ownedTask = Task::factory()->create([
            'project_id' => $ownedProject->id,
            'created_by' => $this->administrator->id,
            'title' => 'Task In Owned Project',
        ]);

        $list = $this->actingAs($this->employee)
            ->getJson('/api/v1/tasks');
        $list->assertOk();
        $ids = collect($list->json('data'))->pluck('id')->all();
        $this->assertContains($ownedTask->id, $ids);
        $this->assertNotContains($this->unrelatedTask->id, $ids);

        $this->actingAs($this->employee)
            ->getJson("/api/v1/tasks/{$ownedTask->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $ownedTask->id)
            ->assertJsonPath('success', true);
    }

    public function test_employee_can_list_and_view_tasks_in_member_projects(): void
    {
        $memberProject = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Member Project',
        ]);
        $memberProject->members()->attach($this->employee->id, [
            'joined_at' => now(),
        ]);
        $memberTask = Task::factory()->create([
            'project_id' => $memberProject->id,
            'created_by' => $this->administrator->id,
            'title' => 'Task In Member Project',
        ]);

        $list = $this->actingAs($this->employee)
            ->getJson('/api/v1/tasks');
        $list->assertOk();
        $ids = collect($list->json('data'))->pluck('id')->all();
        $this->assertContains($memberTask->id, $ids);
        $this->assertNotContains($this->unrelatedTask->id, $ids);

        $this->actingAs($this->employee)
            ->getJson("/api/v1/tasks/{$memberTask->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $memberTask->id);
    }

    public function test_employee_denied_for_tasks_in_unrelated_projects(): void
    {
        $this->actingAs($this->employee)
            ->getJson("/api/v1/tasks/{$this->unrelatedTask->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized.')
            ->assertJsonPath('errors', null);
    }

    public function test_employee_cannot_create_update_delete_or_assign(): void
    {
        $ownedProject = Project::factory()->create([
            'created_by' => $this->employee->id,
            'name' => 'Owned But Locked',
        ]);
        $ownedTask = Task::factory()->create([
            'project_id' => $ownedProject->id,
            'created_by' => $this->administrator->id,
            'title' => 'Locked Task',
        ]);

        $this->actingAs($this->employee)
            ->postJson('/api/v1/tasks', [
                'project_id' => $ownedProject->id,
                'title' => 'Employee Cannot Create',
                'priority' => TaskPriority::Medium->value,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized.');

        $this->actingAs($this->employee)
            ->putJson("/api/v1/tasks/{$ownedTask->id}", $this->validUpdatePayload([
                'title' => 'Employee Cannot Update',
            ]))
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->employee)
            ->patchJson("/api/v1/tasks/{$ownedTask->id}/assignment", [
                'assigned_to' => $this->employee->id,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->employee)
            ->deleteJson("/api/v1/tasks/{$ownedTask->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->employee)
            ->putJson("/api/v1/tasks/{$this->unrelatedTask->id}", $this->validUpdatePayload([
                'title' => 'Still Forbidden',
            ]))
            ->assertForbidden();
    }

    public function test_employee_update_status_ability_requires_assignment_to_self(): void
    {
        $policy = new TaskPolicy;

        $ownedProject = Project::factory()->create([
            'created_by' => $this->employee->id,
        ]);
        $assignedTask = Task::factory()->create([
            'project_id' => $ownedProject->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->employee->id,
        ]);
        $unassignedTask = Task::factory()->create([
            'project_id' => $ownedProject->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => null,
        ]);
        $assignedToOther = Task::factory()->create([
            'project_id' => $ownedProject->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->otherEmployee->id,
        ]);

        $this->assertTrue($policy->updateStatus($this->employee, $assignedTask));
        $this->assertFalse($policy->updateStatus($this->employee, $unassignedTask));
        $this->assertFalse($policy->updateStatus($this->employee, $assignedToOther));
        $this->assertFalse($policy->updateStatus($this->employee, $this->unrelatedTask));

        $this->assertTrue($policy->updateStatus($this->administrator, $this->unrelatedTask));
        $this->assertTrue($policy->updateStatus($this->projectManager, $this->unrelatedTask));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'project_id' => $this->unrelatedProject->id,
            'title' => 'Authorization Task',
            'description' => 'Authorization test task',
            'priority' => TaskPriority::Medium->value,
            'due_date' => '2026-08-15',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validUpdatePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Updated Authorization Task',
            'description' => 'Updated description',
            'priority' => TaskPriority::High->value,
            'due_date' => '2026-09-01',
        ], $overrides);
    }
}
