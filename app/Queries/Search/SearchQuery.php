<?php

declare(strict_types=1);

namespace App\Queries\Search;

use App\Enums\RoleName;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SearchQuery
{
    /**
     * @return Collection<int, Project>
     */
    public function searchProjects(User $actor, string $q, int $limit): Collection
    {
        $query = Project::query()
            ->select([
                'id',
                'name',
                'status',
                'updated_at',
            ])
            ->withCount([
                'tasks as eligible_tasks_count' => function (Builder $tasks): void {
                    $tasks->where('status', '!=', TaskStatus::Cancelled->value);
                },
                'tasks as completed_tasks_count' => function (Builder $tasks): void {
                    $tasks->where('status', TaskStatus::Completed->value);
                },
            ]);

        $this->applyProjectVisibility($query, $actor);
        $this->applyProjectMatch($query, $q);
        $this->applyPrefixThenRecencyOrder($query, 'name', $q);

        return $query->limit($limit)->get();
    }

    /**
     * @return Collection<int, Task>
     */
    public function searchTasks(User $actor, string $q, int $limit): Collection
    {
        $query = Task::query()
            ->select([
                'id',
                'title',
                'status',
                'priority',
                'due_date',
                'project_id',
                'updated_at',
            ])
            ->with([
                'project:id,name',
            ]);

        $this->applyTaskVisibility($query, $actor);
        $this->applyTaskMatch($query, $q);
        $this->applyPrefixThenRecencyOrder($query, 'title', $q);

        return $query->limit($limit)->get();
    }

    /**
     * @return Collection<int, User>
     */
    public function searchUsers(string $q, int $limit): Collection
    {
        $query = User::query()
            ->select([
                'id',
                'first_name',
                'middle_name',
                'last_name',
                'email',
                'status',
                'updated_at',
            ]);

        $this->applyUserMatch($query, $q);

        $prefix = $q.'%';

        $query->orderByRaw(
            'CASE WHEN first_name ILIKE ? OR last_name ILIKE ? OR email ILIKE ? THEN 0 ELSE 1 END',
            [$prefix, $prefix, $prefix],
        )
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        return $query->limit($limit)->get();
    }

    /**
     * @param  Builder<Project>  $query
     */
    private function applyProjectVisibility(Builder $query, User $actor): void
    {
        $actor->loadMissing('role');

        $role = $actor->role?->name;

        if ($role === RoleName::Administrator || $role === RoleName::ProjectManager) {
            return;
        }

        $query->where(function (Builder $builder) use ($actor): void {
            $builder->where('created_by', $actor->id)
                ->orWhereHas(
                    'members',
                    fn (Builder $members): Builder => $members->where('users.id', $actor->id),
                );
        });
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function applyTaskVisibility(Builder $query, User $actor): void
    {
        $actor->loadMissing('role');

        $role = $actor->role?->name;

        if ($role === RoleName::Administrator || $role === RoleName::ProjectManager) {
            return;
        }

        $query->whereHas('project', function (Builder $project) use ($actor): void {
            $project->where(function (Builder $builder) use ($actor): void {
                $builder->where('created_by', $actor->id)
                    ->orWhereHas(
                        'members',
                        fn (Builder $members): Builder => $members->where('users.id', $actor->id),
                    );
            });
        });
    }

    /**
     * @param  Builder<Project>  $query
     */
    private function applyProjectMatch(Builder $query, string $q): void
    {
        $term = '%'.$q.'%';

        $query->where(function (Builder $builder) use ($term): void {
            $builder->where('name', 'ilike', $term)
                ->orWhere('description', 'ilike', $term);
        });
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function applyTaskMatch(Builder $query, string $q): void
    {
        $term = '%'.$q.'%';

        $query->where(function (Builder $builder) use ($term): void {
            $builder->where('title', 'ilike', $term)
                ->orWhere('description', 'ilike', $term);
        });
    }

    /**
     * @param  Builder<User>  $query
     */
    private function applyUserMatch(Builder $query, string $q): void
    {
        $term = '%'.$q.'%';

        $query->where(function (Builder $builder) use ($term): void {
            $builder->where('first_name', 'ilike', $term)
                ->orWhere('middle_name', 'ilike', $term)
                ->orWhere('last_name', 'ilike', $term)
                ->orWhere('email', 'ilike', $term);
        });
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyPrefixThenRecencyOrder(Builder $query, string $column, string $q): void
    {
        $prefix = $q.'%';

        $query->orderByRaw(
            "CASE WHEN {$column} ILIKE ? THEN 0 ELSE 1 END",
            [$prefix],
        )
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }
}
