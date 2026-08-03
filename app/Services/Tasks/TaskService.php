<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Queries\Tasks\TaskQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TaskService
{
    public function __construct(
        private readonly TaskQuery $taskQuery,
    ) {}

    /**
     * @param  array{
     *     search?: string|null,
     *     status?: string|null,
     *     priority?: string|null,
     *     project_id?: int|null,
     *     assigned_to?: int|null,
     *     created_by?: int|null,
     *     sort?: string,
     *     direction?: string,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, Task>
     */
    public function list(User $actor, array $filters = []): LengthAwarePaginator
    {
        return $this->taskQuery->paginate($filters, $actor);
    }

    public function find(Task $task): Task
    {
        return $task->loadMissing(['project', 'assignee', 'creator']);
    }

    /**
     * @param  array{
     *     project_id: int,
     *     title: string,
     *     description?: string|null,
     *     priority?: string|null,
     *     due_date?: string|null,
     *     assigned_to?: int|null
     * }  $data
     */
    public function create(array $data, User $creator): Task
    {
        $task = Task::query()->create([
            'project_id' => $data['project_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => TaskStatus::Todo,
            'priority' => $data['priority'] ?? TaskPriority::Medium->value,
            'due_date' => $data['due_date'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'created_by' => $creator->id,
        ]);

        return $task->load(['project', 'assignee', 'creator']);
    }

    /**
     * @param  array{
     *     title: string,
     *     description?: string|null,
     *     priority: string,
     *     due_date?: string|null
     * }  $data
     */
    public function update(Task $task, array $data): Task
    {
        $task->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'],
            'due_date' => $data['due_date'] ?? null,
        ]);

        return $task->fresh(['project', 'assignee', 'creator'])
            ?? $task->load(['project', 'assignee', 'creator']);
    }

    public function delete(Task $task): void
    {
        $task->delete();
    }

    public function changeAssignment(Task $task, ?int $assignedTo): Task
    {
        $task->update([
            'assigned_to' => $assignedTo,
        ]);

        return $task->fresh(['project', 'assignee', 'creator'])
            ?? $task->load(['project', 'assignee', 'creator']);
    }

    public function changeStatus(Task $task, TaskStatus $status): Task
    {
        $task->update([
            'status' => $status,
        ]);

        return $task->fresh(['project', 'assignee', 'creator'])
            ?? $task->load(['project', 'assignee', 'creator']);
    }
}
