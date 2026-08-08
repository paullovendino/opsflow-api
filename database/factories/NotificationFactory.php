<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $recipient = User::factory();
        $task = Task::factory();

        return [
            'recipient_id' => $recipient,
            'actor_id' => User::factory(),
            'type' => NotificationType::TaskAssigned,
            'subject_type' => (new Task)->getMorphClass(),
            'subject_id' => $task,
            'data' => [
                'title' => 'You were assigned a task',
                'message' => fake()->sentence(),
                'target_type' => 'task',
                'target_id' => 1,
            ],
            'read_at' => null,
            'created_at' => now(),
        ];
    }

    public function read(): static
    {
        return $this->state(fn (): array => [
            'read_at' => now(),
        ]);
    }
}
