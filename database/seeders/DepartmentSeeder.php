<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DepartmentCode;
use App\Enums\OrgEntityStatus;
use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (DepartmentCode::cases() as $department) {
            Department::query()->updateOrCreate(
                ['code' => $department->value],
                [
                    'name' => $department->label(),
                    'description' => $department->description(),
                    'status' => OrgEntityStatus::Active,
                ],
            );
        }
    }
}
