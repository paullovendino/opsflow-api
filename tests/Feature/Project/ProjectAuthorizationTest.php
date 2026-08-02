<?php

declare(strict_types=1);

namespace Tests\Feature\Project;

use App\Enums\ProjectStatus;
use App\Enums\RoleName;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectAuthorizationTest extends TestCase
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
    }

    public function test_administrator_has_full_project_management_access(): void
    {
        $this->actingAs($this->administrator)
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($this->administrator)
            ->getJson("/api/v1/projects/{$this->unrelatedProject->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->unrelatedProject->id);

        $created = $this->actingAs($this->administrator)
            ->postJson('/api/v1/projects', $this->validPayload([
                'name' => 'Admin Created Project',
            ]));
        $created->assertCreated()
            ->assertJsonPath('data.name', 'Admin Created Project');

        $projectId = $created->json('data.id');

        $this->actingAs($this->administrator)
            ->putJson("/api/v1/projects/{$projectId}", $this->validPayload([
                'name' => 'Admin Updated Project',
            ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Admin Updated Project');

        $this->actingAs($this->administrator)
            ->patchJson("/api/v1/projects/{$projectId}/status", [
                'status' => ProjectStatus::Active->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ProjectStatus::Active->value);

        $this->actingAs($this->administrator)
            ->postJson("/api/v1/projects/{$projectId}/members", [
                'user_id' => $this->otherEmployee->id,
            ])
            ->assertCreated();

        $this->actingAs($this->administrator)
            ->getJson("/api/v1/projects/{$projectId}/members")
            ->assertOk();

        $this->actingAs($this->administrator)
            ->deleteJson("/api/v1/projects/{$projectId}/members/{$this->otherEmployee->id}")
            ->assertOk();

        $this->actingAs($this->administrator)
            ->deleteJson("/api/v1/projects/{$projectId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('projects', ['id' => $projectId]);
    }

    public function test_project_manager_has_full_project_management_access(): void
    {
        $this->actingAs($this->projectManager)
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($this->projectManager)
            ->getJson("/api/v1/projects/{$this->unrelatedProject->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->unrelatedProject->id);

        $created = $this->actingAs($this->projectManager)
            ->postJson('/api/v1/projects', $this->validPayload([
                'name' => 'PM Created Project',
            ]));
        $created->assertCreated()
            ->assertJsonPath('data.owner.id', $this->projectManager->id);

        $projectId = $created->json('data.id');

        $this->actingAs($this->projectManager)
            ->putJson("/api/v1/projects/{$projectId}", $this->validPayload([
                'name' => 'PM Updated Project',
            ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'PM Updated Project');

        $this->actingAs($this->projectManager)
            ->patchJson("/api/v1/projects/{$projectId}/status", [
                'status' => ProjectStatus::OnHold->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ProjectStatus::OnHold->value);

        $this->actingAs($this->projectManager)
            ->postJson("/api/v1/projects/{$projectId}/members", [
                'user_id' => $this->employee->id,
            ])
            ->assertCreated();

        $this->actingAs($this->projectManager)
            ->deleteJson("/api/v1/projects/{$projectId}/members/{$this->employee->id}")
            ->assertOk();

        $this->actingAs($this->projectManager)
            ->deleteJson("/api/v1/projects/{$projectId}")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_employee_can_list_and_view_owned_projects(): void
    {
        $owned = Project::factory()->create([
            'created_by' => $this->employee->id,
            'name' => 'Owned By Employee',
        ]);

        $list = $this->actingAs($this->employee)
            ->getJson('/api/v1/projects');
        $list->assertOk();
        $ids = collect($list->json('data'))->pluck('id')->all();
        $this->assertContains($owned->id, $ids);
        $this->assertNotContains($this->unrelatedProject->id, $ids);

        $this->actingAs($this->employee)
            ->getJson("/api/v1/projects/{$owned->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $owned->id)
            ->assertJsonPath('success', true);
    }

    public function test_employee_can_list_and_view_member_projects(): void
    {
        $memberProject = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Member Project',
        ]);
        $memberProject->members()->attach($this->employee->id, [
            'joined_at' => now(),
        ]);

        $list = $this->actingAs($this->employee)
            ->getJson('/api/v1/projects');
        $list->assertOk();
        $ids = collect($list->json('data'))->pluck('id')->all();
        $this->assertContains($memberProject->id, $ids);
        $this->assertNotContains($this->unrelatedProject->id, $ids);

        $this->actingAs($this->employee)
            ->getJson("/api/v1/projects/{$memberProject->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $memberProject->id);

        $this->actingAs($this->employee)
            ->getJson("/api/v1/projects/{$memberProject->id}/members")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_employee_denied_for_unrelated_projects(): void
    {
        $this->actingAs($this->employee)
            ->getJson("/api/v1/projects/{$this->unrelatedProject->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized.')
            ->assertJsonPath('errors', null);

        $this->actingAs($this->employee)
            ->getJson("/api/v1/projects/{$this->unrelatedProject->id}/members")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_employee_cannot_create_update_delete_or_change_status(): void
    {
        $owned = Project::factory()->create([
            'created_by' => $this->employee->id,
            'name' => 'Owned But Locked',
        ]);

        $this->actingAs($this->employee)
            ->postJson('/api/v1/projects', $this->validPayload([
                'name' => 'Employee Cannot Create',
            ]))
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized.');

        $this->actingAs($this->employee)
            ->putJson("/api/v1/projects/{$owned->id}", $this->validPayload([
                'name' => 'Employee Cannot Update',
            ]))
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->employee)
            ->patchJson("/api/v1/projects/{$owned->id}/status", [
                'status' => ProjectStatus::Active->value,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->employee)
            ->deleteJson("/api/v1/projects/{$owned->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->employee)
            ->putJson("/api/v1/projects/{$this->unrelatedProject->id}", $this->validPayload([
                'name' => 'Still Forbidden',
            ]))
            ->assertForbidden();
    }

    public function test_employee_cannot_manage_members(): void
    {
        $owned = Project::factory()->create([
            'created_by' => $this->employee->id,
            'name' => 'Owned Members Locked',
        ]);

        $this->actingAs($this->employee)
            ->postJson("/api/v1/projects/{$owned->id}/members", [
                'user_id' => $this->otherEmployee->id,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized.');

        $owned->members()->attach($this->otherEmployee->id, [
            'joined_at' => now(),
        ]);

        $this->actingAs($this->employee)
            ->deleteJson("/api/v1/projects/{$owned->id}/members/{$this->otherEmployee->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'OpsFlow Project',
            'description' => 'Authorization test project',
            'start_date' => '2026-08-01',
            'due_date' => '2026-12-31',
        ], $overrides);
    }
}
