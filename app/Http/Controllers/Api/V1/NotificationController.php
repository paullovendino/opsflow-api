<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Api\V1\Notifications\IndexNotificationsRequest;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;

class NotificationController extends BaseApiController
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function index(IndexNotificationsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $notifications = $this->notificationService->list($user, $request->filters());

        return $this->paginatedResponse(
            paginator: $notifications,
            data: NotificationResource::collection($notifications),
            message: 'Notifications retrieved successfully.',
        );
    }

    public function unreadCount(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        return $this->successResponse(
            data: [
                'unread_count' => $this->notificationService->unreadCount($user),
            ],
            message: 'Unread count retrieved successfully.',
        );
    }

    public function markRead(Notification $notification): JsonResponse
    {
        $this->authorize('update', $notification);

        $notification = $this->notificationService->markRead($notification);

        return $this->successResponse(
            data: new NotificationResource($notification),
            message: 'Notification marked as read.',
        );
    }

    public function markAllRead(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        $updated = $this->notificationService->markAllRead($user);

        return $this->successResponse(
            data: [
                'updated_count' => $updated,
            ],
            message: 'Notifications marked as read.',
        );
    }
}
