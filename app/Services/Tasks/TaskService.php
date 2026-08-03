<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class TaskService
{
    /**
     * @return Collection<int, Task>
     */
    public function list(): Collection
    {
        return Task::query()
            ->with(['project', 'assignee', 'creator'])
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->get();
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
}
