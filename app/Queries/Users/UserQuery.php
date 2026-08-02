<?php

declare(strict_types=1);

namespace App\Queries\Users;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class UserQuery
{
    public const DEFAULT_SORT = 'created_at';

    public const DEFAULT_DIRECTION = 'desc';

    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @var list<string>
     */
    public const ALLOWED_SORTS = [
        'first_name',
        'last_name',
        'email',
        'created_at',
        'last_login_at',
        'status',
    ];

    /**
     * @param  array{
     *     search?: string|null,
     *     role_id?: int|null,
     *     department_id?: int|null,
     *     job_title_id?: int|null,
     *     status?: string|null,
     *     sort?: string,
     *     direction?: string,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = User::query()->with(['role', 'department', 'jobTitle']);

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
     * @param  Builder<User>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
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
     * @param  Builder<User>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (array_key_exists('role_id', $filters) && $filters['role_id'] !== null) {
            $query->where('role_id', $filters['role_id']);
        }

        if (array_key_exists('department_id', $filters) && $filters['department_id'] !== null) {
            $query->where('department_id', $filters['department_id']);
        }

        if (array_key_exists('job_title_id', $filters) && $filters['job_title_id'] !== null) {
            $query->where('job_title_id', $filters['job_title_id']);
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }
    }

    /**
     * @param  Builder<User>  $query
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
