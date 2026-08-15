<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Enums\ActivityAction;
use App\Enums\NotificationType;
use App\Enums\ProjectStatus;
use App\Enums\UserStatus;
use App\Exceptions\DuplicateProjectMemberException;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectQuery;
use App\Services\ActivityLogs\ActivityLogService;
use App\Services\Notifications\NotificationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ProjectService
{
    public function __construct(
        private readonly ProjectQuery $projectQuery,
        private readonly ActivityLogService $activityLogService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * @param  User  $actor
     * @param  array{
     *     search?: string|null,
     *     status?: string|null,
     *     created_by?: int|null,
     *     sort?: string,
     *     direction?: string,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, Project>
     */
    public function list(User $actor, array $filters = []): LengthAwarePaginator
    {
        return $this->projectQuery->paginate($filters, $actor);
    }

    public function find(Project $project): Project
    {
        $project->loadMissing('owner.avatarFile');

        return $this->projectQuery->hydrateProgress($project);
    }

    /**
     * @param  array{
     *     name: string,
     *     description?: string|null,
     *     start_date?: string|null,
     *     due_date?: string|null
     * }  $data
     */
    public function create(array $data, User $owner): Project
    {
        $project = Project::query()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => ProjectStatus::Planning,
            'start_date' => $data['start_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'created_by' => $owner->id,
        ]);

        $project = $this->projectQuery->hydrateProgress($project->load('owner'));

        $this->activityLogService->record(
            actor: $owner,
            action: ActivityAction::ProjectCreated,
            subject: $project,
            description: "Created project {$project->name}.",
            properties: [
                'status' => ProjectStatus::Planning->value,
            ],
        );

        return $project;
    }

    /**
     * @param  array{
     *     name: string,
     *     description?: string|null,
     *     start_date?: string|null,
     *     due_date?: string|null
     * }  $data
     */
    public function update(Project $project, array $data, User $actor): Project
    {
        $before = $this->projectSnapshot($project);

        $project->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
        ]);

        $project = $this->projectQuery->hydrateProgress(
            $project->fresh('owner') ?? $project->load('owner'),
        );
        $after = $this->projectSnapshot($project);

        if ($before !== $after) {
            $this->activityLogService->record(
                actor: $actor,
                action: ActivityAction::ProjectUpdated,
                subject: $project,
                description: "Updated project {$project->name}.",
                properties: [
                    'before' => $before,
                    'after' => $after,
                ],
            );
        }

        return $project;
    }

    public function delete(Project $project, User $actor): void
    {
        $name = $project->name;

        $this->activityLogService->record(
            actor: $actor,
            action: ActivityAction::ProjectDeleted,
            subject: $project,
            description: "Deleted project {$name}.",
            properties: [
                'name' => $name,
            ],
        );

        $project->delete();
    }

    public function changeStatus(Project $project, ProjectStatus $status, User $actor): Project
    {
        $previous = $this->scalar($project->status);

        if ($previous === $status->value) {
            return $this->projectQuery->hydrateProgress(
                $project->fresh('owner') ?? $project->load('owner'),
            );
        }

        $project->update([
            'status' => $status,
        ]);

        $project = $this->projectQuery->hydrateProgress(
            $project->fresh('owner') ?? $project->load('owner'),
        );

        $this->activityLogService->record(
            actor: $actor,
            action: ActivityAction::ProjectStatusChanged,
            subject: $project,
            description: sprintf(
                'Changed project status from %s to %s.',
                $this->projectStatusLabel($previous),
                $status->label(),
            ),
            properties: [
                'before' => ['status' => $previous],
                'after' => ['status' => $status->value],
            ],
        );

        return $project;
    }

    /**
     * @return Collection<int, User>
     */
    public function listMembers(Project $project): Collection
    {
        return $project->members()
            ->orderByPivot('joined_at')
            ->get();
    }

    public function addMember(Project $project, int $userId, User $actor): User
    {
        $user = User::query()->findOrFail($userId);

        if ($user->status !== UserStatus::Active) {
            throw (new ModelNotFoundException)->setModel(User::class, [$userId]);
        }

        if ($project->members()->where('users.id', $user->id)->exists()) {
            throw new DuplicateProjectMemberException;
        }

        $project->members()->attach($user->id, [
            'joined_at' => now(),
        ]);

        $member = $project->members()->where('users.id', $user->id)->firstOrFail();

        $this->activityLogService->record(
            actor: $actor,
            action: ActivityAction::ProjectMemberAdded,
            subject: $project,
            description: "Added {$member->full_name} as a project member.",
            properties: [
                'member_user_id' => $member->id,
                'member_full_name' => $member->full_name,
            ],
        );

        $this->notificationService->notify(
            recipient: $member,
            type: NotificationType::ProjectMemberAdded,
            actor: $actor,
            subject: $project,
            data: [
                'title' => 'Added to a project',
                'message' => "{$actor->full_name} added you to {$project->name}.",
                'target_type' => 'project',
                'target_id' => (int) $project->id,
            ],
        );

        return $member;
    }

    public function removeMember(Project $project, User $user, User $actor): void
    {
        if (! $project->members()->where('users.id', $user->id)->exists()) {
            throw (new ModelNotFoundException)->setModel(User::class, [$user->id]);
        }

        $this->activityLogService->record(
            actor: $actor,
            action: ActivityAction::ProjectMemberRemoved,
            subject: $project,
            description: "Removed {$user->full_name} from the project.",
            properties: [
                'member_user_id' => $user->id,
                'member_full_name' => $user->full_name,
            ],
        );

        $project->members()->detach($user->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function projectSnapshot(Project $project): array
    {
        return [
            'name' => $project->name,
            'description' => $project->description,
            'start_date' => $project->start_date?->toDateString(),
            'due_date' => $project->due_date?->toDateString(),
        ];
    }

    private function scalar(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value === null ? null : (string) $value;
    }

    private function projectStatusLabel(?string $value): string
    {
        $status = ProjectStatus::tryFrom((string) $value);

        return $status?->label() ?? (string) $value;
    }
}
