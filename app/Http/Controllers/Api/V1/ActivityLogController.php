<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Api\V1\ActivityLogs\IndexActivityLogsRequest;
use App\Http\Resources\Api\V1\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogs\ActivityLogService;
use Illuminate\Http\JsonResponse;

class ActivityLogController extends BaseApiController
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
    ) {}

    public function index(IndexActivityLogsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', ActivityLog::class);

        $logs = $this->activityLogService->list($request->filters());

        return $this->paginatedResponse(
            paginator: $logs,
            data: ActivityLogResource::collection($logs),
            message: 'Activity logs retrieved successfully.',
        );
    }

    public function forProject(IndexActivityLogsRequest $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $logs = $this->activityLogService->list($request->filters(), $project);

        return $this->paginatedResponse(
            paginator: $logs,
            data: ActivityLogResource::collection($logs),
            message: 'Activity logs retrieved successfully.',
        );
    }

    public function forTask(IndexActivityLogsRequest $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $logs = $this->activityLogService->list($request->filters(), $task);

        return $this->paginatedResponse(
            paginator: $logs,
            data: ActivityLogResource::collection($logs),
            message: 'Activity logs retrieved successfully.',
        );
    }

    public function forUser(IndexActivityLogsRequest $request, User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $logs = $this->activityLogService->list($request->filters(), $user);

        return $this->paginatedResponse(
            paginator: $logs,
            data: ActivityLogResource::collection($logs),
            message: 'Activity logs retrieved successfully.',
        );
    }
}
