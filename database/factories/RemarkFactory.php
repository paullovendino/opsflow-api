<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\Remark;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Remark>
 */
class RemarkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'author_id' => User::factory(),
            'remarkable_type' => (new Project)->getMorphClass(),
            'remarkable_id' => Project::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
