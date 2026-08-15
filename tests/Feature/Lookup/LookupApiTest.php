<?php

declare(strict_types=1);

namespace Tests\Feature\Lookup;

use App\Enums\OrgEntityStatus;
use App\Enums\RoleName;
use App\Models\Department;
use App\Models\JobTitle;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\JobTitleSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LookupApiTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, DepartmentSeeder::class, JobTitleSeeder::class]);

        $employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();
        $this->actor = User::factory()->create([
            'role_id' => $employeeRole->id,
        ]);
    }

    public function test_guest_cannot_access_lookup_endpoints(): void
    {
        $this->getJson('/api/v1/lookups/roles')->assertUnauthorized();
        $this->getJson('/api/v1/lookups/departments')->assertUnauthorized();
        $this->getJson('/api/v1/lookups/job-titles')->assertUnauthorized();
    }

    public function test_authenticated_user_can_list_roles(): void
    {
        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/lookups/roles');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Roles retrieved successfully.')
            ->assertJsonPath('errors', null)
            ->assertJsonPath('meta', null);

        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertSame(
            collect($names)->sort()->values()->all(),
            $names,
        );
        $this->assertContains(RoleName::Administrator->value, $names);
        $this->assertContains(RoleName::ProjectManager->value, $names);
        $this->assertContains(RoleName::Employee->value, $names);
        $this->assertSame(['id', 'name', 'description'], array_keys($response->json('data.0')));
    }

    public function test_authenticated_user_can_list_departments(): void
    {
        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/lookups/departments');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Departments retrieved successfully.')
            ->assertJsonPath('errors', null)
            ->assertJsonPath('meta', null);

        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertSame(
            collect($names)->sort()->values()->all(),
            $names,
        );
        $this->assertSame(['id', 'name', 'code', 'description', 'status'], array_keys($response->json('data.0')));
    }

    public function test_authenticated_user_can_list_job_titles(): void
    {
        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/lookups/job-titles');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Job titles retrieved successfully.')
            ->assertJsonPath('errors', null)
            ->assertJsonPath('meta', null);

        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertSame(
            collect($names)->sort()->values()->all(),
            $names,
        );
        $this->assertSame(
            ['id', 'department_id', 'name', 'code', 'description', 'status'],
            array_keys($response->json('data.0')),
        );
    }

    public function test_job_titles_lookup_can_filter_by_department(): void
    {
        $department = Department::query()->where('name', 'Engineering')->firstOrFail();

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/lookups/job-titles?department_id='.$department->id);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('department_id')->unique()->values()->all();

        $this->assertSame([$department->id], $ids);
    }

    public function test_nested_department_job_titles_lookup(): void
    {
        $department = Department::query()->where('name', 'Engineering')->firstOrFail();

        $response = $this->actingAs($this->actor)
            ->getJson("/api/v1/lookups/departments/{$department->id}/job-titles");

        $response->assertOk();

        foreach ($response->json('data') as $row) {
            $this->assertSame($department->id, $row['department_id']);
            $this->assertSame(OrgEntityStatus::Active->value, $row['status']);
        }
    }

    public function test_job_titles_lookup_can_include_inactive_id(): void
    {
        $jobTitle = JobTitle::query()->orderBy('name')->firstOrFail();
        $jobTitle->update(['status' => OrgEntityStatus::Inactive]);

        $without = $this->actingAs($this->actor)
            ->getJson('/api/v1/lookups/job-titles');

        $withoutIds = collect($without->json('data'))->pluck('id')->all();
        $this->assertNotContains($jobTitle->id, $withoutIds);

        $with = $this->actingAs($this->actor)
            ->getJson('/api/v1/lookups/job-titles?include_id='.$jobTitle->id);

        $withIds = collect($with->json('data'))->pluck('id')->all();
        $this->assertContains($jobTitle->id, $withIds);
    }

    public function test_inactive_departments_are_excluded_from_lookup(): void
    {
        $department = Department::query()->orderBy('name')->firstOrFail();
        $department->update(['status' => OrgEntityStatus::Inactive]);

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/lookups/departments');

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertNotContains($department->id, $ids);
    }

    public function test_soft_deleted_departments_are_excluded(): void
    {
        $department = Department::query()->orderBy('name')->firstOrFail();
        $department->delete();

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/lookups/departments');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertNotContains($department->id, $ids);
        $this->assertCount(Department::query()->active()->count(), $ids);
    }

    public function test_soft_deleted_job_titles_are_excluded(): void
    {
        $jobTitle = JobTitle::query()->orderBy('name')->firstOrFail();
        $jobTitle->delete();

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/lookups/job-titles');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertNotContains($jobTitle->id, $ids);
        $this->assertCount(JobTitle::query()->active()->count(), $ids);
    }

    public function test_lookup_results_are_sorted_alphabetically_by_name(): void
    {
        $roles = $this->actingAs($this->actor)
            ->getJson('/api/v1/lookups/roles')
            ->json('data');

        $departments = $this->actingAs($this->actor)
            ->getJson('/api/v1/lookups/departments')
            ->json('data');

        $jobTitles = $this->actingAs($this->actor)
            ->getJson('/api/v1/lookups/job-titles')
            ->json('data');

        $this->assertSame(
            ['administrator', 'employee', 'project_manager'],
            collect($roles)->pluck('name')->all(),
        );
        $this->assertSame(
            ['Administration', 'Engineering', 'Finance', 'Human Resources', 'Operations'],
            collect($departments)->pluck('name')->all(),
        );
        $this->assertSame(
            [
                'Administrator',
                'Human Resources Specialist',
                'Operations Specialist',
                'Project Manager',
                'Software Engineer',
            ],
            collect($jobTitles)->pluck('name')->all(),
        );
    }
}
