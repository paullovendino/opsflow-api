<?php

declare(strict_types=1);

namespace App\Services\ActivityLogs;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;
use App\Queries\ActivityLogs\ActivityLogQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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
        $paginator = $this->activityLogQuery->paginate($filters, $subject);
        $this->enrichAssigneeNames($paginator->items());

        return $paginator;
    }

    /**
     * Resolve missing assigned_to_name values for older logs (IDs only) without N+1.
     *
     * @param  iterable<int, ActivityLog>  $logs
     */
    private function enrichAssigneeNames(iterable $logs): void
    {
        $ids = [];

        foreach ($logs as $log) {
            $properties = is_array($log->properties) ? $log->properties : [];

            foreach (['before', 'after'] as $side) {
                if (! isset($properties[$side]) || ! is_array($properties[$side])) {
                    continue;
                }

                $name = $properties[$side]['assigned_to_name'] ?? null;
                $assignedTo = $properties[$side]['assigned_to'] ?? null;

                if (($name === null || $name === '') && is_numeric($assignedTo)) {
                    $ids[] = (int) $assignedTo;
                }
            }
        }

        if ($ids === []) {
            return;
        }

        /** @var Collection<int, string> $namesById */
        $namesById = User::withTrashed()
            ->whereIn('id', array_values(array_unique($ids)))
            ->get()
            ->mapWithKeys(static fn (User $user): array => [(int) $user->id => $user->full_name]);

        foreach ($logs as $log) {
            $properties = is_array($log->properties) ? $log->properties : [];
            $changed = false;

            foreach (['before', 'after'] as $side) {
                if (! isset($properties[$side]) || ! is_array($properties[$side])) {
                    continue;
                }

                if (! array_key_exists('assigned_to', $properties[$side])) {
                    continue;
                }

                $existingName = $properties[$side]['assigned_to_name'] ?? null;
                if (is_string($existingName) && $existingName !== '') {
                    continue;
                }

                $assignedTo = $properties[$side]['assigned_to'];
                if ($assignedTo === null || $assignedTo === '') {
                    $properties[$side]['assigned_to_name'] = null;
                    $changed = true;

                    continue;
                }

                if (! is_numeric($assignedTo)) {
                    continue;
                }

                $userId = (int) $assignedTo;
                $properties[$side]['assigned_to_name'] = $namesById->get($userId) ?? 'User #'.$userId;
                $changed = true;
            }

            if ($changed) {
                $log->setAttribute('properties', $properties);
            }
        }
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
