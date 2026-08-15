<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Enums\RoleName;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ProjectQuery
{
    public const DEFAULT_SORT = 'created_at';

    public const DEFAULT_DIRECTION = 'desc';

    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @var list<string>
     */
    public const ALLOWED_SORTS = [
        'name',
        'status',
        'start_date',
        'due_date',
        'created_at',
    ];

    /**
     * @param  array{
     *     search?: string|null,
     *     status?: string|null,
     *     created_by?: int|null,
     *     sort?: string,
     *     direction?: string,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @param  User  $actor
     * @return LengthAwarePaginator<int, Project>
     */
    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        $query = Project::query()->with('owner.avatarFile');
        $this->applyProgressAggregates($query);

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
     * Employees see only owned or member projects; administrators and project managers see all.
     *
     * @param  Builder<Project>  $query
     */
    private function applyVisibility(Builder $query, User $actor): void
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
    private function applySearch(Builder $query, ?string $search): void
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
     * @param  Builder<Project>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (array_key_exists('created_by', $filters) && $filters['created_by'] !== null) {
            $query->where('created_by', $filters['created_by']);
        }
    }

    /**
     * @param  Builder<Project>  $query
     */
    private function applySort(Builder $query, string $sort, string $direction): void
    {
        if (! in_array($sort, self::ALLOWED_SORTS, true)) {
            $sort = self::DEFAULT_SORT;
        }

        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction)->orderBy('id');
    }

    /**
     * Attach eligible/completed task counts as correlated aggregates (no N+1).
     *
     * @param  Builder<Project>  $query
     */
    public function applyProgressAggregates(Builder $query): void
    {
        $query->withCount([
            'tasks as eligible_tasks_count' => function (Builder $tasks): void {
                $tasks->where('status', '!=', TaskStatus::Cancelled->value);
            },
            'tasks as completed_tasks_count' => function (Builder $tasks): void {
                $tasks->where('status', TaskStatus::Completed->value);
            },
        ]);
    }

    public function hydrateProgress(Project $project): Project
    {
        $project->loadCount([
            'tasks as eligible_tasks_count' => function (Builder $tasks): void {
                $tasks->where('status', '!=', TaskStatus::Cancelled->value);
            },
            'tasks as completed_tasks_count' => function (Builder $tasks): void {
                $tasks->where('status', TaskStatus::Completed->value);
            },
        ]);

        return $project;
    }
}
