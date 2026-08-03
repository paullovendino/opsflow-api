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
use App\Queries\Tasks\TaskQuery;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskListQueryTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $otherCreator;

    private Project $project;

    private Project $otherProject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $this->actor = User::factory()->create([
            'role_id' => $adminRole->id,
            'first_name' => 'Actor',
            'last_name' => 'User',
            'email' => 'actor@opsflow.test',
        ]);
        $this->otherCreator = User::factory()->create([
            'role_id' => $adminRole->id,
            'first_name' => 'Other',
            'last_name' => 'Creator',
            'email' => 'other.creator@opsflow.test',
        ]);
        $this->project = Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'Primary Project',
        ]);
        $this->otherProject = Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'Secondary Project',
        ]);
    }

    public function test_list_uses_default_pagination_and_sort(): void
    {
        Task::factory()->count(2)->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', TaskQuery::DEFAULT_PER_PAGE)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'description',
                        'status',
                        'priority',
                        'project',
                        'assignee',
                        'creator',
                        'created_at',
                    ],
                ],
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                    'from',
                    'to',
                ],
            ]);

        $this->assertGreaterThanOrEqual(2, $response->json('meta.total'));
        $this->assertNull($response->json('errors'));
    }

    public function test_search_matches_title_and_description(): void
    {
        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'title' => 'UniqueAlpha Draft',
            'description' => 'Standard description',
        ]);
        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'title' => 'Other Task',
            'description' => 'Contains UniqueBeta needle',
        ]);
        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'title' => 'Unrelated',
            'description' => 'Nothing special',
        ]);

        $byTitle = $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?search=UniqueAlpha');
        $byTitle->assertOk();
        $this->assertSame(1, $byTitle->json('meta.total'));
        $this->assertSame('UniqueAlpha Draft', $byTitle->json('data.0.title'));

        $byDescription = $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?search=UniqueBeta');
        $byDescription->assertOk();
        $this->assertSame(1, $byDescription->json('meta.total'));
        $this->assertSame('Other Task', $byDescription->json('data.0.title'));

        $caseInsensitive = $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?search=uniquealpha');
        $caseInsensitive->assertOk();
        $this->assertSame(1, $caseInsensitive->json('meta.total'));
    }

    public function test_filters_are_composable(): void
    {
        $assignee = User::factory()->create();
        $this->project->members()->attach($assignee->id, [
            'joined_at' => now(),
        ]);

        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'assigned_to' => $assignee->id,
            'title' => 'Match Target',
            'status' => TaskStatus::InProgress,
            'priority' => TaskPriority::High,
        ]);
        Task::factory()->create([
            'project_id' => $this->otherProject->id,
            'created_by' => $this->actor->id,
            'assigned_to' => $assignee->id,
            'title' => 'Match Wrong Project',
            'status' => TaskStatus::InProgress,
            'priority' => TaskPriority::High,
        ]);
        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'assigned_to' => $assignee->id,
            'title' => 'Match Wrong Status',
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::High,
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?'.http_build_query([
                'search' => 'Match',
                'status' => TaskStatus::InProgress->value,
                'priority' => TaskPriority::High->value,
                'project_id' => $this->project->id,
                'assigned_to' => $assignee->id,
                'created_by' => $this->actor->id,
            ]));

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Match Target')
            ->assertJsonPath('data.0.status', TaskStatus::InProgress->value)
            ->assertJsonPath('data.0.priority', TaskPriority::High->value)
            ->assertJsonPath('data.0.project.id', $this->project->id)
            ->assertJsonPath('data.0.assignee.id', $assignee->id)
            ->assertJsonPath('data.0.creator.id', $this->actor->id);
    }

    public function test_individual_filters_work(): void
    {
        $assignee = User::factory()->create();
        $this->project->members()->attach($assignee->id, [
            'joined_at' => now(),
        ]);

        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'assigned_to' => $assignee->id,
            'status' => TaskStatus::Blocked,
            'priority' => TaskPriority::Urgent,
            'title' => 'Blocked Urgent',
        ]);
        Task::factory()->create([
            'project_id' => $this->otherProject->id,
            'created_by' => $this->otherCreator->id,
            'assigned_to' => null,
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Low,
            'title' => 'Other Todo',
        ]);

        $byStatus = $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?status='.TaskStatus::Blocked->value);
        $byStatus->assertOk();
        $this->assertTrue(collect($byStatus->json('data'))->every(
            fn (array $task): bool => $task['status'] === TaskStatus::Blocked->value
        ));
        $this->assertGreaterThanOrEqual(1, $byStatus->json('meta.total'));

        $byPriority = $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?priority='.TaskPriority::Urgent->value);
        $byPriority->assertOk();
        $this->assertTrue(collect($byPriority->json('data'))->every(
            fn (array $task): bool => $task['priority'] === TaskPriority::Urgent->value
        ));

        $byProject = $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?project_id='.$this->otherProject->id);
        $byProject->assertOk();
        $this->assertTrue(collect($byProject->json('data'))->every(
            fn (array $task): bool => $task['project']['id'] === $this->otherProject->id
        ));

        $byAssignee = $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?assigned_to='.$assignee->id);
        $byAssignee->assertOk();
        $this->assertTrue(collect($byAssignee->json('data'))->every(
            fn (array $task): bool => data_get($task, 'assignee.id') === $assignee->id
        ));

        $byCreator = $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?created_by='.$this->otherCreator->id);
        $byCreator->assertOk();
        $this->assertTrue(collect($byCreator->json('data'))->every(
            fn (array $task): bool => $task['creator']['id'] === $this->otherCreator->id
        ));
    }

    public function test_sorting_by_allowed_fields(): void
    {
        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'title' => 'Alpha Task',
            'created_at' => now()->subDays(2),
        ]);
        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'title' => 'Zulu Task',
            'created_at' => now()->subDay(),
        ]);

        $asc = $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?sort=title&direction=asc&per_page=100');
        $asc->assertOk();
        $titles = collect($asc->json('data'))->pluck('title')->all();
        $sorted = $titles;
        sort($sorted);
        $this->assertSame($sorted, $titles);

        $desc = $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?sort=title&direction=desc&per_page=100');
        $desc->assertOk();
        $titlesDesc = collect($desc->json('data'))->pluck('title')->all();
        $sortedDesc = $titlesDesc;
        rsort($sortedDesc);
        $this->assertSame($sortedDesc, $titlesDesc);
    }

    public function test_default_sort_is_created_at_desc(): void
    {
        $older = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'title' => 'Older Task',
            'created_at' => now()->subDays(5),
        ]);
        $newer = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'title' => 'Newer Task',
            'created_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?per_page=100');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertTrue(
            array_search($newer->id, $ids, true) < array_search($older->id, $ids, true)
        );
    }

    public function test_pagination_respects_page_and_per_page(): void
    {
        Task::factory()->count(5)->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?page=2&per_page=2');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.from', 3);

        $this->assertCount(2, $response->json('data'));
        $this->assertGreaterThanOrEqual(5, $response->json('meta.total'));
    }

    public function test_per_page_above_max_is_clamped_to_100(): void
    {
        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?per_page=250');

        $response->assertOk()
            ->assertJsonPath('meta.per_page', TaskQuery::MAX_PER_PAGE);
    }

    public function test_invalid_query_params_return_validation_errors(): void
    {
        $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?sort=password')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['sort']);

        $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?status=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?priority=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['priority']);

        $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?direction=sideways')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['direction']);

        $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?project_id=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['project_id']);

        $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?assigned_to=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_to']);

        $this->actingAs($this->actor)
            ->getJson('/api/v1/tasks?created_by=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['created_by']);
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
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_guest_cannot_list_tasks(): void
    {
        $this->getJson('/api/v1/tasks')->assertUnauthorized();
    }
}
