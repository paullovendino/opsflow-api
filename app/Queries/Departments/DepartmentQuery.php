<?php

declare(strict_types=1);

namespace App\Queries\Departments;

use App\Models\Department;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class DepartmentQuery
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{
     *     q?: string|null,
     *     status?: string|null,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, Department>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Department::query()->withCount(['jobTitles', 'users']);

        $this->applySearch($query, $filters['q'] ?? null);
        $this->applyStatus($query, $filters['status'] ?? null);

        $query->orderBy('name')->orderBy('id');

        return $query->paginate(
            perPage: (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE),
            page: (int) ($filters['page'] ?? 1),
        );
    }

    /**
     * @param  Builder<Department>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        if ($search === null || $search === '') {
            return;
        }

        $term = '%'.$search.'%';

        $query->where(function (Builder $builder) use ($term): void {
            $builder->where('name', 'ilike', $term)
                ->orWhere('description', 'ilike', $term)
                ->orWhere('code', 'ilike', $term);
        });
    }

    /**
     * @param  Builder<Department>  $query
     */
    private function applyStatus(Builder $query, ?string $status): void
    {
        if ($status === null || $status === '') {
            return;
        }

        $query->where('status', $status);
    }
}
