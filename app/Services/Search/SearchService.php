<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\RoleName;
use App\Models\User;
use App\Queries\Search\SearchQuery;
use Illuminate\Support\Collection;

class SearchService
{
    public const DEFAULT_PER_TYPE = 5;

    public const MAX_PER_TYPE = 10;

    public const MIN_QUERY_LENGTH = 2;

    public const MAX_QUERY_LENGTH = 100;

    /**
     * @var list<string>
     */
    public const ALLOWED_TYPES = [
        'users',
        'projects',
        'tasks',
    ];

    public function __construct(
        private readonly SearchQuery $searchQuery,
    ) {}

    /**
     * @param  list<string>|null  $types
     * @return array{
     *     data: array{
     *         users: Collection<int, User>|list<never>,
     *         projects: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Project>|list<never>,
     *         tasks: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task>|list<never>
     *     },
     *     meta: array{
     *         q: string,
     *         per_type: int,
     *         users_returned: int,
     *         projects_returned: int,
     *         tasks_returned: int
     *     }
     * }
     */
    public function search(User $actor, string $q, ?array $types, int $perType): array
    {
        $actor->loadMissing('role');

        $requestedTypes = $this->resolveTypes($actor, $types);
        $limit = $this->clampPerType($perType);

        $users = in_array('users', $requestedTypes, true)
            ? $this->searchQuery->searchUsers($q, $limit)
            : collect();

        $projects = in_array('projects', $requestedTypes, true)
            ? $this->searchQuery->searchProjects($actor, $q, $limit)
            : collect();

        $tasks = in_array('tasks', $requestedTypes, true)
            ? $this->searchQuery->searchTasks($actor, $q, $limit)
            : collect();

        return [
            'data' => [
                'users' => $users,
                'projects' => $projects,
                'tasks' => $tasks,
            ],
            'meta' => [
                'q' => $q,
                'per_type' => $limit,
                'users_returned' => $users->count(),
                'projects_returned' => $projects->count(),
                'tasks_returned' => $tasks->count(),
            ],
        ];
    }

    public function canSearchUsers(User $actor): bool
    {
        $actor->loadMissing('role');

        $role = $actor->role?->name;

        return $role === RoleName::Administrator || $role === RoleName::ProjectManager;
    }

    /**
     * @param  list<string>|null  $types
     * @return list<string>
     */
    private function resolveTypes(User $actor, ?array $types): array
    {
        $allowed = self::ALLOWED_TYPES;

        if (! $this->canSearchUsers($actor)) {
            $allowed = array_values(array_filter(
                $allowed,
                static fn (string $type): bool => $type !== 'users',
            ));
        }

        if ($types === null || $types === []) {
            return $allowed;
        }

        $requested = array_values(array_intersect($types, $allowed));

        return $requested === [] ? $allowed : $requested;
    }

    private function clampPerType(int $perType): int
    {
        if ($perType < 1) {
            return 1;
        }

        if ($perType > self::MAX_PER_TYPE) {
            return self::MAX_PER_TYPE;
        }

        return $perType;
    }
}
