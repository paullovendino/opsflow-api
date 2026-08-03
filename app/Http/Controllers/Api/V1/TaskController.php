<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Api\V1\Tasks\IndexTasksRequest;
use App\Http\Requests\Api\V1\Tasks\StoreTaskRequest;
use App\Http\Requests\Api\V1\Tasks\UpdateTaskAssignmentRequest;
use App\Http\Requests\Api\V1\Tasks\UpdateTaskRequest;
use App\Http\Resources\Api\V1\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class TaskController extends BaseApiController
{
    public function __construct(
        private readonly TaskService $taskService,
    ) {}

    public function index(IndexTasksRequest $request): JsonResponse
    {
        $tasks = $this->taskService->list(
            filters: $request->filters(),
        );

        return $this->paginatedResponse(
            paginator: $tasks,
            data: TaskResource::collection($tasks),
            message: 'Tasks retrieved successfully.',
        );
    }

    public function show(Task $task): JsonResponse
    {
        $task = $this->taskService->find($task);

        return $this->successResponse(
            data: new TaskResource($task),
            message: 'Task retrieved successfully.',
        );
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $task = $this->taskService->create($request->validated(), $user);

        return $this->successResponse(
            data: new TaskResource($task),
            message: 'Task created successfully.',
            status: Response::HTTP_CREATED,
        );
    }

    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        $task = $this->taskService->update($task, $request->validated());

        return $this->successResponse(
            data: new TaskResource($task),
            message: 'Task updated successfully.',
        );
    }

    public function destroy(Task $task): JsonResponse
    {
        $this->taskService->delete($task);

        return $this->successResponse(
            message: 'Task deleted successfully.',
        );
    }

    public function updateAssignment(UpdateTaskAssignmentRequest $request, Task $task): JsonResponse
    {
        /** @var array{assigned_to: int|null} $validated */
        $validated = $request->validated();

        $task = $this->taskService->changeAssignment(
            $task,
            $validated['assigned_to'] ?? null,
        );

        return $this->successResponse(
            data: new TaskResource($task),
            message: 'Task assignment updated successfully.',
        );
    }
}
