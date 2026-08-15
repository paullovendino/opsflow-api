<?php

declare(strict_types=1);

namespace App\Services\JobTitles;

use App\Enums\ActivityAction;
use App\Enums\OrgEntityStatus;
use App\Models\JobTitle;
use App\Models\User;
use App\Queries\JobTitles\JobTitleQuery;
use App\Services\ActivityLogs\ActivityLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class JobTitleService
{
    public function __construct(
        private readonly JobTitleQuery $jobTitleQuery,
        private readonly ActivityLogService $activityLogService,
    ) {}

    /**
     * @param  array{
     *     q?: string|null,
     *     status?: string|null,
     *     department_id?: int|null,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, JobTitle>
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return $this->jobTitleQuery->paginate($filters);
    }

    public function find(JobTitle $jobTitle): JobTitle
    {
        return $jobTitle->loadMissing('department')->loadCount('users');
    }

    /**
     * @param  array{
     *     name: string,
     *     description?: string|null,
     *     department_id: int
     * }  $data
     */
    public function create(array $data, User $actor): JobTitle
    {
        $this->assertUniqueName((int) $data['department_id'], $data['name']);

        $jobTitle = JobTitle::query()->create([
            'department_id' => $data['department_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => OrgEntityStatus::Active,
            'code' => null,
        ]);

        $jobTitle = $jobTitle->load('department')->loadCount('users');

        $this->activityLogService->record(
            actor: $actor,
            action: ActivityAction::JobTitleCreated,
            subject: $jobTitle,
            description: "Created job title {$jobTitle->name}.",
            properties: [
                'department_id' => $jobTitle->department_id,
                'status' => OrgEntityStatus::Active->value,
            ],
        );

        return $jobTitle;
    }

    /**
     * @param  array{
     *     name: string,
     *     description?: string|null,
     *     department_id: int
     * }  $data
     */
    public function update(JobTitle $jobTitle, array $data, User $actor): JobTitle
    {
        $newDepartmentId = (int) $data['department_id'];
        $departmentChanged = $newDepartmentId !== (int) $jobTitle->department_id;

        if ($departmentChanged && $jobTitle->users()->exists()) {
            throw ValidationException::withMessages([
                'department_id' => 'Cannot change the department while users are assigned to this job title.',
            ]);
        }

        $this->assertUniqueName($newDepartmentId, $data['name'], $jobTitle->id);

        $before = $this->snapshot($jobTitle);
        $previousDepartmentId = (int) $jobTitle->department_id;

        $jobTitle->update([
            'department_id' => $newDepartmentId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        $jobTitle = ($jobTitle->fresh() ?? $jobTitle)->load('department')->loadCount('users');
        $after = $this->snapshot($jobTitle);

        if ($before !== $after) {
            $this->activityLogService->record(
                actor: $actor,
                action: ActivityAction::JobTitleUpdated,
                subject: $jobTitle,
                description: "Updated job title {$jobTitle->name}.",
                properties: [
                    'before' => $before,
                    'after' => $after,
                ],
            );
        }

        if ($departmentChanged) {
            $this->activityLogService->record(
                actor: $actor,
                action: ActivityAction::JobTitleDepartmentChanged,
                subject: $jobTitle,
                description: "Changed job title department for {$jobTitle->name}.",
                properties: [
                    'before' => ['department_id' => $previousDepartmentId],
                    'after' => ['department_id' => $newDepartmentId],
                ],
            );
        }

        return $jobTitle;
    }

    public function delete(JobTitle $jobTitle, User $actor): void
    {
        if ($jobTitle->users()->exists()) {
            throw ValidationException::withMessages([
                'job_title' => 'Cannot delete a job title that still has assigned users.',
            ]);
        }

        $name = $jobTitle->name;

        $this->activityLogService->record(
            actor: $actor,
            action: ActivityAction::JobTitleDeleted,
            subject: $jobTitle,
            description: "Deleted job title {$name}.",
            properties: [
                'name' => $name,
                'department_id' => $jobTitle->department_id,
            ],
        );

        $jobTitle->delete();
    }

    public function changeStatus(JobTitle $jobTitle, OrgEntityStatus $status, User $actor): JobTitle
    {
        $previous = $jobTitle->status;

        if ($previous === $status) {
            return $jobTitle->loadMissing('department')->loadCount('users');
        }

        $jobTitle->update([
            'status' => $status,
        ]);

        $jobTitle = ($jobTitle->fresh() ?? $jobTitle)->load('department')->loadCount('users');

        $action = $status === OrgEntityStatus::Active
            ? ActivityAction::JobTitleActivated
            : ActivityAction::JobTitleDeactivated;

        $this->activityLogService->record(
            actor: $actor,
            action: $action,
            subject: $jobTitle,
            description: sprintf(
                'Changed job title status from %s to %s.',
                $previous->value,
                $status->value,
            ),
            properties: [
                'before' => ['status' => $previous->value],
                'after' => ['status' => $status->value],
            ],
        );

        return $jobTitle;
    }

    private function assertUniqueName(int $departmentId, string $name, ?int $ignoreId = null): void
    {
        $query = JobTitle::query()
            ->where('department_id', $departmentId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'The name has already been taken for this department.',
            ]);
        }
    }

    /**
     * @return array{name: string, description: string|null, department_id: int}
     */
    private function snapshot(JobTitle $jobTitle): array
    {
        return [
            'name' => $jobTitle->name,
            'description' => $jobTitle->description,
            'department_id' => (int) $jobTitle->department_id,
        ];
    }
}
