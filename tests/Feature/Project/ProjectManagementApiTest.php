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

class ProjectManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

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
    }

    public function test_authenticated_user_can_create_project(): void
    {
        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/projects', $this->validPayload([
                'name' => 'OpsFlow Launch',
            ]));

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Project created successfully.')
            ->assertJsonPath('data.name', 'OpsFlow Launch')
            ->assertJsonPath('data.description', 'Initial launch workstream')
            ->assertJsonPath('data.status', ProjectStatus::Planning->value)
            ->assertJsonPath('data.start_date', '2026-08-01')
            ->assertJsonPath('data.due_date', '2026-12-31')
            ->assertJsonPath('data.owner.id', $this->actor->id)
            ->assertJsonPath('data.owner.email', 'ada.admin@opsflow.test')
            ->assertJsonMissingPath('data.members')
            ->assertJsonMissingPath('data.created_by');

        $this->assertDatabaseHas('projects', [
            'name' => 'OpsFlow Launch',
            'created_by' => $this->actor->id,
            'status' => ProjectStatus::Planning->value,
        ]);
    }

    public function test_create_assigns_authenticated_user_as_owner_and_ignores_client_created_by(): void
    {
        $other = User::factory()->create();

        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/projects', $this->validPayload([
                'name' => 'Owned By Actor',
                'created_by' => $other->id,
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.owner.id', $this->actor->id);

        $this->assertDatabaseHas('projects', [
            'name' => 'Owned By Actor',
            'created_by' => $this->actor->id,
        ]);
        $this->assertDatabaseMissing('projects', [
            'name' => 'Owned By Actor',
            'created_by' => $other->id,
        ]);
    }

    public function test_create_defaults_status_to_planning_even_if_status_supplied(): void
    {
        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/projects', $this->validPayload([
                'name' => 'Status Ignored On Create',
                'status' => ProjectStatus::Active->value,
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.status', ProjectStatus::Planning->value);

        $this->assertDatabaseHas('projects', [
            'name' => 'Status Ignored On Create',
            'status' => ProjectStatus::Planning->value,
        ]);
    }

    public function test_store_validation_errors_are_returned(): void
    {
        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/projects', []);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonStructure([
                'errors' => [
                    'name',
                ],
            ]);
    }

    public function test_authenticated_user_can_list_projects(): void
    {
        Project::factory()->count(2)->create([
            'created_by' => $this->actor->id,
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/projects');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Projects retrieved successfully.')
            ->assertJsonPath('meta', null);

        $this->assertCount(2, $response->json('data'));
        $this->assertIsArray($response->json('data.0.owner'));
        $this->assertArrayNotHasKey('members', $response->json('data.0'));
    }

    public function test_authenticated_user_can_show_project(): void
    {
        $project = Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'Visible Project',
            'status' => ProjectStatus::Active,
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson("/api/v1/projects/{$project->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Project retrieved successfully.')
            ->assertJsonPath('data.id', $project->id)
            ->assertJsonPath('data.name', 'Visible Project')
            ->assertJsonPath('data.status', ProjectStatus::Active->value)
            ->assertJsonPath('data.owner.id', $this->actor->id)
            ->assertJsonMissingPath('data.members');
    }

    public function test_authenticated_user_can_update_project_fields_but_not_status_or_owner(): void
    {
        $other = User::factory()->create();
        $project = Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'Before',
            'description' => 'Old description',
            'status' => ProjectStatus::Planning,
            'start_date' => '2026-01-01',
            'due_date' => '2026-06-01',
        ]);

        $response = $this->actingAs($this->actor)
            ->putJson("/api/v1/projects/{$project->id}", [
                'name' => 'After',
                'description' => 'New description',
                'start_date' => '2026-02-01',
                'due_date' => '2026-07-01',
                'status' => ProjectStatus::Completed->value,
                'created_by' => $other->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Project updated successfully.')
            ->assertJsonPath('data.name', 'After')
            ->assertJsonPath('data.description', 'New description')
            ->assertJsonPath('data.start_date', '2026-02-01')
            ->assertJsonPath('data.due_date', '2026-07-01')
            ->assertJsonPath('data.status', ProjectStatus::Planning->value)
            ->assertJsonPath('data.owner.id', $this->actor->id);

        $fresh = $project->fresh();
        $this->assertSame(ProjectStatus::Planning, $fresh->status);
        $this->assertSame($this->actor->id, $fresh->created_by);
    }

    public function test_authenticated_user_can_soft_delete_project(): void
    {
        $project = Project::factory()->create([
            'created_by' => $this->actor->id,
        ]);

        $response = $this->actingAs($this->actor)
            ->deleteJson("/api/v1/projects/{$project->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Project deleted successfully.');

        $this->assertSoftDeleted($project);
    }

    public function test_authenticated_user_can_update_status_only(): void
    {
        $project = Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'Status Target',
            'description' => 'Keep me',
            'status' => ProjectStatus::Planning,
        ]);

        $response = $this->actingAs($this->actor)
            ->patchJson("/api/v1/projects/{$project->id}/status", [
                'status' => ProjectStatus::Active->value,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Project status updated successfully.')
            ->assertJsonPath('data.status', ProjectStatus::Active->value)
            ->assertJsonPath('data.name', 'Status Target')
            ->assertJsonPath('data.description', 'Keep me');

        $fresh = $project->fresh();
        $this->assertSame(ProjectStatus::Active, $fresh->status);
        $this->assertSame('Status Target', $fresh->name);
        $this->assertSame('Keep me', $fresh->description);
    }

    public function test_status_update_rejects_invalid_values(): void
    {
        $project = Project::factory()->create([
            'created_by' => $this->actor->id,
        ]);

        $response = $this->actingAs($this->actor)
            ->patchJson("/api/v1/projects/{$project->id}/status", [
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

    public function test_guest_cannot_access_project_endpoints(): void
    {
        $project = Project::factory()->create([
            'created_by' => $this->actor->id,
        ]);

        $this->getJson('/api/v1/projects')->assertUnauthorized();
        $this->postJson('/api/v1/projects', $this->validPayload())->assertUnauthorized();
        $this->getJson("/api/v1/projects/{$project->id}")->assertUnauthorized();
        $this->putJson("/api/v1/projects/{$project->id}", $this->validPayload())->assertUnauthorized();
        $this->deleteJson("/api/v1/projects/{$project->id}")->assertUnauthorized();
        $this->patchJson("/api/v1/projects/{$project->id}/status", [
            'status' => ProjectStatus::Active->value,
        ])->assertUnauthorized();
    }

    public function test_project_resource_shape_excludes_members(): void
    {
        $project = Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'Resource Shape',
            'description' => null,
            'status' => ProjectStatus::OnHold,
            'start_date' => null,
            'due_date' => null,
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson("/api/v1/projects/{$project->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'name',
                    'description',
                    'status',
                    'start_date',
                    'due_date',
                    'owner' => [
                        'id',
                        'first_name',
                        'last_name',
                        'email',
                    ],
                    'created_at',
                    'updated_at',
                ],
                'errors',
                'meta',
            ])
            ->assertJsonMissingPath('data.members')
            ->assertJsonPath('data.status', ProjectStatus::OnHold->value);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'OpsFlow Launch',
            'description' => 'Initial launch workstream',
            'start_date' => '2026-08-01',
            'due_date' => '2026-12-31',
        ], $overrides);
    }
}
