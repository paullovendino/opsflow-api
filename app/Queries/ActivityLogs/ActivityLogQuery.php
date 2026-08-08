<?php

declare(strict_types=1);

namespace App\Queries\ActivityLogs;

use App\Models\ActivityLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ActivityLogQuery
{
    public const DEFAULT_DIRECTION = 'desc';

    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{
     *     actor_id?: int|null,
     *     action?: string|null,
     *     subject_type?: string|null,
     *     subject_id?: int|null,
     *     from?: string|null,
     *     to?: string|null,
     *     direction?: string,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function paginate(array $filters, ?Model $subject = null): LengthAwarePaginator
    {
        $query = ActivityLog::query()->with(['actor', 'subject']);

        if ($subject !== null) {
            $query->forSubject($subject);
        } else {
            if (! empty($filters['subject_type'])) {
                $query->where('subject_type', $filters['subject_type']);
            }

            if (! empty($filters['subject_id'])) {
                $query->where('subject_id', $filters['subject_id']);
            }
        }

        if (! empty($filters['actor_id'])) {
            $query->where('actor_id', $filters['actor_id']);
        }

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $direction = ($filters['direction'] ?? self::DEFAULT_DIRECTION) === 'asc' ? 'asc' : 'desc';

        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);
        $page = (int) ($filters['page'] ?? 1);

        return $query
            ->orderBy('created_at', $direction)
            ->orderBy('id', $direction)
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
     * @return Builder<ActivityLog>
     */
    public function baseQuery(): Builder
    {
        return ActivityLog::query();
    }
}
