<?php

declare(strict_types=1);

namespace App\Services\ActivityLogs;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;
use App\Queries\ActivityLogs\ActivityLogQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class ActivityLogService
{
    public function __construct(
        private readonly ActivityLogQuery $activityLogQuery,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        ?User $actor,
        ActivityAction $action,
        Model $subject,
        string $description,
        array $properties = [],
    ): ActivityLog {
        $properties['actor_snapshot'] = $this->actorSnapshot($actor);

        return ActivityLog::query()->create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'description' => $this->truncateDescription($description),
            'properties' => $properties,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array{
     *     actor_id?: int|null,
     *     action?: string|null,
     *     subject_type?: string|null,
     *     subject_id?: int|null,
     *     from?: string|null,
     *     to?: string|null,
     *     direction?: string,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function list(array $filters = [], ?Model $subject = null): LengthAwarePaginator
    {
        return $this->activityLogQuery->paginate($filters, $subject);
    }

    /**
     * @return array{id: int, full_name: string, email: string}|null
     */
    private function actorSnapshot(?User $actor): ?array
    {
        if ($actor === null) {
            return null;
        }

        return [
            'id' => $actor->id,
            'full_name' => $actor->full_name,
            'email' => $actor->email,
        ];
    }

    private function truncateDescription(string $description): string
    {
        if (mb_strlen($description) <= 255) {
            return $description;
        }

        return mb_substr($description, 0, 254).'…';
    }
}
