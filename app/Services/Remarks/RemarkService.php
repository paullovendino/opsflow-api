<?php

declare(strict_types=1);

namespace App\Services\Remarks;

use App\Enums\ActivityAction;
use App\Enums\NotificationType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Project;
use App\Models\Remark;
use App\Models\RemarkMention;
use App\Models\Task;
use App\Models\User;
use App\Queries\Remarks\RemarkQuery;
use App\Services\ActivityLogs\ActivityLogService;
use App\Services\Notifications\NotificationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemarkService
{
    public const MAX_BODY_LENGTH = 5000;

    public function __construct(
        private readonly RemarkQuery $remarkQuery,
        private readonly ActivityLogService $activityLogService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * @param  array{
     *     direction?: string,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, Remark>
     */
    public function list(Model $remarkable, array $filters = []): LengthAwarePaginator
    {
        return $this->remarkQuery->paginateFor($remarkable, $filters);
    }

    /**
     * @param  array{body: string, mentioned_user_ids?: list<int>|null}  $data
     */
    public function create(Model $remarkable, array $data, User $author): Remark
    {
        $this->assertSupportedRemarkable($remarkable);

        $body = $this->normalizeBody($data['body']);
        $mentionIds = $this->normalizeMentionIds($data['mentioned_user_ids'] ?? []);
        $this->assertMentionsAreAllowed($author, $remarkable, $mentionIds);

        $remark = DB::transaction(function () use ($remarkable, $author, $body, $mentionIds): Remark {
            $remark = Remark::query()->create([
                'author_id' => $author->id,
                'remarkable_type' => $remarkable->getMorphClass(),
                'remarkable_id' => $remarkable->getKey(),
                'body' => $body,
            ]);

            $this->syncMentions($remark, $mentionIds);

            return $remark->load(['author', 'mentions.user']);
        });

        $this->activityLogService->record(
            actor: $author,
            action: ActivityAction::RemarkCreated,
            subject: $remark,
            description: $this->activityDescription('Added a remark', $remarkable),
            properties: $this->activityProperties($remarkable, $mentionIds),
        );

        $this->notifyRemarkRecipients($remark, $remarkable, $author, $mentionIds);

        return $remark;
    }

    /**
     * @param  array{body: string, mentioned_user_ids?: list<int>|null}  $data
     */
    public function update(Remark $remark, array $data, User $actor): Remark
    {
        $remarkable = $this->resolveRemarkable($remark);
        $body = $this->normalizeBody($data['body']);

        $mentionIds = array_key_exists('mentioned_user_ids', $data)
            ? $this->normalizeMentionIds($data['mentioned_user_ids'] ?? [])
            : $remark->mentions()->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all();

        $this->assertMentionsAreAllowed($actor, $remarkable, $mentionIds);

        $previousBody = $remark->body;
        $previousMentions = $remark->mentions()->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $remark = DB::transaction(function () use ($remark, $body, $mentionIds): Remark {
            $remark->update([
                'body' => $body,
            ]);

            $this->syncMentions($remark, $mentionIds);

            return $remark->fresh(['author', 'mentions.user']) ?? $remark->load(['author', 'mentions.user']);
        });

        $nextMentions = $remark->mentions()->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        if ($previousBody !== $remark->body || $previousMentions !== $nextMentions) {
            $this->activityLogService->record(
                actor: $actor,
                action: ActivityAction::RemarkUpdated,
                subject: $remark,
                description: $this->activityDescription('Updated a remark', $remarkable),
                properties: $this->activityProperties($remarkable, $nextMentions, [
                    'before' => [
                        'body' => $previousBody,
                        'mentioned_user_ids' => $previousMentions,
                    ],
                    'after' => [
                        'body' => $remark->body,
                        'mentioned_user_ids' => $nextMentions,
                    ],
                ]),
            );
        }

        return $remark;
    }

    public function delete(Remark $remark, User $actor): void
    {
        $remarkable = $this->resolveRemarkable($remark);
        $mentionIds = $remark->mentions()->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all();

        $this->activityLogService->record(
            actor: $actor,
            action: ActivityAction::RemarkDeleted,
            subject: $remark,
            description: $this->activityDescription('Deleted a remark', $remarkable),
            properties: $this->activityProperties($remarkable, $mentionIds, [
                'body' => $remark->body,
            ]),
        );

        $remark->delete();
    }

    private function assertSupportedRemarkable(Model $remarkable): void
    {
        if (! $remarkable instanceof Project && ! $remarkable instanceof Task) {
            throw ValidationException::withMessages([
                'remarkable' => ['Remarks are only supported on projects and tasks.'],
            ]);
        }
    }

    private function resolveRemarkable(Remark $remark): Model
    {
        $remark->loadMissing('remarkable');
        $remarkable = $remark->remarkable;

        if (! $remarkable instanceof Model) {
            throw ValidationException::withMessages([
                'remark' => ['The related record for this remark could not be found.'],
            ]);
        }

        $this->assertSupportedRemarkable($remarkable);

        return $remarkable;
    }

    private function normalizeBody(string $body): string
    {
        $normalized = trim($body);

        if ($normalized === '') {
            throw ValidationException::withMessages([
                'body' => ['The body field is required.'],
            ]);
        }

        if (mb_strlen($normalized) > self::MAX_BODY_LENGTH) {
            throw ValidationException::withMessages([
                'body' => ['The body may not be greater than '.self::MAX_BODY_LENGTH.' characters.'],
            ]);
        }

        return $normalized;
    }

    /**
     * @param  list<int|string>|null  $ids
     * @return list<int>
     */
    private function normalizeMentionIds(?array $ids): array
    {
        if ($ids === null || $ids === []) {
            return [];
        }

        $normalized = [];

        foreach ($ids as $id) {
            if (! is_numeric($id)) {
                continue;
            }

            $value = (int) $id;
            if ($value > 0) {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param  list<int>  $mentionIds
     */
    private function assertMentionsAreAllowed(User $actor, Model $remarkable, array $mentionIds): void
    {
        if ($mentionIds === []) {
            return;
        }

        $users = User::query()
            ->whereIn('id', $mentionIds)
            ->get()
            ->keyBy('id');

        if ($users->count() !== count($mentionIds)) {
            throw ValidationException::withMessages([
                'mentioned_user_ids' => ['One or more mentioned users are invalid.'],
            ]);
        }

        foreach ($mentionIds as $id) {
            /** @var User $user */
            $user = $users->get($id);

            if ($user->status !== UserStatus::Active) {
                throw ValidationException::withMessages([
                    'mentioned_user_ids' => ['Mentioned users must be active accounts.'],
                ]);
            }

            if (! $this->canMention($actor, $user, $remarkable)) {
                throw ValidationException::withMessages([
                    'mentioned_user_ids' => ['You cannot mention one or more of the selected users.'],
                ]);
            }
        }
    }

    private function canMention(User $actor, User $target, Model $remarkable): bool
    {
        if ($this->isAdministrator($actor) || $this->isProjectManager($actor)) {
            return true;
        }

        if ($actor->is($target)) {
            return true;
        }

        $project = $this->projectFor($remarkable);

        return $this->usersShareVisibleProject($actor, $target) || $this->isOwnerOrMember($target, $project);
    }

    private function usersShareVisibleProject(User $actor, User $target): bool
    {
        $actorProjectIds = $this->visibleProjectIdsFor($actor);

        if ($actorProjectIds === []) {
            return false;
        }

        $targetProjectIds = $this->visibleProjectIdsFor($target);

        return count(array_intersect($actorProjectIds, $targetProjectIds)) > 0;
    }

    /**
     * @return list<int>
     */
    private function visibleProjectIdsFor(User $user): array
    {
        $owned = Project::query()
            ->where('created_by', $user->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $member = $user->projects()
            ->pluck('projects.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([...$owned, ...$member]));
    }

    private function projectFor(Model $remarkable): Project
    {
        if ($remarkable instanceof Project) {
            return $remarkable;
        }

        if ($remarkable instanceof Task) {
            $remarkable->loadMissing('project');

            return $remarkable->project;
        }

        throw ValidationException::withMessages([
            'remarkable' => ['Remarks are only supported on projects and tasks.'],
        ]);
    }

    private function isOwnerOrMember(User $user, Project $project): bool
    {
        if ((int) $project->created_by === (int) $user->id) {
            return true;
        }

        return $project->members()->where('users.id', $user->id)->exists();
    }

    /**
     * @param  list<int>  $mentionIds
     */
    private function syncMentions(Remark $remark, array $mentionIds): void
    {
        $existing = $remark->mentions()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $toDetach = array_values(array_diff($existing, $mentionIds));
        $toAttach = array_values(array_diff($mentionIds, $existing));

        if ($toDetach !== []) {
            $remark->mentions()->whereIn('user_id', $toDetach)->delete();
        }

        foreach ($toAttach as $userId) {
            RemarkMention::query()->create([
                'remark_id' => $remark->id,
                'user_id' => $userId,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @param  list<int>  $mentionIds
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function activityProperties(Model $remarkable, array $mentionIds, array $extra = []): array
    {
        $properties = [
            'remarkable_type' => $remarkable->getMorphClass(),
            'remarkable_id' => (int) $remarkable->getKey(),
            'mentioned_user_ids' => $mentionIds,
        ];

        if ($remarkable instanceof Task) {
            $properties['project_id'] = (int) $remarkable->project_id;
        }

        return array_merge($properties, $extra);
    }

    /**
     * @param  list<int>  $mentionIds
     */
    private function notifyRemarkRecipients(Remark $remark, Model $remarkable, User $author, array $mentionIds): void
    {
        $mentionedIds = [];
        foreach ($mentionIds as $id) {
            if ((int) $id !== (int) $author->id) {
                $mentionedIds[(int) $id] = (int) $id;
            }
        }

        $watcherIds = [];
        $project = $this->projectFor($remarkable);

        if ($remarkable instanceof Task) {
            $remarkable->loadMissing('assignee');
            if ($remarkable->assigned_to !== null) {
                $watcherIds[(int) $remarkable->assigned_to] = (int) $remarkable->assigned_to;
            }
        }

        $watcherIds[(int) $project->created_by] = (int) $project->created_by;
        unset($watcherIds[(int) $author->id]);
        foreach ($mentionedIds as $id) {
            unset($watcherIds[$id]);
        }

        $userIds = array_values(array_unique([...array_values($mentionedIds), ...array_values($watcherIds)]));
        if ($userIds === []) {
            return;
        }

        $users = User::query()->whereIn('id', $userIds)->get()->keyBy('id');

        foreach ($mentionedIds as $id) {
            $user = $users->get($id);
            if (! $user instanceof User) {
                continue;
            }

            $this->notificationService->notify(
                recipient: $user,
                type: NotificationType::RemarkMentioned,
                actor: $author,
                subject: $remark,
                data: $this->remarkNotificationData($remarkable, $remark, $author, true),
            );
        }

        foreach ($watcherIds as $id) {
            $user = $users->get($id);
            if (! $user instanceof User) {
                continue;
            }

            $this->notificationService->notify(
                recipient: $user,
                type: NotificationType::RemarkCreated,
                actor: $author,
                subject: $remark,
                data: $this->remarkNotificationData($remarkable, $remark, $author, false),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function remarkNotificationData(Model $remarkable, Remark $remark, User $author, bool $mentioned): array
    {
        if ($remarkable instanceof Task) {
            return [
                'title' => $mentioned ? 'You were mentioned' : 'New remark on your work',
                'message' => $mentioned
                    ? "{$author->full_name} mentioned you on task {$remarkable->title}."
                    : "{$author->full_name} added a remark on task {$remarkable->title}.",
                'target_type' => 'task',
                'target_id' => (int) $remarkable->id,
                'project_id' => (int) $remarkable->project_id,
                'remark_id' => (int) $remark->id,
            ];
        }

        $name = $remarkable instanceof Project ? $remarkable->name : 'a record';

        return [
            'title' => $mentioned ? 'You were mentioned' : 'New remark on your work',
            'message' => $mentioned
                ? "{$author->full_name} mentioned you on project {$name}."
                : "{$author->full_name} added a remark on project {$name}.",
            'target_type' => 'project',
            'target_id' => (int) $remarkable->getKey(),
            'remark_id' => (int) $remark->id,
        ];
    }

    private function activityDescription(string $verb, Model $remarkable): string
    {
        if ($remarkable instanceof Project) {
            return "{$verb} on project {$remarkable->name}.";
        }

        if ($remarkable instanceof Task) {
            return "{$verb} on task {$remarkable->title}.";
        }

        return "{$verb}.";
    }

    private function isAdministrator(User $user): bool
    {
        return $this->roleName($user) === RoleName::Administrator;
    }

    private function isProjectManager(User $user): bool
    {
        return $this->roleName($user) === RoleName::ProjectManager;
    }

    private function roleName(User $user): ?RoleName
    {
        $user->loadMissing('role');

        $name = $user->role?->name;

        return $name instanceof RoleName ? $name : null;
    }
}
