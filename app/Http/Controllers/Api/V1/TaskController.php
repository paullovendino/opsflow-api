<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskStatus;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Api\V1\Tasks\IndexTasksRequest;
use App\Http\Requests\Api\V1\Tasks\StoreTaskRequest;
use App\Http\Requests\Api\V1\Tasks\UpdateTaskAssignmentRequest;
use App\Http\Requests\Api\V1\Tasks\UpdateTaskRequest;
use App\Http\Requests\Api\V1\Tasks\UpdateTaskStatusRequest;
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
        $this->authorize('viewAny', Task::class);

        /** @var User $actor */
        $actor = $request->user();

        $tasks = $this->taskService->list(
            actor: $actor,
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
        $this->authorize('view', $task);

        $task = $this->taskService->find($task);

        return $this->successResponse(
            data: new TaskResource($task),
            message: 'Task retrieved successfully.',
        );
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $this->authorize('create', Task::class);

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
        $this->authorize('update', $task);

        /** @var User $actor */
        $actor = $request->user();

        $task = $this->taskService->update($task, $request->validated(), $actor);

        return $this->successResponse(
            data: new TaskResource($task),
            message: 'Task updated successfully.',
        );
    }

    public function destroy(Task $task): JsonResponse
    {
        $this->authorize('delete', $task);

        /** @var User $actor */
        $actor = request()->user();

        $this->taskService->delete($task, $actor);

        return $this->successResponse(
            message: 'Task deleted successfully.',
        );
    }

    public function updateAssignment(UpdateTaskAssignmentRequest $request, Task $task): JsonResponse
    {
        $this->authorize('updateAssignment', $task);

        /** @var array{assigned_to: int|null} $validated */
        $validated = $request->validated();

        /** @var User $actor */
        $actor = $request->user();

        $task = $this->taskService->changeAssignment(
            $task,
            $validated['assigned_to'] ?? null,
            $actor,
        );

        return $this->successResponse(
            data: new TaskResource($task),
            message: 'Task assignment updated successfully.',
        );
    }

    public function updateStatus(UpdateTaskStatusRequest $request, Task $task): JsonResponse
    {
        $this->authorize('updateStatus', $task);

        $status = $request->enum('status', TaskStatus::class);

        /** @var User $actor */
        $actor = $request->user();

        $task = $this->taskService->changeStatus($task, $status, $actor);

        return $this->successResponse(
            data: new TaskResource($task),
            message: 'Task status updated successfully.',
        );
    }
}
