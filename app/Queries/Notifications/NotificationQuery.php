<?php

declare(strict_types=1);

namespace App\Queries\Notifications;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class NotificationQuery
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{
     *     unread?: bool,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, Notification>
     */
    public function paginateFor(User $recipient, array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);
        $page = (int) ($filters['page'] ?? 1);

        $query = Notification::query()
            ->with(['actor', 'subject'])
            ->forUser($recipient)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (($filters['unread'] ?? false) === true) {
            $query->unread();
        }

        return $query->paginate(perPage: $perPage, page: $page);
    }

    public function unreadCountFor(User $recipient): int
    {
        return Notification::query()
            ->forUser($recipient)
            ->unread()
            ->count();
    }
}
