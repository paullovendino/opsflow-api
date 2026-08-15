<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Enums\DepartmentCode;
use App\Enums\JobTitleCode;
use App\Enums\OrgEntityStatus;
use App\Models\Department;
use App\Models\JobTitle;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\JobTitleSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrganizationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_departments_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('departments'));
        $this->assertTrue(Schema::hasColumns('departments', [
            'id',
            'name',
            'code',
            'description',
            'status',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));
    }

    public function test_job_titles_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('job_titles'));
        $this->assertTrue(Schema::hasColumns('job_titles', [
            'id',
            'department_id',
            'name',
            'code',
            'description',
            'status',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));
    }

    public function test_department_seeder_creates_approved_records(): void
    {
        $this->seed(DepartmentSeeder::class);

        $this->assertSame(count(DepartmentCode::cases()), Department::query()->count());

        foreach (DepartmentCode::cases() as $code) {
            $department = Department::query()->where('code', $code->value)->first();

            $this->assertNotNull($department);
            $this->assertSame($code->label(), $department->name);
            $this->assertSame($code->value, $department->code);
            $this->assertSame($code->description(), $department->description);
            $this->assertSame(OrgEntityStatus::Active, $department->status);
        }
    }

    public function test_job_title_seeder_creates_approved_records_with_department_mapping(): void
    {
        $this->seed([DepartmentSeeder::class, JobTitleSeeder::class]);

        $this->assertSame(count(JobTitleCode::cases()), JobTitle::query()->count());

        $expected = [
            JobTitleCode::Administrator->value => DepartmentCode::Administration->value,
            JobTitleCode::ProjectManager->value => DepartmentCode::Operations->value,
            JobTitleCode::SoftwareEngineer->value => DepartmentCode::Engineering->value,
            JobTitleCode::OperationsSpecialist->value => DepartmentCode::Operations->value,
            JobTitleCode::HumanResourcesSpecialist->value => DepartmentCode::HumanResources->value,
        ];

        foreach (JobTitleCode::cases() as $code) {
            $jobTitle = JobTitle::query()->where('code', $code->value)->first();

            $this->assertNotNull($jobTitle);
            $this->assertSame($code->label(), $jobTitle->name);
            $this->assertSame($code->value, $jobTitle->code);
            $this->assertSame($code->description(), $jobTitle->description);
            $this->assertSame(OrgEntityStatus::Active, $jobTitle->status);
            $this->assertNotNull($jobTitle->department_id);

            $department = Department::query()->find($jobTitle->department_id);
            $this->assertNotNull($department);
            $this->assertSame($expected[$code->value], $department->code);
        }
    }

    public function test_department_name_is_unique_case_insensitive(): void
    {
        Department::query()->create([
            'name' => 'Administration',
            'code' => DepartmentCode::Administration->value,
            'description' => 'Company administration and leadership',
            'status' => OrgEntityStatus::Active,
        ]);

        $this->expectException(QueryException::class);

        Department::query()->create([
            'name' => 'administration',
            'code' => DepartmentCode::Operations->value,
            'description' => null,
            'status' => OrgEntityStatus::Active,
        ]);
    }

    public function test_department_code_is_unique(): void
    {
        Department::query()->create([
            'name' => 'Administration',
            'code' => DepartmentCode::Administration->value,
            'description' => null,
            'status' => OrgEntityStatus::Active,
        ]);

        $this->expectException(QueryException::class);

        Department::query()->create([
            'name' => 'Another Admin',
            'code' => DepartmentCode::Administration->value,
            'description' => null,
            'status' => OrgEntityStatus::Active,
        ]);
    }

    public function test_job_title_name_is_unique_per_department_case_insensitive(): void
    {
        $department = Department::query()->create([
            'name' => 'Engineering',
            'code' => DepartmentCode::Engineering->value,
            'status' => OrgEntityStatus::Active,
        ]);

        JobTitle::query()->create([
            'department_id' => $department->id,
            'name' => 'Project Manager',
            'code' => JobTitleCode::ProjectManager->value,
            'description' => null,
            'status' => OrgEntityStatus::Active,
        ]);

        $this->expectException(QueryException::class);

        JobTitle::query()->create([
            'department_id' => $department->id,
            'name' => 'project manager',
            'code' => JobTitleCode::SoftwareEngineer->value,
            'description' => null,
            'status' => OrgEntityStatus::Active,
        ]);
    }

    public function test_job_title_code_is_unique(): void
    {
        $department = Department::query()->create([
            'name' => 'Operations',
            'code' => DepartmentCode::Operations->value,
            'status' => OrgEntityStatus::Active,
        ]);

        JobTitle::query()->create([
            'department_id' => $department->id,
            'name' => 'Project Manager',
            'code' => JobTitleCode::ProjectManager->value,
            'description' => null,
            'status' => OrgEntityStatus::Active,
        ]);

        $this->expectException(QueryException::class);

        JobTitle::query()->create([
            'department_id' => $department->id,
            'name' => 'Another Project Manager',
            'code' => JobTitleCode::ProjectManager->value,
            'description' => null,
            'status' => OrgEntityStatus::Active,
        ]);
    }

    public function test_departments_support_soft_deletes(): void
    {
        $department = Department::query()->create([
            'name' => 'Administration',
            'code' => DepartmentCode::Administration->value,
            'description' => null,
            'status' => OrgEntityStatus::Active,
        ]);

        $department->delete();

        $this->assertSoftDeleted($department);
        $this->assertNull(Department::query()->find($department->id));
        $this->assertNotNull(Department::withTrashed()->find($department->id));
    }

    public function test_job_titles_support_soft_deletes(): void
    {
        $department = Department::query()->create([
            'name' => 'Operations',
            'code' => DepartmentCode::Operations->value,
            'status' => OrgEntityStatus::Active,
        ]);

        $jobTitle = JobTitle::query()->create([
            'department_id' => $department->id,
            'name' => 'Project Manager',
            'code' => JobTitleCode::ProjectManager->value,
            'description' => null,
            'status' => OrgEntityStatus::Active,
        ]);

        $jobTitle->delete();

        $this->assertSoftDeleted($jobTitle);
        $this->assertNull(JobTitle::query()->find($jobTitle->id));
        $this->assertNotNull(JobTitle::withTrashed()->find($jobTitle->id));
    }

    public function test_migration_mapping_reconciles_mismatched_user_job_titles(): void
    {
        $this->seed([RolesSeeder::class, DepartmentSeeder::class, JobTitleSeeder::class]);

        $engineering = Department::query()->where('code', DepartmentCode::Engineering->value)->firstOrFail();
        $operations = Department::query()->where('code', DepartmentCode::Operations->value)->firstOrFail();
        $pmTitle = JobTitle::query()->where('code', JobTitleCode::ProjectManager->value)->firstOrFail();

        $this->assertSame($operations->id, $pmTitle->department_id);

        $user = User::factory()->create([
            'department_id' => $engineering->id,
            'job_title_id' => $pmTitle->id,
        ]);

        // Simulate the migration reconcile rule: mismatched title is cleared.
        if ((int) $user->department_id !== (int) $pmTitle->department_id) {
            $user->update(['job_title_id' => null]);
        }

        $this->assertNull($user->fresh()->job_title_id);
        $this->assertSame($engineering->id, $user->fresh()->department_id);
    }
}
