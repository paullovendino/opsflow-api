<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Enums\ActivityAction;
use App\Enums\UserStatus;
use App\Models\User;
use App\Queries\Users\UserQuery;
use App\Services\ActivityLogs\ActivityLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function __construct(
        private readonly UserQuery $userQuery,
        private readonly ActivityLogService $activityLogService,
    ) {}

    /**
     * @param  array{
     *     search?: string|null,
     *     role_id?: int|null,
     *     department_id?: int|null,
     *     job_title_id?: int|null,
     *     status?: string|null,
     *     sort?: string,
     *     direction?: string,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return $this->userQuery->paginate($filters);
    }

    public function find(User $user): User
    {
        return $user->loadMissing(['role', 'department', 'jobTitle', 'avatarFile']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): User
    {
        $user = User::query()->create([
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role_id' => $data['role_id'],
            'department_id' => $data['department_id'] ?? null,
            'job_title_id' => $data['job_title_id'] ?? null,
            'status' => $data['status'],
        ]);

        $user = $user->load(['role', 'department', 'jobTitle', 'avatarFile']);

        $this->activityLogService->record(
            actor: $actor,
            action: ActivityAction::UserCreated,
            subject: $user,
            description: "Created user {$user->full_name}.",
            properties: [
                'email' => $user->email,
                'role_id' => $user->role_id,
                'status' => $this->scalar($user->status),
            ],
        );

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data, User $actor): User
    {
        $before = $this->userSnapshot($user);
        $passwordChanged = array_key_exists('password', $data) && filled($data['password']);

        $attributes = [
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'role_id' => $data['role_id'],
            'department_id' => $data['department_id'] ?? null,
            'job_title_id' => $data['job_title_id'] ?? null,
            'status' => $data['status'],
        ];

        if ($passwordChanged) {
            $attributes['password'] = Hash::make($data['password']);
        }

        $user->update($attributes);

        $user = $user->fresh(['role', 'department', 'jobTitle', 'avatarFile']) ?? $user->load(['role', 'department', 'jobTitle', 'avatarFile']);
        $after = $this->userSnapshot($user);

        if ($before !== $after || $passwordChanged) {
            $properties = [
                'before' => $before,
                'after' => $after,
            ];

            if ($passwordChanged) {
                $properties['password_changed'] = true;
            }

            $this->activityLogService->record(
                actor: $actor,
                action: ActivityAction::UserUpdated,
                subject: $user,
                description: "Updated user {$user->full_name}.",
                properties: $properties,
            );
        }

        return $user;
    }

    public function delete(User $user): void
    {
        $user->delete();
    }

    public function changeStatus(User $user, UserStatus $status, User $actor): User
    {
        $previous = $this->scalar($user->status);

        if ($previous === $status->value) {
            return $user->fresh(['role', 'department', 'jobTitle', 'avatarFile']) ?? $user->load(['role', 'department', 'jobTitle', 'avatarFile']);
        }

        $user->update([
            'status' => $status,
        ]);

        $user = $user->fresh(['role', 'department', 'jobTitle', 'avatarFile']) ?? $user->load(['role', 'department', 'jobTitle', 'avatarFile']);

        $action = $status === UserStatus::Active
            ? ActivityAction::UserActivated
            : ActivityAction::UserDeactivated;

        $verb = $status === UserStatus::Active ? 'Activated' : 'Deactivated';

        $this->activityLogService->record(
            actor: $actor,
            action: $action,
            subject: $user,
            description: "{$verb} user {$user->full_name}.",
            properties: [
                'before' => ['status' => $previous],
                'after' => ['status' => $status->value],
            ],
        );

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function userSnapshot(User $user): array
    {
        return [
            'first_name' => $user->first_name,
            'middle_name' => $user->middle_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'department_id' => $user->department_id,
            'job_title_id' => $user->job_title_id,
            'status' => $this->scalar($user->status),
            'avatar' => $user->avatarFile?->file_path,
        ];
    }

    private function scalar(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value === null ? null : (string) $value;
    }
}
