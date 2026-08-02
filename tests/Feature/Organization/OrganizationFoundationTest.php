<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Enums\DepartmentCode;
use App\Enums\JobTitleCode;
use App\Models\Department;
use App\Models\JobTitle;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\JobTitleSeeder;
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
            'name',
            'code',
            'description',
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
            $this->assertSame($code, $department->code);
            $this->assertSame($code->description(), $department->description);
        }
    }

    public function test_job_title_seeder_creates_approved_records(): void
    {
        $this->seed(JobTitleSeeder::class);

        $this->assertSame(count(JobTitleCode::cases()), JobTitle::query()->count());

        foreach (JobTitleCode::cases() as $code) {
            $jobTitle = JobTitle::query()->where('code', $code->value)->first();

            $this->assertNotNull($jobTitle);
            $this->assertSame($code->label(), $jobTitle->name);
            $this->assertSame($code, $jobTitle->code);
            $this->assertSame($code->description(), $jobTitle->description);
        }
    }

    public function test_department_name_and_code_are_unique(): void
    {
        Department::query()->create([
            'name' => 'Administration',
            'code' => DepartmentCode::Administration,
            'description' => 'Company administration and leadership',
        ]);

        $this->expectException(QueryException::class);

        Department::query()->create([
            'name' => 'Administration',
            'code' => DepartmentCode::Operations,
            'description' => null,
        ]);
    }

    public function test_department_code_is_unique(): void
    {
        Department::query()->create([
            'name' => 'Administration',
            'code' => DepartmentCode::Administration,
            'description' => null,
        ]);

        $this->expectException(QueryException::class);

        Department::query()->create([
            'name' => 'Another Admin',
            'code' => DepartmentCode::Administration,
            'description' => null,
        ]);
    }

    public function test_job_title_name_and_code_are_unique(): void
    {
        JobTitle::query()->create([
            'name' => 'Project Manager',
            'code' => JobTitleCode::ProjectManager,
            'description' => null,
        ]);

        $this->expectException(QueryException::class);

        JobTitle::query()->create([
            'name' => 'Project Manager',
            'code' => JobTitleCode::SoftwareEngineer,
            'description' => null,
        ]);
    }

    public function test_job_title_code_is_unique(): void
    {
        JobTitle::query()->create([
            'name' => 'Project Manager',
            'code' => JobTitleCode::ProjectManager,
            'description' => null,
        ]);

        $this->expectException(QueryException::class);

        JobTitle::query()->create([
            'name' => 'Another Project Manager',
            'code' => JobTitleCode::ProjectManager,
            'description' => null,
        ]);
    }

    public function test_departments_support_soft_deletes(): void
    {
        $department = Department::query()->create([
            'name' => 'Administration',
            'code' => DepartmentCode::Administration,
            'description' => null,
        ]);

        $department->delete();

        $this->assertSoftDeleted($department);
        $this->assertNull(Department::query()->find($department->id));
        $this->assertNotNull(Department::withTrashed()->find($department->id));
    }

    public function test_job_titles_support_soft_deletes(): void
    {
        $jobTitle = JobTitle::query()->create([
            'name' => 'Project Manager',
            'code' => JobTitleCode::ProjectManager,
            'description' => null,
        ]);

        $jobTitle->delete();

        $this->assertSoftDeleted($jobTitle);
        $this->assertNull(JobTitle::query()->find($jobTitle->id));
        $this->assertNotNull(JobTitle::withTrashed()->find($jobTitle->id));
    }
}
