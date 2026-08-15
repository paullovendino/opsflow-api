<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Enums\ActivityAction;
use App\Enums\TaskStatus;
use App\Http\Resources\Api\V1\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogs\ActivityLogService;
use App\Services\Files\FileStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProfileService
{
    public const DEFAULT_ACTIVITY_LIMIT = 10;

    public const AVATAR_DISK = File::DISK_PUBLIC;

    public const AVATAR_MAX_KB = 2048;

    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly FileStorageService $fileStorageService,
    ) {}

    /**
     * @return array{
     *     user: User,
     *     projects: array{owned_count: int, member_count: int},
     *     tasks: array{assigned_open: int, assigned_overdue: int},
     *     recent_activity: list<array<string, mixed>>
     * }
     */
    public function show(User $user): array
    {
        $user = $user->loadMissing(['role', 'department', 'jobTitle', 'avatarFile']);

        return [
            'user' => $user,
            'projects' => $this->projectSummary($user),
            'tasks' => $this->taskSummary($user),
            'recent_activity' => $this->recentActivity($user),
        ];
    }

    /**
     * @param  array{
     *     first_name?: string,
     *     middle_name?: string|null,
     *     last_name?: string,
     *     password?: string|null,
     *     avatar?: UploadedFile|null,
     *     theme_preference?: string,
     *     notify_task_assigned?: bool,
     *     notify_task_status?: bool,
     *     notify_remarks?: bool,
     *     notify_mentions?: bool
     * }  $data
     * @return array{
     *     user: User,
     *     projects: array{owned_count: int, member_count: int},
     *     tasks: array{assigned_open: int, assigned_overdue: int},
     *     recent_activity: list<array<string, mixed>>
     * }
     */
    public function update(User $user, array $data): array
    {
        $before = $this->profileSnapshot($user);
        $passwordChanged = array_key_exists('password', $data) && filled($data['password']);
        $avatarFile = ($data['avatar'] ?? null) instanceof UploadedFile
            ? $data['avatar']
            : null;

        $attributes = [];

        foreach ([
            'first_name',
            'middle_name',
            'last_name',
            'theme_preference',
            'notify_task_assigned',
            'notify_task_status',
            'notify_remarks',
            'notify_mentions',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if ($passwordChanged) {
            $attributes['password'] = Hash::make((string) $data['password']);
        }

        $previousAvatar = $user->avatarFile;
        $staged = null;
        $permanent = null;
        $newAvatar = null;

        try {
            if ($avatarFile !== null) {
                $extension = $this->extensionForMime((string) $avatarFile->getMimeType());
                $staged = $this->fileStorageService->stage($avatarFile, $extension);
                $permanent = $this->fileStorageService->writePermanent(
                    staged: $staged,
                    directory: 'avatars/'.$user->id,
                    disk: self::AVATAR_DISK,
                );
            }

            DB::transaction(function () use ($user, $attributes, $permanent, &$newAvatar): void {
                if ($attributes !== []) {
                    $user->update($attributes);
                }

                if ($permanent !== null) {
                    $newAvatar = $this->fileStorageService->createRecord(
                        stored: $permanent,
                        attachable: $user,
                        collection: File::COLLECTION_AVATAR,
                    );
                    $user->touch();
                }
            });
        } catch (Throwable $exception) {
            if ($permanent !== null) {
                $this->fileStorageService->deleteStoredPath($permanent['disk'], $permanent['path']);
            }

            throw $exception;
        } finally {
            $this->fileStorageService->discard($staged);
        }

        if (
            $newAvatar instanceof File
            && $previousAvatar instanceof File
            && $previousAvatar->id !== $newAvatar->id
        ) {
            $this->fileStorageService->delete($previousAvatar);
        }

        $user = $user->fresh(['role', 'department', 'jobTitle', 'avatarFile'])
            ?? $user->load(['role', 'department', 'jobTitle', 'avatarFile']);
        $after = $this->profileSnapshot($user);

        if ($before !== $after || $passwordChanged) {
            $properties = [
                'before' => $before,
                'after' => $after,
            ];

            if ($passwordChanged) {
                $properties['password_changed'] = true;
            }

            $this->activityLogService->record(
                actor: $user,
                action: ActivityAction::UserUpdated,
                subject: $user,
                description: "Updated profile for {$user->full_name}.",
                properties: $properties,
            );
        }

        return $this->show($user);
    }

    /**
     * @return array{
     *     user: User,
     *     projects: array{owned_count: int, member_count: int},
     *     tasks: array{assigned_open: int, assigned_overdue: int},
     *     recent_activity: list<array<string, mixed>>
     * }
     */
    public function removeAvatar(User $user): array
    {
        $before = $this->profileSnapshot($user);
        $avatar = $user->avatarFile;

        DB::transaction(function () use ($user, $avatar): void {
            if ($avatar instanceof File) {
                $this->fileStorageService->delete($avatar);
                $user->touch();
            }
        });

        $user = $user->fresh(['role', 'department', 'jobTitle', 'avatarFile'])
            ?? $user->load(['role', 'department', 'jobTitle', 'avatarFile']);
        $after = $this->profileSnapshot($user);

        if ($before !== $after) {
            $this->activityLogService->record(
                actor: $user,
                action: ActivityAction::UserUpdated,
                subject: $user,
                description: "Updated profile for {$user->full_name}.",
                properties: [
                    'before' => $before,
                    'after' => $after,
                ],
            );
        }

        return $this->show($user);
    }

    public static function publicAvatarUrl(?File $avatar, ?\DateTimeInterface $version = null): ?string
    {
        if ($avatar === null || $avatar->file_path === '') {
            return null;
        }

        $url = Storage::disk($avatar->disk ?: self::AVATAR_DISK)->url($avatar->file_path);

        return self::withAvatarCacheBuster($url, $version ?? $avatar->updated_at);
    }

    private static function withAvatarCacheBuster(string $url, ?\DateTimeInterface $version): string
    {
        if ($version === null) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'v='.$version->getTimestamp();
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new RuntimeException('Unsupported avatar MIME type.'),
        };
    }

    /**
     * @return array{owned_count: int, member_count: int}
     */
    private function projectSummary(User $user): array
    {
        $ownedCount = Project::query()
            ->where('created_by', $user->id)
            ->count();

        $memberCount = Project::query()
            ->whereHas(
                'members',
                fn ($members) => $members->where('users.id', $user->id),
            )
            ->count();

        return [
            'owned_count' => $ownedCount,
            'member_count' => $memberCount,
        ];
    }

    /**
     * @return array{assigned_open: int, assigned_overdue: int}
     */
    private function taskSummary(User $user): array
    {
        $openQuery = Task::query()
            ->where('assigned_to', $user->id)
            ->whereNotIn('status', [
                TaskStatus::Completed->value,
                TaskStatus::Cancelled->value,
            ]);

        $assignedOpen = (clone $openQuery)->count();
        $assignedOverdue = (clone $openQuery)->overdue()->count();

        return [
            'assigned_open' => $assignedOpen,
            'assigned_overdue' => $assignedOverdue,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentActivity(User $user): array
    {
        $logs = ActivityLog::query()
            ->with(['actor', 'subject'])
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::DEFAULT_ACTIVITY_LIMIT)
            ->get();

        /** @var list<array<string, mixed>> $resolved */
        $resolved = ActivityLogResource::collection($logs)->resolve();

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function profileSnapshot(User $user): array
    {
        $user->loadMissing('avatarFile');

        return [
            'first_name' => $user->first_name,
            'middle_name' => $user->middle_name,
            'last_name' => $user->last_name,
            'avatar' => $user->avatarFile?->file_path,
            'theme_preference' => $user->theme_preference,
            'notify_task_assigned' => (bool) $user->notify_task_assigned,
            'notify_task_status' => (bool) $user->notify_task_status,
            'notify_remarks' => (bool) $user->notify_remarks,
            'notify_mentions' => (bool) $user->notify_mentions,
        ];
    }
}
