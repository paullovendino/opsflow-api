<?php

declare(strict_types=1);

namespace App\Queries\Remarks;

use App\Models\Remark;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class RemarkQuery
{
    public const DEFAULT_DIRECTION = 'asc';

    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{
     *     direction?: string,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, Remark>
     */
    public function paginateFor(Model $remarkable, array $filters = []): LengthAwarePaginator
    {
        $direction = ($filters['direction'] ?? self::DEFAULT_DIRECTION) === 'desc' ? 'desc' : 'asc';
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);
        $page = (int) ($filters['page'] ?? 1);

        return Remark::query()
            ->with([
                'author',
                'mentions.user',
            ])
            ->where('remarkable_type', $remarkable->getMorphClass())
            ->where('remarkable_id', $remarkable->getKey())
            ->orderBy('created_at', $direction)
            ->orderBy('id', $direction)
            ->paginate(perPage: $perPage, page: $page);
    }
}
