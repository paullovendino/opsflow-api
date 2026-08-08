<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\ActivityAction;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Queries\Tasks\TaskQuery;
use App\Services\ActivityLogs\ActivityLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TaskService
{
    public function __construct(
        private readonly TaskQuery $taskQuery,
        private readonly ActivityLogService $activityLogService,
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

        $task = $task->load(['project', 'assignee', 'creator']);

        $this->activityLogService->record(
            actor: $creator,
            action: ActivityAction::TaskCreated,
            subject: $task,
            description: "Created task {$task->title}.",
            properties: [
                'project_id' => $task->project_id,
                'priority' => $this->scalar($task->priority),
                'due_date' => $task->due_date?->toDateString(),
                'assigned_to' => $task->assigned_to,
            ],
        );

        if ($task->assigned_to !== null) {
            $assigneeName = $task->assignee?->full_name ?? 'user #'.$task->assigned_to;
            $this->activityLogService->record(
                actor: $creator,
                action: ActivityAction::TaskAssigned,
                subject: $task,
                description: "Assigned task {$task->title} to {$assigneeName}.",
                properties: [
                    'before' => [
                        'assigned_to' => null,
                        'assigned_to_name' => null,
                    ],
                    'after' => [
                        'assigned_to' => $task->assigned_to,
                        'assigned_to_name' => $assigneeName,
                    ],
                    'project_id' => $task->project_id,
                ],
            );
        }

        return $task;
    }

    /**
     * @param  array{
     *     title: string,
     *     description?: string|null,
     *     priority: string,
     *     due_date?: string|null
     * }  $data
     */
    public function update(Task $task, array $data, User $actor): Task
    {
        $previousTitle = $task->title;
        $previousDescription = $task->description;
        $previousPriority = $this->scalar($task->priority);
        $previousDueDate = $task->due_date?->toDateString();

        $task->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'],
            'due_date' => $data['due_date'] ?? null,
        ]);

        $task = $task->fresh(['project', 'assignee', 'creator'])
            ?? $task->load(['project', 'assignee', 'creator']);

        $nextPriority = $this->scalar($task->priority);
        $nextDueDate = $task->due_date?->toDateString();
        $coreChanged = $previousTitle !== $task->title || $previousDescription !== $task->description;

        if ($coreChanged) {
            $this->activityLogService->record(
                actor: $actor,
                action: ActivityAction::TaskUpdated,
                subject: $task,
                description: "Updated task {$task->title}.",
                properties: [
                    'before' => [
                        'title' => $previousTitle,
                        'description' => $previousDescription,
                    ],
                    'after' => [
                        'title' => $task->title,
                        'description' => $task->description,
                    ],
                    'project_id' => $task->project_id,
                ],
            );
        }

        if ($previousPriority !== $nextPriority) {
            $this->activityLogService->record(
                actor: $actor,
                action: ActivityAction::TaskPriorityChanged,
                subject: $task,
                description: sprintf(
                    'Changed task priority from %s to %s.',
                    $this->priorityLabel($previousPriority),
                    $this->priorityLabel($nextPriority),
                ),
                properties: [
                    'before' => ['priority' => $previousPriority],
                    'after' => ['priority' => $nextPriority],
                    'project_id' => $task->project_id,
                ],
            );
        }

        if ($previousDueDate !== $nextDueDate) {
            $this->activityLogService->record(
                actor: $actor,
                action: ActivityAction::TaskDueDateChanged,
                subject: $task,
                description: $this->dueDateDescription($previousDueDate, $nextDueDate),
                properties: [
                    'before' => ['due_date' => $previousDueDate],
                    'after' => ['due_date' => $nextDueDate],
                    'project_id' => $task->project_id,
                ],
            );
        }

        return $task;
    }

    public function delete(Task $task, User $actor): void
    {
        $title = $task->title;

        $this->activityLogService->record(
            actor: $actor,
            action: ActivityAction::TaskDeleted,
            subject: $task,
            description: "Deleted task {$title}.",
            properties: [
                'title' => $title,
                'project_id' => $task->project_id,
            ],
        );

        $task->delete();
    }

    public function changeAssignment(Task $task, ?int $assignedTo, User $actor): Task
    {
        $task->loadMissing('assignee');

        $previous = $task->assigned_to !== null ? (int) $task->assigned_to : null;
        $previousName = $previous !== null
            ? ($task->assignee?->full_name ?? 'user #'.$previous)
            : null;
        $next = $assignedTo;

        if ($previous === $next) {
            return $task->fresh(['project', 'assignee', 'creator'])
                ?? $task->load(['project', 'assignee', 'creator']);
        }

        $task->update([
            'assigned_to' => $assignedTo,
        ]);

        $task = $task->fresh(['project', 'assignee', 'creator'])
            ?? $task->load(['project', 'assignee', 'creator']);

        if ($next === null) {
            $this->activityLogService->record(
                actor: $actor,
                action: ActivityAction::TaskUnassigned,
                subject: $task,
                description: "Unassigned task {$task->title}.",
                properties: [
                    'before' => [
                        'assigned_to' => $previous,
                        'assigned_to_name' => $previousName,
                    ],
                    'after' => [
                        'assigned_to' => null,
                        'assigned_to_name' => null,
                    ],
                    'project_id' => $task->project_id,
                ],
            );

            return $task;
        }

        $assigneeName = $task->assignee?->full_name ?? 'user #'.$next;

        $this->activityLogService->record(
            actor: $actor,
            action: ActivityAction::TaskAssigned,
            subject: $task,
            description: "Assigned task {$task->title} to {$assigneeName}.",
            properties: [
                'before' => [
                    'assigned_to' => $previous,
                    'assigned_to_name' => $previousName,
                ],
                'after' => [
                    'assigned_to' => $next,
                    'assigned_to_name' => $assigneeName,
                ],
                'project_id' => $task->project_id,
            ],
        );

        return $task;
    }

    public function changeStatus(Task $task, TaskStatus $status, User $actor): Task
    {
        $previous = $this->scalar($task->status);

        if ($previous === $status->value) {
            return $task->fresh(['project', 'assignee', 'creator'])
                ?? $task->load(['project', 'assignee', 'creator']);
        }

        $task->update([
            'status' => $status,
        ]);

        $task = $task->fresh(['project', 'assignee', 'creator'])
            ?? $task->load(['project', 'assignee', 'creator']);

        $this->activityLogService->record(
            actor: $actor,
            action: ActivityAction::TaskStatusChanged,
            subject: $task,
            description: sprintf(
                'Changed task status from %s to %s.',
                $this->statusLabel($previous),
                $status->label(),
            ),
            properties: [
                'before' => ['status' => $previous],
                'after' => ['status' => $status->value],
                'project_id' => $task->project_id,
            ],
        );

        return $task;
    }

    private function scalar(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value === null ? null : (string) $value;
    }

    private function statusLabel(?string $value): string
    {
        $status = TaskStatus::tryFrom((string) $value);

        return $status?->label() ?? (string) $value;
    }

    private function priorityLabel(?string $value): string
    {
        $priority = TaskPriority::tryFrom((string) $value);

        return $priority?->label() ?? (string) $value;
    }

    private function dueDateDescription(?string $from, ?string $to): string
    {
        if ($from === null && $to !== null) {
            return "Set task due date to {$to}.";
        }

        if ($from !== null && $to === null) {
            return 'Cleared task due date.';
        }

        return "Changed task due date from {$from} to {$to}.";
    }
}
