<?php

declare(strict_types=1);

namespace App\Services\Projects;

final class ProjectProgress
{
    /**
     * Derived percent of completed tasks among non-cancelled tasks.
     * Null when there are no eligible (non-cancelled) tasks.
     */
    public static function percent(int $eligibleCount, int $completedCount): ?int
    {
        if ($eligibleCount <= 0) {
            return null;
        }

        $completedCount = max(0, min($completedCount, $eligibleCount));

        return (int) round(100 * $completedCount / $eligibleCount);
    }
}
