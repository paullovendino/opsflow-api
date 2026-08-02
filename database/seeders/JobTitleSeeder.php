<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\JobTitleCode;
use App\Models\JobTitle;
use Illuminate\Database\Seeder;

class JobTitleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (JobTitleCode::cases() as $jobTitle) {
            JobTitle::query()->updateOrCreate(
                ['code' => $jobTitle],
                [
                    'name' => $jobTitle->label(),
                    'description' => $jobTitle->description(),
                ],
            );
        }
    }
}
