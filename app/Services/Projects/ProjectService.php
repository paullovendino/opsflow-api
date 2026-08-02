<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Enums\ProjectStatus;
use App\Enums\UserStatus;
use App\Exceptions\DuplicateProjectMemberException;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ProjectService
{
    /**
     * @return Collection<int, Project>
     */
    public function list(): Collection
    {
        return Project::query()
            ->with('owner')
            ->latest('created_at')
            ->get();
    }

    public function find(Project $project): Project
    {
        return $project->loadMissing('owner');
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

        return $project->load('owner');
    }

    /**
     * @param  array{
     *     name: string,
     *     description?: string|null,
     *     start_date?: string|null,
     *     due_date?: string|null
     * }  $data
     */
    public function update(Project $project, array $data): Project
    {
        $project->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
        ]);

        return $project->fresh('owner') ?? $project->load('owner');
    }

    public function delete(Project $project): void
    {
        $project->delete();
    }

    public function changeStatus(Project $project, ProjectStatus $status): Project
    {
        $project->update([
            'status' => $status,
        ]);

        return $project->fresh('owner') ?? $project->load('owner');
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

    public function addMember(Project $project, int $userId): User
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

        return $project->members()->where('users.id', $user->id)->firstOrFail();
    }

    public function removeMember(Project $project, User $user): void
    {
        if (! $project->members()->where('users.id', $user->id)->exists()) {
            throw (new ModelNotFoundException)->setModel(User::class, [$user->id]);
        }

        $project->members()->detach($user->id);
    }
}
