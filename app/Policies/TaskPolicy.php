<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isAdministrator($actor)
            || $this->isProjectManager($actor)
            || $this->isEmployee($actor);
    }

    public function view(User $actor, Task $task): bool
    {
        if ($this->isAdministrator($actor) || $this->isProjectManager($actor)) {
            return true;
        }

        return $this->isEmployee($actor) && $this->hasAccessibleProject($actor, $task);
    }

    public function create(User $actor): bool
    {
        return $this->isAdministrator($actor) || $this->isProjectManager($actor);
    }

    public function update(User $actor, Task $task): bool
    {
        return $this->isAdministrator($actor) || $this->isProjectManager($actor);
    }

    public function delete(User $actor, Task $task): bool
    {
        return $this->isAdministrator($actor) || $this->isProjectManager($actor);
    }

    public function updateAssignment(User $actor, Task $task): bool
    {
        return $this->isAdministrator($actor) || $this->isProjectManager($actor);
    }

    public function updateStatus(User $actor, Task $task): bool
    {
        if ($this->isAdministrator($actor) || $this->isProjectManager($actor)) {
            return true;
        }

        if (! $this->isEmployee($actor)) {
            return false;
        }

        return $this->hasAccessibleProject($actor, $task)
            && $task->assigned_to !== null
            && (int) $task->assigned_to === (int) $actor->id;
    }

    private function hasAccessibleProject(User $actor, Task $task): bool
    {
        $task->loadMissing('project');

        $project = $task->project;

        if ($project === null) {
            return false;
        }

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
