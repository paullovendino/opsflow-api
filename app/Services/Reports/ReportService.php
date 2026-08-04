<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\ProjectStatus;
use App\Enums\RoleName;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public const DEFAULT_DIRECTION = 'desc';

    public const DEFAULT_PROJECT_SORT = 'created_at';

    public const DEFAULT_EMPLOYEE_SORT = 'created_at';

    /**
     * @var list<string>
     */
    public const ALLOWED_PROJECT_SORTS = [
        'name',
        'status',
        'created_at',
    ];

    /**
     * @var list<string>
     */
    public const ALLOWED_EMPLOYEE_SORTS = [
        'first_name',
        'last_name',
        'email',
        'created_at',
    ];

    /**
     * @param  array{
     *     search?: string|null,
     *     status?: string|null,
     *     from_date?: string|null,
     *     to_date?: string|null,
     *     sort?: string,
     *     direction?: string,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function projectReports(User $actor, array $filters): LengthAwarePaginator
    {
        $query = Project::query();
        $this->applyProjectVisibility($query, $actor);
        $this->applyProjectSearch($query, $filters['search'] ?? null);

        if (($filters['status'] ?? null) !== null && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        $this->applySort(
            $query,
            $filters['sort'] ?? self::DEFAULT_PROJECT_SORT,
            $filters['direction'] ?? self::DEFAULT_DIRECTION,
            self::ALLOWED_PROJECT_SORTS,
            self::DEFAULT_PROJECT_SORT,
        );

        /** @var LengthAwarePaginator<int, Project> $paginator */
        $paginator = $query->paginate(
            perPage: (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE),
            page: (int) ($filters['page'] ?? 1),
        );

        $projects = $paginator->getCollection();
        $projectIds = $projects->pluck('id')->all();

        $taskStats = $this->batchProjectTaskStats(
            $projectIds,
            $filters['from_date'] ?? null,
            $filters['to_date'] ?? null,
        );
        $memberCounts = $this->batchMemberCounts($projectIds);

        $reports = $projects->map(fn (Project $project): array => $this->formatProjectReport(
            project: $project,
            taskStats: $taskStats[(int) $project->id] ?? $this->emptyTaskStats(),
            membersCount: (int) ($memberCounts[(int) $project->id] ?? 0),
        ));

        return $this->replacePaginatorCollection($paginator, $reports);
    }

    /**
     * @param  array{from_date?: string|null, to_date?: string|null}  $dateRange
     * @return array<string, mixed>
     */
    public function projectReport(Project $project, array $dateRange = []): array
    {
        $projectId = (int) $project->id;
        $taskStats = $this->batchProjectTaskStats(
            [$projectId],
            $dateRange['from_date'] ?? null,
            $dateRange['to_date'] ?? null,
        );
        $memberCounts = $this->batchMemberCounts([$projectId]);

        return $this->formatProjectReport(
            project: $project,
            taskStats: $taskStats[$projectId] ?? $this->emptyTaskStats(),
            membersCount: (int) ($memberCounts[$projectId] ?? 0),
        );
    }

    /**
     * @param  array{
     *     search?: string|null,
     *     role_id?: int|null,
     *     department_id?: int|null,
     *     status?: string|null,
     *     from_date?: string|null,
     *     to_date?: string|null,
     *     sort?: string,
     *     direction?: string,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function employeeReports(array $filters): LengthAwarePaginator
    {
        $query = User::query();
        $this->applyUserSearch($query, $filters['search'] ?? null);

        if (($filters['role_id'] ?? null) !== null) {
            $query->where('role_id', $filters['role_id']);
        }

        if (($filters['department_id'] ?? null) !== null) {
            $query->where('department_id', $filters['department_id']);
        }

        if (($filters['status'] ?? null) !== null && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        $this->applySort(
            $query,
            $filters['sort'] ?? self::DEFAULT_EMPLOYEE_SORT,
            $filters['direction'] ?? self::DEFAULT_DIRECTION,
            self::ALLOWED_EMPLOYEE_SORTS,
            self::DEFAULT_EMPLOYEE_SORT,
        );

        /** @var LengthAwarePaginator<int, User> $paginator */
        $paginator = $query->paginate(
            perPage: (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE),
            page: (int) ($filters['page'] ?? 1),
        );

        $users = $paginator->getCollection();
        $userIds = $users->pluck('id')->all();

        $taskStats = $this->batchEmployeeTaskStats(
            $userIds,
            $filters['from_date'] ?? null,
            $filters['to_date'] ?? null,
            includeByProject: false,
        );

        $reports = $users->map(fn (User $user): array => $this->formatEmployeeReport(
            user: $user,
            taskStats: $taskStats[(int) $user->id] ?? $this->emptyEmployeeTaskStats(includeByProject: false),
            includeByProject: false,
        ));

        return $this->replacePaginatorCollection($paginator, $reports);
    }

    /**
     * @param  array{from_date?: string|null, to_date?: string|null}  $dateRange
     * @return array<string, mixed>
     */
    public function employeeReport(User $user, array $dateRange = []): array
    {
        $userId = (int) $user->id;
        $taskStats = $this->batchEmployeeTaskStats(
            [$userId],
            $dateRange['from_date'] ?? null,
            $dateRange['to_date'] ?? null,
            includeByProject: true,
        );

        return $this->formatEmployeeReport(
            user: $user,
            taskStats: $taskStats[$userId] ?? $this->emptyEmployeeTaskStats(includeByProject: true),
            includeByProject: true,
        );
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, array{
     *     total: int,
     *     by_status: array<string, int>,
     *     by_priority: array<string, int>,
     *     overdue: int,
     *     unassigned: int
     * }>
     */
    private function batchProjectTaskStats(array $projectIds, ?string $fromDate, ?string $toDate): array
    {
        if ($projectIds === []) {
            return [];
        }

        $base = Task::query()->whereIn('project_id', $projectIds);
        $this->applyCreatedAtDateRange($base, $fromDate, $toDate);

        $totals = (clone $base)
            ->selectRaw('project_id, COUNT(*) as aggregate')
            ->groupBy('project_id')
            ->pluck('aggregate', 'project_id');

        $statusRows = (clone $base)
            ->selectRaw('project_id, status, COUNT(*) as aggregate')
            ->groupBy('project_id', 'status')
            ->get();

        $priorityRows = (clone $base)
            ->selectRaw('project_id, priority, COUNT(*) as aggregate')
            ->groupBy('project_id', 'priority')
            ->get();

        $today = Carbon::now()->toDateString();

        $overdue = (clone $base)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->whereNotIn('status', [
                TaskStatus::Completed->value,
                TaskStatus::Cancelled->value,
            ])
            ->selectRaw('project_id, COUNT(*) as aggregate')
            ->groupBy('project_id')
            ->pluck('aggregate', 'project_id');

        $unassigned = (clone $base)
            ->whereNull('assigned_to')
            ->selectRaw('project_id, COUNT(*) as aggregate')
            ->groupBy('project_id')
            ->pluck('aggregate', 'project_id');

        $result = [];

        foreach ($projectIds as $projectId) {
            $statusCounts = collect();
            foreach ($statusRows as $row) {
                if ((int) $row->project_id === (int) $projectId) {
                    $statusCounts[$this->enumKey($row->status)] = (int) $row->aggregate;
                }
            }

            $priorityCounts = collect();
            foreach ($priorityRows as $row) {
                if ((int) $row->project_id === (int) $projectId) {
                    $priorityCounts[$this->enumKey($row->priority)] = (int) $row->aggregate;
                }
            }

            $result[(int) $projectId] = [
                'total' => (int) ($totals[$projectId] ?? 0),
                'by_status' => $this->zeroFilledEnumCounts(TaskStatus::cases(), $statusCounts),
                'by_priority' => $this->zeroFilledEnumCounts(TaskPriority::cases(), $priorityCounts),
                'overdue' => (int) ($overdue[$projectId] ?? 0),
                'unassigned' => (int) ($unassigned[$projectId] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, array{
     *     total: int,
     *     by_status: array<string, int>,
     *     by_priority: array<string, int>,
     *     overdue: int,
     *     by_project?: list<array{project_id: int, name: string, total: int}>
     * }>
     */
    private function batchEmployeeTaskStats(
        array $userIds,
        ?string $fromDate,
        ?string $toDate,
        bool $includeByProject,
    ): array {
        if ($userIds === []) {
            return [];
        }

        $base = Task::query()
            ->whereIn('assigned_to', $userIds)
            ->whereHas('project');

        $this->applyCreatedAtDateRange($base, $fromDate, $toDate);

        $totals = (clone $base)
            ->selectRaw('assigned_to, COUNT(*) as aggregate')
            ->groupBy('assigned_to')
            ->pluck('aggregate', 'assigned_to');

        $statusRows = (clone $base)
            ->selectRaw('assigned_to, status, COUNT(*) as aggregate')
            ->groupBy('assigned_to', 'status')
            ->get();

        $priorityRows = (clone $base)
            ->selectRaw('assigned_to, priority, COUNT(*) as aggregate')
            ->groupBy('assigned_to', 'priority')
            ->get();

        $today = Carbon::now()->toDateString();

        $overdue = (clone $base)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->whereNotIn('status', [
                TaskStatus::Completed->value,
                TaskStatus::Cancelled->value,
            ])
            ->selectRaw('assigned_to, COUNT(*) as aggregate')
            ->groupBy('assigned_to')
            ->pluck('aggregate', 'assigned_to');

        $byProject = [];
        if ($includeByProject) {
            $byProjectQuery = Task::query()
                ->whereIn('tasks.assigned_to', $userIds)
                ->whereNull('tasks.deleted_at')
                ->join('projects', 'projects.id', '=', 'tasks.project_id')
                ->whereNull('projects.deleted_at');

            $this->applyCreatedAtDateRange($byProjectQuery, $fromDate, $toDate);

            $byProjectRows = $byProjectQuery
                ->selectRaw('tasks.assigned_to, tasks.project_id, projects.name as project_name, COUNT(*) as aggregate')
                ->groupBy('tasks.assigned_to', 'tasks.project_id', 'projects.name')
                ->orderByDesc('aggregate')
                ->orderBy('projects.name')
                ->get();

            foreach ($byProjectRows as $row) {
                $assigneeId = (int) $row->assigned_to;
                $byProject[$assigneeId][] = [
                    'project_id' => (int) $row->project_id,
                    'name' => (string) $row->project_name,
                    'total' => (int) $row->aggregate,
                ];
            }
        }

        $result = [];

        foreach ($userIds as $userId) {
            $statusCounts = collect();
            foreach ($statusRows as $row) {
                if ((int) $row->assigned_to === (int) $userId) {
                    $statusCounts[$this->enumKey($row->status)] = (int) $row->aggregate;
                }
            }

            $priorityCounts = collect();
            foreach ($priorityRows as $row) {
                if ((int) $row->assigned_to === (int) $userId) {
                    $priorityCounts[$this->enumKey($row->priority)] = (int) $row->aggregate;
                }
            }

            $stats = [
                'total' => (int) ($totals[$userId] ?? 0),
                'by_status' => $this->zeroFilledEnumCounts(TaskStatus::cases(), $statusCounts),
                'by_priority' => $this->zeroFilledEnumCounts(TaskPriority::cases(), $priorityCounts),
                'overdue' => (int) ($overdue[$userId] ?? 0),
            ];

            if ($includeByProject) {
                $stats['by_project'] = $byProject[(int) $userId] ?? [];
            }

            $result[(int) $userId] = $stats;
        }

        return $result;
    }

    /**
     * @param  list<int>  $projectIds
     * @return Collection<int|string, mixed>
     */
    private function batchMemberCounts(array $projectIds): Collection
    {
        if ($projectIds === []) {
            return collect();
        }

        return DB::table('project_members')
            ->whereIn('project_id', $projectIds)
            ->selectRaw('project_id, COUNT(*) as aggregate')
            ->groupBy('project_id')
            ->pluck('aggregate', 'project_id');
    }

    /**
     * @param  array{
     *     total: int,
     *     by_status: array<string, int>,
     *     by_priority: array<string, int>,
     *     overdue: int,
     *     unassigned: int
     * }  $taskStats
     * @return array<string, mixed>
     */
    private function formatProjectReport(Project $project, array $taskStats, int $membersCount): array
    {
        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status instanceof ProjectStatus
                    ? $project->status->value
                    : (string) $project->status,
                'start_date' => $project->start_date?->toDateString(),
                'due_date' => $project->due_date?->toDateString(),
                'created_at' => $project->created_at,
            ],
            'tasks' => $taskStats,
            'members_count' => $membersCount,
        ];
    }

    /**
     * @param  array{
     *     total: int,
     *     by_status: array<string, int>,
     *     by_priority: array<string, int>,
     *     overdue: int,
     *     by_project?: list<array{project_id: int, name: string, total: int}>
     * }  $taskStats
     * @return array<string, mixed>
     */
    private function formatEmployeeReport(User $user, array $taskStats, bool $includeByProject): array
    {
        $tasks = [
            'total' => $taskStats['total'],
            'by_status' => $taskStats['by_status'],
            'by_priority' => $taskStats['by_priority'],
            'overdue' => $taskStats['overdue'],
        ];

        if ($includeByProject) {
            $tasks['by_project'] = $taskStats['by_project'] ?? [];
        }

        return [
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'middle_name' => $user->middle_name,
                'last_name' => $user->last_name,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'status' => $user->status instanceof \BackedEnum
                    ? $user->status->value
                    : (string) $user->status,
            ],
            'tasks' => $tasks,
        ];
    }

    /**
     * @return array{
     *     total: int,
     *     by_status: array<string, int>,
     *     by_priority: array<string, int>,
     *     overdue: int,
     *     unassigned: int
     * }
     */
    private function emptyTaskStats(): array
    {
        return [
            'total' => 0,
            'by_status' => $this->zeroFilledEnumCounts(TaskStatus::cases(), collect()),
            'by_priority' => $this->zeroFilledEnumCounts(TaskPriority::cases(), collect()),
            'overdue' => 0,
            'unassigned' => 0,
        ];
    }

    /**
     * @return array{
     *     total: int,
     *     by_status: array<string, int>,
     *     by_priority: array<string, int>,
     *     overdue: int,
     *     by_project?: list<array{project_id: int, name: string, total: int}>
     * }
     */
    private function emptyEmployeeTaskStats(bool $includeByProject): array
    {
        $stats = [
            'total' => 0,
            'by_status' => $this->zeroFilledEnumCounts(TaskStatus::cases(), collect()),
            'by_priority' => $this->zeroFilledEnumCounts(TaskPriority::cases(), collect()),
            'overdue' => 0,
        ];

        if ($includeByProject) {
            $stats['by_project'] = [];
        }

        return $stats;
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function applyCreatedAtDateRange(Builder $query, ?string $fromDate, ?string $toDate): void
    {
        if ($fromDate !== null && $fromDate !== '') {
            $query->whereDate('tasks.created_at', '>=', $fromDate);
        }

        if ($toDate !== null && $toDate !== '') {
            $query->whereDate('tasks.created_at', '<=', $toDate);
        }
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
     * @param  Builder<Project>  $query
     */
    private function applyProjectSearch(Builder $query, ?string $search): void
    {
        if ($search === null || $search === '') {
            return;
        }

        $term = '%'.$search.'%';

        $query->where(function (Builder $builder) use ($term): void {
            $builder->where('name', 'ilike', $term)
                ->orWhere('description', 'ilike', $term);
        });
    }

    /**
     * @param  Builder<User>  $query
     */
    private function applyUserSearch(Builder $query, ?string $search): void
    {
        if ($search === null || $search === '') {
            return;
        }

        $term = '%'.$search.'%';

        $query->where(function (Builder $builder) use ($term): void {
            $builder->where('first_name', 'ilike', $term)
                ->orWhere('middle_name', 'ilike', $term)
                ->orWhere('last_name', 'ilike', $term)
                ->orWhere('email', 'ilike', $term);
        });
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  list<string>  $allowed
     */
    private function applySort(
        Builder $query,
        string $sort,
        string $direction,
        array $allowed,
        string $defaultSort,
    ): void {
        if (! in_array($sort, $allowed, true)) {
            $sort = $defaultSort;
        }

        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction)->orderBy('id');
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  Collection<int, array<string, mixed>>  $items
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function replacePaginatorCollection(LengthAwarePaginator $paginator, Collection $items): LengthAwarePaginator
    {
        return new Paginator(
            items: $items->values()->all(),
            total: $paginator->total(),
            perPage: $paginator->perPage(),
            currentPage: $paginator->currentPage(),
            options: [
                'path' => $paginator->path(),
                'pageName' => $paginator->getPageName(),
            ],
        );
    }

    private function enumKey(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
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
