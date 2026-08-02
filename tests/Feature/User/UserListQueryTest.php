<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Enums\DepartmentCode;
use App\Enums\JobTitleCode;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\JobTitle;
use App\Models\Role;
use App\Models\User;
use App\Queries\Users\UserQuery;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\JobTitleSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserListQueryTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Role $employeeRole;

    private Role $adminRole;

    private Department $engineering;

    private Department $operations;

    private JobTitle $softwareEngineer;

    private JobTitle $projectManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, DepartmentSeeder::class, JobTitleSeeder::class]);

        $this->employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();
        $this->adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $this->engineering = Department::query()->where('code', DepartmentCode::Engineering)->firstOrFail();
        $this->operations = Department::query()->where('code', DepartmentCode::Operations)->firstOrFail();
        $this->softwareEngineer = JobTitle::query()->where('code', JobTitleCode::SoftwareEngineer)->firstOrFail();
        $this->projectManager = JobTitle::query()->where('code', JobTitleCode::ProjectManager)->firstOrFail();
        $this->actor = User::factory()->create([
            'role_id' => $this->adminRole->id,
            'first_name' => 'Actor',
            'last_name' => 'User',
            'email' => 'actor@opsflow.test',
            'created_at' => now()->subDays(10),
        ]);
    }

    public function test_list_uses_default_pagination_and_sort(): void
    {
        User::factory()->count(2)->create([
            'role_id' => $this->employeeRole->id,
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/users');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', UserQuery::DEFAULT_PER_PAGE)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'first_name',
                        'email',
                        'status',
                        'role',
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

        $this->assertGreaterThanOrEqual(3, $response->json('meta.total'));
        $this->assertNull($response->json('errors'));
    }

    public function test_search_matches_name_and_email_fields(): void
    {
        User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'first_name' => 'UniqueFirst',
            'middle_name' => 'Mid',
            'last_name' => 'UniqueLast',
            'email' => 'needle@opsflow.test',
        ]);

        User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'first_name' => 'Other',
            'last_name' => 'Person',
            'email' => 'other@opsflow.test',
        ]);

        $byFirst = $this->actingAs($this->actor)
            ->getJson('/api/v1/users?search=UniqueFirst');
        $byFirst->assertOk();
        $this->assertSame(1, $byFirst->json('meta.total'));
        $this->assertSame('UniqueFirst', $byFirst->json('data.0.first_name'));

        $byEmail = $this->actingAs($this->actor)
            ->getJson('/api/v1/users?search=needle@opsflow');
        $byEmail->assertOk();
        $this->assertSame(1, $byEmail->json('meta.total'));
        $this->assertSame('needle@opsflow.test', $byEmail->json('data.0.email'));

        $byMiddle = $this->actingAs($this->actor)
            ->getJson('/api/v1/users?search=Mid');
        $byMiddle->assertOk();
        $this->assertSame(1, $byMiddle->json('meta.total'));
    }

    public function test_filters_are_composable(): void
    {
        User::factory()->create([
            'role_id' => $this->adminRole->id,
            'department_id' => $this->engineering->id,
            'job_title_id' => $this->softwareEngineer->id,
            'status' => UserStatus::Active,
            'first_name' => 'Match',
            'last_name' => 'Target',
            'email' => 'match.target@opsflow.test',
        ]);

        User::factory()->create([
            'role_id' => $this->adminRole->id,
            'department_id' => $this->operations->id,
            'job_title_id' => $this->softwareEngineer->id,
            'status' => UserStatus::Active,
            'first_name' => 'Wrong',
            'last_name' => 'Dept',
            'email' => 'wrong.dept@opsflow.test',
        ]);

        User::factory()->create([
            'role_id' => $this->adminRole->id,
            'department_id' => $this->engineering->id,
            'job_title_id' => $this->projectManager->id,
            'status' => UserStatus::Inactive,
            'first_name' => 'Match',
            'last_name' => 'Inactive',
            'email' => 'match.inactive@opsflow.test',
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/users?'.http_build_query([
                'search' => 'Match',
                'role_id' => $this->adminRole->id,
                'department_id' => $this->engineering->id,
                'job_title_id' => $this->softwareEngineer->id,
                'status' => UserStatus::Active->value,
            ]));

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'match.target@opsflow.test')
            ->assertJsonPath('data.0.status', UserStatus::Active->value)
            ->assertJsonPath('data.0.department.code', DepartmentCode::Engineering->value)
            ->assertJsonPath('data.0.job_title.code', JobTitleCode::SoftwareEngineer->value);
    }

    public function test_individual_filters_work(): void
    {
        User::factory()->create([
            'role_id' => $this->adminRole->id,
            'status' => UserStatus::Inactive,
            'email' => 'inactive.admin@opsflow.test',
        ]);

        $byRole = $this->actingAs($this->actor)
            ->getJson('/api/v1/users?role_id='.$this->adminRole->id);
        $byRole->assertOk();
        $this->assertTrue(collect($byRole->json('data'))->every(
            fn (array $user): bool => $user['role']['name'] === RoleName::Administrator->value
        ));

        $byStatus = $this->actingAs($this->actor)
            ->getJson('/api/v1/users?status='.UserStatus::Inactive->value);
        $byStatus->assertOk();
        $this->assertTrue(collect($byStatus->json('data'))->every(
            fn (array $user): bool => $user['status'] === UserStatus::Inactive->value
        ));
        $this->assertGreaterThanOrEqual(1, $byStatus->json('meta.total'));
    }

    public function test_sorting_by_allowed_fields(): void
    {
        User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'first_name' => 'Alpha',
            'email' => 'alpha@opsflow.test',
            'created_at' => now()->subDays(2),
        ]);
        User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'first_name' => 'Zulu',
            'email' => 'zulu@opsflow.test',
            'created_at' => now()->subDay(),
        ]);

        $asc = $this->actingAs($this->actor)
            ->getJson('/api/v1/users?sort=first_name&direction=asc&per_page=100');
        $asc->assertOk();
        $names = collect($asc->json('data'))->pluck('first_name')->all();
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);

        $desc = $this->actingAs($this->actor)
            ->getJson('/api/v1/users?sort=first_name&direction=desc&per_page=100');
        $desc->assertOk();
        $namesDesc = collect($desc->json('data'))->pluck('first_name')->all();
        $sortedDesc = $namesDesc;
        rsort($sortedDesc);
        $this->assertSame($sortedDesc, $namesDesc);
    }

    public function test_default_sort_is_created_at_desc(): void
    {
        $older = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'email' => 'older@opsflow.test',
            'created_at' => now()->subDays(5),
        ]);
        $newer = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'email' => 'newer@opsflow.test',
            'created_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/users?per_page=100');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertTrue(
            array_search($newer->id, $ids, true) < array_search($older->id, $ids, true)
        );
    }

    public function test_pagination_respects_page_and_per_page(): void
    {
        User::factory()->count(5)->create([
            'role_id' => $this->employeeRole->id,
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/users?page=2&per_page=2');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.from', 3);

        $this->assertCount(2, $response->json('data'));
        $this->assertGreaterThanOrEqual(6, $response->json('meta.total'));
    }

    public function test_per_page_above_max_is_clamped_to_100(): void
    {
        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/users?per_page=250');

        $response->assertOk()
            ->assertJsonPath('meta.per_page', UserQuery::MAX_PER_PAGE);
    }

    public function test_invalid_sort_and_status_return_validation_errors(): void
    {
        $this->actingAs($this->actor)
            ->getJson('/api/v1/users?sort=password')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['sort']);

        $this->actingAs($this->actor)
            ->getJson('/api/v1/users?status=archived')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($this->actor)
            ->getJson('/api/v1/users?direction=sideways')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['direction']);
    }

    public function test_guest_cannot_list_users(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();
    }
}
