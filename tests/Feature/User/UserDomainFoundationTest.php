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
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\JobTitleSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserDomainFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const STATEFUL_ORIGIN = 'http://localhost:5173';

    public function test_users_table_has_organization_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'role_id',
            'department_id',
            'job_title_id',
            'first_name',
            'middle_name',
            'last_name',
            'email',
            'email_verified_at',
            'password',
            'avatar',
            'status',
            'last_login_at',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));

        $this->assertFalse(Schema::hasColumn('users', 'name'));
    }

    public function test_existing_user_name_is_migrated_into_structured_fields(): void
    {
        $this->seed(RolesSeeder::class);

        $this->artisan('migrate:rollback', ['--step' => 3])->assertSuccessful();

        $this->assertTrue(Schema::hasColumn('users', 'name'));
        $this->assertFalse(Schema::hasColumn('users', 'first_name'));

        DB::table('users')->insert([
            [
                'name' => 'Jane Doe',
                'email' => 'jane@opsflow.test',
                'password' => Hash::make('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'John Paul Lovendino',
                'email' => 'john@opsflow.test',
                'password' => Hash::make('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Singleton',
                'email' => 'solo@opsflow.test',
                'password' => Hash::make('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->artisan('migrate')->assertSuccessful();

        $jane = User::query()->where('email', 'jane@opsflow.test')->firstOrFail();
        $this->assertSame('Jane', $jane->first_name);
        $this->assertNull($jane->middle_name);
        $this->assertSame('Doe', $jane->last_name);
        $this->assertSame(UserStatus::Active, $jane->status);
        $this->assertSame(RoleName::Employee, $jane->role->name);

        $john = User::query()->where('email', 'john@opsflow.test')->firstOrFail();
        $this->assertSame('John', $john->first_name);
        $this->assertSame('Paul', $john->middle_name);
        $this->assertSame('Lovendino', $john->last_name);

        $solo = User::query()->where('email', 'solo@opsflow.test')->firstOrFail();
        $this->assertSame('Singleton', $solo->first_name);
        $this->assertNull($solo->middle_name);
        $this->assertNull($solo->last_name);
    }

    public function test_user_belongs_to_role_department_and_job_title(): void
    {
        $this->seed([RolesSeeder::class, DepartmentSeeder::class, JobTitleSeeder::class]);

        $role = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $department = Department::query()->where('code', DepartmentCode::Engineering)->firstOrFail();
        $jobTitle = JobTitle::query()->where('code', JobTitleCode::SoftwareEngineer)->firstOrFail();

        $user = User::factory()->create([
            'role_id' => $role->id,
            'department_id' => $department->id,
            'job_title_id' => $jobTitle->id,
        ]);

        $user->load(['role', 'department', 'jobTitle']);

        $this->assertTrue($user->role->is($role));
        $this->assertTrue($user->department->is($department));
        $this->assertTrue($user->jobTitle->is($jobTitle));
    }

    public function test_user_status_is_cast_to_enum(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::Active,
        ]);

        $this->assertInstanceOf(UserStatus::class, $user->status);
        $this->assertSame(UserStatus::Active, $user->status);

        $user->update(['status' => UserStatus::Inactive]);
        $user->refresh();

        $this->assertSame(UserStatus::Inactive, $user->status);
    }

    public function test_full_name_accessor_follows_documented_rules(): void
    {
        $withMiddle = User::factory()->make([
            'first_name' => 'John',
            'middle_name' => 'Paul',
            'last_name' => 'Lovendino',
        ]);
        $this->assertSame('John Paul Lovendino', $withMiddle->full_name);

        $withoutMiddle = User::factory()->make([
            'first_name' => 'John',
            'middle_name' => null,
            'last_name' => 'Lovendino',
        ]);
        $this->assertSame('John Lovendino', $withoutMiddle->full_name);

        $firstOnly = User::factory()->make([
            'first_name' => 'John',
            'middle_name' => 'Paul',
            'last_name' => null,
        ]);
        $this->assertSame('John', $firstOnly->full_name);
    }

    public function test_users_support_soft_deletes(): void
    {
        $user = User::factory()->create();

        $user->delete();

        $this->assertSoftDeleted($user);
        $this->assertNull(User::query()->find($user->id));
        $this->assertNotNull(User::withTrashed()->find($user->id));
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->inactive()->create([
            'email' => 'inactive@opsflow.test',
        ]);

        $response = $this->withHeader('Origin', self::STATEFUL_ORIGIN)
            ->postJson('/api/v1/auth/login', [
                'email' => 'inactive@opsflow.test',
                'password' => 'password',
            ]);

        $response->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Account is inactive.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors', null);

        $this->assertGuest();
        $this->assertNull($user->fresh()->last_login_at);
    }

    public function test_active_user_login_returns_expanded_user_resource(): void
    {
        $this->seed([RolesSeeder::class, DepartmentSeeder::class, JobTitleSeeder::class]);

        $role = Role::query()->where('name', RoleName::ProjectManager)->firstOrFail();
        $department = Department::query()->where('code', DepartmentCode::Operations)->firstOrFail();
        $jobTitle = JobTitle::query()->where('code', JobTitleCode::ProjectManager)->firstOrFail();

        $user = User::factory()->create([
            'email' => 'pm@opsflow.test',
            'role_id' => $role->id,
            'department_id' => $department->id,
            'job_title_id' => $jobTitle->id,
            'first_name' => 'Pat',
            'middle_name' => null,
            'last_name' => 'Manager',
        ]);

        $response = $this->withHeader('Origin', self::STATEFUL_ORIGIN)
            ->postJson('/api/v1/auth/login', [
                'email' => 'pm@opsflow.test',
                'password' => 'password',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.first_name', 'Pat')
            ->assertJsonPath('data.user.last_name', 'Manager')
            ->assertJsonPath('data.user.full_name', 'Pat Manager')
            ->assertJsonPath('data.user.status', UserStatus::Active->value)
            ->assertJsonPath('data.user.role.name', RoleName::ProjectManager->value)
            ->assertJsonPath('data.user.department.code', DepartmentCode::Operations->value)
            ->assertJsonPath('data.user.job_title.code', JobTitleCode::ProjectManager->value)
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.name');

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertAuthenticatedAs($user);
    }
}
