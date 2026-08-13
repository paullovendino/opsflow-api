<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\ProjectStatus;
use App\Enums\RoleName;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Resources\Api\V1\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Remark;
use App\Models\Task;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Projects\ProjectProgress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardService
{
    public const DEFAULT_RECENT_LIMIT = 10;

    public const MAX_RECENT_LIMIT = 25;

    public const DEFAULT_ACTIVITY_LIMIT = 10;

    public const MAX_ACTIVITY_LIMIT = 25;

    public const DEFAULT_DUE_SOON_LIMIT = 10;

    public const DUE_SOON_DAYS = 7;

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * @return array{
     *     projects: array{total: int, by_status: array<string, int>, average_progress: int|null},
     *     tasks: array{
     *         total: int,
     *         by_status: array<string, int>,
     *         by_priority: array<string, int>,
     *         overdue: int,
     *         assigned_to_me: int,
     *         due_soon: int
     *     },
     *     recent: list<array<string, mixed>>,
     *     due_soon: list<array<string, mixed>>,
     *     recent_activity: list<array<string, mixed>>,
     *     notifications: array{unread_count: int}
     * }
     */
    public function summary(
        User $actor,
        int $recentLimit = self::DEFAULT_RECENT_LIMIT,
        int $activityLimit = self::DEFAULT_ACTIVITY_LIMIT,
    ): array {
        $recentLimit = max(1, min($recentLimit, self::MAX_RECENT_LIMIT));
        $activityLimit = max(1, min($activityLimit, self::MAX_ACTIVITY_LIMIT));

        $projectQuery = Project::query();
        $this->applyProjectVisibility($projectQuery, $actor);

        $taskQuery = Task::query();
        $this->applyTaskVisibility($taskQuery, $actor);

        $dueSoonCount = $this->dueSoonCount(clone $taskQuery);
        $dueSoonItems = $this->dueSoonTasks(clone $taskQuery);

        return [
            'projects' => $this->projectStatistics(clone $projectQuery),
            'tasks' => $this->taskStatistics(clone $taskQuery, $actor, $dueSoonCount),
            'recent' => $this->recentWorkItems(
                projectQuery: clone $projectQuery,
                taskQuery: clone $taskQuery,
                recentLimit: $recentLimit,
            ),
            'due_soon' => $dueSoonItems,
            'recent_activity' => $this->recentActivity($actor, clone $projectQuery, clone $taskQuery, $activityLimit),
            'notifications' => [
                'unread_count' => $this->notificationService->unreadCount($actor),
            ],
        ];
    }

    /**
     * @param  Builder<Project>  $query
     * @return array{total: int, by_status: array<string, int>, average_progress: int|null}
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
            'average_progress' => $this->averageProgress(clone $query),
        ];
    }

    /**
     * Mean of derived project progress values, ignoring null (no eligible tasks).
     *
     * @param  Builder<Project>  $query
     */
    private function averageProgress(Builder $query): ?int
    {
        $projects = $query
            ->withCount([
                'tasks as eligible_tasks_count' => function (Builder $tasks): void {
                    $tasks->where('status', '!=', TaskStatus::Cancelled->value);
                },
                'tasks as completed_tasks_count' => function (Builder $tasks): void {
                    $tasks->where('status', TaskStatus::Completed->value);
                },
            ])
            ->get(['projects.id']);

        $percents = [];

        foreach ($projects as $project) {
            $percent = ProjectProgress::percent(
                (int) ($project->eligible_tasks_count ?? 0),
                (int) ($project->completed_tasks_count ?? 0),
            );

            if ($percent !== null) {
                $percents[] = $percent;
            }
        }

        if ($percents === []) {
            return null;
        }

        return (int) round(array_sum($percents) / count($percents));
    }

    /**
     * @param  Builder<Task>  $query
     * @return array{
     *     total: int,
     *     by_status: array<string, int>,
     *     by_priority: array<string, int>,
     *     overdue: int,
     *     assigned_to_me: int,
     *     due_soon: int
     * }
     */
    private function taskStatistics(Builder $query, User $actor, int $dueSoonCount): array
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
            'due_soon' => $dueSoonCount,
        ];
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function applyDueSoonConstraints(Builder $query): void
    {
        $today = Carbon::now()->startOfDay();
        $until = $today->copy()->addDays(self::DUE_SOON_DAYS)->toDateString();
        $todayDate = $today->toDateString();

        $query
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', $todayDate)
            ->whereDate('due_date', '<=', $until)
            ->whereNotIn('status', [
                TaskStatus::Completed->value,
                TaskStatus::Cancelled->value,
            ]);
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function dueSoonCount(Builder $query): int
    {
        $this->applyDueSoonConstraints($query);

        return $query->count();
    }

    /**
     * Tasks due from today through today+7 (UTC), excluding overdue / completed / cancelled.
     *
     * @param  Builder<Task>  $query
     * @return list<array<string, mixed>>
     */
    private function dueSoonTasks(Builder $query): array
    {
        $this->applyDueSoonConstraints($query);

        $tasks = $query
            ->with(['project:id,name'])
            ->select([
                'id',
                'title',
                'status',
                'priority',
                'due_date',
                'project_id',
            ])
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(self::DEFAULT_DUE_SOON_LIMIT)
            ->get();

        return $tasks->map(function (Task $task): array {
            $status = $task->status instanceof TaskStatus
                ? $task->status->value
                : (string) $task->status;
            $priority = $task->priority instanceof TaskPriority
                ? $task->priority->value
                : (string) $task->priority;

            return [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $status,
                'priority' => $priority,
                'due_date' => $task->due_date?->toDateString(),
                'is_overdue' => $task->isOverdue(),
                'project' => $task->project === null
                    ? null
                    : [
                        'id' => $task->project->id,
                        'name' => $task->project->name,
                    ],
            ];
        })->all();
    }

    /**
     * @param  Builder<Project>  $projectQuery
     * @param  Builder<Task>  $taskQuery
     * @return list<array<string, mixed>>
     */
    private function recentActivity(
        User $actor,
        Builder $projectQuery,
        Builder $taskQuery,
        int $activityLimit,
    ): array {
        $query = ActivityLog::query()->with(['actor', 'subject']);
        $this->applyActivityVisibility($query, $actor, $projectQuery, $taskQuery);

        $logs = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($activityLimit)
            ->get();

        /** @var list<array<string, mixed>> $resolved */
        $resolved = ActivityLogResource::collection($logs)->resolve();

        return $resolved;
    }

    /**
     * @param  Builder<ActivityLog>  $query
     * @param  Builder<Project>  $projectQuery
     * @param  Builder<Task>  $taskQuery
     */
    private function applyActivityVisibility(
        Builder $query,
        User $actor,
        Builder $projectQuery,
        Builder $taskQuery,
    ): void {
        $actor->loadMissing('role');
        $role = $actor->role?->name;

        if ($role === RoleName::Administrator || $role === RoleName::ProjectManager) {
            return;
        }

        $projectIds = (clone $projectQuery)->pluck('id');
        $taskIds = (clone $taskQuery)->pluck('id');

        $remarkIds = Remark::query()
            ->where(function (Builder $builder) use ($projectIds, $taskIds): void {
                $builder->where(function (Builder $inner) use ($projectIds): void {
                    $inner->where('remarkable_type', 'project')
                        ->whereIn('remarkable_id', $projectIds);
                })->orWhere(function (Builder $inner) use ($taskIds): void {
                    $inner->where('remarkable_type', 'task')
                        ->whereIn('remarkable_id', $taskIds);
                });
            })
            ->pluck('id');

        $query->where(function (Builder $builder) use ($actor, $projectIds, $taskIds, $remarkIds): void {
            $builder->where(function (Builder $inner) use ($projectIds): void {
                $inner->where('subject_type', 'project')
                    ->whereIn('subject_id', $projectIds);
            })->orWhere(function (Builder $inner) use ($taskIds): void {
                $inner->where('subject_type', 'task')
                    ->whereIn('subject_id', $taskIds);
            })->orWhere(function (Builder $inner) use ($remarkIds): void {
                $inner->where('subject_type', 'remark')
                    ->whereIn('subject_id', $remarkIds);
            })->orWhere(function (Builder $inner) use ($actor): void {
                $inner->where('subject_type', 'user')
                    ->where('subject_id', $actor->id);
            });
        });
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
