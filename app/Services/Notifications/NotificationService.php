<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use App\Queries\Notifications\NotificationQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class NotificationService
{
    public function __construct(
        private readonly NotificationQuery $notificationQuery,
    ) {}

    /**
     * @param  array{
     *     unread?: bool,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, Notification>
     */
    public function list(User $recipient, array $filters = []): LengthAwarePaginator
    {
        return $this->notificationQuery->paginateFor($recipient, $filters);
    }

    public function unreadCount(User $recipient): int
    {
        return $this->notificationQuery->unreadCountFor($recipient);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function notify(
        User $recipient,
        NotificationType $type,
        ?User $actor,
        ?Model $subject,
        array $data = [],
    ): ?Notification {
        if ($actor !== null && (int) $actor->id === (int) $recipient->id) {
            return null;
        }

        $recipient->refresh();

        if (! $this->prefersType($recipient, $type)) {
            return null;
        }

        return Notification::query()->create([
            'recipient_id' => $recipient->id,
            'actor_id' => $actor?->id,
            'type' => $type,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'data' => $data,
            'created_at' => now(),
        ])->load(['actor', 'subject']);
    }

    public function markRead(Notification $notification): Notification
    {
        if ($notification->read_at === null) {
            $notification->forceFill([
                'read_at' => now(),
            ])->save();
        }

        return $notification->fresh(['actor', 'subject']) ?? $notification->load(['actor', 'subject']);
    }

    public function markAllRead(User $recipient): int
    {
        return Notification::query()
            ->forUser($recipient)
            ->unread()
            ->update([
                'read_at' => now(),
            ]);
    }

    private function prefersType(User $recipient, NotificationType $type): bool
    {
        return match ($type) {
            NotificationType::TaskAssigned => (bool) $recipient->notify_task_assigned,
            NotificationType::TaskStatusChanged => (bool) $recipient->notify_task_status,
            NotificationType::RemarkCreated => (bool) $recipient->notify_remarks,
            NotificationType::RemarkMentioned => (bool) $recipient->notify_mentions,
            NotificationType::ProjectMemberAdded => true,
        };
    }
}
