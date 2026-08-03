<?php

declare(strict_types=1);

namespace App\Queries\Tasks;

use App\Models\Task;
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
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Task::query()->with(['project', 'assignee', 'creator']);

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

        $query->orderBy($sort, $direction)->orderBy('id');
    }
}
