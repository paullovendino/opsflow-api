<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use App\Services\ActivityLogs\ActivityLogService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
    }

    public function test_activity_log_can_be_created_with_actor_subject_and_properties(): void
    {
        $actor = User::factory()->create();
        $project = Project::factory()->create([
            'created_by' => $actor->id,
            'name' => 'Website Redesign',
        ]);

        $log = ActivityLog::query()->create([
            'actor_id' => $actor->id,
            'action' => ActivityAction::ProjectCreated,
            'subject_type' => $project->getMorphClass(),
            'subject_id' => $project->id,
            'description' => 'Created project Website Redesign.',
            'properties' => [
                'status' => 'planning',
                'actor_snapshot' => [
                    'id' => $actor->id,
                    'full_name' => $actor->full_name,
                    'email' => $actor->email,
                ],
            ],
            'created_at' => now(),
        ]);

        $this->assertTrue($log->exists);
        $this->assertSame(ActivityAction::ProjectCreated, $log->action);
        $this->assertSame('project', $log->subject_type);
        $this->assertSame($project->id, $log->subject_id);
        $this->assertSame('Created project Website Redesign.', $log->description);
        $this->assertSame('planning', $log->properties['status']);
        $this->assertNull($log->updated_at);
        $this->assertNotNull($log->created_at);
    }

    public function test_actor_relationship_loads_user(): void
    {
        $actor = User::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Admin',
        ]);
        $project = Project::factory()->create([
            'created_by' => $actor->id,
        ]);

        $log = ActivityLog::factory()->create([
            'actor_id' => $actor->id,
            'subject_type' => $project->getMorphClass(),
            'subject_id' => $project->id,
        ]);

        $this->assertTrue($log->actor->is($actor));
        $this->assertSame('Ada Admin', $log->actor->full_name);
    }

    public function test_subject_morph_relationship_loads_project(): void
    {
        $actor = User::factory()->create();
        $project = Project::factory()->create([
            'created_by' => $actor->id,
            'name' => 'Morph Subject',
        ]);

        $log = ActivityLog::factory()->create([
            'actor_id' => $actor->id,
            'subject_type' => $project->getMorphClass(),
            'subject_id' => $project->id,
        ]);

        $this->assertInstanceOf(Project::class, $log->subject);
        $this->assertTrue($log->subject->is($project));
        $this->assertSame('Morph Subject', $log->subject->name);
    }

    public function test_properties_are_cast_to_array(): void
    {
        $actor = User::factory()->create();
        $project = Project::factory()->create([
            'created_by' => $actor->id,
        ]);

        $log = ActivityLog::factory()->create([
            'actor_id' => $actor->id,
            'subject_type' => $project->getMorphClass(),
            'subject_id' => $project->id,
            'properties' => [
                'before' => ['status' => 'todo'],
                'after' => ['status' => 'in_progress'],
            ],
        ]);

        $fresh = $log->fresh();

        $this->assertIsArray($fresh->properties);
        $this->assertSame('todo', $fresh->properties['before']['status']);
        $this->assertSame('in_progress', $fresh->properties['after']['status']);
    }

    public function test_service_record_persists_actor_snapshot_and_truncates_description(): void
    {
        $actor = User::factory()->create([
            'first_name' => 'Maria',
            'last_name' => 'Lopez',
        ]);
        $project = Project::factory()->create([
            'created_by' => $actor->id,
        ]);

        $service = app(ActivityLogService::class);
        $longDescription = str_repeat('A', 300);

        $log = $service->record(
            actor: $actor,
            action: ActivityAction::ProjectUpdated,
            subject: $project,
            description: $longDescription,
            properties: ['changed' => ['name']],
        );

        $this->assertSame(255, mb_strlen($log->description));
        $this->assertSame('…', mb_substr($log->description, -1));
        $this->assertSame($actor->id, $log->properties['actor_snapshot']['id']);
        $this->assertSame('Maria Lopez', $log->properties['actor_snapshot']['full_name']);
        $this->assertSame($actor->email, $log->properties['actor_snapshot']['email']);
        $this->assertSame(['name'], $log->properties['changed']);
    }

    public function test_for_subject_and_for_action_scopes(): void
    {
        $actor = User::factory()->create();
        $project = Project::factory()->create(['created_by' => $actor->id]);
        $other = Project::factory()->create(['created_by' => $actor->id]);

        ActivityLog::factory()->create([
            'actor_id' => $actor->id,
            'action' => ActivityAction::ProjectCreated,
            'subject_type' => $project->getMorphClass(),
            'subject_id' => $project->id,
        ]);
        ActivityLog::factory()->create([
            'actor_id' => $actor->id,
            'action' => ActivityAction::ProjectUpdated,
            'subject_type' => $project->getMorphClass(),
            'subject_id' => $project->id,
        ]);
        ActivityLog::factory()->create([
            'actor_id' => $actor->id,
            'action' => ActivityAction::ProjectCreated,
            'subject_type' => $other->getMorphClass(),
            'subject_id' => $other->id,
        ]);

        $this->assertSame(2, ActivityLog::query()->forSubject($project)->count());
        $this->assertSame(2, ActivityLog::query()->forAction(ActivityAction::ProjectCreated)->count());
        $this->assertSame(1, ActivityLog::query()->forSubject($project)->forAction(ActivityAction::ProjectUpdated)->count());
    }
}
