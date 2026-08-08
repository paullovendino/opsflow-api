<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory(),
            'action' => ActivityAction::ProjectCreated,
            'subject_type' => (new Project)->getMorphClass(),
            'subject_id' => Project::factory(),
            'description' => 'Created project.',
            'properties' => [],
            'created_at' => now(),
        ];
    }
}
