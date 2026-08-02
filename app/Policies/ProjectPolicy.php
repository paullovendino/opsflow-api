<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isAdministrator($actor)
            || $this->isProjectManager($actor)
            || $this->isEmployee($actor);
    }

    public function view(User $actor, Project $project): bool
    {
        if ($this->isAdministrator($actor) || $this->isProjectManager($actor)) {
            return true;
        }

        return $this->isEmployee($actor) && $this->isOwnerOrMember($actor, $project);
    }

    public function create(User $actor): bool
    {
        return $this->isAdministrator($actor) || $this->isProjectManager($actor);
    }

    public function update(User $actor, Project $project): bool
    {
        return $this->isAdministrator($actor) || $this->isProjectManager($actor);
    }

    public function delete(User $actor, Project $project): bool
    {
        return $this->isAdministrator($actor) || $this->isProjectManager($actor);
    }

    public function updateStatus(User $actor, Project $project): bool
    {
        return $this->isAdministrator($actor) || $this->isProjectManager($actor);
    }

    public function manageMembers(User $actor, Project $project): bool
    {
        return $this->isAdministrator($actor) || $this->isProjectManager($actor);
    }

    private function isOwnerOrMember(User $actor, Project $project): bool
    {
        if ((int) $project->created_by === (int) $actor->id) {
            return true;
        }

        return $project->members()->where('users.id', $actor->id)->exists();
    }

    private function isAdministrator(User $user): bool
    {
        return $this->roleName($user) === RoleName::Administrator;
    }

    private function isProjectManager(User $user): bool
    {
        return $this->roleName($user) === RoleName::ProjectManager;
    }

    private function isEmployee(User $user): bool
    {
        return $this->roleName($user) === RoleName::Employee;
    }

    private function roleName(User $user): ?RoleName
    {
        $user->loadMissing('role');

        $name = $user->role?->name;

        return $name instanceof RoleName ? $name : null;
    }
}
