<?php

declare(strict_types=1);

namespace App\Services\Departments;

use App\Enums\ActivityAction;
use App\Enums\OrgEntityStatus;
use App\Models\Department;
use App\Models\User;
use App\Queries\Departments\DepartmentQuery;
use App\Services\ActivityLogs\ActivityLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class DepartmentService
{
    public function __construct(
        private readonly DepartmentQuery $departmentQuery,
        private readonly ActivityLogService $activityLogService,
    ) {}

    /**
     * @param  array{
     *     q?: string|null,
     *     status?: string|null,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, Department>
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return $this->departmentQuery->paginate($filters);
    }

    public function find(Department $department): Department
    {
        return $department->loadCount(['jobTitles', 'users']);
    }

    /**
     * @param  array{
     *     name: string,
     *     description?: string|null
     * }  $data
     */
    public function create(array $data, User $actor): Department
    {
        $this->assertUniqueName($data['name']);

        $department = Department::query()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => OrgEntityStatus::Active,
            'code' => null,
        ]);

        $department = $department->loadCount(['jobTitles', 'users']);

        $this->activityLogService->record(
            actor: $actor,
            action: ActivityAction::DepartmentCreated,
            subject: $department,
            description: "Created department {$department->name}.",
            properties: [
                'status' => OrgEntityStatus::Active->value,
            ],
        );

        return $department;
    }

    /**
     * @param  array{
     *     name: string,
     *     description?: string|null
     * }  $data
     */
    public function update(Department $department, array $data, User $actor): Department
    {
        $this->assertUniqueName($data['name'], $department->id);

        $before = $this->snapshot($department);

        $department->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        $department = ($department->fresh() ?? $department)->loadCount(['jobTitles', 'users']);
        $after = $this->snapshot($department);

        if ($before !== $after) {
            $this->activityLogService->record(
                actor: $actor,
                action: ActivityAction::DepartmentUpdated,
                subject: $department,
                description: "Updated department {$department->name}.",
                properties: [
                    'before' => $before,
                    'after' => $after,
                ],
            );
        }

        return $department;
    }

    public function delete(Department $department, User $actor): void
    {
        if ($department->users()->exists()) {
            throw ValidationException::withMessages([
                'department' => 'Cannot delete a department that still has assigned users.',
            ]);
        }

        if ($department->jobTitles()->exists()) {
            throw ValidationException::withMessages([
                'department' => 'Cannot delete a department that still has job titles.',
            ]);
        }

        $name = $department->name;

        $this->activityLogService->record(
            actor: $actor,
            action: ActivityAction::DepartmentDeleted,
            subject: $department,
            description: "Deleted department {$name}.",
            properties: [
                'name' => $name,
            ],
        );

        $department->delete();
    }

    public function changeStatus(Department $department, OrgEntityStatus $status, User $actor): Department
    {
        $previous = $department->status;

        if ($previous === $status) {
            return $department->loadCount(['jobTitles', 'users']);
        }

        $department->update([
            'status' => $status,
        ]);

        $department = ($department->fresh() ?? $department)->loadCount(['jobTitles', 'users']);

        $action = $status === OrgEntityStatus::Active
            ? ActivityAction::DepartmentActivated
            : ActivityAction::DepartmentDeactivated;

        $this->activityLogService->record(
            actor: $actor,
            action: $action,
            subject: $department,
            description: sprintf(
                'Changed department status from %s to %s.',
                $previous->value,
                $status->value,
            ),
            properties: [
                'before' => ['status' => $previous->value],
                'after' => ['status' => $status->value],
            ],
        );

        return $department;
    }

    private function assertUniqueName(string $name, ?int $ignoreId = null): void
    {
        $query = Department::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'The name has already been taken.',
            ]);
        }
    }

    /**
     * @return array{name: string, description: string|null}
     */
    private function snapshot(Department $department): array
    {
        return [
            'name' => $department->name,
            'description' => $department->description,
        ];
    }
}
