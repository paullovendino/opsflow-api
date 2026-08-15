<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DepartmentCode;
use App\Enums\JobTitleCode;
use App\Enums\OrgEntityStatus;
use App\Models\Department;
use App\Models\JobTitle;
use Illuminate\Database\Seeder;
use RuntimeException;

class JobTitleSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private array $titleToDepartment = [
        'ADMIN' => 'ADMIN',
        'PM' => 'OPS',
        'SE' => 'ENG',
        'OPS_SPEC' => 'OPS',
        'HR_SPEC' => 'HR',
    ];

    public function run(): void
    {
        foreach (JobTitleCode::cases() as $jobTitle) {
            $departmentCode = $this->titleToDepartment[$jobTitle->value] ?? null;
            if ($departmentCode === null) {
                throw new RuntimeException("No department mapping for job title code [{$jobTitle->value}].");
            }

            $department = Department::query()->where('code', $departmentCode)->first();
            if ($department === null) {
                throw new RuntimeException("Department [{$departmentCode}] missing; seed departments first.");
            }

            JobTitle::query()->updateOrCreate(
                ['code' => $jobTitle->value],
                [
                    'department_id' => $department->id,
                    'name' => $jobTitle->label(),
                    'description' => $jobTitle->description(),
                    'status' => OrgEntityStatus::Active,
                ],
            );
        }
    }
}
