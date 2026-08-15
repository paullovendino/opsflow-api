<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Enums\DepartmentCode;
use App\Enums\JobTitleCode;
use App\Enums\OrgEntityStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\JobTitle;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\JobTitleSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserOrgAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Role $employeeRole;

    private Department $engineering;

    private Department $operations;

    private JobTitle $softwareEngineer;

    private JobTitle $projectManagerTitle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, DepartmentSeeder::class, JobTitleSeeder::class]);

        $adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $this->employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();
        $this->actor = User::factory()->create(['role_id' => $adminRole->id]);

        $this->engineering = Department::query()->where('code', DepartmentCode::Engineering->value)->firstOrFail();
        $this->operations = Department::query()->where('code', DepartmentCode::Operations->value)->firstOrFail();
        $this->softwareEngineer = JobTitle::query()->where('code', JobTitleCode::SoftwareEngineer->value)->firstOrFail();
        $this->projectManagerTitle = JobTitle::query()->where('code', JobTitleCode::ProjectManager->value)->firstOrFail();
    }

    public function test_empty_department_clears_job_title_on_create(): void
    {
        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/users', $this->validPayload([
                'email' => 'no.dept@opsflow.test',
                'department_id' => null,
                'job_title_id' => $this->softwareEngineer->id,
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.department', null)
            ->assertJsonPath('data.job_title', null);

        $this->assertDatabaseHas('users', [
            'email' => 'no.dept@opsflow.test',
            'department_id' => null,
            'job_title_id' => null,
        ]);
    }

    public function test_job_title_must_belong_to_department(): void
    {
        $this->actingAs($this->actor)
            ->postJson('/api/v1/users', $this->validPayload([
                'department_id' => $this->engineering->id,
                'job_title_id' => $this->projectManagerTitle->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['job_title_id']);
    }

    public function test_inactive_department_cannot_be_newly_assigned(): void
    {
        $this->engineering->update(['status' => OrgEntityStatus::Inactive]);

        $this->actingAs($this->actor)
            ->postJson('/api/v1/users', $this->validPayload([
                'department_id' => $this->engineering->id,
                'job_title_id' => $this->softwareEngineer->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id']);
    }

    public function test_inactive_job_title_cannot_be_newly_assigned(): void
    {
        $this->softwareEngineer->update(['status' => OrgEntityStatus::Inactive]);

        $this->actingAs($this->actor)
            ->postJson('/api/v1/users', $this->validPayload([
                'department_id' => $this->engineering->id,
                'job_title_id' => $this->softwareEngineer->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['job_title_id']);
    }

    public function test_update_allows_keeping_current_inactive_department_and_title(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'department_id' => $this->engineering->id,
            'job_title_id' => $this->softwareEngineer->id,
            'email' => 'keep.inactive@opsflow.test',
        ]);

        $this->engineering->update(['status' => OrgEntityStatus::Inactive]);
        $this->softwareEngineer->update(['status' => OrgEntityStatus::Inactive]);

        $this->actingAs($this->actor)
            ->putJson("/api/v1/users/{$user->id}", $this->validPayload([
                'email' => $user->email,
                'department_id' => $this->engineering->id,
                'job_title_id' => $this->softwareEngineer->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.department.id', $this->engineering->id)
            ->assertJsonPath('data.job_title.id', $this->softwareEngineer->id);
    }

    public function test_update_rejects_switching_to_inactive_department(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'department_id' => $this->operations->id,
            'job_title_id' => $this->projectManagerTitle->id,
            'email' => 'switch.inactive@opsflow.test',
        ]);

        $this->engineering->update(['status' => OrgEntityStatus::Inactive]);

        $this->actingAs($this->actor)
            ->putJson("/api/v1/users/{$user->id}", $this->validPayload([
                'email' => $user->email,
                'department_id' => $this->engineering->id,
                'job_title_id' => $this->softwareEngineer->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id']);
    }

    public function test_prepare_for_validation_clears_job_title_when_department_empty_on_update(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'department_id' => $this->engineering->id,
            'job_title_id' => $this->softwareEngineer->id,
            'email' => 'clear.title@opsflow.test',
        ]);

        $this->actingAs($this->actor)
            ->putJson("/api/v1/users/{$user->id}", $this->validPayload([
                'email' => $user->email,
                'department_id' => null,
                'job_title_id' => $this->softwareEngineer->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.department', null)
            ->assertJsonPath('data.job_title', null);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'department_id' => null,
            'job_title_id' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Jane',
            'middle_name' => null,
            'last_name' => 'Doe',
            'email' => 'jane.doe@opsflow.test',
            'password' => 'password123',
            'role_id' => $this->employeeRole->id,
            'department_id' => $this->engineering->id,
            'job_title_id' => $this->softwareEngineer->id,
            'status' => UserStatus::Active->value,
            'avatar' => null,
        ], $overrides);
    }
}
