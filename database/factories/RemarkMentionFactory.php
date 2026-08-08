<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Remark;
use App\Models\RemarkMention;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RemarkMention>
 */
class RemarkMentionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'remark_id' => Remark::factory(),
            'user_id' => User::factory(),
            'created_at' => now(),
        ];
    }
}
