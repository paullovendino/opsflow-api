<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\ProjectStatus;
use App\Enums\RoleName;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardService
{
    public const DEFAULT_RECENT_LIMIT = 10;

    public const MAX_RECENT_LIMIT = 25;

    /**
     * @return array{
     *     projects: array{total: int, by_status: array<string, int>},
     *     tasks: array{
     *         total: int,
     *         by_status: array<string, int>,
     *         by_priority: array<string, int>,
     *         overdue: int,
     *         assigned_to_me: int
     *     },
     *     recent: list<array<string, mixed>>
     * }
     */
    public function summary(User $actor, int $recentLimit = self::DEFAULT_RECENT_LIMIT): array
    {
        $recentLimit = max(1, min($recentLimit, self::MAX_RECENT_LIMIT));

        $projectQuery = Project::query();
        $this->applyProjectVisibility($projectQuery, $actor);

        $taskQuery = Task::query();
        $this->applyTaskVisibility($taskQuery, $actor);

        return [
            'projects' => $this->projectStatistics(clone $projectQuery),
            'tasks' => $this->taskStatistics(clone $taskQuery, $actor),
            'recent' => $this->recentWorkItems(
                projectQuery: clone $projectQuery,
                taskQuery: clone $taskQuery,
                recentLimit: $recentLimit,
            ),
        ];
    }

    /**
     * @param  Builder<Project>  $query
     * @return array{total: int, by_status: array<string, int>}
     */
    private function projectStatistics(Builder $query): array
    {
        $total = (clone $query)->count();

        $counts = (clone $query)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => $total,
            'by_status' => $this->zeroFilledEnumCounts(ProjectStatus::cases(), $counts),
        ];
    }

    /**
     * @param  Builder<Task>  $query
     * @return array{
     *     total: int,
     *     by_status: array<string, int>,
     *     by_priority: array<string, int>,
     *     overdue: int,
     *     assigned_to_me: int
     * }
     */
    private function taskStatistics(Builder $query, User $actor): array
    {
        $total = (clone $query)->count();

        $statusCounts = (clone $query)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $priorityCounts = (clone $query)
            ->selectRaw('priority, COUNT(*) as aggregate')
            ->groupBy('priority')
            ->pluck('aggregate', 'priority');

        $today = Carbon::now()->toDateString();

        $overdue = (clone $query)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->whereNotIn('status', [
                TaskStatus::Completed->value,
                TaskStatus::Cancelled->value,
            ])
            ->count();

        $assignedToMe = (clone $query)
            ->where('assigned_to', $actor->id)
            ->count();

        return [
            'total' => $total,
            'by_status' => $this->zeroFilledEnumCounts(TaskStatus::cases(), $statusCounts),
            'by_priority' => $this->zeroFilledEnumCounts(TaskPriority::cases(), $priorityCounts),
            'overdue' => $overdue,
            'assigned_to_me' => $assignedToMe,
        ];
    }

    /**
     * @param  Builder<Project>  $projectQuery
     * @param  Builder<Task>  $taskQuery
     * @return list<array<string, mixed>>
     */
    private function recentWorkItems(Builder $projectQuery, Builder $taskQuery, int $recentLimit): array
    {
        $projects = (clone $projectQuery)
            ->select(['id', 'name', 'status', 'updated_at'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($recentLimit)
            ->get()
            ->map(fn (Project $project): array => [
                'type' => 'project',
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status instanceof ProjectStatus
                    ? $project->status->value
                    : (string) $project->status,
                'updated_at' => $project->updated_at,
                '_sort_at' => $project->updated_at?->getTimestamp() ?? 0,
            ]);

        $tasks = (clone $taskQuery)
            ->select(['id', 'title', 'status', 'project_id', 'updated_at'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($recentLimit)
            ->get()
            ->map(fn (Task $task): array => [
                'type' => 'task',
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status instanceof TaskStatus
                    ? $task->status->value
                    : (string) $task->status,
                'project_id' => $task->project_id,
                'updated_at' => $task->updated_at,
                '_sort_at' => $task->updated_at?->getTimestamp() ?? 0,
            ]);

        /** @var Collection<int, array<string, mixed>> $merged */
        $merged = $projects->concat($tasks)
            ->sort(function (array $left, array $right): int {
                $timeCompare = $right['_sort_at'] <=> $left['_sort_at'];
                if ($timeCompare !== 0) {
                    return $timeCompare;
                }

                $typeCompare = strcmp((string) $left['type'], (string) $right['type']);
                if ($typeCompare !== 0) {
                    return $typeCompare;
                }

                return ((int) $right['id']) <=> ((int) $left['id']);
            })
            ->take($recentLimit)
            ->values()
            ->map(function (array $item): array {
                unset($item['_sort_at']);

                return $item;
            });

        return $merged->all();
    }

    /**
     * @param  Builder<Project>  $query
     */
    private function applyProjectVisibility(Builder $query, User $actor): void
    {
        $actor->loadMissing('role');

        $role = $actor->role?->name;

        if ($role === RoleName::Administrator || $role === RoleName::ProjectManager) {
            return;
        }

        $query->where(function (Builder $builder) use ($actor): void {
            $builder->where('created_by', $actor->id)
                ->orWhereHas(
                    'members',
                    fn (Builder $members): Builder => $members->where('users.id', $actor->id),
                );
        });
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function applyTaskVisibility(Builder $query, User $actor): void
    {
        $actor->loadMissing('role');

        $role = $actor->role?->name;

        if ($role === RoleName::Administrator || $role === RoleName::ProjectManager) {
            return;
        }

        $query->whereHas('project', function (Builder $project) use ($actor): void {
            $project->where(function (Builder $builder) use ($actor): void {
                $builder->where('created_by', $actor->id)
                    ->orWhereHas(
                        'members',
                        fn (Builder $members): Builder => $members->where('users.id', $actor->id),
                    );
            });
        });
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @param  Collection<string|int, mixed>  $counts
     * @return array<string, int>
     */
    private function zeroFilledEnumCounts(array $cases, Collection $counts): array
    {
        $result = [];

        foreach ($cases as $case) {
            $key = (string) $case->value;
            $result[$key] = (int) ($counts[$key] ?? 0);
        }

        return $result;
    }
}
