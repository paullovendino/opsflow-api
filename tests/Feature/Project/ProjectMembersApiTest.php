<?php

declare(strict_types=1);

namespace Tests\Feature\Project;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectMembersApiTest extends TestCase
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
        ]);
        $this->project = Project::factory()->create([
            'created_by' => $this->actor->id,
        ]);
    }

    public function test_authenticated_user_can_list_project_members(): void
    {
        $member = User::factory()->create();
        $this->project->members()->attach($member->id, [
            'joined_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson("/api/v1/projects/{$this->project->id}/members");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Project members retrieved successfully.')
            ->assertJsonPath('data.0.id', $member->id)
            ->assertJsonPath('data.0.email', $member->email)
            ->assertJsonPath('data.0.status', UserStatus::Active->value)
            ->assertJsonMissingPath('data.0.password');

        $this->assertNotNull($response->json('data.0.joined_at'));
        $this->assertCount(1, $response->json('data'));
    }

    public function test_owner_is_not_listed_as_member_unless_explicitly_added(): void
    {
        $response = $this->actingAs($this->actor)
            ->getJson("/api/v1/projects/{$this->project->id}/members");

        $response->assertOk()
            ->assertJsonPath('data', []);

        $this->assertDatabaseMissing('project_members', [
            'project_id' => $this->project->id,
            'user_id' => $this->actor->id,
        ]);
    }

    public function test_authenticated_user_can_add_project_member(): void
    {
        $member = User::factory()->create([
            'first_name' => 'Mem',
            'last_name' => 'Ber',
            'email' => 'member@opsflow.test',
        ]);

        $response = $this->actingAs($this->actor)
            ->postJson("/api/v1/projects/{$this->project->id}/members", [
                'user_id' => $member->id,
                'joined_at' => '2020-01-01T00:00:00Z',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Project member added successfully.')
            ->assertJsonPath('data.id', $member->id)
            ->assertJsonPath('data.email', 'member@opsflow.test')
            ->assertJsonPath('data.full_name', 'Mem Ber')
            ->assertJsonMissingPath('data.password');

        $this->assertDatabaseHas('project_members', [
            'project_id' => $this->project->id,
            'user_id' => $member->id,
        ]);

        $joinedAt = \Illuminate\Support\Carbon::parse(
            $this->project->members()->where('users.id', $member->id)->firstOrFail()->pivot->joined_at
        );
        $this->assertNotSame('2020-01-01', $joinedAt->toDateString());
        $this->assertTrue($joinedAt->isToday());
    }

    public function test_duplicate_membership_returns_conflict(): void
    {
        $member = User::factory()->create();
        $this->project->members()->attach($member->id, [
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($this->actor)
            ->postJson("/api/v1/projects/{$this->project->id}/members", [
                'user_id' => $member->id,
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'User is already a member of this project.')
            ->assertJsonPath('data', null);

        $this->assertSame(1, $this->project->members()->count());
    }

    public function test_cannot_add_inactive_user_as_member(): void
    {
        $inactive = User::factory()->inactive()->create();

        $response = $this->actingAs($this->actor)
            ->postJson("/api/v1/projects/{$this->project->id}/members", [
                'user_id' => $inactive->id,
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'errors' => [
                    'user_id',
                ],
            ]);

        $this->assertDatabaseMissing('project_members', [
            'project_id' => $this->project->id,
            'user_id' => $inactive->id,
        ]);
    }

    public function test_cannot_add_soft_deleted_user_as_member(): void
    {
        $deleted = User::factory()->create();
        $deleted->delete();

        $response = $this->actingAs($this->actor)
            ->postJson("/api/v1/projects/{$this->project->id}/members", [
                'user_id' => $deleted->id,
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'errors' => [
                    'user_id',
                ],
            ]);

        $this->assertDatabaseMissing('project_members', [
            'project_id' => $this->project->id,
            'user_id' => $deleted->id,
        ]);
    }

    public function test_store_member_validation_requires_user_id(): void
    {
        $response = $this->actingAs($this->actor)
            ->postJson("/api/v1/projects/{$this->project->id}/members", []);

        $response->assertUnprocessable()
            ->assertJsonStructure([
                'errors' => [
                    'user_id',
                ],
            ]);
    }

    public function test_authenticated_user_can_remove_project_member(): void
    {
        $member = User::factory()->create();
        $this->project->members()->attach($member->id, [
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($this->actor)
            ->deleteJson("/api/v1/projects/{$this->project->id}/members/{$member->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Project member removed successfully.');

        $this->assertDatabaseMissing('project_members', [
            'project_id' => $this->project->id,
            'user_id' => $member->id,
        ]);
        $this->assertDatabaseHas('projects', [
            'id' => $this->project->id,
            'created_by' => $this->actor->id,
        ]);
    }

    public function test_removing_unknown_member_returns_not_found(): void
    {
        $nonMember = User::factory()->create();

        $response = $this->actingAs($this->actor)
            ->deleteJson("/api/v1/projects/{$this->project->id}/members/{$nonMember->id}");

        $response->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Resource not found.');
    }

    public function test_guest_cannot_access_member_endpoints(): void
    {
        $member = User::factory()->create();

        $this->getJson("/api/v1/projects/{$this->project->id}/members")->assertUnauthorized();
        $this->postJson("/api/v1/projects/{$this->project->id}/members", [
            'user_id' => $member->id,
        ])->assertUnauthorized();
        $this->deleteJson("/api/v1/projects/{$this->project->id}/members/{$member->id}")
            ->assertUnauthorized();
    }
}
