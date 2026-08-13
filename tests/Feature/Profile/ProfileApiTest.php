<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Enums\ActivityAction;
use App\Enums\DepartmentCode;
use App\Enums\JobTitleCode;
use App\Enums\ProjectStatus;
use App\Enums\RoleName;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\JobTitle;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\JobTitleSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private Role $employeeRole;

    private Role $adminRole;

    private Department $department;

    private JobTitle $jobTitle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, DepartmentSeeder::class, JobTitleSeeder::class]);

        $this->adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $this->employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();
        $this->department = Department::query()->where('code', DepartmentCode::Operations)->firstOrFail();
        $this->jobTitle = JobTitle::query()->where('code', JobTitleCode::SoftwareEngineer)->firstOrFail();

        $this->employee = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'department_id' => $this->department->id,
            'job_title_id' => $this->jobTitle->id,
            'first_name' => 'Eli',
            'middle_name' => null,
            'last_name' => 'Employee',
            'email' => 'eli.profile@opsflow.test',
            'password' => Hash::make('password'),
            'avatar' => null,
            'status' => UserStatus::Active,
            'theme_preference' => 'system',
            'notify_task_assigned' => true,
            'notify_task_status' => true,
            'notify_remarks' => true,
            'notify_mentions' => true,
        ]);
    }

    public function test_guest_cannot_access_profile(): void
    {
        $this->getJson('/api/v1/profile')->assertUnauthorized();
        $this->putJson('/api/v1/profile', [
            'first_name' => 'Nope',
        ])->assertUnauthorized();
    }

    public function test_authenticated_user_can_get_own_profile(): void
    {
        $owned = Project::factory()->create([
            'created_by' => $this->employee->id,
            'status' => ProjectStatus::Active,
        ]);
        $member = Project::factory()->create([
            'created_by' => User::factory()->create(['role_id' => $this->adminRole->id])->id,
            'status' => ProjectStatus::Active,
        ]);
        $member->members()->attach($this->employee->id, ['joined_at' => now()]);

        Task::factory()->create([
            'project_id' => $owned->id,
            'created_by' => $this->employee->id,
            'assigned_to' => $this->employee->id,
            'status' => TaskStatus::Todo,
            'due_date' => now()->subDay()->toDateString(),
        ]);
        Task::factory()->create([
            'project_id' => $owned->id,
            'created_by' => $this->employee->id,
            'assigned_to' => $this->employee->id,
            'status' => TaskStatus::Completed,
        ]);

        ActivityLog::factory()->create([
            'actor_id' => $this->employee->id,
            'action' => ActivityAction::UserUpdated,
            'subject_type' => $this->employee->getMorphClass(),
            'subject_id' => $this->employee->id,
            'description' => 'Updated profile for Eli Employee.',
        ]);

        $response = $this->actingAs($this->employee)
            ->getJson('/api/v1/profile');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Profile retrieved successfully.')
            ->assertJsonPath('data.user.id', $this->employee->id)
            ->assertJsonPath('data.user.email', $this->employee->email)
            ->assertJsonPath('data.user.theme_preference', 'system')
            ->assertJsonPath('data.user.notify_task_assigned', true)
            ->assertJsonPath('data.projects.owned_count', 1)
            ->assertJsonPath('data.projects.member_count', 1)
            ->assertJsonPath('data.tasks.assigned_open', 1)
            ->assertJsonPath('data.tasks.assigned_overdue', 1)
            ->assertJsonMissingPath('data.user.password');

        $this->assertNotEmpty($response->json('data.recent_activity'));
    }

    public function test_user_can_update_name_prefs_and_theme(): void
    {
        $response = $this->actingAs($this->employee)
            ->putJson('/api/v1/profile', [
                'first_name' => 'Elena',
                'middle_name' => 'Q',
                'last_name' => 'Employee',
                'theme_preference' => 'dark',
                'notify_task_assigned' => false,
                'notify_task_status' => true,
                'notify_remarks' => false,
                'notify_mentions' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Profile updated successfully.')
            ->assertJsonPath('data.user.first_name', 'Elena')
            ->assertJsonPath('data.user.middle_name', 'Q')
            ->assertJsonPath('data.user.theme_preference', 'dark')
            ->assertJsonPath('data.user.notify_task_assigned', false)
            ->assertJsonPath('data.user.notify_remarks', false)
            ->assertJsonMissingPath('data.user.password');

        $this->employee->refresh();
        $this->assertSame('Elena', $this->employee->first_name);
        $this->assertSame('dark', $this->employee->theme_preference);
        $this->assertFalse($this->employee->notify_task_assigned);
        $this->assertFalse($this->employee->notify_remarks);
    }

    public function test_user_can_change_password_with_confirmation(): void
    {
        $response = $this->actingAs($this->employee)
            ->putJson('/api/v1/profile', [
                'password' => 'NewSecurePass1!',
                'password_confirmation' => 'NewSecurePass1!',
            ]);

        $response->assertOk()
            ->assertJsonMissingPath('data.user.password');

        $this->employee->refresh();
        $this->assertTrue(Hash::check('NewSecurePass1!', $this->employee->password));
    }

    public function test_password_requires_confirmation_and_valid_rules(): void
    {
        $this->actingAs($this->employee)
            ->putJson('/api/v1/profile', [
                'password' => 'NewSecurePass1!',
                'password_confirmation' => 'mismatch',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->actingAs($this->employee)
            ->putJson('/api/v1/profile', [
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_invalid_theme_is_rejected(): void
    {
        $this->actingAs($this->employee)
            ->putJson('/api/v1/profile', [
                'theme_preference' => 'neon',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['theme_preference']);
    }

    public function test_avatar_url_string_is_rejected(): void
    {
        $this->actingAs($this->employee)
            ->putJson('/api/v1/profile', [
                'avatar' => 'https://cdn.opsflow.test/avatars/elena.png',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['avatar']);
    }

    public function test_invalid_notification_preference_values_are_rejected(): void
    {
        $this->actingAs($this->employee)
            ->putJson('/api/v1/profile', [
                'notify_task_assigned' => 'yes-please',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['notify_task_assigned']);
    }

    public function test_protected_fields_cannot_be_changed_via_profile(): void
    {
        $otherDepartment = Department::query()->where('code', DepartmentCode::Engineering)->firstOrFail();
        $otherTitle = JobTitle::query()->where('code', JobTitleCode::ProjectManager)->firstOrFail();

        $response = $this->actingAs($this->employee)
            ->putJson('/api/v1/profile', [
                'first_name' => 'Eli',
                'last_name' => 'Employee',
                'email' => 'hijack@opsflow.test',
                'role_id' => $this->adminRole->id,
                'department_id' => $otherDepartment->id,
                'job_title_id' => $otherTitle->id,
                'status' => UserStatus::Inactive->value,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.user.email', 'eli.profile@opsflow.test')
            ->assertJsonPath('data.user.role.name', RoleName::Employee->value)
            ->assertJsonPath('data.user.department.id', $this->department->id)
            ->assertJsonPath('data.user.job_title.id', $this->jobTitle->id)
            ->assertJsonPath('data.user.status', UserStatus::Active->value);

        $this->employee->refresh();
        $this->assertSame('eli.profile@opsflow.test', $this->employee->email);
        $this->assertSame($this->employeeRole->id, $this->employee->role_id);
        $this->assertSame($this->department->id, $this->employee->department_id);
        $this->assertSame($this->jobTitle->id, $this->employee->job_title_id);
        $this->assertSame(UserStatus::Active, $this->employee->status);
    }

    public function test_me_includes_preference_fields(): void
    {
        $this->employee->forceFill([
            'theme_preference' => 'light',
            'notify_mentions' => false,
        ])->save();

        $this->actingAs($this->employee)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.theme_preference', 'light')
            ->assertJsonPath('data.notify_mentions', false)
            ->assertJsonMissingPath('data.password');
    }

    public function test_profile_password_change_is_logged_without_password_values(): void
    {
        $this->actingAs($this->employee)
            ->putJson('/api/v1/profile', [
                'password' => 'AnotherSecure1!',
                'password_confirmation' => 'AnotherSecure1!',
                'theme_preference' => 'light',
            ])
            ->assertOk();

        $log = ActivityLog::query()
            ->where('action', ActivityAction::UserUpdated->value)
            ->where('subject_id', $this->employee->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertTrue((bool) ($log->properties['password_changed'] ?? false));
        $encoded = json_encode($log->properties);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('AnotherSecure1!', $encoded);
        $this->assertArrayNotHasKey('password', $log->properties['before'] ?? []);
        $this->assertArrayNotHasKey('password', $log->properties['after'] ?? []);
    }
}
