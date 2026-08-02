<?php

declare(strict_types=1);

namespace Tests\Feature\Project;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectDomainFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('projects'));
        $this->assertTrue(Schema::hasColumns('projects', [
            'id',
            'name',
            'description',
            'status',
            'start_date',
            'due_date',
            'created_by',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));
    }

    public function test_project_members_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('project_members'));
        $this->assertTrue(Schema::hasColumns('project_members', [
            'id',
            'project_id',
            'user_id',
            'joined_at',
            'created_at',
            'updated_at',
        ]));

        $this->assertFalse(Schema::hasColumn('project_members', 'deleted_at'));
    }

    public function test_project_belongs_to_owner(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'created_by' => $owner->id,
        ]);

        $project->load(['owner', 'createdBy']);

        $this->assertTrue($project->owner->is($owner));
        $this->assertTrue($project->createdBy->is($owner));
        $this->assertTrue($owner->ownedProjects()->first()->is($project));
    }

    public function test_project_has_many_members_via_pivot(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $project = Project::factory()->create([
            'created_by' => $owner->id,
        ]);

        $joinedAt = now()->startOfSecond();

        $project->members()->attach($member->id, [
            'joined_at' => $joinedAt,
        ]);

        $project->load('members');

        $this->assertCount(1, $project->members);
        $this->assertTrue($project->members->first()->is($member));
        $this->assertTrue(
            $joinedAt->equalTo($project->members->first()->pivot->joined_at)
        );
        $this->assertTrue($member->projects()->first()->is($project));
        $this->assertFalse($project->members->contains(fn (User $user): bool => $user->is($owner)));
    }

    public function test_project_owner_is_not_auto_added_as_member(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'created_by' => $owner->id,
        ]);

        $this->assertSame(0, $project->members()->count());
        $this->assertDatabaseMissing('project_members', [
            'project_id' => $project->id,
            'user_id' => $owner->id,
        ]);
    }

    public function test_project_member_pair_is_unique(): void
    {
        $project = Project::factory()->create();
        $member = User::factory()->create();

        $project->members()->attach($member->id, [
            'joined_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        $project->members()->attach($member->id, [
            'joined_at' => now(),
        ]);
    }

    public function test_project_status_is_cast_to_enum(): void
    {
        $project = Project::factory()->create([
            'status' => ProjectStatus::Planning,
        ]);

        $this->assertInstanceOf(ProjectStatus::class, $project->status);
        $this->assertSame(ProjectStatus::Planning, $project->status);
        $this->assertSame('Planning', $project->status->label());

        $project->update(['status' => ProjectStatus::Active]);
        $project->refresh();

        $this->assertSame(ProjectStatus::Active, $project->status);
    }

    public function test_project_factory_defaults_to_planning_status(): void
    {
        $project = Project::factory()->create();

        $this->assertSame(ProjectStatus::Planning, $project->status);
        $this->assertNotNull($project->owner);
    }

    public function test_projects_support_soft_deletes(): void
    {
        $project = Project::factory()->create();

        $project->delete();

        $this->assertSoftDeleted($project);
        $this->assertNull(Project::query()->find($project->id));
        $this->assertNotNull(Project::withTrashed()->find($project->id));
    }

    public function test_soft_deleted_project_keeps_member_rows(): void
    {
        $project = Project::factory()->create();
        $member = User::factory()->create();

        $project->members()->attach($member->id, [
            'joined_at' => now(),
        ]);

        $project->delete();

        $this->assertSoftDeleted($project);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_cannot_hard_delete_user_who_owns_a_project(): void
    {
        $owner = User::factory()->create();
        Project::factory()->create([
            'created_by' => $owner->id,
        ]);

        $this->expectException(QueryException::class);

        $owner->forceDelete();
    }

    public function test_cannot_hard_delete_user_who_is_a_project_member(): void
    {
        $project = Project::factory()->create();
        $member = User::factory()->create();

        $project->members()->attach($member->id, [
            'joined_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        $member->forceDelete();
    }

    public function test_cannot_hard_delete_project_with_members(): void
    {
        $project = Project::factory()->create();
        $member = User::factory()->create();

        $project->members()->attach($member->id, [
            'joined_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        $project->forceDelete();
    }

    public function test_project_status_default_in_database_is_planning(): void
    {
        $owner = User::factory()->create();

        $projectId = DB::table('projects')->insertGetId([
            'name' => 'Default Status Project',
            'description' => null,
            'start_date' => null,
            'due_date' => null,
            'created_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $project = Project::query()->findOrFail($projectId);

        $this->assertSame(ProjectStatus::Planning, $project->status);
    }

    public function test_morph_map_includes_project_alias(): void
    {
        $this->assertSame(
            'project',
            (new Project)->getMorphClass()
        );
    }
}
