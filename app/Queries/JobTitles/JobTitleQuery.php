<?php

declare(strict_types=1);

namespace App\Queries\JobTitles;

use App\Models\JobTitle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class JobTitleQuery
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{
     *     q?: string|null,
     *     status?: string|null,
     *     department_id?: int|null,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, JobTitle>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = JobTitle::query()
            ->with('department')
            ->withCount('users');

        $this->applySearch($query, $filters['q'] ?? null);
        $this->applyFilters($query, $filters);

        $query->orderBy('name')->orderBy('id');

        return $query->paginate(
            perPage: (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE),
            page: (int) ($filters['page'] ?? 1),
        );
    }

    /**
     * @param  Builder<JobTitle>  $query
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
     * @param  Builder<JobTitle>  $query
     * @param  array{
     *     status?: string|null,
     *     department_id?: int|null
     * }  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (array_key_exists('department_id', $filters) && $filters['department_id'] !== null) {
            $query->where('department_id', $filters['department_id']);
        }
    }
}
