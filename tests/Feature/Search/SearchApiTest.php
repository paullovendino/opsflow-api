<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Enums\RoleName;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\Search\SearchService;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SearchApiTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private User $projectManager;

    private User $employee;

    private User $outsider;

    private Project $accessibleProject;

    private Project $hiddenProject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $pmRole = Role::query()->where('name', RoleName::ProjectManager)->firstOrFail();
        $employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();

        $this->administrator = User::factory()->create([
            'role_id' => $adminRole->id,
            'first_name' => 'Ada',
            'last_name' => 'Admin',
            'email' => 'ada.admin@opsflow.test',
        ]);
        $this->projectManager = User::factory()->create([
            'role_id' => $pmRole->id,
            'first_name' => 'Pam',
            'last_name' => 'Manager',
            'email' => 'pam.manager@opsflow.test',
        ]);
        $this->employee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'first_name' => 'Eli',
            'last_name' => 'Employee',
            'email' => 'eli.employee@opsflow.test',
        ]);
        $this->outsider = User::factory()->create([
            'role_id' => $employeeRole->id,
            'first_name' => 'Ollie',
            'last_name' => 'Outsider',
            'email' => 'ollie.outsider@opsflow.test',
        ]);

        $this->accessibleProject = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Alpha OpsFlow Launch',
            'description' => 'Visible rocket launch workstream',
        ]);
        $this->accessibleProject->members()->attach($this->employee->id, [
            'joined_at' => now(),
        ]);

        $this->hiddenProject = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Hidden Vault Project',
            'description' => 'Secret vault description',
        ]);

        Task::factory()->create([
            'project_id' => $this->accessibleProject->id,
            'created_by' => $this->administrator->id,
            'title' => 'Draft API contract',
            'description' => 'Document searchable task endpoints',
            'status' => TaskStatus::Todo,
        ]);

        Task::factory()->create([
            'project_id' => $this->hiddenProject->id,
            'created_by' => $this->administrator->id,
            'title' => 'Secret vault task',
            'description' => 'Must stay hidden from employees',
            'status' => TaskStatus::Todo,
        ]);
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        DB::flushQueryLog();
        DB::disableQueryLog();

        parent::tearDown();
    }

    public function test_guest_cannot_search(): void
    {
        $this->getJson('/api/v1/search?q=op')
            ->assertUnauthorized();
    }

    public function test_missing_q_returns_422(): void
    {
        $this->actingAs($this->administrator)
            ->getJson('/api/v1/search')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
    }

    public function test_empty_q_returns_422(): void
    {
        $this->actingAs($this->administrator)
            ->getJson('/api/v1/search?q=')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
    }

    public function test_one_character_q_returns_422(): void
    {
        $this->actingAs($this->administrator)
            ->getJson('/api/v1/search?q=a')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
    }

    public function test_whitespace_only_q_returns_422(): void
    {
        $this->actingAs($this->administrator)
            ->getJson('/api/v1/search?q=%20%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
    }

    public function test_trimmed_two_character_q_is_accepted(): void
    {
        $this->actingAs($this->administrator)
            ->getJson('/api/v1/search?q=%20op%20')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.q', 'op')
            ->assertJsonPath('meta.per_type', SearchService::DEFAULT_PER_TYPE);
    }

    public function test_valid_search_returns_grouped_results(): void
    {
        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/search?q=OpsFlow')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'users',
                    'projects' => [
                        ['id', 'name', 'status', 'progress', 'type'],
                    ],
                    'tasks',
                ],
                'meta' => [
                    'q',
                    'per_type',
                    'users_returned',
                    'projects_returned',
                    'tasks_returned',
                ],
            ]);

        $projectIds = collect($response->json('data.projects'))->pluck('id');
        $this->assertTrue($projectIds->contains($this->accessibleProject->id));
    }

    public function test_matches_project_name_and_description_case_insensitive(): void
    {
        $byName = $this->actingAs($this->administrator)
            ->getJson('/api/v1/search?q=alpha+opsflow')
            ->assertOk();
        $this->assertTrue(
            collect($byName->json('data.projects'))->pluck('id')->contains($this->accessibleProject->id),
        );

        $byDescription = $this->actingAs($this->administrator)
            ->getJson('/api/v1/search?q=ROCKET+LAUNCH')
            ->assertOk();
        $this->assertTrue(
            collect($byDescription->json('data.projects'))->pluck('id')->contains($this->accessibleProject->id),
        );
    }

    public function test_matches_task_title_and_description_case_insensitive(): void
    {
        $byTitle = $this->actingAs($this->administrator)
            ->getJson('/api/v1/search?q=draft+api')
            ->assertOk();
        $this->assertNotEmpty($byTitle->json('data.tasks'));
        $this->assertSame('Draft API contract', $byTitle->json('data.tasks.0.title'));

        $byDescription = $this->actingAs($this->administrator)
            ->getJson('/api/v1/search?q=SEARCHABLE+TASK')
            ->assertOk();
        $this->assertNotEmpty($byDescription->json('data.tasks'));
    }

    public function test_matches_user_name_and_email_case_insensitive(): void
    {
        $byName = $this->actingAs($this->administrator)
            ->getJson('/api/v1/search?q=ada')
            ->assertOk();
        $this->assertTrue(
            collect($byName->json('data.users'))->pluck('email')->contains('ada.admin@opsflow.test'),
        );

        $byEmail = $this->actingAs($this->projectManager)
            ->getJson('/api/v1/search?q=PAM.MANAGER')
            ->assertOk();
        $this->assertTrue(
            collect($byEmail->json('data.users'))->pluck('email')->contains('pam.manager@opsflow.test'),
        );
    }

    public function test_administrator_can_search_users(): void
    {
        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/search?q=employee')
            ->assertOk();

        $this->assertNotEmpty($response->json('data.users'));
    }

    public function test_project_manager_can_search_users(): void
    {
        $response = $this->actingAs($this->projectManager)
            ->getJson('/api/v1/search?q=ada')
            ->assertOk();

        $this->assertTrue(
            collect($response->json('data.users'))->pluck('id')->contains($this->administrator->id),
        );
    }

    public function test_employee_never_receives_users(): void
    {
        $response = $this->actingAs($this->employee)
            ->getJson('/api/v1/search?q=ada')
            ->assertOk();

        $this->assertSame([], $response->json('data.users'));
        $this->assertSame(0, $response->json('meta.users_returned'));
    }

    public function test_employee_types_users_still_returns_empty_users(): void
    {
        $response = $this->actingAs($this->employee)
            ->getJson('/api/v1/search?q=ada&types=users,projects,tasks')
            ->assertOk();

        $this->assertSame([], $response->json('data.users'));
    }

    public function test_employee_only_sees_permitted_projects_and_tasks(): void
    {
        $response = $this->actingAs($this->employee)
            ->getJson('/api/v1/search?q=vault')
            ->assertOk();

        $this->assertSame([], $response->json('data.projects'));
        $this->assertSame([], $response->json('data.tasks'));

        $visible = $this->actingAs($this->employee)
            ->getJson('/api/v1/search?q=OpsFlow')
            ->assertOk();

        $this->assertTrue(
            collect($visible->json('data.projects'))->pluck('id')->contains($this->accessibleProject->id),
        );
        $this->assertFalse(
            collect($visible->json('data.projects'))->pluck('id')->contains($this->hiddenProject->id),
        );

        $tasks = $this->actingAs($this->employee)
            ->getJson('/api/v1/search?q=Draft')
            ->assertOk();
        $this->assertNotEmpty($tasks->json('data.tasks'));
        $this->assertFalse(
            collect($tasks->json('data.tasks'))->pluck('title')->contains('Secret vault task'),
        );
    }

    public function test_outsider_employee_sees_no_hidden_work(): void
    {
        $response = $this->actingAs($this->outsider)
            ->getJson('/api/v1/search?q=OpsFlow')
            ->assertOk();

        $this->assertSame([], $response->json('data.projects'));
        $this->assertSame([], $response->json('data.tasks'));
        $this->assertSame([], $response->json('data.users'));
    }

    public function test_per_type_cap_is_respected_and_clamped(): void
    {
        foreach (range(1, 12) as $i) {
            Project::factory()->create([
                'created_by' => $this->administrator->id,
                'name' => "Cap Project {$i}",
                'description' => "capmatch {$i}",
            ]);
        }

        $default = $this->actingAs($this->administrator)
            ->getJson('/api/v1/search?q=capmatch')
            ->assertOk();
        $this->assertCount(SearchService::DEFAULT_PER_TYPE, $default->json('data.projects'));
        $this->assertSame(SearchService::DEFAULT_PER_TYPE, $default->json('meta.per_type'));

        $custom = $this->actingAs($this->administrator)
            ->getJson('/api/v1/search?q=capmatch&per_type=3')
            ->assertOk();
        $this->assertCount(3, $custom->json('data.projects'));
        $this->assertSame(3, $custom->json('meta.per_type'));

        $clamped = $this->actingAs($this->administrator)
            ->getJson('/api/v1/search?q=capmatch&per_type=99')
            ->assertOk();
        $this->assertCount(SearchService::MAX_PER_TYPE, $clamped->json('data.projects'));
        $this->assertSame(SearchService::MAX_PER_TYPE, $clamped->json('meta.per_type'));
    }

    public function test_valid_query_with_no_matches_returns_empty_groups(): void
    {
        $this->actingAs($this->administrator)
            ->getJson('/api/v1/search?q=zzzznomatch')
            ->assertOk()
            ->assertJsonPath('data.users', [])
            ->assertJsonPath('data.projects', [])
            ->assertJsonPath('data.tasks', [])
            ->assertJsonPath('meta.users_returned', 0)
            ->assertJsonPath('meta.projects_returned', 0)
            ->assertJsonPath('meta.tasks_returned', 0);
    }

    public function test_types_filter_limits_result_groups(): void
    {
        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/search?q=OpsFlow&types=projects')
            ->assertOk();

        $this->assertNotEmpty($response->json('data.projects'));
        $this->assertSame([], $response->json('data.tasks'));
        $this->assertSame([], $response->json('data.users'));
    }

    public function test_search_uses_database_level_queries_without_n_plus_one(): void
    {
        Model::preventLazyLoading();

        foreach (range(1, 5) as $i) {
            $project = Project::factory()->create([
                'created_by' => $this->administrator->id,
                'name' => "NPlus Project {$i}",
                'description' => "nplusmatch {$i}",
            ]);
            Task::factory()->create([
                'project_id' => $project->id,
                'created_by' => $this->administrator->id,
                'title' => "NPlus Task {$i}",
                'description' => "nplusmatch task {$i}",
            ]);
            User::factory()->create([
                'role_id' => Role::query()->where('name', RoleName::Employee)->firstOrFail()->id,
                'first_name' => 'NPlus',
                'last_name' => "User{$i}",
                'email' => "nplus.user{$i}@opsflow.test",
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->administrator)
            ->getJson('/api/v1/search?q=nplusmatch&per_type=5')
            ->assertOk();

        $queries = collect(DB::getQueryLog());
        $this->assertLessThanOrEqual(12, $queries->count(), 'Unexpected query growth for capped global search.');

        $selects = $queries->filter(
            static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'),
        );
        $this->assertTrue(
            $selects->contains(
                static fn (array $query): bool => str_contains(strtolower($query['query']), 'ilike'),
            ),
            'Expected ILIKE matching at the database level.',
        );
    }

    public function test_invalid_type_returns_422(): void
    {
        $this->actingAs($this->administrator)
            ->getJson('/api/v1/search?q=op&types=widgets')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['types.0']);
    }
}
