<?php

declare(strict_types=1);

namespace Tests\Feature\Project;

use App\Enums\RoleName;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ProjectProgress;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();

        $this->actor = User::factory()->create([
            'role_id' => $adminRole->id,
            'email' => 'progress.admin@opsflow.test',
        ]);
        $this->employee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'email' => 'progress.employee@opsflow.test',
        ]);
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        DB::flushQueryLog();
        DB::disableQueryLog();

        parent::tearDown();
    }

    public function test_progress_helper_matches_documented_rules(): void
    {
        $this->assertNull(ProjectProgress::percent(0, 0));
        $this->assertSame(0, ProjectProgress::percent(4, 0));
        $this->assertSame(50, ProjectProgress::percent(4, 2));
        $this->assertSame(75, ProjectProgress::percent(8, 6));
        $this->assertSame(100, ProjectProgress::percent(3, 3));
    }

    public function test_project_with_zero_tasks_returns_null_progress(): void
    {
        $project = Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'Empty Project',
        ]);

        $this->actingAs($this->actor)
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.progress', null);

        $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?search=Empty+Project')
            ->assertOk()
            ->assertJsonPath('data.0.progress', null);
    }

    public function test_project_with_only_cancelled_tasks_returns_null_progress(): void
    {
        $project = Project::factory()->create([
            'created_by' => $this->actor->id,
        ]);
        Task::factory()->count(5)->create([
            'project_id' => $project->id,
            'created_by' => $this->actor->id,
            'status' => TaskStatus::Cancelled,
        ]);

        $this->actingAs($this->actor)
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.progress', null);
    }

    public function test_progress_is_fifty_when_half_of_eligible_tasks_are_completed(): void
    {
        $project = $this->projectWithTasks([
            TaskStatus::Completed,
            TaskStatus::Completed,
            TaskStatus::Todo,
            TaskStatus::InProgress,
            TaskStatus::Cancelled,
        ]);

        $this->actingAs($this->actor)
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.progress', 50)
            ->assertJsonPath('data.status', $project->status->value);
    }

    public function test_progress_is_one_hundred_when_all_eligible_tasks_are_completed(): void
    {
        $project = $this->projectWithTasks([
            TaskStatus::Completed,
            TaskStatus::Completed,
            TaskStatus::Cancelled,
        ]);

        $this->actingAs($this->actor)
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.progress', 100);
    }

    public function test_progress_is_zero_when_eligible_tasks_exist_but_none_are_completed(): void
    {
        $project = $this->projectWithTasks([
            TaskStatus::Todo,
            TaskStatus::InProgress,
            TaskStatus::InReview,
            TaskStatus::Blocked,
        ]);

        $this->actingAs($this->actor)
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.progress', 0);
    }

    public function test_soft_deleted_tasks_are_excluded_from_progress(): void
    {
        $project = Project::factory()->create([
            'created_by' => $this->actor->id,
        ]);
        Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $this->actor->id,
            'status' => TaskStatus::Completed,
        ]);
        $deleted = Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $this->actor->id,
            'status' => TaskStatus::Todo,
        ]);
        $deleted->delete();

        $this->actingAs($this->actor)
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.progress', 100);
    }

    public function test_list_and_show_return_the_same_progress(): void
    {
        $project = $this->projectWithTasks([
            TaskStatus::Completed,
            TaskStatus::Todo,
            TaskStatus::Cancelled,
        ]);

        $show = $this->actingAs($this->actor)
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertOk();

        $list = $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?search='.urlencode($project->name))
            ->assertOk();

        $this->assertSame(50, $show->json('data.progress'));
        $this->assertSame(50, $list->json('data.0.progress'));
        $this->assertSame($show->json('data.progress'), $list->json('data.0.progress'));
    }

    public function test_list_includes_progress_in_resource_shape_and_keeps_pagination(): void
    {
        Project::factory()->count(5)->create([
            'created_by' => $this->actor->id,
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?page=2&per_page=2');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.from', 3)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'status',
                        'progress',
                    ],
                ],
            ]);

        $this->assertCount(2, $response->json('data'));
        $this->assertGreaterThanOrEqual(5, $response->json('meta.total'));
    }

    public function test_search_and_status_filters_still_work_with_progress(): void
    {
        $match = Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'ProgressFilter Alpha',
            'status' => \App\Enums\ProjectStatus::Active,
        ]);
        Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'ProgressFilter Alpha Hidden',
            'status' => \App\Enums\ProjectStatus::Planning,
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?search=ProgressFilter+Alpha&status=active');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $match->id)
            ->assertJsonPath('data.0.progress', null);
    }

    public function test_employee_does_not_receive_progress_for_inaccessible_projects(): void
    {
        $visible = $this->projectWithTasks([
            TaskStatus::Completed,
            TaskStatus::Todo,
        ], owner: $this->employee, name: 'Visible Progress Project');
        $hidden = $this->projectWithTasks([
            TaskStatus::Completed,
        ], owner: $this->actor, name: 'Hidden Progress Project');

        $list = $this->actingAs($this->employee)
            ->getJson('/api/v1/projects?per_page=100')
            ->assertOk();

        $ids = collect($list->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($visible->id));
        $this->assertFalse($ids->contains($hidden->id));
        $this->assertSame(50, collect($list->json('data'))->firstWhere('id', $visible->id)['progress']);

        $this->actingAs($this->employee)
            ->getJson("/api/v1/projects/{$hidden->id}")
            ->assertForbidden();
    }

    public function test_progress_does_not_change_project_status(): void
    {
        $project = $this->projectWithTasks([
            TaskStatus::Completed,
            TaskStatus::Completed,
        ]);
        $status = $project->status;

        $this->actingAs($this->actor)
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.progress', 100)
            ->assertJsonPath('data.status', $status->value);

        $this->assertSame($status, $project->fresh()->status);
    }

    public function test_project_list_progress_query_count_does_not_scale_with_project_count(): void
    {
        Project::factory()->count(3)->create([
            'created_by' => $this->actor->id,
        ]);

        Model::preventLazyLoading();

        $this->actingAs($this->actor)->getJson('/api/v1/projects?per_page=15');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $first = $this->actingAs($this->actor)->getJson('/api/v1/projects?per_page=15');
        $first->assertOk();
        $firstCount = count(DB::getQueryLog());
        $firstTaskSelects = $this->standaloneTaskSelectCount(DB::getQueryLog());

        Project::factory()->count(9)->create([
            'created_by' => $this->actor->id,
        ]);

        DB::flushQueryLog();
        $second = $this->actingAs($this->actor)->getJson('/api/v1/projects?per_page=15');
        $second->assertOk();
        $secondCount = count(DB::getQueryLog());
        $secondTaskSelects = $this->standaloneTaskSelectCount(DB::getQueryLog());

        $this->assertGreaterThanOrEqual(12, $second->json('meta.total'));
        $this->assertSame($firstCount, $secondCount);
        $this->assertSame(0, $firstTaskSelects);
        $this->assertSame(0, $secondTaskSelects);
        $this->assertTrue(collect($first->json('data'))->every(
            fn (array $project): bool => array_key_exists('progress', $project)
        ));
    }

    /**
     * @param  list<TaskStatus>  $statuses
     */
    private function projectWithTasks(array $statuses, ?User $owner = null, ?string $name = null): Project
    {
        $owner ??= $this->actor;

        $project = Project::factory()->create([
            'created_by' => $owner->id,
            'name' => $name ?? fake()->unique()->words(3, true),
        ]);

        foreach ($statuses as $status) {
            Task::factory()->create([
                'project_id' => $project->id,
                'created_by' => $owner->id,
                'status' => $status,
            ]);
        }

        return $project->fresh() ?? $project;
    }

    /**
     * @param  list<array{query: string, bindings: mixed, time: mixed}>  $log
     */
    private function standaloneTaskSelectCount(array $log): int
    {
        return collect($log)->filter(function (array $entry): bool {
            $sql = strtolower($entry['query']);

            $selectsTasks = str_contains($sql, 'from "tasks"') || str_contains($sql, 'from tasks');
            $selectsProjects = str_contains($sql, 'from "projects"') || str_contains($sql, 'from projects');

            return $selectsTasks && ! $selectsProjects;
        })->count();
    }
}
