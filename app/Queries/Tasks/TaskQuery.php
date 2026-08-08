<?php

declare(strict_types=1);

namespace App\Queries\Tasks;

use App\Enums\RoleName;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class TaskQuery
{
    public const DEFAULT_SORT = 'created_at';

    public const DEFAULT_DIRECTION = 'desc';

    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @var list<string>
     */
    public const ALLOWED_SORTS = [
        'title',
        'status',
        'priority',
        'due_date',
        'created_at',
    ];

    /**
     * @param  array{
     *     search?: string|null,
     *     status?: string|null,
     *     priority?: string|null,
     *     overdue?: bool,
     *     due_before?: string|null,
     *     due_after?: string|null,
     *     project_id?: int|null,
     *     assigned_to?: int|null,
     *     created_by?: int|null,
     *     sort?: string,
     *     direction?: string,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, Task>
     */
    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        $query = Task::query()->with(['project', 'assignee', 'creator']);

        $this->applyVisibility($query, $actor);
        $this->applySearch($query, $filters['search'] ?? null);
        $this->applyFilters($query, $filters);
        $this->applySort(
            $query,
            $filters['sort'] ?? self::DEFAULT_SORT,
            $filters['direction'] ?? self::DEFAULT_DIRECTION,
        );

        return $query->paginate(
            perPage: (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE),
            page: (int) ($filters['page'] ?? 1),
        );
    }

    /**
     * Employees see only tasks in owned or member projects; administrators and project managers see all.
     *
     * @param  Builder<Task>  $query
     */
    private function applyVisibility(Builder $query, User $actor): void
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
     * @param  Builder<Task>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        if ($search === null || $search === '') {
            return;
        }

        $term = '%'.$search.'%';

        $query->where(function (Builder $builder) use ($term): void {
            $builder->where('title', 'ilike', $term)
                ->orWhere('description', 'ilike', $term);
        });
    }

    /**
     * @param  Builder<Task>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (array_key_exists('priority', $filters) && $filters['priority'] !== null && $filters['priority'] !== '') {
            $query->where('priority', $filters['priority']);
        }

        if (($filters['overdue'] ?? false) === true) {
            $query->overdue();
        }

        if (array_key_exists('due_before', $filters) && is_string($filters['due_before']) && $filters['due_before'] !== '') {
            $query->whereNotNull('due_date')->whereDate('due_date', '<=', $filters['due_before']);
        }

        if (array_key_exists('due_after', $filters) && is_string($filters['due_after']) && $filters['due_after'] !== '') {
            $query->whereNotNull('due_date')->whereDate('due_date', '>=', $filters['due_after']);
        }

        if (array_key_exists('project_id', $filters) && $filters['project_id'] !== null) {
            $query->where('project_id', $filters['project_id']);
        }

        if (array_key_exists('assigned_to', $filters) && $filters['assigned_to'] !== null) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        if (array_key_exists('created_by', $filters) && $filters['created_by'] !== null) {
            $query->where('created_by', $filters['created_by']);
        }
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function applySort(Builder $query, string $sort, string $direction): void
    {
        if (! in_array($sort, self::ALLOWED_SORTS, true)) {
            $sort = self::DEFAULT_SORT;
        }

        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        if ($sort === 'due_date') {
            $query->orderByRaw('case when due_date is null then 1 else 0 end')
                ->orderBy('due_date', $direction)
                ->orderBy('id');

            return;
        }

        $query->orderBy($sort, $direction)->orderBy('id');
    }
}
